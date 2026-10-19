<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Analytics;
use App\Models\ImportBatch;
use App\Models\Model;
use App\Models\Sale;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Agrégats « rythmes de vente » du dashboard Analytics : CA par jour de
 * semaine (WEEKDAY), par heure (HOUR) et tendance hebdomadaire d'un
 * produit (semaines lundi, zéro-remplies, rapprochement product_key OU
 * description).
 *
 * Grille de référence : 2026-09-28 est un LUNDI, la fenêtre couvre
 * exactement 3 semaines pleines (28/09 → 18/10/2026).
 *
 * Saute automatiquement si la base aeic_test est indisponible.
 */
final class AnalyticsTrendTest extends TestCase
{
    use TestDatabaseTrait;

    private function requireDatabase(): bool
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped('Base aeic_test indisponible : test des agrégats ignoré.');

            return false;
        }

        try {
            $pdo->query('SELECT 1 FROM `sales` LIMIT 1');
        } catch (\Throwable) {
            self::markTestSkipped('Table `sales` absente de aeic_test : importez database/schema.sql.');

            return false;
        }

        $this->reset($pdo, ['sales', 'import_batches']);
        $this->applyImportDedupMigration($pdo);
        Model::setTestPdo($pdo);

        return true;
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
    }

    /** Ligne de vente au format importBatch (cf. StockTheoriqueTest). */
    private function saleRow(string $ref, string $soldAt, string $description, int $qty, ?string $productKey = null): array
    {
        return [
            'transaction_ref'  => $ref,
            'sold_at'          => $soldAt,
            'payment_method'   => 'CARTE',
            'payment_raw'      => 'Visa - Débit',
            'quantity'         => $qty,
            'description'      => $description,
            'product_key'      => $productKey,
            'category'         => null,
            'sku'              => null,
            'currency'         => 'EUR',
            'price_ttc'        => 2.5 * $qty,
            'price_ht'         => null,
            'vat'              => null,
            'vat_rate'         => null,
            'seller_account'   => null,
            'is_custom_amount' => 0,
        ];
    }

    private function importSales(array $rows): void
    {
        $batchId = ImportBatch::create(['filename' => 'analytics-trend-test.csv']);
        Sale::importBatch($batchId, $rows);
    }

    /**
     * 3 semaines de ventes : lundis (10h), mercredi (18h), mardi (19h),
     * dimanche (15h), samedi (15h) — produits suivis par clé ou libellé.
     */
    private function seedTroisSemaines(): void
    {
        $this->importSales([
            $this->saleRow('at1', '2026-09-28 10:15:00', 'Bières', 4),                    // lundi, 10 €
            $this->saleRow('at2', '2026-09-30 18:45:00', 'Café', 2),                      // mercredi, 5 €
            $this->saleRow('at3', '2026-10-05 10:30:00', 'Bières', 2),                    // lundi, 5 €
            $this->saleRow('at4', '2026-10-06 19:00:00', 'Coca-cola', 8, 'coca'),         // mardi, 20 € (clé)
            $this->saleRow('at5', '2026-10-11 15:00:00', 'Café', 6),                      // dimanche, 15 €
            $this->saleRow('at6', '2026-10-14 18:10:00', 'Café', 2),                      // mercredi, 5 €
            $this->saleRow('at7', '2026-10-17 15:30:00', 'Coca-cola', 4),                 // samedi, 10 € (libellé)
        ]);
    }

    public function test_revenue_by_weekday_agrege_ca_qty_et_transactions(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }
        $this->seedTroisSemaines();

        $wd = Analytics::revenueByWeekday('2026-09-28', '2026-10-18');

        self::assertCount(7, $wd, '7 clés 0 (lundi) à 6 (dimanche).');
        self::assertSame([0, 1, 2, 3, 4, 5, 6], array_keys($wd));
        // Lundi : 10 € + 5 €, 6 u., 2 transactions.
        self::assertSame(15.0, $wd[0]['ca']);
        self::assertSame(6, $wd[0]['qty']);
        self::assertSame(2, $wd[0]['tx']);
        // Mardi : 20 € (vente à la clé « coca »).
        self::assertSame(20.0, $wd[1]['ca']);
        self::assertSame(1, $wd[1]['tx']);
        // Mercredi : 5 € + 5 €.
        self::assertSame(10.0, $wd[2]['ca']);
        self::assertSame(2, $wd[2]['tx']);
        // Jeudi / vendredi : aucun jour à zéro absent de la carte.
        self::assertSame(0.0, $wd[3]['ca']);
        self::assertSame(0, $wd[3]['qty']);
        self::assertSame(0, $wd[3]['tx']);
        self::assertSame(0.0, $wd[4]['ca']);
        // Samedi 10 €, dimanche 15 €.
        self::assertSame(10.0, $wd[5]['ca']);
        self::assertSame(15.0, $wd[6]['ca']);
    }

    public function test_revenue_by_hour_agrege_par_heure_pleine(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }
        $this->seedTroisSemaines();

        $hr = Analytics::revenueByHour('2026-09-28', '2026-10-18');

        self::assertCount(24, $hr, '24 clés 0 à 23.');
        self::assertSame([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23], array_keys($hr));
        // 10h : 10 € + 5 € (les deux lundis).
        self::assertSame(15.0, $hr[10]['ca']);
        self::assertSame(2, $hr[10]['tx']);
        // 15h : 15 € (dimanche) + 10 € (samedi).
        self::assertSame(25.0, $hr[15]['ca']);
        // 18h : 5 € + 5 € (mercredis), 19h : 20 €.
        self::assertSame(10.0, $hr[18]['ca']);
        self::assertSame(20.0, $hr[19]['ca']);
        // Nuit : zéro.
        self::assertSame(0.0, $hr[0]['ca']);
        self::assertSame(0, $hr[0]['tx']);
    }

    public function test_product_weekly_trend_semaines_lundi_zero_remplies(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }
        $this->seedTroisSemaines();

        // Café : rapproché par DESCRIPTION (ventes sans clé canonique).
        $tw = Analytics::productWeeklyTrend('Café', '2026-09-28', '2026-10-18');

        self::assertCount(3, $tw, '3 semaines pleines dans la fenêtre.');
        self::assertSame('2026-09-28', $tw[0]['week'], 'Les buckets sont les lundis.');
        self::assertSame('2026-10-05', $tw[1]['week']);
        self::assertSame('2026-10-12', $tw[2]['week']);
        // S1 : mercredi 5 € ; S2 : dimanche 15 € ; S3 : mercredi 5 €.
        self::assertSame(5.0, $tw[0]['ca']);
        self::assertSame(2, $tw[0]['qty']);
        self::assertSame(15.0, $tw[1]['ca']);
        self::assertSame(6, $tw[1]['qty']);
        self::assertSame(5.0, $tw[2]['ca']);
    }

    public function test_product_weekly_trend_aligne_sur_le_lundi_de_la_fenetre(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }
        $this->seedTroisSemaines();

        // Fenêtre démarrant un MERCREDI : le premier bucket reste le lundi
        // de cette semaine, et la vente du mercredi 30 y est rattachée.
        $tw = Analytics::productWeeklyTrend('Café', '2026-09-30', '2026-10-18');

        self::assertSame('2026-09-28', $tw[0]['week']);
        self::assertSame(5.0, $tw[0]['ca'], 'La vente du mercredi 30/09 compte dans la semaine du lundi 28.');
    }

    public function test_product_weekly_trend_rapproche_par_cle_ou_libelle(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }
        $this->seedTroisSemaines();

        // Clé canonique « coca » : uniquement la vente portant la clé ;
        // la vente sans clé (libellé « Coca-cola ») ne correspond PAS.
        $byKey = Analytics::productWeeklyTrend('coca', '2026-09-28', '2026-10-18');
        self::assertSame(0.0, $byKey[0]['ca']);
        self::assertSame(20.0, $byKey[1]['ca'], 'Mardi 06/10 : vente à la clé « coca ».');
        self::assertSame(0.0, $byKey[2]['ca'], 'Samedi 17/10 : libellé différent de la clé.');

        // Le même produit suivi par son libellé retrouve la vente du samedi.
        $byLabel = Analytics::productWeeklyTrend('Coca-cola', '2026-09-28', '2026-10-18');
        self::assertSame(10.0, $byLabel[2]['ca'], 'Vente sans clé rapprochée par description.');
        self::assertSame(4, $byLabel[2]['qty']);
    }
}
