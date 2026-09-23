<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\InventoryCount;
use App\Models\Model;
use App\Models\ProductAlias;
use App\Models\ProductCost;
use App\Models\ProductKeyMerge;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la fusion de clés produits (ProductKeyMerge) : déplacement des
 * lignes dans les 7 tables à product_key, stock de référence additionné,
 * drapeau « plus en vente » suivi, garde-fous de saisie, et stock
 * théorique recombiné après fusion.
 *
 * Saute automatiquement si la base aeic_test est indisponible.
 */
final class ProductKeyMergeTest extends TestCase
{
    use TestDatabaseTrait;

    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped('Base aeic_test indisponible : configurez DB_* dans phpunit.xml.');
        }
        $this->pdo = $pdo;

        try {
            foreach (['sales', 'purchases', 'losses', 'product_aliases', 'inventory_counts', 'product_costs', 'product_stocks', 'product_discontinued'] as $table) {
                $pdo->query('SELECT 1 FROM `' . $table . '` LIMIT 1');
            }
        } catch (\Throwable) {
            self::markTestSkipped('Tables produits absentes de aeic_test : importez database/schema.sql.');
        }

        $this->reset($pdo, [
            'sales', 'import_batches', 'purchases', 'losses', 'product_aliases',
            'product_costs', 'inventory_counts', 'product_stocks', 'product_discontinued',
        ]);
        Model::setTestPdo($pdo);
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
    }

    private function addPurchase(string $key, int $qty, string $date): void
    {
        $this->pdo->prepare(
            'INSERT INTO purchases (id, purchased_at, product_key, quantity, unit_cost, total_ttc, created_at)
             VALUES (?, ?, ?, ?, 1.0, 1.0, NOW())'
        )->execute(['pur_' . bin2hex(random_bytes(6)), $date, $key, $qty]);
    }

    private function addLoss(string $key, int $qty, string $date): void
    {
        $this->pdo->prepare(
            "INSERT INTO losses (id, lost_at, product_key, quantity, reason, created_at)
             VALUES (?, ?, ?, ?, 'CASSE', NOW())"
        )->execute(['los_' . bin2hex(random_bytes(6)), $date, $key, $qty]);
    }

    private function addCount(string $key, int $qty, string $at): void
    {
        $this->pdo->prepare(
            'INSERT INTO inventory_counts (id, counted_at, product_key, counted_qty, theoretical_qty, gap, created_at)
             VALUES (?, ?, ?, ?, ?, 0, NOW())'
        )->execute(['inv_' . bin2hex(random_bytes(6)), $at, $key, $qty, $qty]);
    }

    private function addStock(string $key, int $stock): void
    {
        $this->pdo->prepare('INSERT INTO product_stocks (product_key, stock) VALUES (?, ?)')
            ->execute([$key, $stock]);
    }

    private function addCostLot(string $key, string $validFrom): void
    {
        $this->pdo->prepare(
            'INSERT INTO product_costs (id, product_key, cost_price, valid_from, created_at)
             VALUES (?, ?, 1.5, ?, NOW())'
        )->execute(['cost_' . bin2hex(random_bytes(6)), $key, $validFrom]);
    }

    /**
     * Clés distinctes présentes dans une table, triées.
     *
     * @return list<string>
     */
    private function tableKeys(string $table): array
    {
        /** @var list<string> $keys */
        $keys = $this->pdo->query('SELECT DISTINCT product_key FROM `' . $table . '`')->fetchAll(PDO::FETCH_COLUMN);
        sort($keys);

        return $keys;
    }

    public function test_merge_deplace_lignes_et_supprime_la_source(): void
    {
        $source = 'Pulco Citronnade';
        $target = 'pulco';

        $this->addPurchase($source, 2, '2026-09-02');
        $this->addLoss($source, 1, '2026-09-04');
        ProductAlias::save(['raw_description' => 'pulco citron', 'product_key' => $source]);
        $this->addCount($source, 3, '2026-09-01 10:00:00');

        $moved = ProductKeyMerge::merge($source, $target, 'user_test');

        self::assertSame(1, $moved['purchases']);
        self::assertSame(1, $moved['losses']);
        self::assertSame(1, $moved['aliases']);
        self::assertSame(1, $moved['counts']);
        self::assertSame(0, $moved['costs'], 'Aucun lot de coût : rien à déplacer.');
        self::assertSame(0, $moved['stocks'], 'Aucun stock de référence : rien à additionner.');
        self::assertSame(0, $moved['discontinued'], 'Aucun drapeau : rien à déplacer.');

        self::assertSame([$target], $this->tableKeys('purchases'));
        self::assertSame([$target], $this->tableKeys('losses'));
        self::assertSame([$target], $this->tableKeys('product_aliases'));
        self::assertSame([$target], $this->tableKeys('inventory_counts'));

        self::assertContains($target, ProductKeyMerge::allKeys());
        self::assertNotContains($source, ProductKeyMerge::allKeys(), 'La source ne doit plus exister nulle part.');
    }

    public function test_lots_de_couts_suivent_la_cible(): void
    {
        $source = 'Pulco Citronnade';
        $target = 'pulco';

        $this->addCostLot($source, '2026-09-01');

        $moved = ProductKeyMerge::merge($source, $target, null);

        self::assertSame(1, $moved['costs'], 'Le lot de coût source doit être déplacé.');
        self::assertSame([$target], $this->tableKeys('product_costs'));

        $lot = ProductCost::lotAt($target, '2026-09-15');
        self::assertNotNull($lot, 'La cible porte le coût après fusion.');
        self::assertSame('1.500', (string) $lot['cost_price']);
    }

    public function test_stocks_additionnes_et_ligne_source_supprimee(): void
    {
        $this->addStock('Pulco Citronnade', 5);
        $this->addStock('pulco', 7);

        ProductKeyMerge::merge('Pulco Citronnade', 'pulco', null);

        $stmt = $this->pdo->prepare('SELECT stock FROM product_stocks WHERE product_key = ?');
        $stmt->execute(['pulco']);
        self::assertSame(12, (int) $stmt->fetchColumn(), 'Les stocks source et cible doivent s\'additionner.');

        self::assertSame(['pulco'], $this->tableKeys('product_stocks'), 'La ligne source doit être supprimée.');
    }

    public function test_drapeau_plus_en_vente_suit_la_cible(): void
    {
        $this->pdo->prepare('INSERT INTO product_discontinued (product_key, updated_by) VALUES (?, ?)')
            ->execute(['Pulco Citronnade', 'user_orig']);

        ProductKeyMerge::merge('Pulco Citronnade', 'pulco', 'user_merge');

        $stmt = $this->pdo->prepare('SELECT updated_by FROM product_discontinued WHERE product_key = ?');
        $stmt->execute(['pulco']);
        self::assertSame('user_orig', $stmt->fetchColumn(), 'Le drapeau suit la cible, premier auteur conservé.');

        self::assertSame(['pulco'], $this->tableKeys('product_discontinued'));
    }

    public function test_source_identique_a_la_cible_refusee(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProductKeyMerge::merge('Coca', 'Coca', null);
    }

    public function test_source_vide_refusee(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProductKeyMerge::merge('   ', 'pulco', null);
    }

    public function test_theoretical_stocks_map_apres_fusion(): void
    {
        // Source : comptée 3 le 09-01, puis achat +4 et perte −1 → théorique 6.
        $this->addCount('Pulco Citronnade', 3, '2026-09-01 10:00:00');
        $this->addPurchase('Pulco Citronnade', 4, '2026-09-02');
        $this->addLoss('Pulco Citronnade', 1, '2026-09-04');
        // Cible : jamais comptée, un achat +2 → théorique 2 (base 0).
        $this->addPurchase('pulco', 2, '2026-09-02');

        $before = InventoryCount::theoreticalStocksMap();
        self::assertSame(6, $before['Pulco Citronnade']);
        self::assertSame(2, $before['pulco']);

        ProductKeyMerge::merge('Pulco Citronnade', 'pulco', null);

        $map = InventoryCount::theoreticalStocksMap();
        self::assertSame(['pulco' => 8], $map, 'La cible porte le théorique combiné (6 + 2), la source a disparu.');
    }

    public function test_keyStats_compte_les_lignes_par_cle(): void
    {
        $this->addPurchase('Madel Coquille', 50, '2026-09-02');
        $this->addStock('Madel Coquille', 50);
        $this->addLoss('Madeleine', 1, '2026-09-04');

        $stats = ProductKeyMerge::keyStats();

        self::assertSame(1, $stats['Madel Coquille']['purchases']);
        self::assertSame(50, $stats['Madel Coquille']['stock']);
        self::assertSame(0, $stats['Madel Coquille']['sales']);
        self::assertFalse($stats['Madel Coquille']['discontinued']);

        self::assertSame(1, $stats['Madeleine']['losses']);
        self::assertSame(0, $stats['Madeleine']['purchases']);
        self::assertNull($stats['Madeleine']['stock'], 'Aucune ligne product_stocks : stock null.');
    }

    public function test_theoretical_base_zero_sur_achat_sans_comptage(): void
    {
        // Clé jamais comptée : l'achat pose une base 0, le théorique existe.
        $this->addPurchase('Nesquik', 24, '2026-09-10');

        $map = InventoryCount::theoreticalStocksMap();
        self::assertSame(24, $map['Nesquik'] ?? 0);
    }
}
