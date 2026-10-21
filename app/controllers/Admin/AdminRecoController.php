<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Compta\Reco\EffetMesure;
use App\Core\Compta\Reco\RecommendationEngine;
use App\Models\RecoData;
use App\Models\Recommendation;

/**
 * Intégration du moteur de recommandations commerciales (cœur pur
 * App\Core\Compta\Reco) au site : carte « Idées du moment » du dashboard
 * Analytics + kiosque admin, page d'historique avec EFFET MESURÉ, décisions
 * humaines (Appliquer / Plus tard / Ignorer) et seuils modifiables.
 *
 * RÈGLE DE MISSION : le site PROPOSE, un humain DÉCIDE — aucune écriture
 * automatique de prix ou de stock. Une décision « appliquer » écrit la
 * BASELINE d'effet mesuré (photo de l'avant) au moment de l'application ;
 * la mesure elle-même tourne en différé (mesurerEffetsEnAttente) et se
 * contente d'étiqueter l'effet observé.
 */
final class AdminRecoController extends AdminBaseController
{
    /** Fuseau de référence de l'asso (cohérent avec les moteurs). */
    private const TZ = 'Europe/Paris';

    /** Décisions humaines admises (POST « decision »). */
    private const DECISIONS = ['appliquer', 'plus_tard', 'ignorer'];

    /** Clés de seuils modifiables (page Historique) avec leur borne haute. */
    private const PARAMETRES = [
        'reco_marge_min'              => 1.0,   // fraction ≤ 1
        'reco_stock_dormant_semaines' => 104.0, // semaines
        'reco_stock_dormant_min'      => 10000.0,
        'reco_echantillon_min_unites' => 10000.0,
        'reco_duo_remise_max'         => 1.0,   // fraction ≤ 1
        'reco_immobilise_seuil_eur'   => 100000.0,
        'reco_max_actives'            => 10.0,
    ];

    /** Redirects autorisés après décision (whitelist anti open-redirect). */
    private const RETOURS = ['/admin/analytics', '/admin/reco/historique'];

    // -----------------------------------------------------------------
    //  Payload de la carte « Idées du moment »
    // -----------------------------------------------------------------

    /**
     * Payload de la carte reco, SANS garde (l'appelant est déjà gardé :
     * buildPage est derrière guardCompta, kiosk derrière le jeton admin).
     * Robustesse pré-migration : toute panne (table absente, cœur en
     * échec) renvoie un payload « indisponible » — la page analytics doit
     * TOUJOURS se rendre.
     *
     * @return array{idees:list<array<string,mixed>>,meta:array<string,mixed>,insuffisant:bool,indisponible:bool,raison:string,fenetre:array{from:string,to:string,jours_ouverts:int}}
     */
    public static function payloadPourAnalytics(): array
    {
        $vide = [
            'idees'        => [],
            'meta'         => ['indisponible' => true, 'raison' => ''],
            'insuffisant'  => false,
            'indisponible' => true,
            'raison'       => '',
            'fenetre'      => ['from' => '', 'to' => '', 'jours_ouverts' => 0],
        ];

        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
            ['reco' => $reco, 'forecast' => $forecast, 'fenetre' => $fenetre] = RecoData::dataset($now->format('Y-m-d'), $now);
            ['idees' => $idees, 'meta' => $meta] = RecommendationEngine::recommend($reco, $now);

            return [
                'idees'        => array_map(static fn ($i): array => $i->toArray(), $idees),
                'meta'         => $meta,
                'insuffisant'  => (bool) ($meta['historique_insuffisant'] ?? false),
                'indisponible' => false,
                'raison'       => '',
                'fenetre'      => $fenetre,
            ];
        } catch (\Throwable $e) {
            $vide['raison'] = $e->getMessage();

            return $vide;
        }
    }

    /**
     * Persiste les idées AFFICHÉES (statut « proposee » par défaut du
     * upsert) pour que les boutons Appliquer / Plus tard / Ignorer aient
     * un id. Le upsert ne touche JAMAIS aux décisions déjà prises.
     * Les $idees sont des tableaux toArray() du cœur ; un échec lève
     * (l'appelant enveloppe dans un try/catch silencieux).
     *
     * @param list<array<string,mixed>> $idees
     * @param array<string,mixed>       $meta    (réservé : les idées d'un payload indisponible ne sont jamais persistées)
     * @param array{from:string,to:string,jours_ouverts:int} $fenetre
     */
    public static function persistIdees(array $idees, array $meta, array $fenetre): void
    {
        if ($idees === [] || ($meta['indisponible'] ?? false) === true) {
            return;
        }

        $fenetreJson = (string) json_encode([
            'from'          => (string) ($fenetre['from'] ?? ''),
            'to'            => (string) ($fenetre['to'] ?? ''),
            'jours_ouverts' => (int) ($fenetre['jours_ouverts'] ?? 0),
        ], JSON_UNESCAPED_UNICODE);

        foreach ($idees as $idee) {
            if (!is_array($idee) || !is_string($idee['id'] ?? null) || $idee['id'] === '') {
                continue;
            }
            Recommendation::upsert(
                [
                    'id'       => $idee['id'],
                    'regle'    => (string) ($idee['regle'] ?? ''),
                    'titre'    => (string) ($idee['titre'] ?? ''),
                    'payload'  => $idee,
                    'produits' => is_array($idee['produits'] ?? null) ? $idee['produits'] : [],
                ],
                $fenetreJson
            );
        }
    }

    // -----------------------------------------------------------------
    //  Pages
    // -----------------------------------------------------------------

    /**
     * Historique des idées appliquées (effet mesuré) + seuils + idées
     * en attente. PROFITE du passage pour mesurer les effets en attente
     * (mesure paresseuse en différé, aucune écriture de prix/stock).
     */
    public function historique(): void
    {
        $this->guardCompta();

        self::mesurerEffetsEnAttente();

        $lignes = Recommendation::historique();

        // Décoration de chaque ligne avec son effet décodé (prêt pour
        // EffetMesure::libelle) + « Ce qui a marché » : mesures concluantes
        // à effet strictement positif.
        $reussies = [];
        foreach ($lignes as $i => $l) {
            $effet = self::effetDepuisJson($l['effet_json'] ?? null);
            if ($effet !== null) {
                $lignes[$i]['effet'] = $effet;
            }
            if (($l['effet_conclusion'] ?? null) === 'concluant'
                && $effet !== null && (float) ($effet['effet'] ?? 0.0) > 0.0) {
                $reussies[] = $lignes[$i];
            }
        }

        $seuils = [];
        foreach (array_keys(self::PARAMETRES) as $cle) {
            $brut = trim((string) \App\Models\Setting::get($cle, ''));
            $seuils[$cle] = $brut !== '' ? $brut : (string) (RecoData::parametres()[$cle] ?? '');
        }

        $this->renderAdmin('admin/reco/historique', [
            'title'     => 'Idées appliquées',
            'lignes'    => $lignes,
            'reussies'  => $reussies,
            'proposees' => Recommendation::proposees(),
            'seuils'    => $seuils,
        ]);
    }

    /**
     * Effet décodé du JSON stocké, PRÊT pour EffetMesure::libelle() :
     * json_decode rend des int pour les valeurs rondes (« 4 ») alors que
     * le libellé du cœur exige des float stricts — normalisation ici,
     * source unique pour toutes les vues.
     *
     * @return array{etat:string,effet:?float,bas:?float,haut:?float,jours:int,note:string}|null
     */
    private static function effetDepuisJson(?string $json): ?array
    {
        $dec = json_decode((string) $json, true);
        if (!is_array($dec) || $dec === []) {
            return null;
        }
        foreach (['effet', 'bas', 'haut'] as $cle) {
            if (array_key_exists($cle, $dec) && is_numeric($dec[$cle])) {
                $dec[$cle] = (float) $dec[$cle];
            }
        }

        return $dec;
    }

    /**
     * Version KIOSQUE ADMIN de la carte idées + historique compact :
     * accès par lien secret (jeton ADMIN = l'authentification, sans
     * connexion), même mécanique que AdminAnalyticsController::kiosk().
     */
    public function kiosk(string $token): void
    {
        if (!$this->adminTokenOk($token)) {
            $this->deny();

            return;
        }

        self::mesurerEffetsEnAttente();

        $payload = self::payloadPourAnalytics();
        try {
            self::persistIdees($payload['idees'], $payload['meta'], $payload['fenetre']);
        } catch (\Throwable) {
            // Table non migrée : les boutons resteront sans effet, la
            // page doit quand même se rendre.
        }

        // Historique compact (idées appliquées, la plus récente d'abord).
        $hist = [];
        foreach (Recommendation::historique() as $l) {
            $effet = self::effetDepuisJson($l['effet_json'] ?? null);
            $hist[] = [
                'titre'       => (string) ($l['titre'] ?? ''),
                'regle'       => (string) ($l['regle'] ?? ''),
                'applied_at'  => (string) ($l['applied_at'] ?? ''),
                'conclusion'  => (string) ($l['effet_conclusion'] ?? ''),
                'libelle'     => is_array($effet) ? EffetMesure::libelle($effet) : '',
                'note'        => is_array($effet) ? (string) ($effet['note'] ?? '') : '',
            ];
        }

        $this->renderKiosk('admin/reco/kiosk', $payload + [
            'title'   => 'Idées du moment',
            'kiosk'   => true,
            'token'   => $token,
            'hist'    => $hist,
        ]);
    }

    // -----------------------------------------------------------------
    //  Décisions humaines
    // -----------------------------------------------------------------

    /**
     * Enregistre une décision depuis la carte (admin, CSRF vérifié
     * centralement par le routeur pour tout POST hors /kiosque/).
     */
    public function decision(): void
    {
        $this->guardCompta();

        $retour = (string) ($_POST['retour'] ?? '/admin/analytics');
        if (!in_array($retour, self::RETOURS, true)) {
            $retour = '/admin/analytics';
        }

        $this->traiterDecision(
            trim((string) ($_POST['id'] ?? '')),
            (string) ($_POST['decision'] ?? ''),
            self::normaliserMotif(),
            $retour
        );
    }

    /**
     * Même décision depuis le kiosque : le jeton admin EST
     * l'authentification (PAS de CSRF pour les POST /kiosque/* maison),
     * retour vers la page kiosque ideas.
     */
    public function kioskDecision(string $token): void
    {
        if (!$this->adminTokenOk($token)) {
            $this->deny();

            return;
        }

        $this->traiterDecision(
            trim((string) ($_POST['id'] ?? '')),
            (string) ($_POST['decision'] ?? ''),
            self::normaliserMotif(),
            url('/kiosque/admin/reco/' . rawurlencode($token))
        );
    }

    /**
     * Motif d'ignorance : valeur du select ('deja_trop', 'non_pertinent',
     * 'autre') complétée par le texte libre optionnel.
     */
    private static function normaliserMotif(): string
    {
        $motif = trim((string) ($_POST['motif'] ?? ''));
        $texte = trim((string) ($_POST['motif_texte'] ?? ''));
        if ($texte !== '') {
            $motif = $motif !== '' ? $motif . ' : ' . $texte : $texte;
        }

        return mb_substr($motif, 0, 255);
    }

    /**
     * Cœur commun des deux décisions : appliquer écrit la BASELINE AVANT
     * de dater l'application (photo de l'avant pour la mesure d'effet) ;
     * plus_tard / ignorer ne font qu'étiqueter.
     */
    private function traiterDecision(string $id, string $decision, string $motif, string $retour): void
    {
        if ($id === '' || !in_array($decision, self::DECISIONS, true)) {
            $this->setFlash('error', 'Décision invalide.');
            redirect($retour);

            return;
        }

        try {
            if ($decision === 'ignorer') {
                Recommendation::decision($id, 'ignoree', $motif !== '' ? $motif : null);
            } elseif ($decision === 'plus_tard') {
                Recommendation::decision($id, 'plus_tard', $motif !== '' ? $motif : null);
            } else {
                // Appliquer : baseline AVANT toute écriture de statut.
                $now      = new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
                $baseline = RecommendationEngine::baseline(
                    self::clesProduitDe($id),
                    RecoData::dataset($now->format('Y-m-d'), $now)['reco'],
                    $now
                );
                Recommendation::decision($id, 'appliquee');
                Recommendation::saveBaseline($id, (string) json_encode($baseline, JSON_UNESCAPED_UNICODE));
            }
        } catch (\Throwable $e) {
            $this->setFlash('error', 'Décision impossible : ' . $e->getMessage());
            redirect($retour);

            return;
        }

        $this->audit('reco.decision', 'recommendation', $id, [
            'decision' => $decision,
            'motif'    => $motif !== '' ? $motif : null,
        ]);
        $this->setFlash(
            'success',
            match ($decision) {
                'appliquer' => 'Idée appliquée : la mesure d\'effet démarre (résultat dans ~4 semaines).',
                'plus_tard' => 'Idée reportée : elle restera visible dans les idées en attente.',
                default     => 'Idée ignorée : elle ne sera pas re-proposée avant 30 jours.',
            }
        );
        redirect($retour);
    }

    /** Clés produits d'une reco persistée (produits_json). @return list<string> */
    private static function clesProduitDe(string $id): array
    {
        $row = Recommendation::find($id);
        if ($row === null) {
            return [];
        }
        $dec = json_decode((string) ($row['produits_json'] ?? '[]'), true);

        return array_values(array_filter(
            is_array($dec) ? array_column($dec, 'key') : [],
            static fn ($k): bool => is_string($k) && $k !== ''
        ));
    }

    // -----------------------------------------------------------------
    //  Seuils
    // -----------------------------------------------------------------

    /**
     * Enregistre les seuils du moteur (whitelist, valeurs numériques ≥ 0,
     * bornes : marge_min ≤ 1, max_actives ≤ 10). Aucun autre paramètre
     * n'est touché.
     */
    public function saveParametres(): void
    {
        $this->guardCompta();

        $changements = [];
        foreach (self::PARAMETRES as $cle => $max) {
            $brut = str_replace([' ', ','], ['', '.'], trim((string) ($_POST[$cle] ?? '')));
            if ($brut === '' || !is_numeric($brut)) {
                continue; // champ absent/vide : inchangé
            }
            $val = (float) $brut;
            if ($val < 0.0 || $val > $max) {
                continue; // hors bornes : inchangé
            }
            $canonique = (string) $val;
            \App\Models\Setting::set($cle, $canonique);
            $changements[$cle] = $canonique;
        }

        if ($changements !== []) {
            $this->audit('reco.parametres', 'setting', null, $changements);
            $this->setFlash('success', 'Seuils enregistrés : ils s\'appliqueront à la prochaine génération d\'idées.');
        } else {
            $this->setFlash('error', 'Aucun seuil valide à enregistrer (nombres ≥ 0 requis).');
        }
        redirect(url('/admin/reco/historique'));
    }

    // -----------------------------------------------------------------
    //  Mesure d'effet en différé
    // -----------------------------------------------------------------

    /**
     * Mesure paresseuse des idées appliquées jamais contrôlées : compare
     * le CA réel observé à la baseline (EffetMesure, pur) et étiquette
     * l'effet quand un verdict est possible ('concluant' / 'non_concluant').
     * Aucune écriture de prix/stock ; un échec est silencieux (la page
     * doit toujours se rendre).
     */
    private static function mesurerEffetsEnAttente(): void
    {
        try {
            $enCours = Recommendation::appliqueesEnCours();
            if ($enCours === []) {
                return;
            }

            $todayKey = (new \DateTimeImmutable('now', new \DateTimeZone(self::TZ)))->format('Y-m-d');

            // Clés produits de chaque idée (pour la détection de chevauchement).
            $clesParId = [];
            foreach ($enCours as $a) {
                $dec = json_decode((string) $a['produits_json'], true);
                $clesParId[$a['id']] = array_values(array_filter(
                    is_array($dec) ? array_column($dec, 'key') : [],
                    static fn ($k): bool => is_string($k) && $k !== ''
                ));
            }

            foreach ($enCours as $a) {
                $baseline = json_decode((string) ($a['baseline_json'] ?? ''), true);
                if (!is_array($baseline) || $baseline === []) {
                    continue; // pas de photo « avant » : rien à mesurer
                }

                $dates = array_values(array_filter(array_column($baseline, 'date'), 'is_string'));
                if ($dates === []) {
                    continue;
                }
                $premiere = min($dates);
                $derniere = max($dates);

                // Jours ouverts ÉCOULÉS : lignes de baseline strictement passées.
                $joursEcoules = count(array_filter($dates, static fn (string $d): bool => $d < $todayKey));

                // Chevauchement : une AUTRE idée appliquée dont la fenêtre
                // d'activité [applied_at ; applied_at + 28 j] intersecte la
                // période couverte ET partage au moins un produit.
                $chevauchement = false;
                foreach ($enCours as $b) {
                    if ($b['id'] === $a['id']) {
                        continue;
                    }
                    $debutB = substr((string) $b['applied_at'], 0, 10);
                    $finB   = date('Y-m-d', strtotime($debutB . ' +28 days'));
                    if ($debutB <= $derniere && $finB >= $premiere
                        && array_intersect($clesParId[$a['id']], $clesParId[$b['id']]) !== []) {
                        $chevauchement = true;
                        break;
                    }
                }

                $r = EffetMesure::calculer(
                    $baseline,
                    RecoData::ventesParJour($clesParId[$a['id']], $premiere, $derniere),
                    $joursEcoules,
                    $chevauchement
                );
                if (in_array($r['etat'], ['concluant', 'non_concluant'], true)) {
                    Recommendation::saveEffet($a['id'], $r, $r['etat'] === 'concluant' ? 'concluant' : 'non_concluant');
                }
            }
        } catch (\Throwable) {
            // Mesure best-effort : jamais bloquante pour l'affichage.
        }
    }

    // -----------------------------------------------------------------
    //  Jeton kiosque (copie conforme d'AdminAnalyticsController)
    // -----------------------------------------------------------------

    /** Jeton kiosque ADMIN : totalement indépendant du jeton membres. */
    private function adminTokenOk(string $token): bool
    {
        $expected = trim((string) \App\Models\Setting::get('admin_kiosk_token', ''));
        $given    = trim($token);

        return $expected !== '' && $given !== '' && hash_equals($expected, $given);
    }

    /** Réponse 403 pour un lien kiosque invalide ou révoqué. */
    private function deny(): void
    {
        http_response_code(403);
        echo '<h1>Erreur 403 — Lien invalide ou révoqué.</h1>';
    }
}
