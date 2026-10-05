<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Cohérence inter-pages de la comptabilité : les mêmes données doivent
 * produire les mêmes chiffres partout (bilan annuel, analytics, dashboard
 * sumup, dépenses) et chaque écriture doit se répercuter sur toutes les
 * pages concernées.
 *
 * Scénarios :
 *   1. L'export CSV du bilan annuel reflète exactement les ventes et
 *      dépenses saisies en base (par mois et en total, résultat net
 *      inclus) — c'est LA page de référence du bureau.
 *   2. La page Analytics, filtrée sur la même période personnalisée,
 *      affiche le même CA que le bilan.
 *   3. Un dépôt à la banque génère bien : le mouvement de caisse (−
 *      montant déposé), la dépense de frais bancaires (3,50 €) ET la
 *      Majoration des dépenses dans le bilan annuel.
 *
 * Base `aeic_test` requise (les tests sont sautés sinon).
 */
final class ComptaConsistencyTest extends IntegrationTestCase
{
    private string $rootId = 'u_compta_root';

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->requireDatabase();
        $this->reset(['sale_adjustments', 'sales', 'import_batches', 'expenses', 'cash_movements', 'cash_counts', 'users']);
        $this->seedUser($this->rootId, 'compta-root@exemple.fr', 'Password123456', 'SUPERADMIN');

        // Ventes : juin = 25,00 € (2 lignes), septembre = 100,00 € (1 ligne).
        $this->insertSale('s_cons_j1', 'TCONS001', '2026-06-06 10:00:00', '10.00');
        $this->insertSale('s_cons_j2', 'TCONS002', '2026-06-20 12:00:00', '15.00');
        $this->insertSale('s_cons_s1', 'TCONS003', '2026-09-15 12:30:00', '100.00');

        // Dépenses : juin 5,00 € · septembre 12,50 + 3,50 (frais) = 16,00 €.
        $this->insertExpense('exp_cons_j1', '2026-06-10', 'MATIERE', 'Gobelets', '5.00');
        $this->insertExpense('exp_cons_s1', '2026-09-10', 'MATIERE', 'Cafetière', '12.50');
        $this->insertExpense('exp_cons_s2', '2026-09-12', 'FRAIS', 'Frais bancaires', '3.50');
    }

    public function test_bilan_annuel_csv_conforme_aux_donnees_saisies(): void
    {
        $rows = $this->annualCsvRows(2026);

        // Juin : CA 25,00 · bénéfice = CA (aucun coût de revient saisi) ·
        // dépenses 5,00 · net 20,00.
        self::assertSame(
            ['06/2026', '25.00', '25.00', '100.0', '5.00', '20.00', '0.00', ''],
            array_values($rows['06/2026'] ?? []),
            'Ligne juin du bilan annuel incohérente avec les données en base.'
        );

        // Septembre : CA 100,00 · dépenses 16,00 (12,50 + 3,50) · net 84,00.
        self::assertSame(
            ['09/2026', '100.00', '100.00', '100.0', '16.00', '84.00', '0.00', ''],
            array_values($rows['09/2026'] ?? []),
            'Ligne septembre du bilan annuel incohérente avec les données en base.'
        );

        // Totaux : CA 125,00 · dépenses 21,00 · net 104,00.
        $total = $rows['TOTAL'] ?? [];
        self::assertSame('125.00', $total[1] ?? '', 'CA total du bilan incohérent.');
        self::assertSame('21.00', $total[4] ?? '', 'Dépenses totales du bilan incohérentes.');
        self::assertSame('104.00', $total[5] ?? '', 'Résultat net total incohérent.');
    }

    public function test_analytics_et_bilan_html_affichent_les_memes_chiffres(): void
    {
        // Bilan HTML : la ligne juin existe et affiche bien le CA de juin.
        $html = $this->request('GET', '/admin/compta/annuel?year=2026', [], [], $this->rootId);
        self::assertSame(200, (int) ($html['code'] ?? 0), 'Le bilan annuel (HTML) doit répondre.');
        self::assertStringContainsString('06/2026', (string) ($html['body'] ?? ''));
        self::assertStringContainsString('25,00', (string) ($html['body'] ?? ''));

        // Analytics filtré sur la même fenêtre (juin 2026) : même CA affiché.
        $analytics = $this->request(
            'GET',
            '/admin/analytics?period=custom&from=2026-06-01&to=2026-06-30',
            [],
            [],
            $this->rootId
        );
        self::assertSame(200, (int) ($analytics['code'] ?? 0), 'Analytics doit répondre sur une période personnalisée.');
        self::assertStringContainsString('25,00', (string) ($analytics['body'] ?? ''), 'Analytics n\'affiche pas le CA de la période.');

        // Le dashboard SumUp (même source « sales ») répond aussi avec les données.
        $sumup = $this->request('GET', '/admin/sumup', [], [], $this->rootId);
        self::assertSame(200, (int) ($sumup['code'] ?? 0), 'Le dashboard SumUp doit répondre.');
    }

    public function test_depot_banque_genere_frais_repercutes_dans_le_bilan(): void
    {
        // Dépôt de 50,50 € via la page Caisses.
        $r = $this->request('POST', '/admin/caisses/depot', ['amount' => '50,50'], [], $this->rootId);

        // Flash de succès mentionnant les frais.
        $flash = $r['session']['_flash'] ?? [];
        $last  = is_array($flash) && $flash !== [] ? end($flash) : null;
        self::assertNotNull($last, 'Aucun flash après le dépôt.');
        self::assertSame('success', $last['type'] ?? '');
        self::assertStringContainsString('frais bancaires', (string) ($last['message'] ?? ''));

        // Caisse : un mouvement DEPOT du montant exact.
        self::assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM cash_movements WHERE type = 'DEPOT' AND amount = -50.50")->fetchColumn(),
            'Le mouvement de caisse du dépôt est absent ou erroné.'
        );

        // Dépenses : la ligne de frais bancaires (3,50 €) est créée.
        self::assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM expenses WHERE category = 'FRAIS' AND amount_ttc = 3.50 AND label LIKE 'Frais de dépôt banque%'")->fetchColumn(),
            'La dépense de frais bancaires (3,50 €) n\'a pas été créée.'
        );

        // Le bilan annuel de l'année courante intègre les 3,50 € de frais.
        $year    = (int) date('Y');
        $rows    = $this->annualCsvRows($year);
        $attendu = $year === 2026 ? '24.50' : '3.50'; // 21,00 € de dépenses 2026 + 3,50 € de frais.
        self::assertSame(
            $attendu,
            $rows['TOTAL'][4] ?? '',
            'Les frais de dépôt ne se répercutent pas dans les dépenses du bilan annuel.'
        );
    }

    // -----------------------------------------------------------------
    //  Helpers
    // -----------------------------------------------------------------

    /**
     * Insère une vente directement en base (SumUp simulé).
     */
    private function insertSale(string $id, string $ref, string $soldAt, string $priceTtc): void
    {
        $this->pdo->prepare(
            'INSERT INTO sales (id, transaction_ref, sold_at, payment_method, payment_raw, quantity, description, price_ttc, is_custom_amount)
             VALUES (?,?,?,?,?,1,?,?,0)'
        )->execute([$id, $ref, $soldAt, 'CARTE', 'Visa - Débit', $ref, $priceTtc]);
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
     * Export CSV du bilan annuel, parsé en lignes indexées par leur
     * première colonne (« 06/2026 », « TOTAL »…).
     *
     * @return array<string,list<string>>
     */
    private function annualCsvRows(int $year): array
    {
        $r = $this->request('GET', '/admin/compta/annuel?export=csv&year=' . $year, [], [], $this->rootId);
        self::assertSame(
            200,
            (int) ($r['code'] ?? 0),
            sprintf('L\'export CSV du bilan annuel %d doit répondre.', $year)
        );

        $body = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($r['body'] ?? ''));
        $lines = preg_split('/\r?\n/', trim((string) $body)) ?: [];

        $rows = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $cells = str_getcsv($line);
            if ($cells === [] || $cells[0] === '') {
                continue;
            }
            $rows[$cells[0]] = $cells;
        }

        self::assertArrayHasKey('TOTAL', $rows, 'Ligne TOTAL absente de l\'export CSV du bilan.');

        return $rows;
    }
}
