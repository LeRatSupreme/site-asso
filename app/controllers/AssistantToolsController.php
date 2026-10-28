<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Compta\ComptaCalc;
use App\Core\Compta\Dashboard\Data;
use App\Core\Compta\Kiosk;
use App\Core\Compta\StockPublic;
use App\Core\Compta\T1\T1AutoFusion;
use App\Core\Compta\T1\T1Quarantaine;
use App\Core\Compta\T1\T1Suggestions;
use App\Core\Controller;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Models\AnalyticsForecastData;
use App\Models\AuditLog;
use App\Models\ProductCost;
use App\Models\ProductKeyMerge;
use App\Models\Sale;

/**
 * Endpoints OUTILS de l'assistant conversationnel (T4) — LECTURE SEULE.
 *
 * Contrat (consommé par le pont local scripts/assistant.py — JAMAIS par
 * le modèle LLM lui-même, qui ne connaît pas le jeton) :
 *
 *   GET /internal/assistant/ventes_periode?debut=AAAA-MM-JJ&fin=AAAA-MM-JJ
 *   GET /internal/assistant/stock_produit?nom=<libellé produit>
 *   GET /internal/assistant/produits_a_commander
 *
 * Le nom d'outil est validé par liste blanche (outil()) : 404 « Outil
 * inconnu. » APRÈS la vérification du jeton — aucune énumération sans lui.
 *
 * Sécurité (spec T4 §2.5, pattern kiosque « le jeton EST
 * l'authentification ») :
 *  - jeton serveur ASSISTANT_TOKEN (config.env, 40+ hex), transmis par
 *    le pont en en-tête « X-Assistant-Token », comparé en hash_equals ;
 *    échec = 403 générique (détail en log) ; jeton vide = outils
 *    désactivés (fail-closed) ;
 *  - aucune écriture base : chaque action = appel de méthodes EXISTANTES
 *    (Sale::aggregatesBetween, Data::analyseReappro, livre de stock via
 *    AnalyticsForecastData::stockBook()) + mise en forme JSON — zéro
 *    logique métier dupliquée ;
 *  - réponses jamais mémorisables (no-store, mêmes en-têtes que les
 *    pages à jeton) ;
 *  - rate limit 20 req/min/IP (RateLimiter partagé) : un modèle en
 *    boucle erronée ne doit pas marteler la base.
 *
 * Les jours sont des jours de PARIS (Europe/Paris), même convention que
 * les récaps kiosque. Les structures JSON sont FIXES (champs français
 * stables) : le pont et le LLM s'y raccrochent — toute évolution du
 * contrat doit rester rétro-compatible (champs ajoutés, jamais retirés).
 */
final class AssistantToolsController extends Controller
{
    private const TOOL_VENTES   = 'ventes_periode';
    private const TOOL_STOCK    = 'stock_produit';
    private const TOOL_REAPPRO  = 'produits_a_commander';
    private const TOOL_MARGE    = 'marge_produit';
    private const TOOL_DOUBLONS = 'doublons_en_attente';

    /** Rate limit : 20 requêtes par minute et par IP. */
    private const RATE_MAX     = 20;
    private const RATE_FENETRE = 60;

    /** Nombre de produits du top ventes renvoyé (T4-P1 : réduit de 10 à 5 —
     *  chaque entrée paie ~28 ms de préfill CPU ; le modèle n'énumère de
     *  toute façon que ~5 produits dans ses réponses). */
    private const TOP_LIMITE = 5;

    /** Candidats proposés quand un libellé produit est introuvable. */
    private const CANDIDATS_LIMITE = 5;

    /** Entrées de quarantaine détaillées par section (cap compact). */
    private const QUARANTAINE_LIMITE = 10;

    /** Paires suggérées détaillées par section (cap compact T4-P1 : le
     *  préfill CPU paie chaque token ~28 ms — le TOTAL reste complet). */
    private const SUGGESTIONS_LIMITE = 10;

    /** Libellés français des états réappro de Data::analyseReappro. */
    private const ETATS_REAPPRO = [
        'ok'      => 'ok',
        'reorder' => 'a_commander',
        'unknown' => 'a_compter',
    ];

    /** Liste blanche des outils : nom d'URL → action du contrôleur. */
    private const OUTILS = [
        self::TOOL_VENTES   => 'ventesPeriode',
        self::TOOL_STOCK    => 'stockProduit',
        self::TOOL_REAPPRO  => 'produitsACommander',
        self::TOOL_MARGE    => 'margeProduit',
        self::TOOL_DOUBLONS => 'doublonsEnAttente',
    ];

    /**
     * Point d'entrée unique du routeur : valide le nom d'outil par liste
     * blanche PUIS délègue — la garde (jeton, rate limit, no-store) reste
     * au début de chaque outil, avant toute lecture de paramètre.
     */
    public function outil(string $outil): void
    {
        $action = self::OUTILS[$outil] ?? null;
        if ($action === null) {
            // Garde d'abord (pas d'énumération des outils sans jeton),
            // puis refus générique.
            $this->ouvrir();
            $this->repondreErreur(404, 'Outil inconnu.');
        }

        $this->{$action}();
    }

    /**
     * Outil 1 — ventes d'une période : CA total, nombre de transactions,
     * quantité vendue et top produits. Réutilise exactement les méthodes
     * du récap kiosque (Sale::aggregatesBetween / transactionsBetween /
     * topProductsBetween) — bornes incluses, jours de Paris.
     */
    private function ventesPeriode(): void
    {
        $this->ouvrir();

        $debut = trim((string) ($_GET['debut'] ?? ''));
        $fin   = trim((string) ($_GET['fin'] ?? ''));

        if (!self::jourIsoValide($debut)) {
            $this->repondreErreur(400, 'Paramètre « debut » invalide (date ISO AAAA-MM-JJ attendue).');
        }
        if (!self::jourIsoValide($fin)) {
            $this->repondreErreur(400, 'Paramètre « fin » invalide (date ISO AAAA-MM-JJ attendue).');
        }
        if ($debut > $fin) {
            $this->repondreErreur(400, 'La date « debut » doit être antérieure ou égale à « fin ».');
        }

        try {
            $agg = Sale::aggregatesBetween($debut, $fin);
            $tx  = Sale::transactionsBetween($debut, $fin);
            $top = Sale::topProductsBetween($debut, $fin, self::TOP_LIMITE);
        } catch (\Throwable $e) {
            $this->echecInterne(self::TOOL_VENTES, $e);
        }

        $topProduits = [];
        foreach ($top as $row) {
            $topProduits[] = [
                'produit'  => (string) $row['label'],
                'quantite' => (int) $row['qty'],
                'ca_eur'   => round((float) $row['ca'], 2),
            ];
        }

        $this->json([
            'outil'            => self::TOOL_VENTES,
            'debut'            => $debut,
            'fin'              => $fin,
            'total_ventes_eur' => round((float) $agg['ca'], 2),
            'nb_transactions'  => $tx,
            'quantite_vendue'  => (int) $agg['qty'],
            'top_produits'     => $topProduits,
        ]);
    }

    /**
     * Outil 2 — stock d'un produit par son nom : résolution par le livre
     * de stock UNIQUE (AnalyticsForecastData::stockBook() → StockBook :
     * passes exacte → normalisée → alias, puis variante couleur/goût),
     * candidats proches si introuvable. Le stock renvoyé est le stock
     * THÉORIQUE de l'inventaire (peut être négatif = survente, anomalie
     * à signaler), jamais la table product_stocks obsolète.
     */
    private function stockProduit(): void
    {
        $this->ouvrir();

        $nom = trim((string) ($_GET['nom'] ?? ''));
        if ($nom === '' || mb_strlen($nom) > 255) {
            $this->repondreErreur(400, 'Paramètre « nom » invalide (1 à 255 caractères attendu).');
        }

        try {
            $livre  = AnalyticsForecastData::stockBook();
            $trouve = $livre->resolveLoose($nom);
            $candidats = $trouve === null ? $livre->searchCandidates($nom, self::CANDIDATS_LIMITE) : [];
        } catch (\Throwable $e) {
            $this->echecInterne(self::TOOL_STOCK, $e);
        }

        // Candidats en champs français stables (cle + stock), tri du résolveur.
        $candidatsFr = array_map(
            static fn (array $c): array => ['cle' => $c['key'], 'stock' => $c['stock']],
            $candidats
        );

        $this->json([
            'outil'            => self::TOOL_STOCK,
            'nom_demande'      => $nom,
            'trouve'           => $trouve !== null,
            'cle_canonique'    => $trouve['key'] ?? null,
            'stock'            => $trouve['stock'] ?? null,
            'dernier_comptage' => $trouve['at'] ?? null,
            'resolution'       => $trouve['via'] ?? null,
            'candidats'        => $candidatsFr,
        ]);
    }

    /**
     * Outil 3 — liste de réapprovisionnement : résultat EXACT de
     * Data::analyseReappro (point d'assemblage unique partagé avec la
     * page Réappro) sur ses réglages par défaut : analyse des 14 derniers
     * jours (jours calendaires, week-end inclus) pour couvrir 7 jours.
     * Tri conservé : quantité à commander décroissante.
     */
    private function produitsACommander(): void
    {
        $this->ouvrir();

        // Fenêtre par défaut de la page Réappro (ref=14d, horizon 1w) :
        // aujourd'hui en fuseau Paris explicite (leçon #1).
        $paris     = new \DateTimeZone('Europe/Paris');
        $aujourdhui = new \DateTimeImmutable('today', $paris);
        $debut = $aujourdhui->modify('-13 days')->format('Y-m-d');
        $fin   = $aujourdhui->format('Y-m-d');

        $calDays = ComptaCalc::calendarDaysBetween($debut, $fin);
        $horizon = 7;

        try {
            $analyse = Data::analyseReappro($horizon, $debut, $fin, $calDays);
        } catch (\Throwable $e) {
            $this->echecInterne(self::TOOL_REAPPRO, $e);
        }

        $produits = [];
        foreach ($analyse['rows'] as $row) {
            $produits[] = [
                'produit'           => (string) $row['name'],
                'categorie'         => (string) $row['category'],
                'stock'             => $row['stock'],
                'autonomie_jours'   => $row['autonomy'],
                'a_commander'       => (int) $row['to_order'],
                'pack'              => (int) $row['pack'],
                'cout_unitaire_eur' => $row['unit_cost'],
                'cout_ligne_eur'    => $row['order_cost'],
                'etat'              => self::ETATS_REAPPRO[(string) $row['state']] ?? (string) $row['state'],
            ];
        }

        $this->json([
            'outil'           => self::TOOL_REAPPRO,
            'genere_le'       => $fin,
            'periode_analyse' => ['debut' => $debut, 'fin' => $fin, 'jours' => $calDays],
            'horizon_jours'   => $horizon,
            'nb_produits'     => count($produits),
            'nb_alertes'      => (int) $analyse['alerts'],
            'produits'        => $produits,
        ]);
    }

    /**
     * Outil 4 — marge d'un produit sur une période : réutilise les
     * agrégats par produit de la compta (Sale::byProductBetween — chaque
     * vente valorisée au lot applicable à SA date) et LA règle de marge
     * partagée ComptaCalc::marginPercent (même calcul que le bénéfice par
     * produit d'AdminComptaController::products, zéro duplication).
     *
     * Résolution du nom : le livre de stock UNIQUE (AnalyticsForecastData
     * ::stockBook() → StockBook : exacte → normalisée → alias) ; à défaut
     * (produit vendu jamais inventorié), appariement direct des lignes de
     * ventes par forme normalisée StockPublic::normalizeKey (primitive
     * partagée, jamais un strtolower ad hoc) — toutes les graphies d'une
     * même clé (casse différente) sont sommées.
     *
     * Coût du lot en cours : ProductCost::costAt à aujourd'hui (le même
     * « as-of » que la page Réappro), à titre indicatif.
     */
    private function margeProduit(): void
    {
        $this->ouvrir();

        $nom   = trim((string) ($_GET['nom'] ?? ''));
        $debut = trim((string) ($_GET['debut'] ?? ''));
        $fin   = trim((string) ($_GET['fin'] ?? ''));

        if ($nom === '' || mb_strlen($nom) > 255) {
            $this->repondreErreur(400, 'Paramètre « nom » invalide (1 à 255 caractères attendu).');
        }
        if (!self::jourIsoValide($debut)) {
            $this->repondreErreur(400, 'Paramètre « debut » invalide (date ISO AAAA-MM-JJ attendue).');
        }
        if (!self::jourIsoValide($fin)) {
            $this->repondreErreur(400, 'Paramètre « fin » invalide (date ISO AAAA-MM-JJ attendue).');
        }
        if ($debut > $fin) {
            $this->repondreErreur(400, 'La date « debut » doit être antérieure ou égale à « fin ».');
        }

        try {
            $livre  = AnalyticsForecastData::stockBook();
            $resolu = $livre->resolveLoose($nom);
            $rows   = Sale::byProductBetween($debut, $fin);
        } catch (\Throwable $e) {
            $this->echecInterne(self::TOOL_MARGE, $e);
        }

        // Clé cible : la clé canonique résolue par le livre de stock, sinon
        // le nom brut (essai direct sur les ventes — produit jamais inventorié).
        $cible = (string) ($resolu['key'] ?? $nom);
        $norm  = StockPublic::normalizeKey($cible);

        $ca = 0.0;
        $profit = 0.0;
        $qty = 0;
        $coutUnitaireMoyen = 0.0;
        $categorie = null;
        $cleVentes = $cible;
        $nbLignes = 0;
        if ($norm !== '') {
            foreach ($rows as $row) {
                if (StockPublic::normalizeKey((string) $row['product_key']) !== $norm) {
                    continue;
                }
                $nbLignes++;
                if ($nbLignes === 1) {
                    // Clé EXACTE de vente (pour le lot de coût as-of) : la
                    // première ligne rencontrée, l'ordre est celui du SQL.
                    $cleVentes = (string) $row['product_key'];
                    $categorie = (string) $row['category'];
                }
                $ca      += (float) $row['ca'];
                $profit  += (float) $row['profit'];
                $qty     += (int) $row['qty'];
            }
        }

        if ($nbLignes === 0) {
            // Introuvable dans les ventes de la période : candidats du livre
            // de stock (même comportement que stock_produit), jamais deviné.
            $candidats = array_map(
                static fn (array $c): array => ['cle' => $c['key'], 'stock' => $c['stock']],
                $resolu === null ? $livre->searchCandidates($nom, self::CANDIDATS_LIMITE) : []
            );
            $this->json([
                'outil'        => self::TOOL_MARGE,
                'nom_demande'  => $nom,
                'trouve'       => false,
                'periode'      => ['debut' => $debut, 'fin' => $fin],
                'message'      => 'Aucune vente de ce produit sur la période.',
                'candidats'    => $candidats,
            ]);
        }

        $cout = $ca - $profit;
        $coutUnitaireMoyen = $qty > 0 ? $cout / $qty : 0.0;

        try {
            $lotActuel = ProductCost::costAt($cleVentes, date('Y-m-d'));
        } catch (\Throwable) {
            $lotActuel = null;
        }

        $this->json([
            'outil'                    => self::TOOL_MARGE,
            'nom_demande'              => $nom,
            'trouve'                   => true,
            'cle_ventes'               => $cleVentes,
            'resolution'               => $resolu['via'] ?? 'ventes',
            'categorie'                => $categorie,
            'periode'                  => ['debut' => $debut, 'fin' => $fin],
            'quantite_vendue'          => $qty,
            'ca_eur'                   => round($ca, 2),
            'cout_eur'                 => round($cout, 2),
            'marge_eur'                => round($profit, 2),
            'marge_pct'                => ComptaCalc::marginPercent($profit, $ca),
            'cout_unitaire_moyen_eur'  => round($coutUnitaireMoyen, 3),
            'cout_lot_actuel_eur'      => $lotActuel === null ? null : round((float) $lotActuel, 3),
        ]);
    }

    /**
     * Outil 5 — doublons produits en attente de décision : résultat EXACT
     * de T1Suggestions::pourInventaire (point d'assemblage unique partagé
     * avec la carte « Suggestions de rapprochement » de l'inventaire :
     * mêmes livrables data/eval_t1, même liste noire audit + quarantaine,
     * mêmes plafonds) + quarantaine T1.5 en cours (T1Quarantaine, lecture
     * seule — AUCUN passage T1AutoFusion n'est déclenché ici : les fusions
     * réelles restent sur l'écran admin existant, jamais via l'assistant).
     */
    private function doublonsEnAttente(): void
    {
        $this->ouvrir();

        try {
            $quarantaine = new T1Quarantaine(T1AutoFusion::cheminQuarantaine());
            $suggestions = T1Suggestions::pourInventaire(
                AEIC_ROOT . '/data/eval_t1/candidats_fusion.json',
                AEIC_ROOT . '/data/eval_t1/residuels_a_qualifier.csv',
                ProductKeyMerge::keyStats(),
                array_merge(
                    AuditLog::entityIdsForAction(T1Suggestions::ACTION_FEEDBACK),
                    array_keys($quarantaine->idsStatuts())
                )
            );
        } catch (\Throwable $e) {
            $this->echecInterne(self::TOOL_DOUBLONS, $e);
        }

        // Quarantaine compacte : en attente (compte à rebours Paris) et
        // mûres (prêtes à être appliquées au prochain chargement admin).
        $maintenant = T1AutoFusion::maintenantIso();
        $enAttente = [];
        $mures = [];
        try {
            foreach ($quarantaine->entrees() as $e) {
                if ((string) ($e['statut'] ?? '') !== T1Quarantaine::STATUT_EN_ATTENTE) {
                    continue;
                }
                $restant = T1AutoFusion::secondesRestantes((string) ($e['created_at'] ?? ''), $maintenant);
                $ligne = [
                    'id'           => (string) ($e['id'] ?? ''),
                    'source'       => (string) ($e['source'] ?? ''),
                    'target'       => (string) ($e['target'] ?? ''),
                    'score_pct'    => (int) round(100 * (float) ($e['score'] ?? 0)),
                    'motif'        => (string) ($e['motif'] ?? ''),
                    'cree_le'      => (string) ($e['created_at'] ?? ''),
                    'reste_heures' => $restant <= 0 ? 0 : round($restant / 3600),
                ];
                if ($restant <= 0) {
                    $mures[] = $ligne;
                } else {
                    $enAttente[] = $ligne;
                }
            }
        } catch (\Throwable) {
            // Lecture impossible : sections quarantaine vides, suggestions intactes.
        }

        $this->json([
            'outil'              => self::TOOL_DOUBLONS,
            'dispo'              => (bool) $suggestions['dispo'],
            'total_suggestions'  => (int) $suggestions['total'],
            'suggestions_modele' => array_slice(array_map(
                static fn (array $s): array => [
                    'source'       => $s['source'],
                    'target'       => $s['target'],
                    'ressemblance' => (int) $s['ressemblance'],
                ],
                $suggestions['modele']
            ), 0, self::SUGGESTIONS_LIMITE),
            'suggestions_residuelles' => array_slice(array_map(
                static fn (array $s): array => [
                    'source' => $s['source'],
                    'target' => $s['target'],
                    'motif'  => $s['motif'],
                ],
                $suggestions['residuels']
            ), 0, self::SUGGESTIONS_LIMITE),
            'suggestions_non_listees' => max(0, count($suggestions['modele']) + count($suggestions['residuels']) - 2 * self::SUGGESTIONS_LIMITE),
            'quarantaine' => [
                'nb_en_attente' => count($enAttente),
                'nb_mures'      => count($mures),
                'en_attente'    => array_slice($enAttente, 0, self::QUARANTAINE_LIMITE),
                'mures'         => array_slice($mures, 0, self::QUARANTAINE_LIMITE),
            ],
            'a_decider_par' => 'humain (écran Inventaire — l\'assistant ne fusionne jamais)',
        ]);
    }

    // -----------------------------------------------------------------
    //  Garde commune : no-store + rate limit + jeton serveur
    // -----------------------------------------------------------------

    /**
     * Garde d'entrée de chaque outil : réponse non mémorisable, rate
     * limit par IP, puis authentification par le jeton serveur
     * ASSISTANT_TOKEN (en-tête X-Assistant-Token) — comparaison en
     * hash_equals, refus 403 générique, jeton non configuré = refus.
     */
    private function ouvrir(): void
    {
        Kiosk::sendNoStore();

        $limiter = new RateLimiter();
        $cle = 'assistant:' . client_ip();
        if ($limiter->tooManyAttempts($cle, self::RATE_MAX, self::RATE_FENETRE)) {
            Logger::warning('assistant: rate limit atteint', ['ip' => client_ip()]);
            $this->repondreErreur(429, 'Trop de requêtes. Réessayez dans une minute.');
        }
        $limiter->hit($cle);

        $attendu = env('ASSISTANT_TOKEN', '');
        $donne   = (string) ($_SERVER['HTTP_X_ASSISTANT_TOKEN'] ?? '');
        if ($attendu === '' || $donne === '' || !hash_equals($attendu, $donne)) {
            Logger::warning('assistant: jeton invalide ou absent', ['ip' => client_ip()]);
            $this->repondreErreur(403, 'Accès refusé.');
        }
    }

    /**
     * Jour ISO AAAA-MM-JJ strict et réellement valide (checkdate).
     */
    private static function jourIsoValide(string $jour): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $jour, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * Erreur JSON générique (message stable, sans détail interne).
     */
    private function repondreErreur(int $code, string $message): void
    {
        $this->json(['erreur' => $message], $code);
    }

    /**
     * Échec d'exécution d'un outil : détail en log, réponse 500 générique.
     */
    private function echecInterne(string $outil, \Throwable $e): void
    {
        Logger::error('assistant: échec outil ' . $outil, ['msg' => $e->getMessage()]);

        $this->repondreErreur(500, 'Erreur interne.');
    }
}
