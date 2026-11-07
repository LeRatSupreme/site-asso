<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Compta\CardFees;
use App\Models\ImportBatch;
use App\Models\Model;
use App\Models\Sale;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests de l'estimation des commissions SumUp (ventes carte).
 *
 * Pure : analyse/bornage du taux saisi dans les réglages.
 * Base : le frais est estimé transaction par transaction, arrondi au
 * centime comme SumUp (vérifié sur un lot réel : 28,73 + 30,52 +
 * 32,98 + 5,69 € à 1,75 % → 0,50 + 0,53 + 0,58 + 0,10 = 1,71 €).
 *
 * Saute automatiquement si la base aeic_test est indisponible.
 */
final class CardFeesTest extends TestCase
{
    use TestDatabaseTrait;

    // ── Taux : analyse et bornage (sans base) ──────────────────────────

    public function test_parse_rate_accepte_virgule_et_point(): void
    {
        self::assertSame(1.75, CardFees::parseRate('1,75'));
        self::assertSame(1.75, CardFees::parseRate('1.75'));
        self::assertSame(2.0, CardFees::parseRate('2'));
        self::assertSame(2.5, CardFees::parseRate(' 2,50 '));
    }

    public function test_parse_rate_rejete_les_valeurs_invalides(): void
    {
        self::assertSame(CardFees::DEFAULT_RATE, CardFees::parseRate(''), 'Vide → défaut.');
        self::assertSame(CardFees::DEFAULT_RATE, CardFees::parseRate('abc'), 'Non numérique → défaut.');
        self::assertSame(CardFees::DEFAULT_RATE, CardFees::parseRate('-3'), 'Négatif → défaut.');
        self::assertSame(CardFees::DEFAULT_RATE, CardFees::parseRate('175'), '> 100 → défaut.');
        self::assertSame(CardFees::DEFAULT_RATE, CardFees::parseRate('1,75 %'), 'Suffixe non numérique → défaut.');
    }

    public function test_parse_rate_accepte_les_bornes(): void
    {
        self::assertSame(0.0, CardFees::parseRate('0'));
        self::assertSame(100.0, CardFees::parseRate('100'));
    }

    // ── Estimation transaction par transaction (base aeic_test) ───────

    private function requireDatabase(): bool
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped('Base aeic_test indisponible : test de frais ignoré.');

            return false;
        }

        try {
            $pdo->query('SELECT 1 FROM sales LIMIT 1');
        } catch (\Throwable) {
            self::markTestSkipped('Table sales absente de aeic_test : importez database/schema.sql.');

            return false;
        }

        $this->reset($pdo, ['sales', 'import_batches', 'product_aliases']);
        $this->applyImportDedupMigration($pdo);
        Model::setTestPdo($pdo);

        return true;
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
    }

    private function cardRow(string $ref, float $price): array
    {
        return [
            'transaction_ref'  => $ref,
            'sold_at'          => '2026-09-21 12:00:00',
            'payment_method'   => 'CARTE',
            'payment_raw'      => 'Visa - Débit',
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
    }

    public function test_frais_estimes_transaction_par_transaction(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        // Lot réel de versement SumUp (4 paiements regroupés)
        // + une vente liquide qui ne doit JAMAIS porter de frais carte.
        $liquideRow = $this->cardRow('TFEE5', 12.5);
        $liquideRow['payment_method'] = 'LIQUIDE';
        $liquideRow['payment_raw'] = 'Espèces';
        $batchId = ImportBatch::create(['filename' => 'frais-test.csv']);
        Sale::importBatch($batchId, [
            $this->cardRow('TFEE1', 28.73),
            $this->cardRow('TFEE2', 30.52),
            $this->cardRow('TFEE3', 32.98),
            $this->cardRow('TFEE4', 5.69),
            $liquideRow,
        ]);

        // Le frais est arrondi PAR transaction : 0,50 + 0,53 + 0,58 + 0,10 = 1,71 €.
        self::assertSame(1.71, Sale::sumCardFeeEstimate(1.75));
        self::assertSame(1.71, Sale::sumCardFeeEstimate(1.75, '2026-09-21', '2026-09-21'));
        self::assertSame(0.0, Sale::sumCardFeeEstimate(1.75, '2026-09-22', null), 'Hors période : zéro.');
    }

    public function test_net_estime_apres_frais(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $batchId = ImportBatch::create(['filename' => 'frais-net.csv']);
        Sale::importBatch($batchId, [$this->cardRow('TNET1', 100.0)]);

        // 100 € bruts − 1,75 € de frais = 98,25 € nets.
        self::assertSame(98.25, CardFees::estimatedNet());
    }
}
