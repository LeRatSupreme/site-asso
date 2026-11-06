<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Compta\CashBreakdown;
use App\Core\Compta\CashLedger;
use App\Models\CashCount;
use App\Models\CashMovement;
use App\Models\ImportBatch;
use App\Models\Model;
use App\Models\Sale;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests du livre de caisse (traçabilité du liquide).
 *
 * Valide : solde théorique (ventes LIQUIDE + mouvements), dépôt banque,
 * comptage avec écart (ajustement automatique + historisation), comptage
 * exact sans ajustement, fenêtre 30 jours des écarts — et prise « chez
 * soi » (mouvement PRISE signé + détail par coupures en centimes
 * entiers, solde « chez soi » CashMovement::homeTotal, retour annulant
 * la prise, dépôts avec coupures cohérentes/incohérentes).
 *
 * Saute automatiquement si la base aeic_test est indisponible.
 */
final class CashLedgerTest extends TestCase
{
    use TestDatabaseTrait;

    private function requireDatabase(): bool
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped('Base aeic_test indisponible : test de caisse ignoré.');

            return false;
        }

        // Crée les tables de caisse si absentes (migration idempotente).
        foreach (['cash_movements', 'cash_counts'] as $table) {
            try {
                $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
            } catch (\Throwable) {
                $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/2026_cash_ledger.sql');
                foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                    if ($stmt !== '' && !str_starts_with($stmt, '--')) {
                        try {
                            $pdo->exec($stmt);
                        } catch (\Throwable) {
                            // statement de commentaire : ignoré
                        }
                    }
                }
            }
        }

        try {
            $pdo->query('SELECT 1 FROM sales LIMIT 1');
        } catch (\Throwable) {
            self::markTestSkipped('Table sales absente de aeic_test : importez database/schema.sql.');

            return false;
        }

        $this->applyCashPriseMigration($pdo);
        $this->reset($pdo, ['sales', 'import_batches', 'product_aliases', 'cash_movements', 'cash_counts', 'cash_movement_breakdown']);
        $this->applyImportDedupMigration($pdo);
        Model::setTestPdo($pdo);

        return true;
    }

    /**
     * Applique (best effort) la migration « prise chez soi »
     * (database/migrations/2026_cash_prise.sql) : ENUM + PRISE et table
     * cash_movement_breakdown. Les commentaires « -- » sont retirés AVANT
     * le découpage en statements (sinon le premier statement du fichier
     * commence par un commentaire et serait sauté). Idempotente : le
     * MODIFY vers un ENUM identique est un no-op, le CREATE TABLE est
     * IF NOT EXISTS.
     */
    private function applyCashPriseMigration(PDO $pdo): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/2026_cash_prise.sql');
        $sql = (string) preg_replace('/^--.*$/m', '', $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt !== '') {
                try {
                    $pdo->exec($stmt);
                } catch (\Throwable) {
                    // Déjà appliqué : non bloquant.
                }
            }
        }
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
    }

    /** Insère des ventes directes (liquide et carte) et renvoie leur total liquide. */
    private function seedSales(): float
    {
        $row = static fn (string $ref, string $method, float $price): array => [
            'transaction_ref'  => $ref,
            'sold_at'          => '2026-09-21 12:00:00',
            'payment_method'   => $method,
            'payment_raw'      => $method,
            'quantity'         => 1,
            'description'      => 'Test ' . $ref,
            'product_key'      => null,
            'category'         => null,
            'sku'              => null,
            'currency'         => 'EUR',
            'price_ttc'        => $price,
            'price_ht'         => null,
            'vat'              => null,
            'vat_rate'         => null,
            'seller_account'   => null,
            'is_custom_amount' => 0,
        ];

        $batchId = ImportBatch::create(['filename' => 'cash-test.csv']);
        Sale::importBatch($batchId, [
            $row('TAAA TEST 01', 'LIQUIDE', 10.0),
            $row('TAAA TEST 02', 'LIQUIDE', 2.5),
            $row('TAAA TEST 03', 'CARTE', 100.0), // ne doit jamais toucher la caisse
        ]);

        return 12.5;
    }

    public function test_solde_avec_ventes_liquide_fond_et_depot(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $liquide = $this->seedSales();

        CashLedger::recordFund(50.0, 'fond initial', 'test@aeic.fr');
        CashLedger::recordDeposit(20.0, 'bordereau 1', 'test@aeic.fr');

        self::assertSame(50.0 + $liquide - 20.0, CashLedger::balance());
        self::assertSame($liquide, CashLedger::salesTotal());

        // Les ventes CARTE ne sont jamais dans la caisse.
        self::assertNotSame(112.5, CashLedger::balance());
    }

    public function test_total_des_ventes_carte_hors_caisse(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $this->seedSales();

        self::assertSame(100.0, CashLedger::cardSalesTotal());
        // La carte n'entre jamais dans le solde de la caisse (liquide seul).
        self::assertSame(12.5, CashLedger::balance());
    }

    public function test_comptage_avec_ecart_realigne_et_historise(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $liquide = $this->seedSales();
        CashLedger::recordFund(50.0, 'fond', 'test@aeic.fr');
        $theoretical = 50.0 + $liquide; // 62.5

        $res = CashLedger::recordCount(57.5, 'comptage du soir', 'test@aeic.fr');

        self::assertSame(-5.0, $res['ecart'], '5 € manquants = vol potentiel.');
        self::assertSame(57.5, CashLedger::balance(), 'Le théorique est réaligné sur le compté.');

        // L'écart est visible dans la section « ⚠️ Écarts détectés »…
        $ecarts = CashCount::recentEcarts(30);
        self::assertCount(1, $ecarts);
        self::assertSame(-5.0, (float) $ecarts[0]['ecart']);

        // …et matérialisé par un mouvement AJUSTEMENT signé.
        $movements = CashMovement::recent(10);
        $ajustements = array_values(array_filter($movements, static fn ($m): bool => $m['type'] === CashMovement::TYPE_AJUSTEMENT));
        self::assertCount(1, $ajustements);
        self::assertSame(-5.0, (float) $ajustements[0]['amount']);
    }

    public function test_comptage_exact_sans_ajustement(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $liquide = $this->seedSales();
        $res = CashLedger::recordCount($liquide, 'contrôle', 'test@aeic.fr');

        self::assertSame(0.0, $res['ecart']);
        self::assertNull($res['adjustment_id']);
        self::assertSame([], CashCount::recentEcarts(30));
        self::assertSame(1, count(CashCount::recent(10)));
    }

    public function test_ecarts_30_jours_exclut_les_vieux_comptages(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        CashCount::add([
            'counted'     => 10.0,
            'theoretical' => 12.0,
            'label'       => 'vieux comptage',
            'created_by'  => 'test@aeic.fr',
            'created_at'  => date('Y-m-d H:i:s', strtotime('-40 days')),
        ]);
        CashCount::add([
            'counted'     => 10.0,
            'theoretical' => 11.0,
            'label'       => 'comptage récent',
            'created_by'  => 'test@aeic.fr',
        ]);

        self::assertCount(1, CashCount::recentEcarts(30));
        self::assertSame('comptage récent', CashCount::recentEcarts(30)[0]['label']);
    }

    /** 3×20€ + 5×2€ + 12×50c = 7600 centimes = 76,00 € (détail type). */
    private function breakdown76(): CashBreakdown
    {
        return CashBreakdown::fromPost(['qty_2000' => '3', 'qty_200' => '5', 'qty_50' => '12']);
    }

    /** Lignes de coupures d'un mouvement, unités croissantes. */
    private function breakdownRows(PDO $pdo, string $movementId): array
    {
        $stmt = $pdo->prepare(
            'SELECT unit_cents, qty FROM cash_movement_breakdown WHERE movement_id = ? ORDER BY unit_cents ASC'
        );
        $stmt->execute([$movementId]);

        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['unit_cents']] = (int) $row['qty'];
        }

        return $map;
    }

    public function test_prise_chez_soi_cree_mouvement_signe_et_coupures(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $liquide = $this->seedSales();
        CashLedger::recordFund(50.0, 'fond initial', 'test@aeic.fr');
        $avant = 50.0 + $liquide; // 62.50

        $id = CashLedger::recordTake($this->breakdown76(), 'Prise chez soi', 'Jean Dupont (trésorier)', null);

        $m = CashMovement::find($id);
        self::assertNotNull($m, 'Le mouvement PRISE doit exister.');
        self::assertSame(CashMovement::TYPE_PRISE, $m['type']);
        self::assertSame(-76.0, (float) $m['amount'], 'Une prise est NÉGATIVE (sortie du tiroir), même convention que DEPOT.');
        self::assertSame('Jean Dupont (trésorier)', $m['created_by'], 'La prise est SIGNÉE par qui a pris l\'argent.');

        // Le détail par coupures reflète EXACTEMENT le montant (Σ unit×qty).
        self::assertSame([50 => 12, 200 => 5, 2000 => 3], $this->breakdownRows($this->connect(), $id));

        // Solde de caisse diminué du total ; solde « chez soi » = −Σ(PRISE).
        self::assertSame($avant - 76.0, CashLedger::balance());
        self::assertSame(76.0, CashMovement::homeTotal());
    }

    public function test_retour_de_chez_soi_annule_la_prise(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $this->seedSales();
        CashLedger::recordFund(50.0, 'fond initial', 'test@aeic.fr');
        $avant = CashLedger::balance(); // 62.50

        CashLedger::recordTake($this->breakdown76(), 'Prise chez soi', 'test@aeic.fr');
        $idRetour = CashLedger::recordTake($this->breakdown76(), 'Ramené de chez soi', 'test@aeic.fr', null, true);

        $retour = CashMovement::find($idRetour);
        self::assertSame(76.0, (float) $retour['amount'], 'Un retour est POSITIF (ramené en caisse).');
        self::assertSame($avant, CashLedger::balance(), 'Le retour recrédite la caisse du montant exact.');
        self::assertSame(0.0, CashMovement::homeTotal(), 'Chez soi = −Σ(PRISE) : prise + retour = 0.');
    }

    public function test_prise_sans_coupures_est_refusee(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $this->expectException(\InvalidArgumentException::class);
        CashLedger::recordTake(CashBreakdown::fromPost([]), 'Prise chez soi', 'test@aeic.fr');
    }

    public function test_depot_avec_coupures_coherentes_attache_le_detail(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $id = CashLedger::recordDeposit(76.0, 'bordereau', 'test@aeic.fr', null, $this->breakdown76());

        self::assertSame(-76.0, (float) CashMovement::find($id)['amount']);
        self::assertSame([50 => 12, 200 => 5, 2000 => 3], $this->breakdownRows($this->connect(), $id), 'Le détail est attaché aussi aux dépôts banque.');
    }

    public function test_depot_avec_coupures_incoherentes_refuse_avant_toute_ecriture(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $avants = (int) $this->connect()->query('SELECT COUNT(*) FROM cash_movements')->fetchColumn();

        try {
            // Détail de 81,00 € (3×20€ + 5×2€ + 1×5€ + 12×50c) pour un
            // dépôt saisi de 76,00 € : refusé.
            CashLedger::recordDeposit(76.0, 'bordereau', 'test@aeic.fr', null, CashBreakdown::fromPost(['qty_2000' => '3', 'qty_200' => '5', 'qty_500' => '1', 'qty_50' => '12']));
            self::fail('Un détail incohérent avec le montant doit être refusé.');
        } catch (\InvalidArgumentException) {
            // Attendu : aucun mouvement ni coupure créés (contrôle AVANT écriture).
            self::assertSame($avants, (int) $this->connect()->query('SELECT COUNT(*) FROM cash_movements')->fetchColumn());
            self::assertSame(0, (int) $this->connect()->query('SELECT COUNT(*) FROM cash_movement_breakdown')->fetchColumn());
        }
    }

    public function test_mouvements_between_et_totaux_pris_deposes(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $this->seedSales();
        CashLedger::recordTake($this->breakdown76(), 'Prise chez soi', 'test@aeic.fr');
        CashLedger::recordDeposit(20.0, 'bordereau 1', 'test@aeic.fr');

        $today = date('Y-m-d');
        $movements = CashMovement::between($today, $today);
        self::assertCount(2, $movements, 'Les mouvements de la journée sont listés.');
        $types = array_column($movements, 'type');
        sort($types);
        self::assertSame([CashMovement::TYPE_DEPOT, CashMovement::TYPE_PRISE], $types, 'PRISE et DEPOT de la journée, quel que soit l\'ordre intra-seconde (tie-break d\'ids aléatoires).');

        ['pris' => $pris, 'depose' => $depose] = CashMovement::takesAndDepositsBetween($today, $today);
        self::assertSame(76.0, $pris, 'Pris : montants POSITIFS (Σ|PRISE|).');
        self::assertSame(20.0, $depose, 'Déposé : montants POSITIFS (Σ|DEPOT|).');

        // Hors période : rien.
        ['pris' => $prisVide, 'depose' => $deposeVide] = CashMovement::takesAndDepositsBetween('2020-01-01', '2020-01-31');
        self::assertSame(0.0, $prisVide);
        self::assertSame(0.0, $deposeVide);
    }
}
