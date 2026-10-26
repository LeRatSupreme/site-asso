<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Compta\CashLedger;
use App\Models\AnalyticsForecastData;
use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\Model;

/**
 * Page /admin/compta/tresorerie « Où est l'argent ? » — chantier P2-1
 * (traçabilité de l'argent pour le trésorier) :
 *
 *   1. chaque bloc est STRICTEMENT ÉGAL à sa source unique — aucun
 *      recalcul parallèle : caisse = CashLedger::balance(), banque =
 *      Σ dépôts − frais (CashMovement::depositsTotal() −
 *      Expense::depositFeesTotal(), recoupés en SQL brut), stock =
 *      AnalyticsForecastData::stockValueAtLastCost(stockBook()) — et le
 *      total = simple somme d'affichage des trois blocs ;
 *   2. les lectures « banque » ne comptent que les dépôts et leurs frais
 *      (les autres dépenses FRAIS, ex. abonnement, n'en font pas partie) ;
 *   3. accès refusé sans rôle compta : élève → 403 brut, CAFETERIA
 *      (bureau sans compta) → redirection douce vers /admin — jamais la
 *      page servie ni le moindre chiffre ;
 *   4. la page répond 200 pour le trésorier (garde compta) et l'entrée
 *      « Trésorerie » du menu Comptabilité est rendue.
 *
 * Le trésorier étant le destinataire, les montants attendus sont AUSSI
 * vérifiés en dur (47,40 / 58,00 / 25,00 / 130,40 €) : si la page ET les
 * méthodes source dérivaient ensemble, ces constantes resteraient fausses.
 *
 * Base `aeic_test` requise (les tests sont sautés sinon).
 */
final class TresoreriePageTest extends IntegrationTestCase
{
    private string $rootId = 'u_treso_root';
    private string $tresorierId = 'u_treso_tresorier';
    private string $eleveId = 'u_treso_eleve';
    private string $cafetId = 'u_treso_cafet';

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->requireDatabase();
        $this->reset([
            'sale_adjustments',
            'sales',
            'import_batches',
            'expenses',
            'cash_movements',
            'cash_counts',
            'inventory_counts',
            'inventory_tablet_sessions',
            'inventory_tablet_lines',
            'product_costs',
            'product_discontinued',
            'product_zero_since',
            'product_infinite',
            'product_packs',
            'product_aliases',
            'purchases',
            'losses',
            'budgets',
            'users',
        ]);
        $this->seedUser($this->rootId, 'treso-root@exemple.fr', 'Password123456', 'SUPERADMIN');
        $this->seedUser($this->tresorierId, 'treso-tresorier@exemple.fr', 'Password123456', 'TRESORERIE');
        $this->seedUser($this->eleveId, 'treso-eleve@exemple.fr', 'Password123456', 'ELEVE');
        $this->seedUser($this->cafetId, 'treso-cafet@exemple.fr', 'Password123456', 'CAFETERIA');

        // CAISSE : fond +50 €, ventes liquide 40,00 + 22,40 (une vente CARTE
        // de 30,00 € ne doit JAMAIS entrer en caisse) − dépôts 40 + 25 €
        //   → CashLedger::balance() = (50 − 65) + 62,40 = 47,40 €.
        $this->insertMovement('cm_treso_fond', CashMovement::TYPE_FOND, '50.00', '2026-09-01 09:00:00');
        $this->insertSale('s_treso_l1', 'TTRESO1', '2026-09-15 10:00:00', '40.00', 'LIQUIDE', 'Gaufre');
        $this->insertSale('s_treso_l2', 'TTRESO2', '2026-09-15 11:00:00', '22.40', 'LIQUIDE', 'Chocolat');
        $this->insertSale('s_treso_c1', 'TTRESO3', '2026-09-15 12:00:00', '30.00', 'CARTE', 'Menu');
        $this->insertMovement('cm_treso_d1', CashMovement::TYPE_DEPOT, '-40.00', '2026-09-20 18:00:00');
        $this->insertMovement('cm_treso_d2', CashMovement::TYPE_DEPOT, '-25.00', '2026-10-05 18:00:00');

        // BANQUE : dépôts 65,00 € − frais 2 × 3,50 € = 58,00 €. Le second
        // frais porte le suffixe « — bordereau » (convention des contrôleurs)
        // et une dépense FRAIS étrangère (abonnement) ne doit pas être
        // comptée : 65 − 7 = 58 €, pas 46 €.
        $this->insertExpense('exp_treso_f1', '2026-09-20', 'FRAIS', 'Frais de dépôt banque', '3.50');
        $this->insertExpense('exp_treso_f2', '2026-10-05', 'FRAIS', 'Frais de dépôt banque — bordereau 4512', '3.50');
        $this->insertExpense('exp_treso_ab', '2026-10-06', 'FRAIS', 'Abonnement SumUp', '12.00');

        // STOCK : café compté 10 u. × 2,50 € (lot couvrant) = 25,00 € ;
        // thé compté 4 u. SANS coût connu → exclu de la valorisation.
        $this->insertCount('inv_treso_1', '2026-09-20 10:00:00', 'café', 10);
        $this->insertCount('inv_treso_2', '2026-09-20 10:05:00', 'thé', 4);
        $this->pdo->prepare(
            'INSERT INTO product_costs (id, product_key, cost_price, valid_from) VALUES (?,?,?,?)'
        )->execute(['pc_treso_1', 'café', '2.500', '2026-01-01']);

        // Les méthodes source attendues sont appelées DANS le test, sur la
        // même base : comparaison stricte page ↔ source (remis à zéro dans
        // tearDown pour ne pas fuiter vers les autres tests, ordre aléatoire).
        Model::setTestPdo($pdo);
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    //  1. Blocs strictement égaux aux sources uniques
    // -----------------------------------------------------------------

    public function test_les_trois_blocs_et_le_total_strictement_egaux_aux_sources(): void
    {
        // Sources uniques, appelées telles quelles (zéro recalcul ici).
        $caisse = CashLedger::balance();
        $banque = round(CashMovement::depositsTotal() - Expense::depositFeesTotal(), 2);
        $stock = AnalyticsForecastData::stockValueAtLastCost(AnalyticsForecastData::stockBook());
        $total = round($caisse + $banque + $stock, 2);

        // Recoupement SQL brut (indépendant des méthodes) : Σ dépôts − Σ frais.
        $depSql = (float) $this->pdo
            ->query("SELECT COALESCE(SUM(ABS(amount)), 0) FROM cash_movements WHERE type = 'DEPOT'")
            ->fetchColumn();
        $fraisSql = (float) $this->pdo
            ->query("SELECT COALESCE(SUM(amount_ttc), 0) FROM expenses WHERE category = 'FRAIS' AND label LIKE 'Frais de dépôt banque%'")
            ->fetchColumn();
        self::assertEqualsWithDelta($depSql - $fraisSql, $banque, 0.001, 'Le bloc banque doit valoir Σ dépôts − Σ frais (SQL brut).');
        self::assertSame(58.0, $banque, 'Banque attendue : 65,00 − 7,00 = 58,00 €.');

        // Valeurs attendues en dur (cf. docblock de la classe).
        self::assertEqualsWithDelta(47.40, $caisse, 0.001, 'Caisse attendue : fond 50 + liquide 62,40 − dépôts 65.');
        self::assertEqualsWithDelta(25.00, $stock, 0.001, 'Stock attendu : café 10 × 2,50 € (thé sans coût exclu).');
        self::assertEqualsWithDelta(130.40, $total, 0.001, 'Total attendu : 47,40 + 58,00 + 25,00.');

        // La page affiche EXACTEMENT ces montants, bloc par bloc.
        $r = $this->request('GET', '/admin/compta/tresorerie', [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0), 'La page trésorerie doit répondre 200 (SUPERADMIN).');
        $body = (string) ($r['body'] ?? '');

        self::assertStringContainsString('Où est l\'argent', $body, 'Le titre de la page est absent.');
        foreach ([
            'caisse' => $caisse,
            'banque' => $banque,
            'stock'  => $stock,
            'total'  => $total,
        ] as $bloc => $valeur) {
            self::assertStringContainsString(
                'data-bloc="' . $bloc . '"',
                $body,
                sprintf('Le bloc %s doit être rendu.', $bloc)
            );
            self::assertStringContainsString(
                formatPrice($valeur),
                $body,
                sprintf('Le bloc %s doit être STRICTEMENT ÉGAL à sa source (%s).', $bloc, formatPrice($valeur))
            );
        }

        // Détail humain du bloc banque : dépôts et frais affichés tels quels.
        self::assertStringContainsString(formatPrice(65.00), $body, 'Le sous-texte banque doit montrer les 65,00 € déposés.');
        self::assertStringContainsString(formatPrice(7.00), $body, 'Le sous-texte banque doit montrer les 7,00 € de frais.');

        // Le thé (4 u. sans coût connu) est exclu : la mention l'explique.
        self::assertStringContainsString('sans coût connu', $body, 'La mention « produits sans coût connu exclus » est absente.');
    }

    // -----------------------------------------------------------------
    //  2. Lectures « banque » : uniquement dépôts et leurs frais
    // -----------------------------------------------------------------

    public function test_les_lectures_banque_n_ont_pas_de_recalcul_parallele(): void
    {
        // Σ dépôts = uniquement les mouvements DEPOT (le fond n'y entre pas).
        self::assertSame(65.0, CashMovement::depositsTotal(), 'Σ dépôts : 40 + 25, le fond de caisse exclu.');
        // Σ frais = uniquement les « Frais de dépôt banque… » (l'abonnement
        // FRAIS de 12 € NE doit PAS être compté).
        self::assertSame(7.0, Expense::depositFeesTotal(), 'Σ frais de dépôt : 2 × 3,50, l\'abonnement exclu.');

        // La page, elle, ne fait qu'afficher ces deux lectures (58,00 €) —
        // vérifié aussi depuis un compte trésorier (le destinataire).
        $r = $this->request('GET', '/admin/compta/tresorerie', [], [], $this->tresorierId);
        self::assertSame(200, (int) ($r['code'] ?? 0), 'La page trésorerie doit répondre 200 pour le rôle TRESORERIE.');
        self::assertStringContainsString(formatPrice(58.00), (string) ($r['body'] ?? ''));
        self::assertStringNotContainsString(formatPrice(46.00), (string) ($r['body'] ?? ''), 'L\'abonnement SumUp ne doit pas retirer du bloc banque.');
    }

    // -----------------------------------------------------------------
    //  3. Accès refusé sans rôle compta
    // -----------------------------------------------------------------

    public function test_acces_refuse_sans_role_compta(): void
    {
        // Élève : refus brut 403 au routeur ; rôle du bureau sans compta
        // (CAFETERIA) : redirection douce vers /admin avec message « Accès
        // refusé » (convention Middleware::forbidden, cf. RolesMenuTest) —
        // dans les deux cas la page n'est JAMAIS servie.
        $r = $this->request('GET', '/admin/compta/tresorerie', [], [], $this->eleveId);
        self::assertSame(403, (int) ($r['code'] ?? 0), 'Un élève doit être refusé (403) sur la page trésorerie.');

        $r = $this->request('GET', '/admin/compta/tresorerie', [], [], $this->cafetId);
        self::assertSame(302, (int) ($r['code'] ?? 0), 'Un rôle du bureau sans compta reçoit la redirection douce.');
        self::assertStringContainsString('/admin', $this->location($r), 'Le bureau est renvoyé vers son accueil.');
        self::assertStringNotContainsString('/admin/compta/tresorerie', $this->location($r), 'La page refusée n\'est pas l\'objectif de la redirection.');
        $body = (string) ($r['body'] ?? '');
        self::assertStringNotContainsString('data-bloc="caisse"', $body, 'Aucun chiffre ne doit fuiter sans le rôle compta.');
        $flash = (string) ($r['session']['_flash'][0]['message'] ?? '');
        self::assertStringContainsString('Accès refusé', $flash, 'La redirection douce porte un message explicite.');
    }

    // -----------------------------------------------------------------
    //  4. Entrée de menu Comptabilité (au même endroit que Budgets)
    // -----------------------------------------------------------------

    public function test_entree_menu_comptabilite_visible_pour_le_tresorier(): void
    {
        // Vue depuis une AUTRE page compta : le lien n'est pas tautologique
        // avec l'URL courante.
        $r = $this->request('GET', '/admin/compta/depenses', [], [], $this->tresorierId);
        self::assertSame(200, (int) ($r['code'] ?? 0));
        $body = (string) ($r['body'] ?? '');

        self::assertStringContainsString(
            '/admin/compta/tresorerie',
            $body,
            'L\'entrée « Trésorerie » doit apparaître dans le menu Comptabilité du trésorier.'
        );
    }

    // -----------------------------------------------------------------
    //  Helpers (même gabarit que BudgetPageTest / ComptaConsistencyTest)
    // -----------------------------------------------------------------

    /**
     * Insère un mouvement de caisse signé directement en base.
     */
    private function insertMovement(string $id, string $type, string $amount, string $createdAt): void
    {
        $this->pdo->prepare(
            'INSERT INTO cash_movements (id, type, amount, label, created_by, created_at) VALUES (?,?,?,?,?,?)'
        )->execute([$id, $type, $amount, 'seed trésorerie', 'test@aeic.fr', $createdAt]);
    }

    /**
     * Insère une vente directement en base (SumUp simulé).
     */
    private function insertSale(string $id, string $ref, string $soldAt, string $priceTtc, string $paymentMethod, string $description): void
    {
        $this->pdo->prepare(
            'INSERT INTO sales (id, transaction_ref, sold_at, payment_method, payment_raw, quantity, description, price_ttc, is_custom_amount, category, imported_at)
             VALUES (?,?,?,?,?,1,?,?,0,NULL,?)'
        )->execute([$id, $ref, $soldAt, $paymentMethod, $paymentMethod === 'LIQUIDE' ? 'Espèces' : 'Visa - Débit', $description, $priceTtc, date('Y-m-d H:i:s')]);
    }

    /**
     * Insère une dépense directement en base.
     */
    private function insertExpense(string $id, string $spentAt, string $category, string $label, string $amountTtc): void
    {
        $this->pdo->prepare(
            'INSERT INTO expenses (id, spent_at, category, label, amount_ttc, created_by)
             VALUES (?,?,?,?,?,?)'
        )->execute([$id, $spentAt, $category, $label, $amountTtc, 'test@aeic.fr']);
    }

    /**
     * Insère un comptage inventaire (théorique = compté, aucun mouvement).
     */
    private function insertCount(string $id, string $countedAt, string $productKey, int $qty): void
    {
        $this->pdo->prepare(
            'INSERT INTO inventory_counts (id, counted_at, product_key, counted_qty, theoretical_qty, gap, created_by)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$id, $countedAt, $productKey, $qty, $qty, 0, 'test']);
    }
}
