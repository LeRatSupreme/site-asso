<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Page /admin/compta refondue en 3 niveaux (verdict + 4 cartes + « À faire »).
 *
 * Scénarios :
 *   1. Les 4 cartes « Niveau 1 » et le bloc « À faire » sont rendus.
 *   2. Verdict « progresse » (vert) sur une semaine passée complète dont le
 *      résultat net dépasse celui de la semaine encore avant.
 *   3. Verdict neutre « Période en cours » sur la fenêtre par défaut (30 j
 *      finissant aujourd'hui) — choix d'assertion : la phrase « Période en
 *      cours » est le marqueur du refus de verdict (règle 1 du cœur).
 *   4. Aucune vente + des dépenses sur une semaine passée : phrase « Aucune
 *      vente » et badge rouge (classe badge-danger).
 *   5. Cohérence avec le livre : les montants affichés (CA, profit, net)
 *      sont exactement ceux seedés, au format FR, profit = somme des marges
 *      recalculée dans le test.
 *   6. Aucun « % vs période précédente » sur la période par défaut (en
 *      cours) : règle de mission « aucun % sur période incomplète » —
 *      assertion robuste substr_count('vs période précédente') == 0.
 *
 * Base `aeic_test` requise (les tests sont sautés sinon).
 */
final class DashboardPageTest extends IntegrationTestCase
{
    private string $rootId = 'u_dash_root';

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
            'users',
        ]);
        $this->seedUser($this->rootId, 'dash-root@exemple.fr', 'Password123456', 'SUPERADMIN');
    }

    public function test_les_4_cartes_sont_affichees(): void
    {
        $r = $this->request('GET', '/admin/compta', [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0), 'Le dashboard compta doit répondre.');
        $body = (string) ($r['body'] ?? '');

        // Les apostrophes des libellés sont échappées par e() : on asserte
        // des fragments stables (sans apostrophe).
        self::assertStringContainsString('Argent encaissé', $body, 'La carte « Argent encaissé » est absente.');
        self::assertStringContainsString('gagne sur les ventes', $body, 'La carte profit est absente.');
        self::assertStringContainsString('reste après les dépenses', $body, 'La carte résultat net est absente.');
        self::assertStringContainsString('Articles vendus', $body, 'La carte articles est absente.');
        self::assertStringContainsString('À faire', $body, 'Le bloc « À faire » est absent.');
    }

    public function test_verdict_hausse_sur_periode_passee_complete(): void
    {
        // Semaine T1 (passée, complète) vs semaine T2 (encore avant) :
        // profit T1 100 € > profit T2 50 € (aucun coût saisi : profit = CA).
        $t1From = date('Y-m-d', strtotime('-13 days'));
        $t1To = date('Y-m-d', strtotime('-7 days'));
        $this->insertSale('s_dash_t1', 'TDASH1', date('Y-m-d H:i:s', strtotime('-10 days')), '60.00', 'CARTE');
        $this->insertSale('s_dash_t1b', 'TDASH2', date('Y-m-d H:i:s', strtotime('-9 days')), '40.00', 'LIQUIDE');
        $this->insertSale('s_dash_t2', 'TDASH3', date('Y-m-d H:i:s', strtotime('-17 days')), '50.00', 'CARTE');

        $r = $this->request(
            'GET',
            '/admin/compta?period=custom&from=' . $t1From . '&to=' . $t1To,
            [],
            [],
            $this->rootId
        );
        self::assertSame(200, (int) ($r['code'] ?? 0), 'Le dashboard doit répondre sur une période personnalisée.');
        $body = (string) ($r['body'] ?? '');

        // Verdict : +100 % de résultat net → « progresse ».
        self::assertStringContainsString('progresse', $body, 'Le verdict de hausse (résultat net en progression) est absent.');
    }

    public function test_verdict_neutre_sur_periode_courante(): void
    {
        // Fenêtre par défaut = 30 derniers jours, finissant aujourd'hui :
        // période en cours → le cœur refuse de trancher (règle 1). Assertion
        // la plus robuste : la phrase exacte « Période en cours » (impossible
        // sur une période complète, quel que soit le reste des données).
        $r = $this->request('GET', '/admin/compta', [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0));
        $body = (string) ($r['body'] ?? '');

        self::assertStringContainsString('Période en cours', $body, 'Le verdict doit refuser de trancher sur la période courante.');
    }

    public function test_0_vente_avec_depenses(): void
    {
        // Une dépense seule sur une semaine passée (complète : un import est
        // connu — la vente hors fenêtre porte imported_at du jour) → verdict
        // rouge « Aucune vente … des dépenses ».
        $from = date('Y-m-d', strtotime('-13 days'));
        $to = date('Y-m-d', strtotime('-7 days'));
        $this->insertSale('s_dash_old', 'TDASH0', date('Y-m-d H:i:s', strtotime('-60 days')), '5.00', 'CARTE');
        $this->insertExpense('exp_dash_1', date('Y-m-d', strtotime('-10 days')), 'MATIERE', 'Gobelets', '12.50');

        $r = $this->request(
            'GET',
            '/admin/compta?period=custom&from=' . $from . '&to=' . $to,
            [],
            [],
            $this->rootId
        );
        self::assertSame(200, (int) ($r['code'] ?? 0));
        $body = (string) ($r['body'] ?? '');

        self::assertStringContainsString('Aucune vente', $body, 'Le verdict « Aucune vente » est absent.');
        self::assertStringContainsString('badge-danger', $body, 'Le badge rouge (alerte) est absent du verdict.');
    }

    public function test_coherence_avec_le_livre(): void
    {
        // Données connues sur une semaine passée : carte 60,00 € (« café »,
        // coût 2,50 €/u) + liquide 40,00 € (« thé », sans coût) + dépense
        // 2,50 €. Recalcul indépendant : CA 100,00 · profit (60 − 2,50 × 1)
        // + (40 − 0) = 97,50 · net = 97,50 − 2,50 = 95,00.
        $from = date('Y-m-d', strtotime('-13 days'));
        $to = date('Y-m-d', strtotime('-7 days'));
        $this->insertSale('s_dash_c1', 'TLIVRE1', date('Y-m-d H:i:s', strtotime('-10 days')), '60.00', 'CARTE', 'café');
        $this->insertSale('s_dash_c2', 'TLIVRE2', date('Y-m-d H:i:s', strtotime('-9 days')), '40.00', 'LIQUIDE', 'thé');
        $this->insertExpense('exp_dash_2', date('Y-m-d', strtotime('-9 days')), 'FRAIS', 'Frais bancaires', '2.50');
        $this->pdo->prepare(
            'INSERT INTO product_costs (id, product_key, cost_price, valid_from) VALUES (?,?,?,?)'
        )->execute(['pc_dash_1', 'café', '2.500', '2026-01-01']);

        $r = $this->request(
            'GET',
            '/admin/compta?period=custom&from=' . $from . '&to=' . $to,
            [],
            [],
            $this->rootId
        );
        self::assertSame(200, (int) ($r['code'] ?? 0));
        $body = (string) ($r['body'] ?? '');

        // Montants attendus, formatés FR (virgule décimale + « € »).
        self::assertStringContainsString('100,00 €', $body, 'Le CA encaissé affiché ne correspond pas aux ventes seedées.');
        self::assertStringContainsString('97,50 €', $body, 'Le profit affiché ne correspond pas à la somme des marges recalculée.');
        self::assertStringContainsString('95,00 €', $body, 'Le résultat net affiché ne correspond pas au calcul du livre.');
    }

    public function test_aucun_pourcent_sur_periode_incomplete(): void
    {
        // Période par défaut (30 j, finissant aujourd'hui) : incomplète →
        // règle de mission « aucun % sur période incomplète ». Assertion la
        // plus robuste : aucun repère « vs période précédente » ne peut
        // apparaître (le cœur Cartes renvoie des repères null), quel que
        // soit le contenu de la base.
        $this->insertSale('s_dash_cur', 'TDASHC1', date('Y-m-d H:i:s', strtotime('-2 days')), '30.00', 'CARTE');

        $r = $this->request('GET', '/admin/compta', [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0));
        $body = (string) ($r['body'] ?? '');

        self::assertSame(
            0,
            substr_count($body, 'vs période précédente'),
            'Aucun repère « vs période précédente » ne doit être affiché sur la période en cours.'
        );
        self::assertStringContainsString('Période en cours', $body, 'La période courante doit produire un verdict neutre « Période en cours ».');
    }

    // -----------------------------------------------------------------
    //  Helpers
    // -----------------------------------------------------------------

    /**
     * Insère une vente directement en base (SumUp simulé), importée
     * aujourd'hui : Sale::lastImportAt() couvre donc la fenêtre testée.
     */
    private function insertSale(
        string $id,
        string $ref,
        string $soldAt,
        string $priceTtc,
        string $payment = 'CARTE',
        ?string $description = null
    ): void {
        $this->pdo->prepare(
            'INSERT INTO sales (id, transaction_ref, sold_at, payment_method, payment_raw, quantity, description, price_ttc, is_custom_amount, category, imported_at)
             VALUES (?,?,?,?,?,1,?,?,0,?,?)'
        )->execute([$id, $ref, $soldAt, $payment, $payment === 'CARTE' ? 'Visa - Débit' : 'Cash', $description ?? $ref, $priceTtc, null, date('Y-m-d H:i:s')]);
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
}
