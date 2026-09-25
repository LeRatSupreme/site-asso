<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Compta\SumUpApiClient;
use App\Core\Compta\SumUpSalesSync;
use App\Models\ImportBatch;
use App\Models\Model;
use App\Models\Sale;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la synchro automatique des ventes SumUp (API â†’ table `sales`).
 *
 * Les tests HTTP utilisent un transport factice (aucun socket, cf. les
 * conventions du projet) dont les rÃ©ponses reproduisent fidÃ¨lement les
 * charges utiles rÃ©elles de l'API SumUp v2.1.
 *
 * Les tests de persistance sautent si la base aeic_test est indisponible.
 */
final class SumUpSalesSyncTest extends TestCase
{
    use TestDatabaseTrait;

    /** Transaction rÃ©elle (vente cash d'un produit du catalogue). */
    private const TX_MONSTER = [
        'amount'            => 1.8,
        'currency'          => 'EUR',
        'id'                => 'b6c64e5f-9665-4898-85d3-40ce988673da',
        'payment_type'      => 'CASH',
        'product_summary'   => '1 x Monster Blanche',
        'status'            => 'SUCCESSFUL',
        'timestamp'         => '2026-09-21T10:16:14.714Z',
        'transaction_code'  => 'TAAA6HD6QEE',
        'transaction_id'    => 'b6c64e5f-9665-4898-85d3-40ce988673da',
        'type'              => 'PAYMENT',
        'user'              => 'alexis.blouin26@gmail.com',
        'local_time'        => '2026-09-21T12:16:14.714+02:00',
        'simple_payment_type' => 'CASH',
        'products'          => [
            [
                'name'           => 'Monster Blanche',
                'price'          => 1.8,
                'quantity'       => 1,
                'total_price'    => 1.8,
                'total_with_vat' => 1.8,
                'vat_amount'     => 0.0,
            ],
        ],
    ];

    /** Transaction rÃ©elle (paiement carte sans dÃ©tail panier). */
    private const TX_CARTE = [
        'amount'           => 1.0,
        'currency'         => 'EUR',
        'id'               => '7d947cbe-f4e6-4a5f-9518-bf83f97ad67e',
        'payment_type'     => 'POS',
        'product_summary'  => 'Dr Pepper',
        'status'           => 'SUCCESSFUL',
        'timestamp'        => '2026-09-21T08:07:00.798Z',
        'transaction_code' => 'TAAA6HCQDVP',
        'transaction_id'   => '7d947cbe-f4e6-4a5f-9518-bf83f97ad67e',
        'type'             => 'PAYMENT',
        'user'             => 'alexis.blouin26@gmail.com',
        'local_time'       => '2026-09-21T10:07:00.798+02:00',
    ];

    // â”€â”€ Mapping API â†’ lignes du parseur CSV â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function test_map_transaction_avec_produits(): void
    {
        $rows = SumUpSalesSync::mapTransaction(self::TX_MONSTER);

        self::assertCount(1, $rows);
        $r = $rows[0];
        self::assertSame('TAAA6HD6QEE', $r['transaction_ref']);
        self::assertSame('2026-09-21 12:16:14', $r['sold_at'], 'Heure locale du point de vente, comme la colonne Date du CSV.');
        self::assertSame('LIQUIDE', $r['payment_method']);
        self::assertSame('CASH', $r['payment_raw']);
        self::assertSame('Monster Blanche', $r['description']);
        self::assertSame(1, $r['quantity']);
        self::assertSame(1.8, $r['price_ttc']);
        self::assertSame(1.8, $r['price_ht']);
        self::assertSame('EUR', $r['currency']);
        self::assertSame('alexis.blouin26@gmail.com', $r['seller_account']);
        self::assertSame(0, $r['is_custom_amount']);
    }

    public function test_map_transaction_paiement_carte(): void
    {
        $rows = SumUpSalesSync::mapTransaction(self::TX_CARTE);

        self::assertSame('CARTE', $rows[0]['payment_method']);
        self::assertSame('POS', $rows[0]['payment_raw']);
        self::assertSame(1.0, $rows[0]['price_ttc']);
        self::assertSame('Dr Pepper', $rows[0]['description']);
        self::assertSame(1, $rows[0]['is_custom_amount'], 'Sans panier : montant unique (saisie libre).');
    }

    public function test_map_transaction_multi_produits(): void
    {
        $tx = self::TX_MONSTER;
        $tx['transaction_code'] = 'TAAA6ZZZ999';
        $tx['transaction_id'] = '11111111-2222-3333-4444-555555555555';
        $tx['products'] = [
            ['name' => 'Bonbon', 'price' => 0.5, 'quantity' => 2, 'total_price' => 1.0, 'total_with_vat' => 1.0, 'vat_amount' => 0.0],
            ['name' => 'Bueno', 'price' => 1.0, 'quantity' => 1, 'total_price' => 1.0, 'total_with_vat' => 1.0, 'vat_amount' => 0.0],
        ];

        $rows = SumUpSalesSync::mapTransaction($tx);

        self::assertCount(2, $rows, 'Un panier produit une ligne par produit, comme le CSV.');
        self::assertSame('Bonbon', $rows[0]['description']);
        self::assertSame(2, $rows[0]['quantity']);
        self::assertSame(1.0, $rows[0]['price_ttc']);
        self::assertSame('Bueno', $rows[1]['description']);
        self::assertSame('TAAA6ZZZ999', $rows[0]['transaction_ref']);
        self::assertSame('TAAA6ZZZ999', $rows[1]['transaction_ref']);
    }

    public function test_map_transaction_sans_reference_renvoie_vide(): void
    {
        self::assertSame([], SumUpSalesSync::mapTransaction(['status' => 'SUCCESSFUL']));
    }

    public function test_map_transaction_sans_heure_locale_replie_sur_utc(): void
    {
        $tx = self::TX_MONSTER;
        unset($tx['local_time']);

        $rows = SumUpSalesSync::mapTransaction($tx);

        self::assertSame('2026-09-21 12:16:14', $rows[0]['sold_at'], 'UTC 10:16 â†’ Europe/Paris 12:16 (Ã©tÃ©).');
    }

    public function test_map_transaction_resoud_le_product_key(): void
    {
        $rows = SumUpSalesSync::mapTransaction(self::TX_MONSTER, static fn (string $d): ?string => $d === 'Monster Blanche' ? 'monster-blanche' : null);

        self::assertSame('monster-blanche', $rows[0]['product_key']);
    }

    // â”€â”€ Client HTTP (transport factice) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function test_client_decouvre_le_code_marchand(): void
    {
        $calls = [];
        $client = new SumUpApiClient('sup_sk_test', null, function (string $url) use (&$calls): array {
            $calls[] = $url;

            return ['merchant_profile' => ['merchant_code' => 'MCMNHAA3']];
        });

        self::assertSame('MCMNHAA3', $client->merchantCode());
        self::assertCount(1, $calls);
        self::assertStringEndsWith('/v0.1/me', $calls[0]);
    }

    public function test_client_history_sans_pagination(): void
    {
        $client = new SumUpApiClient('sup_sk_test', 'MCMNHAA3', fn (): array => ['items' => [self::TX_MONSTER], 'links' => []]);

        $items = $client->history(new \DateTimeImmutable('-24 hours'));

        self::assertCount(1, $items);
        self::assertSame('TAAA6HD6QEE', $items[0]['transaction_code']);
    }

    public function test_client_history_suit_la_pagination(): void
    {
        $pages = [
            ['items' => [self::TX_MONSTER], 'links' => [['rel' => 'next', 'href' => 'limit=200&newest_ref=abc&order=descending']]],
            ['items' => [self::TX_CARTE], 'links' => []],
        ];
        $i = 0;
        $urls = [];
        $client = new SumUpApiClient('sup_sk_test', 'MCMNHAA3', function (string $url) use (&$i, &$urls, $pages): array {
            $urls[] = $url;

            return $pages[$i++];
        });

        $items = $client->history(new \DateTimeImmutable('-24 hours'));

        self::assertCount(2, $items);
        self::assertCount(2, $urls);
        self::assertStringContainsString('transactions/history?', $urls[1]);
        self::assertStringContainsString('newest_ref=abc', $urls[1], 'Le lien rel=next relatif est rÃ©solu sur le mÃªme endpoint.');
    }

    // â”€â”€ Synchro complÃ¨te avec base (aeic_test) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    private function makeSyncClient(array $historyItems): SumUpApiClient
    {
        return new SumUpApiClient('sup_sk_test', 'MCMNHAA3', function (string $url) use ($historyItems): array {
            if (str_contains($url, '/transactions/history')) {
                return ['items' => $historyItems, 'links' => []];
            }

            // DÃ©tail : retrouve la transaction par son id.
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            foreach ($historyItems as $item) {
                if (($item['transaction_id'] ?? null) === ($q['id'] ?? null)) {
                    return $item;
                }
            }

            throw new \RuntimeException('Transaction inconnue dans le transport factice.');
        });
    }

    private function syncRow(string $ref, string $description): array
    {
        // Ligne Ã©quivalente Ã  un import CSV de la mÃªme vente.
        return [
            'transaction_ref'  => $ref,
            'sold_at'          => '2026-09-21 12:16:14',
            'payment_method'   => 'LIQUIDE',
            'payment_raw'      => 'Cash',
            'quantity'         => 1,
            'description'      => $description,
            'product_key'      => null,
            'category'         => null,
            'sku'              => null,
            'currency'         => 'EUR',
            'price_ttc'        => 1.8,
            'price_ht'         => 1.8,
            'vat'              => 0.0,
            'vat_rate'         => null,
            'seller_account'   => 'Alex',
            'is_custom_amount' => 0,
        ];
    }

    /**
     * PrÃ©pare la base de test (tables vides + PDO injectÃ© dans les modÃ¨les).
     * Renvoie false (et marque le test Â« skipped Â») si elle est indisponible.
     */
    private function requireDatabase(): bool
    {
        $pdo = $this->connect();
        if ($pdo === null || !$this->tablesExist($pdo)) {
            self::markTestSkipped('Base aeic_test indisponible : test de persistance ignorÃ©.');

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

    private function tablesExist(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT 1 FROM sales LIMIT 1');
            $pdo->query('SELECT 1 FROM import_batches LIMIT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function test_sync_insere_les_nouvelles_ventes(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $sync = new SumUpSalesSync($this->makeSyncClient([self::TX_MONSTER, self::TX_CARTE]));

        $stats = $sync->sync(new \DateTimeImmutable('-24 hours'));

        self::assertSame(2, $stats['fetched']);
        self::assertSame(2, $stats['sales']);
        self::assertSame(2, $stats['inserted']);
        self::assertNotNull($stats['batch_id']);
        self::assertSame(2, Sale::count());

        // Le lot de synchro est tracÃ© avec sa pÃ©riode (anti-chevauchement CSV).
        $batch = ImportBatch::find((string) $stats['batch_id']);
        self::assertNotNull($batch);
        self::assertSame('synchro-api-sumup.json', $batch['filename']);
        self::assertSame('2026-09-21', $batch['period_start']);
        self::assertSame('2026-09-21', $batch['period_end']);
    }

    public function test_sync_est_idempotent(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $client = $this->makeSyncClient([self::TX_MONSTER]);
        $sync = new SumUpSalesSync($client);

        $first = $sync->sync(new \DateTimeImmutable('-24 hours'));
        $second = $sync->sync(new \DateTimeImmutable('-24 hours'));

        self::assertSame(1, $first['inserted']);
        self::assertSame(0, $second['sales'], 'La transaction dÃ©jÃ  en base ne doit plus Ãªtre candidate.');
        self::assertSame(0, $second['inserted']);
        self::assertSame(1, Sale::count(), 'Aucune vente dupliquÃ©e.');
    }

    public function test_sync_ignore_les_ventes_deja_importees_par_csv(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $batchId = ImportBatch::create(['filename' => 'rapport.csv']);
        Sale::importBatch($batchId, [$this->syncRow('TAAA6HD6QEE', 'Monster Blanche')]);

        $sync = new SumUpSalesSync($this->makeSyncClient([self::TX_MONSTER]));
        $stats = $sync->sync(new \DateTimeImmutable('-24 hours'));

        self::assertSame(0, $stats['sales'], 'RÃ©f. dÃ©jÃ  comptabilisÃ©e par le CSV â†’ ignorÃ©e.');
        self::assertSame(1, Sale::count(), 'Aucun doublon CSV/synchro.');
    }

    public function test_sync_ignore_les_remboursements_et_paiements_echoues(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $refund = [
            'amount'           => 1.8,
            'currency'         => 'EUR',
            'id'               => 'refund-1111',
            'payment_type'     => 'POS',
            'status'           => 'SUCCESSFUL',
            'timestamp'        => '2026-09-21T10:20:00.000Z',
            'transaction_code' => 'TAAA6RFD001',
            'transaction_id'   => 'refund-1111',
            'type'             => 'REFUND',
            'local_time'       => '2026-09-21T12:20:00.714+02:00',
        ];
        $failed = [
            'amount'           => 1.8,
            'currency'         => 'EUR',
            'id'               => 'failed-2222',
            'payment_type'     => 'POS',
            'status'           => 'FAILED',
            'timestamp'        => '2026-09-21T10:25:00.000Z',
            'transaction_code' => 'TAAA6FAI001',
            'transaction_id'   => 'failed-2222',
            'type'             => 'PAYMENT',
            'local_time'       => '2026-09-21T12:25:00.714+02:00',
        ];

        $sync = new SumUpSalesSync($this->makeSyncClient([$refund, $failed, self::TX_MONSTER]));

        $stats = $sync->sync(new \DateTimeImmutable('-24 hours'));

        self::assertSame(3, $stats['fetched']);
        self::assertSame(1, $stats['sales'], 'Seul le PAYMENT SUCCESSFUL est candidat (remboursement et échec ignorés).');
        self::assertSame(1, $stats['inserted']);
        self::assertSame(1, Sale::count(), 'Un remboursement ne doit jamais créer de ligne de vente.');
    }

    public function test_sync_dry_run_n_ecrit_rien(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $sync = new SumUpSalesSync($this->makeSyncClient([self::TX_MONSTER]));

        $stats = $sync->sync(new \DateTimeImmutable('-24 hours'), 200, true);

        self::assertSame(1, $stats['sales']);
        self::assertSame(0, $stats['inserted']);
        self::assertNull($stats['batch_id']);
        self::assertSame(0, Sale::count());
        self::assertSame([], ImportBatch::all());
    }

    public function test_sync_sans_ventes_nouvelle_ne_cree_pas_de_lot(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $sync = new SumUpSalesSync($this->makeSyncClient([]));

        $stats = $sync->sync(new \DateTimeImmutable('-24 hours'));

        self::assertSame(0, $stats['fetched']);
        self::assertNull($stats['batch_id']);
        self::assertSame([], ImportBatch::all());
    }
}
