<?php

declare(strict_types=1);

namespace App\Core\Assistant;

use App\Core\Compta\Edt\CalendarContext;
use App\Core\Compta\Edt\EdtCalendar;
use App\Core\Logger;
use App\Models\AnalyticsForecastData;

/**
 * ASSEMBLAGE DES DONNÉES d'outils assistant PARTAGÉ entre les deux voies
 * (EDT-PUB-13 / STOCK-PUB-14, mission 31/10 soir) :
 *  - l'API interne AssistantToolsController (consommée par le pont
 *    scripts/assistant.py — gabarits du CLI admin) ;
 *  - les GABARITS de la voie PUBLIQUE (PublicGabarits, appelés par
 *    PublicAssistant AVANT la FAQ — zéro tour LLM).
 *
 * Règle AGENTS (« une donnée partagée par plusieurs pages vient d'UN
 * seul point d'assemblage ») : la mise en forme JSON de edt_iut et la
 * résolution stock + garde de fraîcheur de stock_produit vivent ICI et
 * nulle part ailleurs — le contrôleur ne fait que valider les paramètres
 * et encoder, les gabarits publics ne font que mettre en phrase.
 *
 * Les structures de retour sont les structures JSON FIXES de l'API
 * interne (champs français stables, contrat T4) : byte-identiques à ce
 * que servait le contrôleur avant l'extraction.
 */
final class AssistantOutils
{
    /** Noms d'outils (contrat URL de l'API interne). */
    public const OUTIL_EDT   = 'edt_iut';
    public const OUTIL_STOCK = 'stock_produit';

    /** Départements IUT demandables (valeur d'URL → ids Calais
     *  d'EdtCalendar, point d'assemblage unique de l'EDT — jamais de
     *  recalcul ici). */
    public const EDT_DEPTS = [
        'tous' => ['iut-info', 'iut-gea', 'iut-geii'],
        'info' => ['iut-info'],
        'gea'  => ['iut-gea'],
        'geii' => ['iut-geii'],
    ];

    /** Noms courts affichés dans la réponse (même vocabulaire que le
     * paramètre « dept » : info/gea/geii). */
    public const EDT_DEPTS_COURTS = ['iut-info' => 'info', 'iut-gea' => 'gea', 'iut-geii' => 'geii'];

    /** Vues acceptées du paramètre « semaine ». */
    public const EDT_SEMAINES = ['aujourdhui' => true, 'courante' => true, 'prochaine' => true];

    /** Seuil de fraîcheur d'un comptage (jours calendaires, Paris) :
     * au-delà, le stock théorique (dernier comptage + mouvements)
     * dérive et doit être signalé comme peu fiable (mission T2 : le
     * cas réel « Monster verte » re-questions ×5 avec un comptage de
     * 44 j — l'utilisateur doit savoir que le chiffre est vieux). */
    public const FRAICHEUR_SEUIL_JOURS = 30;

    /** Candidats proposés quand un libellé produit est introuvable. */
    public const CANDIDATS_LIMITE = 5;

    /**
     * Budget global (s) d'UNE lecture EDT côté assistant — ÉCHÉANCE
     * STRICTE PARTAGÉE par toutes les voies (gabarit public, gabarits
     * kiosque, outil CLI admin : tout passe par edtIut) : jamais plus
     * de ~2 s de fetch edt-ulco.fr par requête, à froid comme à chaud
     * (EDT-PUB-13-SUITE ; l'analytique garde le défaut EdtCalendar 8 s,
     * le cron SMS minuteur rafraîchit le cache en tâche de fond).
     */
    public const EDT_BUDGET_S = 2.0;

    /** Familles MAX de la sélection publique (GENERIC-PUB-17 : une
     *  courte sélection, JAMAIS l'inventaire — arbitrage STOCK-PUB-14
     *  étendu aux questions génériques « à boire et à manger »). */
    public const SELECTION_MAX = 10;

    /** Premiers mots qui ne sont PAS des familles de produits (artefacts
     *  de saisie du catalogue : « custom amount », « Montant
     *  personnalisé », « Menu BBQ » ; « Verre Nesquik » = demande
     *  produit, pas une famille « Verre » affichable). */
    private const FAMILLES_EXCLUES = ['custom', 'montant', 'menu', 'verre'];

    // -----------------------------------------------------------------
    //  Outil edt_iut — cours/EDT de l'IUT (données EdtCalendar, cache 6 h)
    // -----------------------------------------------------------------

    /**
     * Réponse edt_iut complète (structure JSON FIXE de l'API interne).
     * Paramètres déjà validés par l'appelant (ou valeurs sûres par
     * défaut) ; toute valeur hors liste blanche → null (défense en
     * profondeur, jamais d'exception).
     *
     * Fail-closed honnête : calendrier totalement vide (API down sans
     * aucun cache) → null (« Données EDT indisponibles. » côté contrôleur,
     * fail-soft vers la voie LLM côté gabarit public), jamais de
     * « semaine sans cours » inventée sur du vide.
     *
     * @return array<string, mixed>|null
     */
    public static function edtIut(string $dept, string $semaine): ?array
    {
        $deptsIut = self::EDT_DEPTS[$dept] ?? null;
        if ($deptsIut === null || !isset(self::EDT_SEMAINES[$semaine])) {
            return null;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));

        // Injecteurs partagés CalendarContext : production = défauts
        // EdtCalendar (curl + cache/edt), test = calendrier vide hors-ligne.
        // Budget 2 s (EDT_BUDGET_S) : échéance stricte commune aux voies
        // assistant — jamais > ~2,5 s au mur, à froid (repli stale daté).
        [$fetch, $dir] = CalendarContext::injecteurs(null, null);
        $plage = EdtCalendar::plagePubliee($fetch, $dir, self::EDT_BUDGET_S);
        if ($plage['fin'] === null) {
            // Aucune donnée EDT publiée nulle part : refus honnête.
            Logger::warning('assistant: edt_iut sans donnees (calendrier EDT vide)');

            return null;
        }

        if ($semaine === 'aujourdhui') {
            $jour   = $now->format('Y-m-d');
            $infos  = EdtCalendar::jourInfos($jour, $fetch, $dir, self::EDT_BUDGET_S);
            $n      = self::edtSommeDept($infos['par_dept'], $deptsIut);
            $reponse = [
                'outil'        => self::OUTIL_EDT,
                'depts'        => self::edtNomsCourts($dept),
                'semaine'      => 'aujourdhui',
                'jour'         => $jour,
                'creneaux'     => $n,
                'jours_actifs' => $n > 0 ? 1 : 0,
                'avec_cours'   => $n > 0,
                'hors_horizon' => strcmp($jour, (string) $plage['fin']) > 0,
                'label'        => $n > 0
                    ? 'Jour de cours'
                    : (strcmp($jour, (string) $plage['fin']) > 0
                        ? 'Au-delà de l\'horizon publié'
                        : 'Jour sans cours (vacances ?)'),
                'stale'        => (bool) $infos['stale'],
                // Date du cache servi (jour Paris du dernier fetch, champ
                // ADDITIF) : affichée par le repli quand stale=true.
                'cache_du'     => self::jourParisFr((int) $plage['fetched_at']),
            ];
        } else {
            $lundi = $now->modify($semaine === 'courante' ? 'monday this week' : 'monday next week')->format('Y-m-d');
            $s = EdtCalendar::semaineInfos($lundi, $fetch, $dir, self::EDT_BUDGET_S);

            if ($dept === 'tous') {
                $n      = (int) $s['creneaux_total'];
                $actifs = (int) $s['jours_actifs'];
            } else {
                // Vue departement : somme des jours ouvrés via jourInfos
                // (mémoïsé par EdtCalendar — une seule lecture du calendrier).
                $n = 0;
                $actifs = 0;
                for ($i = 0; $i < CalendarContext::JOURS_OUVRES_SEMAINE; $i++) {
                    $d = (new \DateTimeImmutable($lundi))->modify("+{$i} day")->format('Y-m-d');
                    $c = self::edtSommeDept(EdtCalendar::jourInfos($d, $fetch, $dir, self::EDT_BUDGET_S)['par_dept'], $deptsIut);
                    $n += $c;
                    if ($c > 0) {
                        $actifs++;
                    }
                }
            }
            $reponse = [
                'outil'        => self::OUTIL_EDT,
                'depts'        => self::edtNomsCourts($dept),
                'semaine'      => $semaine,
                'lundi'        => $lundi,
                'vendredi'     => (new \DateTimeImmutable($lundi))->modify('+4 days')->format('Y-m-d'),
                'creneaux'     => $n,
                'jours_actifs' => $actifs,
                'avec_cours'   => $n > 0,
                'hors_horizon' => (bool) $s['hors_horizon'],
                'label'        => $n > 0
                    ? 'Semaine de cours'
                    : ((bool) $s['hors_horizon']
                        ? 'Au-delà de l\'horizon publié'
                        : 'Semaine sans cours (vacances ?)'),
                'stale'        => (bool) $s['stale'],
                'cache_du'     => self::jourParisFr((int) $plage['fetched_at']),
            ];
        }

        $reponse['prochain_jour_cours'] = self::edtProchainJourCours($now, $deptsIut, $plage['fin'], $fetch, $dir, self::EDT_BUDGET_S);
        $reponse['source'] = 'edt-ulco.fr via EdtCalendar (cache 6h)';

        return $reponse;
    }

    /**
     * Jour Paris « JJ/MM » d'un timestamp de cache (0/null → null) —
     * date du REPLI EDT (cache périmé servi avec sa date, jamais la
     * fraîcheur survendue).
     */
    private static function jourParisFr(int $fetchedAt): ?string
    {
        if ($fetchedAt <= 0) {
            return null;
        }

        return (new \DateTimeImmutable('@' . $fetchedAt))
            ->setTimezone(new \DateTimeZone('Europe/Paris'))
            ->format('d/m');
    }

    /**
     * Noms courts de la réponse (« tous » → les trois départements).
     *
     * @return list<string>
     */
    private static function edtNomsCourts(string $dept): array
    {
        if ($dept !== 'tous') {
            return [$dept];
        }

        return array_values(self::EDT_DEPTS_COURTS);
    }

    /**
     * Créneaux des départements demandés dans le tableau par_dept de
     * EdtCalendar::jourInfos (['tous'] = somme de tous).
     *
     * @param array<string,int> $parDept
     * @param list<string>      $deptsIut
     */
    private static function edtSommeDept(array $parDept, array $deptsIut): int
    {
        $somme = 0;
        foreach ($deptsIut as $d) {
            $somme += (int) ($parDept[$d] ?? 0);
        }

        return $somme;
    }

    /**
     * Premier jour de cours STRICTEMENT postérieur à aujourd'hui pour les
     * départements demandés, borné par l'horizon publié : « tous » réutilise
     * EdtCalendar::prochainJourCours tel quel ; un sous-ensemble departement
     * est scanné jour par jour via jourInfos (données déjà en mémoire —
     * aucune nouvelle lecture du calendrier).
     *
     * @param list<string> $deptsIut
     */
    private static function edtProchainJourCours(
        \DateTimeImmutable $now,
        array $deptsIut,
        ?string $finPubliee,
        ?callable $fetch,
        ?string $dir,
        ?float $budgetS = null
    ): ?string {
        $aujourdhui = $now->format('Y-m-d');
        if ($deptsIut === self::EDT_DEPTS['tous']) {
            return EdtCalendar::prochainJourCours($aujourdhui, $fetch, $dir, $budgetS);
        }
        if ($finPubliee === null) {
            return null;
        }
        for ($d = $now->modify('+1 day'); strcmp($d->format('Y-m-d'), $finPubliee) <= 0; $d = $d->modify('+1 day')) {
            if (self::edtSommeDept(EdtCalendar::jourInfos($d->format('Y-m-d'), $fetch, $dir, $budgetS)['par_dept'], $deptsIut) > 0) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }

    // -----------------------------------------------------------------
    //  Outil stock_produit — livre de stock unique + garde de fraîcheur
    // -----------------------------------------------------------------

    /**
     * Réponse stock_produit complète (structure JSON FIXE de l'API
     * interne) : résolution par le livre de stock UNIQUE
     * (AnalyticsForecastData::stockBook() → StockBook : passes exacte →
     * normalisée → alias, puis variante couleur/goût), candidats proches
     * si introuvable. Le stock renvoyé est le stock THÉORIQUE de
     * l'inventaire (peut être négatif = survente, anomalie à signaler),
     * jamais la table product_stocks obsolète.
     *
     * @return array<string, mixed>
     */
    public static function stockProduit(string $nom): array
    {
        $livre  = AnalyticsForecastData::stockBook();
        $trouve = $livre->resolveLoose($nom);
        $candidats = $trouve === null ? $livre->searchCandidates($nom, self::CANDIDATS_LIMITE) : [];

        // Candidats en champs français stables (cle + stock), tri du résolveur.
        $candidatsFr = array_map(
            static fn (array $c): array => ['cle' => $c['key'], 'stock' => $c['stock']],
            $candidats
        );

        // Fraîcheur du comptage (champs STRICTEMENT ADDITIFS — contrat T4) :
        // âge en jours du dernier comptage + avertissement lisible quand il
        // dépasse le seuil (vide sinon), pour le fast-path et le LLM.
        $fraicheur = self::fraicheurJours(is_string($trouve['at'] ?? null) ? $trouve['at'] : null);
        $avertissement = ($fraicheur !== null && $fraicheur > self::FRAICHEUR_SEUIL_JOURS)
            ? 'comptage ancien (' . $fraicheur . ' j), fiabilité limitée'
            : '';

        return [
            'outil'            => self::OUTIL_STOCK,
            'nom_demande'      => $nom,
            'trouve'           => $trouve !== null,
            'cle_canonique'    => $trouve['key'] ?? null,
            'stock'            => $trouve['stock'] ?? null,
            'dernier_comptage' => $trouve['at'] ?? null,
            'fraicheur_jours'  => $fraicheur,
            'avertissement'    => $avertissement,
            'resolution'       => $trouve['via'] ?? null,
            'candidats'        => $candidatsFr,
        ];
    }

    // -----------------------------------------------------------------
    //  Sélection publique de produits — questions génériques (GENERIC-PUB-17)
    // -----------------------------------------------------------------

    /**
     * SÉLECTION PUBLIQUE de produits de la cafétéria (GENERIC-PUB-17 :
     * « vous avez quoi à boire et à manger ? » ne doit plus recevoir les
     * horaires) : familles disponibles issues du livre de stock UNIQUE
     * (AnalyticsForecastData::stockBook — même source que stock_produit,
     * règle AGENTS : un seul point d'assemblage), MAX SELECTION_MAX,
     * tri stock décroissant (égalité → ordre alphabétique, déterministe).
     *
     * Arbitrage public STRICT : nom + disponibilité (unités restantes,
     * déjà servies produit par produit par STOCK-PUB-14) — AUCUN prix
     * d'achat, coût, marge ni prix de vente ; jamais l'inventaire
     * complet, une SÉLECTION. Famille = premier mot du libellé
     * (« Monster Blanche » → « Monster » ; « red bull » rabattu sur
     * « redbull » pour rejoindre ses variantes) ; artefacts de saisie
     * exclus (FAMILLES_EXCLUES). Fraîcheur : même garde 30 j que
     * stock_produit (avertissement quand le comptage dépasse le seuil).
     *
     * Honnêteté familles (mission 03/11, réclamée par 3 selfreview
     * d'affilée) : « Bueno (126) » résumait une famille de 2 clés
     * comptées à des dates différentes alors que la question précise
     * « des Bueno ? » sert la clé exacte (comptage du 13/10) — chaque
     * famille embarque désormais son nombre de clés et SA fraîcheur
     * CONSERVATRICE (clé la plus anciennement comptée de la famille),
     * indépendante de la date globale du livre.
     *
     * @return array{familles:list<array{nom:string, stock:int, cles:int, fraicheur_jours:?int}>,
     *               dernier_comptage:?string, fraicheur_jours:?int,
     *               avertissement:string}
     */
    public static function stockSelection(int $limite = self::SELECTION_MAX): array
    {
        $livre = AnalyticsForecastData::stockBook();

        $familles = [];
        foreach ($livre->linesWithDates() as $ligne) {
            $nom = trim((string) preg_replace('/\s+/u', ' ', (string) $ligne['key']));
            if ($nom === '') {
                continue;
            }
            // « red bull » → « redbull » : la famille rejoint ses variantes.
            $famille = explode(' ', PublicAssistant::sansAccents(
                (string) preg_replace('/\bred\s+bull\b/iu', 'redbull', $nom)
            ))[0];
            if ($famille === '' || in_array($famille, self::FAMILLES_EXCLUES, true)) {
                continue;
            }
            $stock = max(0, (int) $ligne['stock']);
            $familles[$famille]['stock'] = ($familles[$famille]['stock'] ?? 0) + $stock;
            $familles[$famille]['cles']  = ($familles[$famille]['cles'] ?? 0) + 1;
            // Fraîcheur conservatrice : date de comptage la plus ANCIENNE
            // des clés de la famille (null = aucune clé comptée).
            $at = $ligne['at'] ?? null;
            if (is_string($at) && $at !== ''
                && (!isset($familles[$famille]['at']) || $at < $familles[$famille]['at'])) {
                $familles[$famille]['at'] = $at;
            }
            // Nom affiché : premier mot du membre le mieux disposé (casse d'origine).
            if ($stock > ($familles[$famille]['rep'] ?? -1)) {
                $familles[$famille]['rep'] = $stock;
                $familles[$famille]['nom'] = explode(' ', $nom)[0];
            }
        }

        $disponibles = array_values(array_filter(
            $familles,
            static fn (array $f): bool => ($f['stock'] ?? 0) > 0 && ($f['nom'] ?? '') !== ''
        ));
        usort($disponibles, static fn (array $a, array $b): int =>
            [$b['stock'], $a['nom']] <=> [$a['stock'], $b['nom']]);

        $selection = array_map(
            static fn (array $f): array => [
                'nom'             => $f['nom'],
                'stock'           => (int) $f['stock'],
                'cles'            => max(1, (int) ($f['cles'] ?? 1)),
                'fraicheur_jours' => self::fraicheurJours(isset($f['at']) ? (string) $f['at'] : null),
            ],
            array_slice($disponibles, 0, max(0, $limite))
        );

        $comptage = $livre->derniereDate();
        $fraicheur = self::fraicheurJours($comptage);

        return [
            'familles'          => $selection,
            'dernier_comptage'  => $comptage,
            'fraicheur_jours'   => $fraicheur,
            'avertissement'     => ($fraicheur !== null && $fraicheur > self::FRAICHEUR_SEUIL_JOURS)
                ? 'comptage ancien (' . $fraicheur . ' j), fiabilité limitée'
                : '',
        ];
    }

    /**
     * Jour ISO AAAA-MM-JJ strict et réellement valide (checkdate).
     */
    public static function jourIsoValide(string $jour): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $jour, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * Âge en JOURS CALENDAIRES (Paris) du dernier comptage : seule la
     * partie DATE du timestamp compte (un comptage du 15/09 à 23 h a le
     * même âge qu'à 8 h — convention « jours de Paris » du reste des
     * outils). Null si la date est inconnue ou illisible.
     */
    public static function fraicheurJours(?string $at): ?int
    {
        if ($at === null || !self::jourIsoValide(substr($at, 0, 10))) {
            return null;
        }

        $paris = new \DateTimeZone('Europe/Paris');
        $comptage = new \DateTimeImmutable(substr($at, 0, 10), $paris);
        $aujourdhui = new \DateTimeImmutable('today', $paris);

        return (int) $aujourdhui->diff($comptage)->days;
    }
}
