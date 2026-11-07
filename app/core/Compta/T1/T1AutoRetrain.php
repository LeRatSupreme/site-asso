<?php

declare(strict_types=1);

namespace App\Core\Compta\T1;

use App\Core\Logger;
use App\Models\AuditLog;
use Throwable;

/**
 * Boucle de ré-entraînement automatique du détecteur de doublons
 * (P2-auto) : récolte des retours « t1.pair_feedback » DEPUIS le
 * dernier entraînement, compteur de paires validées, déclenchement du
 * pipeline scripts/t1_auto_retrain.sh EN ARRIÈRE-PLAN au chargement de
 * la page Inventaire (aucun cron, comme la quarantaine T1.5), indicateur
 * sobre sur l'écran.
 *
 * État : UN fichier JSON data/eval_t1/p2/auto/etat.json protégé par
 * flock (verrou dans <chemin>.lock, JAMAIS renommé ; écriture atomique
 * tmp + rename — même discipline que T1Quarantaine) :
 *
 *   { version, dernier_run, modele_version, paires_cumulees, seuil,
 *     statut, dernier_lancement, historique[] }
 *
 * statut : pret | entrainement_en_cours | echec | remplace | consserve
 * (echec/remplace/consserve sont écrits par le script lui-même, brief
 * A2 ; « consserve » = modèle conservé à l'issue d'un run moins bon).
 *
 * paires_cumulees est RECALCULÉ à chaque passage (paires distinctes
 * postérieures à dernier_run) : idempotent, sans dérive — le script le
 * remet à 0 en faisant avancer dernier_run.
 *
 * Déclenchement : paires_cumulees >= seuil ET statut != en cours ET
 * anti-tempête respecté (pas de relance moins de DELAI_RELANCE_MIN
 * minutes après le dernier essai ; un « en cours » depuis plus de
 * STALE_HEURES est considéré mort et relancé — le script A2 a son
 * propre flock : plafond 1 run simultané des deux côtés).
 *
 * Le lancement ne contient AUCUNE entrée utilisateur : chemins internes
 * constants (revue sécurité brief B). Chemins redirigeables pour les
 * tests : constantes AEIC_P2AUTO_ETAT / AEIC_P2AUTO_JOURNAL /
 * AEIC_P2AUTO_LOG (tests/bootstrap.php + runner.php → tests/cache/),
 * et AEIC_P2AUTO_SCRIPT par variable d'environnement UNIQUEMENT en mode
 * test (APP_TESTING) — jamais en production.
 */
final class T1AutoRetrain
{
    public const VERSION_ETAT = 1;

    /** Paires validées nécessaires avant un entraînement (brief A1). */
    public const SEUIL_DEFAUT = 30;

    public const STATUT_PRET = 'pret';
    public const STATUT_EN_COURS = 'entrainement_en_cours';
    public const STATUT_ECHEC = 'echec';
    public const STATUT_REMPLACE = 'remplace';
    public const STATUT_CONSSERVE = 'consserve';

    /** @var list<string> */
    private const STATUTS = [
        self::STATUT_PRET,
        self::STATUT_EN_COURS,
        self::STATUT_ECHEC,
        self::STATUT_REMPLACE,
        self::STATUT_CONSSERVE,
    ];

    /** Anti-tempête : minutes minimales entre deux ESSAIS de lancement. */
    public const DELAI_RELANCE_MIN = 15;

    /** Un « en cours » plus vieux que ça (heures) est mort : on relance. */
    public const STALE_HEURES = 4;

    /** Taille max de l'historique conservé dans etat.json. */
    public const HISTORIQUE_MAX = 50;

    public const CHEMIN_DEFAUT_ETAT = AEIC_ROOT . '/data/eval_t1/p2/auto/etat.json';
    public const CHEMIN_DEFAUT_JOURNAL = AEIC_ROOT . '/data/eval_t1/p2/auto/journal.md';
    public const CHEMIN_DEFAUT_SCRIPT = AEIC_ROOT . '/scripts/t1_auto_retrain.sh';
    public const CHEMIN_DEFAUT_LOG = AEIC_ROOT . '/data/eval_t1/p2/auto/retrain.log';

    /** Livrable du modèle courant : swap prod = date du dernier entraînement. */
    public const CHEMIN_CANDIDATS = AEIC_ROOT . '/data/eval_t1/candidats_fusion.json';

    /** Fuseau de tout affichage (fenêtre métier de l'association). */
    public const FUSEAU = T1AutoFusion::FUSEAU;

    // ── Chemins (redirigeables pour les tests) ────────────────────────────

    public static function cheminEtat(): string
    {
        return self::surchargeConstante('AEIC_P2AUTO_ETAT', self::CHEMIN_DEFAUT_ETAT);
    }

    public static function cheminJournal(): string
    {
        return self::surchargeConstante('AEIC_P2AUTO_JOURNAL', self::CHEMIN_DEFAUT_JOURNAL);
    }

    public static function cheminLog(): string
    {
        return self::surchargeConstante('AEIC_P2AUTO_LOG', self::CHEMIN_DEFAUT_LOG);
    }

    /**
     * Script du pipeline (brief A2). En production : le chemin interne
     * constant, TOUJOURS. En contexte de test (APP_TESTING), la variable
     * d'environnement AEIC_P2AUTO_SCRIPT désigne un faux script qui
     * trace son passage — le VRAI pipeline n'est JAMAIS exécuté depuis
     * un test, même après l'arrivée du brief A2.
     */
    public static function cheminScript(): string
    {
        if (\defined('APP_TESTING') && \constant('APP_TESTING')) {
            $surcharge = (string) getenv('AEIC_P2AUTO_SCRIPT');

            return $surcharge !== '' ? $surcharge : self::surchargeConstante('AEIC_P2AUTO_SCRIPT', self::CHEMIN_DEFAUT_SCRIPT);
        }

        return self::surchargeConstante('AEIC_P2AUTO_SCRIPT', self::CHEMIN_DEFAUT_SCRIPT);
    }

    private static function surchargeConstante(string $nom, string $defaut): string
    {
        if (\defined($nom)) {
            $valeur = (string) \constant($nom);

            return $valeur !== '' ? $valeur : $defaut;
        }

        return $defaut;
    }

    // ── État : structure, lectures tolérantes, écriture atomique ─────────

    /**
     * @return array{version:int,dernier_run:?string,modele_version:?string,paires_cumulees:int,seuil:int,statut:string,dernier_lancement:?string,historique:list<array<string,mixed>>}
     */
    public static function etatDefaut(): array
    {
        return [
            'version'            => self::VERSION_ETAT,
            'dernier_run'        => null,
            'modele_version'     => null,
            'paires_cumulees'    => 0,
            'seuil'              => self::SEUIL_DEFAUT,
            'statut'             => self::STATUT_PRET,
            'dernier_lancement'  => null,
            'historique'         => [],
        ];
    }

    /**
     * État brut défensif : structure par défaut si le fichier est
     * absent/corrompu, champs invalides rabattus sur leurs valeurs
     * sûres (jamais d'exception).
     *
     * @return array<string,mixed>
     */
    public static function lireEtat(): array
    {
        $etat = self::etatDefaut();
        try {
            $chemin = self::cheminEtat();
            if (is_file($chemin) && is_readable($chemin)) {
                $data = json_decode((string) @file_get_contents($chemin), true, 8);
                if (is_array($data)) {
                    $etat = self::normaliserEtat($data);
                }
            }
        } catch (Throwable) {
            $etat = self::etatDefaut();
        }

        return $etat;
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    private static function normaliserEtat(array $data): array
    {
        $etat = self::etatDefaut();
        $etat['dernier_run'] = is_string($data['dernier_run'] ?? null) && $data['dernier_run'] !== ''
            ? $data['dernier_run'] : null;
        $etat['modele_version'] = is_string($data['modele_version'] ?? null) && $data['modele_version'] !== ''
            ? $data['modele_version'] : null;
        $etat['paires_cumulees'] = max(0, (int) ($data['paires_cumulees'] ?? 0));
        $etat['seuil'] = max(1, (int) ($data['seuil'] ?? self::SEUIL_DEFAUT));
        $statut = (string) ($data['statut'] ?? '');
        $etat['statut'] = in_array($statut, self::STATUTS, true) ? $statut : self::STATUT_PRET;
        $etat['dernier_lancement'] = is_string($data['dernier_lancement'] ?? null) && $data['dernier_lancement'] !== ''
            ? $data['dernier_lancement'] : null;
        if (is_array($data['historique'] ?? null)) {
            foreach ($data['historique'] as $e) {
                if (is_array($e)) {
                    $etat['historique'][] = $e;
                }
            }
        }

        return $etat;
    }

    /**
     * Écriture ATOMIQUE de l'état (tmp + rename), verrou détenu par
     * l'appelant (passage).
     *
     * @param array<string,mixed> $etat
     */
    private static function ecrireEtat(array $etat): void
    {
        $chemin = self::cheminEtat();
        $dir = dirname($chemin);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $json = json_encode($etat, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            return;
        }
        $tmp = $chemin . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $json) === false) {
            return;
        }
        @rename($tmp, $chemin);
    }

    /**
     * Ouvre (crée au besoin) le fichier de VERROU — distinct du fichier
     * de données (remplacé par rename : deux inodes = deux verrous).
     *
     * @return resource|null
     */
    private static function ouvrirVerrou()
    {
        $chemin = self::cheminEtat();
        $dir = dirname($chemin);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $handle = @fopen($chemin . '.lock', 'c');
        if ($handle === false) {
            return null;
        }

        return $handle;
    }

    // ── Récolte (point d'assemblage unique, réutilisé par le CLI A2) ─────

    /**
     * Paires étiquetées NOUVELLES (postérieures à $depuisIso) : lignes
     * d'audit action t1.pair_feedback → T1FeedbackFeed::collecter.
     * Tolérante sans base : liste vide.
     *
     * @return array{positives:list<array<string,mixed>>,negatives:list<array<string,mixed>>,total:int}
     */
    public static function recolter(?string $depuisIso): array
    {
        $lignes = AuditLog::rowsForAction(
            T1Suggestions::ACTION_FEEDBACK,
            T1FeedbackFeed::borneSqlUtc($depuisIso)
        );

        return T1FeedbackFeed::collecter($lignes, $depuisIso);
    }

    // ── Décision de lancement (PURE, testée unitairement) ────────────────

    /**
     * Faut-il lancer le pipeline maintenant ?
     *
     * @param array<string,mixed> $etat          État courant (normalisé).
     * @param int                 $paires        Paires validées recomptées.
     * @param string|null         $maintenantIso Horloge injectable (ISO à décalage explicite).
     *
     * @return array{lancer:bool,raison:string}
     */
    public static function decision(array $etat, int $paires, ?string $maintenantIso = null): array
    {
        $seuil = max(1, (int) ($etat['seuil'] ?? self::SEUIL_DEFAUT));
        $statut = (string) ($etat['statut'] ?? self::STATUT_PRET);
        $maintenant = T1FeedbackFeed::instantIso($maintenantIso ?? T1AutoFusion::maintenantIso());
        if ($maintenant === null) {
            return ['lancer' => false, 'raison' => 'horloge illisible'];
        }

        if ($paires < $seuil) {
            return ['lancer' => false, 'raison' => 'sous le seuil'];
        }

        if ($statut === self::STATUT_EN_COURS) {
            $lancement = T1FeedbackFeed::instantIso((string) ($etat['dernier_lancement'] ?? ''));
            if ($lancement !== null && $maintenant - $lancement < self::STALE_HEURES * 3600) {
                return ['lancer' => false, 'raison' => 'entraînement déjà en cours'];
            }

            return ['lancer' => true, 'raison' => 'entraînement en cours jugé mort, relance'];
        }

        $lancement = T1FeedbackFeed::instantIso((string) ($etat['dernier_lancement'] ?? ''));
        if ($lancement !== null && $maintenant - $lancement < self::DELAI_RELANCE_MIN * 60) {
            return ['lancer' => false, 'raison' => 'anti-tempête : essai trop récent'];
        }

        return ['lancer' => true, 'raison' => 'seuil atteint'];
    }

    // ── Passage : récolte + compteur + déclenchement (au chargement) ─────

    /**
     * UN passage de la boucle (appelé au chargement de la page
     * Inventaire, jamais bloquant — < 1 s) :
     *
     *  0. verrou NON BLOQUANT sur etat.json : deux chargements
     *     simultanés → le second passe son tour (idempotence) ;
     *  1. état lu/corrigé, amorcé au premier passage depuis
     *     candidats_fusion.json (dernier entraînement = date de mise en
     *     prod du modèle courant, sa version notée) ;
     *  2. paires_cumulees RECALCULÉ depuis l'audit (idempotent) ;
     *  3. si le seuil est atteint et le statut le permet : statut =
     *     entrainement_en_cours persisté SOUS VERROU, puis lancement du
     *     script en ARRIÈRE-PLAN (après libération) — plafond 1 run ;
     *  4. journal + ligne d'indicateur pour l'écran.
     *
     * Ne jette JAMAIS : toute erreur est journalisée, la page reste
     * fonctionnelle (dégradation gracieuse).
     *
     * @return array{saute:bool,lance:bool,paires:int,seuil:int,statut:string,ligne:string}
     */
    public static function passage(?string $maintenantIso = null): array
    {
        $r = ['saute' => false, 'lance' => false, 'paires' => 0, 'seuil' => self::SEUIL_DEFAUT,
              'statut' => self::STATUT_PRET, 'ligne' => ''];
        $handle = self::ouvrirVerrou();
        if ($handle === null) {
            $r['ligne'] = self::ligneIndicateur(self::lireEtat());

            return $r;
        }

        $voulu = false;
        $lancer = false;
        $raison = '';
        $nbPositives = 0;
        $nbNegatives = 0;
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                // Un autre chargement tient le verrou : on passe (jamais
                // d'attente bloquante sur une page admin).
                $r['saute'] = true;
                $r['ligne'] = self::ligneIndicateur(self::lireEtat());

                return $r;
            }

            $etat = self::lireEtat();
            $avant = $etat;
            $maintenant = $maintenantIso ?? T1AutoFusion::maintenantIso();

            // Premier passage : amorçage depuis le modèle courant.
            self::amorcer($etat);

            try {
                $recolte = self::recolter(is_string($etat['dernier_run']) ? $etat['dernier_run'] : null);
            } catch (Throwable $e) {
                Logger::warning('t1.retrain: récolte impossible', ['msg' => $e->getMessage()]);
                $recolte = ['positives' => [], 'negatives' => [], 'total' => 0];
            }
            $etat['paires_cumulees'] = $recolte['total'];
            $nbPositives = count($recolte['positives']);
            $nbNegatives = count($recolte['negatives']);

            $decision = self::decision($etat, $recolte['total'], $maintenant);
            $voulu = $decision['lancer'];
            $raison = $decision['raison'];
            $lancer = $voulu && self::lancementPossible();
            if ($voulu && !$lancer) {
                // Seuil atteint mais lancement impossible : on ne marque
                // PAS « en cours » (sinon la boucle se verrouillerait sur
                // un run jamais parti) — nouvel essai au chargement suivant.
                self::journaliser(sprintf(
                    'seuil atteint (%d paires) mais lancement impossible (script ou exec indisponible) — nouvel essai au prochain chargement',
                    $recolte['total']
                ));
                Logger::warning('t1.retrain: lancement impossible (script ou exec indisponible)', [
                    'script' => self::cheminScript(),
                ]);
            }

            if ($lancer) {
                $etat['statut'] = self::STATUT_EN_COURS;
                $etat['dernier_lancement'] = $maintenant;
                $etat['historique'][] = [
                    'quand'     => $maintenant,
                    'evenement' => 'lancement',
                    'paires'    => $recolte['total'],
                    'positives' => $nbPositives,
                    'negatives' => $nbNegatives,
                    'raison'    => $raison,
                ];
                $etat['historique'] = array_slice($etat['historique'], -self::HISTORIQUE_MAX);
            }

            if ($etat !== $avant) {
                self::ecrireEtat($etat);
            }

            $r['paires'] = (int) $etat['paires_cumulees'];
            $r['seuil'] = (int) $etat['seuil'];
            $r['statut'] = (string) $etat['statut'];
            $r['ligne'] = self::ligneIndicateur($etat);
        } catch (Throwable $e) {
            Logger::warning('t1.retrain: passage interrompu', ['msg' => $e->getMessage()]);
            $r['ligne'] = self::ligneIndicateur(self::lireEtat());
            $lancer = false;
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }

        if ($lancer) {
            if (self::lancerArrierePlan()) {
                $r['lance'] = true;
                self::journaliser(sprintf(
                    'lancement du pipeline en arrière-plan — %d paires validées (%d fusions, %d ignorés), %s',
                    $r['paires'],
                    $nbPositives,
                    $nbNegatives,
                    $raison
                ));
            } else {
                self::journaliser('lancement refusé par le système (exec) — nouvel essai au prochain chargement');
                Logger::warning('t1.retrain: exec du pipeline refusé', []);
            }
        }

        return $r;
    }

    /**
     * Le lancement est-il POSSIBLE dans cet environnement : script
     * présent ET exec utilisable. En contexte de test (APP_TESTING),
     * exiger la variable AEIC_P2AUTO_SCRIPT : sans elle, AUCUN script
     * n'est exécuté — le vrai pipeline ne peut pas partir d'un test.
     */
    private static function lancementPossible(): bool
    {
        if (\defined('APP_TESTING') && \constant('APP_TESTING')
            && (string) getenv('AEIC_P2AUTO_SCRIPT') === '') {
            return false;
        }
        if (!is_file(self::cheminScript()) || !is_readable(self::cheminScript())) {
            return false;
        }
        $desactivees = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return function_exists('exec') && !in_array('exec', $desactivees, true);
    }

    /**
     * Lance le pipeline en arrière-plan : nohup + & (le processus
     * survit à la requête), sorties vers le log dédié du script (brief
     * A2). AUCUNE entrée utilisateur dans la ligne de commande —
     * uniquement des chemins internes échappés (revue sécurité B).
     */
    private static function lancerArrierePlan(): bool
    {
        try {
            $log = self::cheminLog();
            $dir = dirname($log);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $cmd = sprintf(
                'cd %s && nohup bash %s >> %s 2>&1 &',
                escapeshellarg((string) AEIC_ROOT),
                escapeshellarg(self::cheminScript()),
                escapeshellarg($log)
            );
            @exec($cmd, $sortie, $code);

            return $code === 0;
        } catch (Throwable $e) {
            Logger::warning('t1.retrain: exec refusé', ['msg' => $e->getMessage()]);

            return false;
        }
    }

    // ── Marquage de l'issue d'un run (appelé par le script A2) ──────────

    /**
     * Marque l'issue d'un run du pipeline (brief A2) — UN seul
     * écrivain : cette méthode, sous VERROU EXCLUSIF sur <etat>.lock,
     * écriture atomique (même discipline que passage()).
     *
     *  - echec    : dernier_run INCHANGÉ (les paires restent comptées,
     *               la boucle réessaie après l'anti-tempête) ;
     *  - remplace / consserve : les paires exportées sont CONSOMMÉES →
     *               dernier_run = $runIso (borne export), paires_cumulees
     *               remis à 0 (recompté au passage suivant), modele_version
     *               mis à jour si remplace.
     *
     * $runIso : instant ISO à décalage explicite (borne d'export — la
     * plus récente decide_le consommée) ; illisible → maintenant.
     *
     * @return bool succès de la persistance (jamais d'exception).
     */
    public static function marquer(string $statut, ?string $runIso = null, ?string $modeleVersion = null, ?string $detail = null): bool
    {
        if (!\in_array($statut, [self::STATUT_ECHEC, self::STATUT_REMPLACE, self::STATUT_CONSSERVE], true)) {
            return false;
        }
        $handle = self::ouvrirVerrou();
        if ($handle === null) {
            return false;
        }
        try {
            if (!flock($handle, LOCK_EX)) { // bloquant : CLI court, plafonné par le flock du script
                return false;
            }
            $etat = self::lireEtat();
            $maintenant = T1AutoFusion::maintenantIso();
            $run = T1FeedbackFeed::instantIso((string) $runIso) !== null ? (string) $runIso : $maintenant;

            $etat['statut'] = $statut;
            $evenement = [
                'quand'     => $maintenant,
                'evenement' => $statut,
                'run'       => $run,
                'detail'    => $detail,
            ];
            if ($statut !== self::STATUT_ECHEC) {
                $etat['dernier_run'] = $run;
                $etat['paires_cumulees'] = 0;
                if (\is_string($modeleVersion) && $modeleVersion !== '') {
                    $etat['modele_version'] = $modeleVersion;
                }
            }
            $etat['historique'][] = $evenement;
            $etat['historique'] = \array_slice($etat['historique'], -self::HISTORIQUE_MAX);
            self::ecrireEtat($etat);

            $runFormate = self::formaterDateIso($run);
            $message = match ($statut) {
                self::STATUT_ECHEC => 'run en échec'
                    . ($detail !== null && $detail !== '' ? ' — ' . $detail : '')
                    . ' — les paires restent comptées, nouvel essai automatique',
                self::STATUT_REMPLACE => 'run terminé : modèle REMPLACÉ'
                    . ($runFormate !== '' ? ' (paires consommées jusqu\'au ' . $runFormate . ')' : '')
                    . ($modeleVersion !== null && $modeleVersion !== '' ? ' — ' . $modeleVersion : ''),
                default => 'run terminé : modèle CONSERVÉ (pas meilleur à mesures égales)'
                    . ($runFormate !== '' ? ' — paires consommées jusqu\'au ' . $runFormate : '')
                    . ($detail !== null && $detail !== '' ? ' — ' . $detail : ''),
            };
            self::journaliser($message, $maintenant);

            return true;
        } catch (Throwable $e) {
            Logger::warning('t1.retrain: marquage impossible', ['msg' => $e->getMessage()]);

            return false;
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    // ── Journal (data/eval_t1/p2/auto/journal.md) ───────────────────────

    /**
     * Ajoute une ligne horodatée au journal (append atomique par verrou
     * propre à l'écriture). Tolérant : un journal incréable n'entrave
     * jamais la boucle.
     */
    public static function journaliser(string $ligne, ?string $quandIso = null): void
    {
        try {
            $chemin = self::cheminJournal();
            $dir = dirname($chemin);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $entete = '';
            if (!is_file($chemin)) {
                $entete = "# Journal — boucle de ré-entraînement automatique (P2-auto)\n\n";
            }
            $date = self::formaterDateIso($quandIso ?? T1AutoFusion::maintenantIso());
            @file_put_contents(
                $chemin,
                $entete . '- ' . ($date !== '' ? $date . ' — ' : '') . $ligne . "\n",
                FILE_APPEND | LOCK_EX
            );
        } catch (Throwable) {
            // Journal best-effort : jamais bloquant.
        }
    }

    // ── Indicateur (carte « Suggestions de rapprochement ») ──────────────

    /**
     * Ligne sobre de l'indicateur, calculée au centre (UN seul point
     * d'assemblage) : la vue ne fait qu'afficher.
     *
     * @param array<string,mixed> $etat
     */
    public static function ligneIndicateur(array $etat): string
    {
        $paires = max(0, (int) ($etat['paires_cumulees'] ?? 0));
        $seuil = max(1, (int) ($etat['seuil'] ?? self::SEUIL_DEFAUT));
        $statut = (string) ($etat['statut'] ?? self::STATUT_PRET);
        $dernierRun = self::formaterDateIso((string) ($etat['dernier_run'] ?? ''));

        return match ($statut) {
            self::STATUT_EN_COURS => 'Ré-entraînement automatique : entraînement en cours.',
            self::STATUT_ECHEC => 'Ré-entraînement automatique : ' . $paires . '/' . $seuil
                . ' paires validées — dernier entraînement en échec'
                . ($dernierRun !== '' ? ' le ' . $dernierRun : '')
                . ', nouvel essai automatique.',
            self::STATUT_REMPLACE, self::STATUT_CONSSERVE => 'Ré-entraînement automatique : ' . $paires . '/' . $seuil
                . ' paires validées — dernier entraînement'
                . ($dernierRun !== '' ? ' le ' . $dernierRun : '')
                . ', modèle ' . ($statut === self::STATUT_REMPLACE ? 'remplacé' : 'conservé') . '.',
            default => $paires >= $seuil
                ? 'Ré-entraînement automatique : ' . $paires . '/' . $seuil
                    . ' paires validées — entraînement imminent.'
                : 'Ré-entraînement automatique : ' . $paires . '/' . $seuil
                    . ' paires validées — prochain entraînement à ' . $seuil . '.',
        };
    }

    /**
     * Date ISO (décalage explicite) → « d/m/Y à H:i » heure de Paris.
     * Illisible → '' (la ligne s'adapte, jamais d'heure aberrante).
     */
    public static function formaterDateIso(string $iso): string
    {
        $instant = T1FeedbackFeed::instantIso($iso);
        if ($instant === null) {
            return '';
        }

        try {
            return (new \DateTimeImmutable('@' . $instant))
                ->setTimezone(new \DateTimeZone(self::FUSEAU))
                ->format('d/m/Y \à H:i');
        } catch (Throwable) {
            return '';
        }
    }

    // ── Amorçage de l'état depuis le modèle courant ───────────────────────

    /**
     * Amorce l'état au PREMIER passage : dernier_run = date de mise en
     * prod du modèle courant (generated_at de candidats_fusion.json),
     * modele_version = son libellé « modele ». Fichier illisible → on
     * reste à null (tout l'historique compte : le doute ne perd pas de
     * paires). Appelé SOUS VERROU avant la première récolte.
     *
     * @param array<string,mixed> $etat (référence, mutée si amorçage)
     */
    private static function amorcer(array &$etat): void
    {
        if ($etat['dernier_run'] !== null) {
            return;
        }
        try {
            if (!is_file(self::CHEMIN_CANDIDATS) || !is_readable(self::CHEMIN_CANDIDATS)) {
                return;
            }
            $data = json_decode((string) @file_get_contents(self::CHEMIN_CANDIDATS), true, 8);
            if (!is_array($data)) {
                return;
            }
            $genere = is_string($data['generated_at'] ?? null) ? (string) $data['generated_at'] : '';
            if (T1FeedbackFeed::instantIso($genere) !== null) {
                $etat['dernier_run'] = $genere;
            }
            $modele = is_string($data['modele'] ?? null) ? trim((string) $data['modele']) : '';
            if ($modele !== '') {
                $etat['modele_version'] = $modele;
            }
        } catch (Throwable) {
            // Amorçage best-effort : rester à null est sûr.
        }
    }
}
