<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Compta\ComptaCalc;
use App\Core\Compta\Dashboard\Data;
use App\Core\Compta\Kiosk;
use App\Core\Controller;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Models\AnalyticsForecastData;
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
    private const TOOL_VENTES  = 'ventes_periode';
    private const TOOL_STOCK   = 'stock_produit';
    private const TOOL_REAPPRO = 'produits_a_commander';

    /** Rate limit : 20 requêtes par minute et par IP. */
    private const RATE_MAX     = 20;
    private const RATE_FENETRE = 60;

    /** Nombre de produits du top ventes renvoyé. */
    private const TOP_LIMITE = 10;

    /** Candidats proposés quand un libellé produit est introuvable. */
    private const CANDIDATS_LIMITE = 5;

    /** Libellés français des états réappro de Data::analyseReappro. */
    private const ETATS_REAPPRO = [
        'ok'      => 'ok',
        'reorder' => 'a_commander',
        'unknown' => 'a_compter',
    ];

    /** Liste blanche des outils : nom d'URL → action du contrôleur. */
    private const OUTILS = [
        self::TOOL_VENTES  => 'ventesPeriode',
        self::TOOL_STOCK   => 'stockProduit',
        self::TOOL_REAPPRO => 'produitsACommander',
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
