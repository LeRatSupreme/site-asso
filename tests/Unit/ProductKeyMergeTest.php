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
 * lignes dans les tables à product_key (PK id et PK product_key), stock de
 * référence additionné, drapeaux suivis, purge des scan_labels, fusion des
 * fiches jumelles `products`, garde-fous de saisie, et stock théorique
 * recombiné après fusion.
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
            foreach (['sales', 'purchases', 'losses', 'product_aliases', 'inventory_counts', 'product_costs', 'product_stocks', 'product_discontinued', 'product_infinite', 'product_zero_since', 'product_packs', 'shopping_checks', 'promotions', 'scan_labels', 'products', 'product_categories', 'cafeteria_orders', 'cafeteria_order_items'] as $table) {
                $pdo->query('SELECT 1 FROM `' . $table . '` LIMIT 1');
            }
        } catch (\Throwable) {
            self::markTestSkipped('Tables produits absentes de aeic_test : importez database/schema.sql.');
        }

        $this->reset($pdo, [
            'sales', 'import_batches', 'purchases', 'losses', 'product_aliases',
            'product_costs', 'inventory_counts', 'product_stocks', 'product_discontinued',
            'product_infinite', 'product_zero_since', 'product_packs', 'shopping_checks',
            'promotions', 'scan_labels', 'cafeteria_order_items', 'cafeteria_orders', 'products',
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

    private function addSale(string $description, string $productKey, ?string $category = null, ?string $soldAt = null): void
    {
        $this->pdo->prepare(
            "INSERT INTO sales (id, transaction_ref, sold_at, payment_method, quantity, description, product_key, category, price_ttc)
             VALUES (?, ?, ?, 'CARTE', 1, ?, ?, ?, 1.0)"
        )->execute([
            'sal_' . bin2hex(random_bytes(6)),
            'tx_' . bin2hex(random_bytes(8)),
            $soldAt ?? '2026-10-01 12:00:00',
            $description,
            $productKey,
            $category,
        ]);
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

    private function addInfinite(string $key, string $by = 'user_test'): void
    {
        $this->pdo->prepare('INSERT INTO product_infinite (product_key, updated_by) VALUES (?, ?)')
            ->execute([$key, $by]);
    }

    private function addZeroSince(string $key, string $at = '2026-09-05 08:00:00'): void
    {
        $this->pdo->prepare('INSERT INTO product_zero_since (product_key, zero_since) VALUES (?, ?)')
            ->execute([$key, $at]);
    }

    private function addPack(string $key, int $size): void
    {
        $this->pdo->prepare('INSERT INTO product_packs (product_key, pack_size) VALUES (?, ?)')
            ->execute([$key, $size]);
    }

    private function addCheck(string $key, string $by = 'user_test'): void
    {
        $this->pdo->prepare('INSERT INTO shopping_checks (product_key, checked_by) VALUES (?, ?)')
            ->execute([$key, $by]);
    }

    private function addPromotion(?string $key): void
    {
        $this->pdo->prepare(
            'INSERT INTO promotions (id, title, product_key, new_price) VALUES (?, ?, ?, 1.0)'
        )->execute(['prom_' . bin2hex(random_bytes(6)), 'Test', $key]);
    }

    private function addScanLabel(string $label): void
    {
        $this->pdo->prepare('INSERT INTO scan_labels (id, label) VALUES (?, ?)')
            ->execute(['scan_' . bin2hex(random_bytes(6)), $label]);
    }

    private function addFiche(string $name, int $stock = 0): string
    {
        $id = 'prod_' . bin2hex(random_bytes(6));
        $this->pdo->prepare('INSERT INTO products (id, name, price, stock) VALUES (?, ?, 1.0, ?)')
            ->execute([$id, $name, $stock]);

        return $id;
    }

    private function addOrderItem(string $productId): void
    {
        $orderId = 'ord_' . bin2hex(random_bytes(6));
        $this->pdo->prepare('INSERT INTO cafeteria_orders (id) VALUES (?)')->execute([$orderId]);
        $this->pdo->prepare(
            'INSERT INTO cafeteria_order_items (id, order_id, product_id, quantity, price) VALUES (?, ?, ?, 1, 1.0)'
        )->execute(['itm_' . bin2hex(random_bytes(6)), $orderId, $productId]);
    }

    /**
     * Ids des fiches portant exactement ce nom.
     *
     * @return list<string>
     */
    private function ficheIdsByName(string $name): array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM products WHERE name = ?');
        $stmt->execute([$name]);

        /** @var list<string> $ids */
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return $ids;
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
        self::assertSame(0, $moved['aliases_deduped'], 'Aucun doublon de libellé sur la cible : rien à consolider.');
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

    public function test_tables_pk_cle_cible_absente_sont_deplacees(): void
    {
        $this->addInfinite('Cristaline');
        $this->addZeroSince('Cristaline');
        $this->addPack('Cristaline', 6);
        $this->addCheck('Cristaline');

        $moved = ProductKeyMerge::merge('Cristaline', 'Eau', 'user_merge');

        self::assertSame(1, $moved['infinite']);
        self::assertSame(1, $moved['zero_since']);
        self::assertSame(1, $moved['packs']);
        self::assertSame(1, $moved['checks']);
        self::assertSame(['Eau'], $this->tableKeys('product_infinite'));
        self::assertSame(['Eau'], $this->tableKeys('product_zero_since'));
        self::assertSame(['Eau'], $this->tableKeys('product_packs'));
        self::assertSame(['Eau'], $this->tableKeys('shopping_checks'));
    }

    public function test_tables_pk_cle_cible_existante_conserve_la_cible(): void
    {
        $this->addPack('Cristaline', 6);
        $this->addPack('Eau', 24);
        $this->addCheck('Cristaline');
        $this->addCheck('Eau');

        $moved = ProductKeyMerge::merge('Cristaline', 'Eau', null);

        self::assertSame(0, $moved['packs'], 'La ligne cible existe : rien n\'est déplacé.');
        self::assertSame(0, $moved['checks']);

        $stmt = $this->pdo->prepare('SELECT pack_size FROM product_packs WHERE product_key = ?');
        $stmt->execute(['Eau']);
        self::assertSame(24, (int) $stmt->fetchColumn(), 'Le pack de la cible n\'est jamais écrasé.');
        self::assertSame(['Eau'], $this->tableKeys('product_packs'), 'La ligne source est supprimée.');
        self::assertSame(['Eau'], $this->tableKeys('shopping_checks'));
    }

    public function test_promotions_suivent_la_cible(): void
    {
        $this->addPromotion('Cristaline');
        $this->addPromotion(null);

        $moved = ProductKeyMerge::merge('Cristaline', 'Eau', null);

        self::assertSame(1, $moved['promotions'], 'Seule la promotion rattachée à la source est déplacée.');

        // La promotion sans produit (product_key NULL) reste en place :
        // elle ne fait pas partie des clés déplacées.
        $keys = array_values(array_filter(
            $this->tableKeys('promotions'),
            static fn ($k): bool => $k !== null
        ));
        self::assertSame(['Eau'], $keys);

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM promotions WHERE product_key IS NULL');
        self::assertSame(1, (int) $stmt->fetchColumn(), 'La promotion sans produit reste intacte.');
    }

    public function test_scan_labels_de_la_source_purges(): void
    {
        $this->addScanLabel('Cristaline');
        $this->addScanLabel('CRISTALINE 50CL');
        $this->addScanLabel('Evian');

        $moved = ProductKeyMerge::merge('Cristaline', 'Eau', null);

        self::assertSame(1, $moved['scan_labels'], 'Seul le libellé de même forme normalisée que la source est purgé.');

        /** @var list<string> $labels */
        $labels = $this->pdo->query('SELECT label FROM scan_labels ORDER BY label')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['CRISTALINE 50CL', 'Evian'], $labels);
    }

    public function test_merge_absorbe_le_libelle_mort_de_la_source(): void
    {
        // « Schweppes » seul n'est plus vendu depuis juin : sa ligne
        // « identité » est un doublon inutile — la fusion l'absorbe.
        $source = 'Schweppes';
        $target = 'Schweppes Agrumes';

        ProductAlias::save(['raw_description' => $source, 'product_key' => $source]);
        ProductAlias::save(['raw_description' => 'Schweppes Agrumes', 'product_key' => $target, 'category' => 'Boissons']);
        $this->addSale($source, $source, null, '2026-06-09 14:48:00');

        $moved = ProductKeyMerge::merge($source, $target, 'user_test');

        self::assertSame(1, $moved['sales'], 'La vente du libellé mort suit la cible.');
        self::assertSame(1, $moved['aliases_absorbed'], 'La ligne identité du libellé mort est absorbée.');
        self::assertSame(0, $moved['aliases'], 'Aucune ligne à repointer.');

        $rows = $this->pdo->query('SELECT raw_description, product_key FROM product_aliases')->fetchAll();
        self::assertCount(1, $rows, 'Une seule ligne reste : la canonique.');
        self::assertSame('Schweppes Agrumes', (string) $rows[0]['raw_description']);
        self::assertSame($target, (string) $rows[0]['product_key']);

        self::assertNull(ProductAlias::resolve('Schweppes'), 'Le libellé mort ne pointe nulle part.');
    }

    public function test_merge_conserve_un_pointeur_pour_le_libelle_encore_vendu(): void
    {
        // « Schweppes » encore actif au POS (vente récente) : sa ligne
        // suit la cible pour que le prochain import résolve vers elle.
        $source = 'Schweppes';
        $target = 'Schweppes Agrumes';

        ProductAlias::save(['raw_description' => $source, 'product_key' => $source]);
        $this->addSale($source, $source, null, date('Y-m-d H:i:s', strtotime('-5 days')));

        $moved = ProductKeyMerge::merge($source, $target, 'user_test');

        self::assertSame(1, $moved['aliases'], 'La ligne du libellé vivant est repointée.');
        self::assertSame(0, $moved['aliases_absorbed'], 'Aucune absorption pour un libellé vivant.');

        self::assertSame($target, ProductAlias::resolve('Schweppes'), 'Le libellé vivant résout vers la cible.');
    }

    public function test_merge_consolide_les_alias_en_doublon(): void
    {
        $target = 'Coca Cherry';
        $source = 'coca cherry';

        // Doublons de forme normalisée déjà sur la cible + alias de la source.
        // NB : la collation ci de raw_description interdit deux libellés ne
        // différant que par la casse (save() ferait un upsert) — les
        // doublons réels ne diffèrent que par la ponctuation/séparateurs.
        ProductAlias::save(['raw_description' => 'Coca Cherry', 'product_key' => $target, 'category' => 'Boissons']);
        ProductAlias::save(['raw_description' => 'Coca_cherry', 'product_key' => $target, 'category' => 'ex: Spécial']);
        ProductAlias::save(['raw_description' => 'Coca.cherry', 'product_key' => $source]);

        // Le libellé le plus vendu (« Coca Cherry », 3 ventes) doit survivre.
        $this->addSale('Coca Cherry', $target, 'Boissons');
        $this->addSale('Coca Cherry', $target, 'Boissons');
        $this->addSale('Coca Cherry', $target, 'Boissons');
        $this->addSale('Coca_cherry', $target, null);
        $this->addSale('Coca.cherry', $source, null);

        $moved = ProductKeyMerge::merge($source, $target, 'user_test');

        self::assertSame(1, $moved['aliases'], 'L\'alias de la source est déplacé.');
        self::assertSame(2, $moved['aliases_deduped'], 'Les deux doublons de forme normalisée sont supprimés.');

        // Un seul alias survit : le libellé le plus vendu, catégorie conservée.
        /** @var list<array<string,mixed>> $rows */
        $rows = $this->pdo->query('SELECT raw_description, category FROM product_aliases')->fetchAll();
        self::assertCount(1, $rows);
        self::assertSame('Coca Cherry', (string) $rows[0]['raw_description']);
        self::assertSame('Boissons', (string) $rows[0]['category']);

        // Ventes des libellés absorbés : catégorie vide comblée, jamais écrasée.
        $stmt = $this->pdo->prepare('SELECT description, category FROM sales ORDER BY description');
        $stmt->execute();
        $byDesc = [];
        foreach ($stmt->fetchAll() as $sale) {
            $byDesc[(string) $sale['description']] = (string) ($sale['category'] ?? '');
        }
        self::assertSame('Boissons', $byDesc['Coca_cherry']);
        self::assertSame('Boissons', $byDesc['Coca.cherry']);

        // Les orthographes supprimées restent résolvables (repli normalisé).
        self::assertSame('Coca Cherry', ProductAlias::resolve('Coca Cherry'));
        self::assertSame('Coca Cherry', ProductAlias::resolve('Coca cherry'));
        self::assertSame('Coca Cherry', ProductAlias::resolve('Coca_Cherry'));
        self::assertNull(ProductAlias::resolve('Pepsi Max'));
    }

    /**
     * @return list<array<string,string>>
     */
    private function survivorRow(string $id, string $raw, string $category, string $createdAt): array
    {
        return ['id' => $id, 'raw_description' => $raw, 'category' => $category, 'created_at' => $createdAt];
    }

    public function test_chooseSurvivor_prefere_le_libelle_le_plus_vendu(): void
    {
        $rows = [
            $this->survivorRow('a1', 'Coca Cherry', 'Boissons', '2026-09-01 00:00:00'),
            $this->survivorRow('b1', 'coca cherry', '', '2026-08-01 00:00:00'),
        ];

        $id = ProductAlias::chooseSurvivor($rows, ['coca cherry' => 5, 'Coca Cherry' => 2], 'Coca Cherry');

        self::assertSame('b1', $id, 'Le libellé le plus vendu gagne malgré les autres critères.');
    }

    public function test_chooseSurvivor_a_egalite_auto_alias_puis_categorie(): void
    {
        $rows = [
            $this->survivorRow('a1', 'coca cherry', '', '2026-08-01 00:00:00'),
            $this->survivorRow('b1', 'Coca Cherry', '', '2026-09-01 00:00:00'),
            $this->survivorRow('c1', 'Coca-cherry', 'Boissons', '2026-07-01 00:00:00'),
        ];

        // À usage égal, le libellé canonique (=== clé) bat les variantes.
        self::assertSame('b1', ProductAlias::chooseSurvivor($rows, [], 'Coca Cherry'));

        // Sans libellé canonique dans le groupe : la catégorie renseignée gagne.
        unset($rows[1]);
        self::assertSame('c1', ProductAlias::chooseSurvivor($rows, [], 'Coca Cherry'));
    }

    public function test_chooseSurvivor_a_egalite_totale_plus_ancien_puis_id(): void
    {
        $rows = [
            $this->survivorRow('z9', 'coca cherry', '', '2026-09-01 00:00:00'),
            $this->survivorRow('a2', 'Coca_cherry', '', '2026-09-01 00:00:00'),
            $this->survivorRow('m3', 'Coca-cherry', '', '2026-08-01 00:00:00'),
        ];

        // Plus ancien d'abord…
        self::assertSame('m3', ProductAlias::chooseSurvivor($rows, [], 'Coca Cherry'));

        // …puis, à ancienneté égale, l'id le plus petit (déterminisme).
        $rows[2]['created_at'] = '2026-09-01 00:00:00';
        self::assertSame('a2', ProductAlias::chooseSurvivor($rows, [], 'Coca Cherry'));
    }

    public function test_fiches_jumelles_repointees_et_source_supprimee(): void
    {
        $srcId = $this->addFiche('Cristaline', 5);
        $tgtId = $this->addFiche('Eau', 7);
        $this->addOrderItem($srcId);
        $this->addOrderItem($srcId);

        $moved = ProductKeyMerge::merge('Cristaline', 'Eau', 'user_merge');

        self::assertSame(1, $moved['fiches']);
        self::assertSame(2, $moved['order_items'], 'Les lignes de commande caisse suivent la fiche cible.');

        $stmt = $this->pdo->query('SELECT DISTINCT product_id FROM cafeteria_order_items');
        /** @var list<string> $ids */
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([$tgtId], $ids, 'Toutes les lignes pointent la fiche conservée.');

        self::assertSame([], $this->ficheIdsByName('Cristaline'), 'La fiche source est supprimée.');
        self::assertSame([$tgtId], $this->ficheIdsByName('Eau'));

        $stmt = $this->pdo->prepare('SELECT stock FROM products WHERE id = ?');
        $stmt->execute([$tgtId]);
        self::assertSame(12, (int) $stmt->fetchColumn(), 'Le stock de la fiche source est additionné à la cible.');
    }

    public function test_fiche_source_seule_renommee(): void
    {
        $id = $this->addFiche('Cristaline', 5);
        $this->addOrderItem($id);

        $moved = ProductKeyMerge::merge('Cristaline', 'Eau', null);

        self::assertSame(1, $moved['fiches']);
        self::assertSame(0, $moved['order_items'], 'Aucun repointage : l\'historique suit l\'id conservé.');
        self::assertSame([$id], $this->ficheIdsByName('Eau'), 'La fiche est renommée, id inchangé.');

        $stmt = $this->pdo->prepare('SELECT stock FROM products WHERE id = ?');
        $stmt->execute([$id]);
        self::assertSame(5, (int) $stmt->fetchColumn(), 'Le stock suit la fiche renommée.');
    }
}
