<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Model;
use App\Models\ProductKeyMerge;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests du renommage casse-seule dans la fusion de clés produits
 * (ProductKeyMerge) : les colonnes product_key sont en
 * utf8mb4_unicode_ci (insensible casse ET accents), donc fusionner
 * « Fuze Tea » → « fuze tea » doit RENOMMER les lignes, jamais
 * doubler le stock (22 → 44, l'ancien INSERT ... ON DUPLICATE KEY
 * UPDATE voyait la ligne source encore en place comme « doublon de
 * la cible ») ni supprimer la ligne (l'ancien test d'existence de
 * mergeUniqueKeyRow confondait la source et la cible à une casse
 * près : taille de pack détruite).
 *
 *  - product_stocks : une seule ligne, stock conservé ;
 *  - product_packs : ligne renommée, jamais supprimée ;
 *  - product_discontinued : drapeau renommé (casse-seule), premier
 *    auteur conservé quand les deux lignes existent binairement ;
 *  - fusion additive intacte quand source et cible existent
 *    binairement (5 + 7 → 12) ;
 *  - tables à PK id (sales, product_costs) : simple UPDATE.
 *
 * Saute automatiquement si la base aeic_test est indisponible.
 */
final class ProductKeyMergeCaseRenameTest extends TestCase
{
    use TestDatabaseTrait;

    private ?PDO $pdo = null;

    private function requireDatabase(): bool
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped('Base aeic_test indisponible : test de renommage casse-seule ignoré.');

            return false;
        }
        $this->pdo = $pdo;

        // Toutes les tables touchées par ProductKeyMerge::merge : si
        // l'une manque, la fusion échouerait à mi-course (transaction
        // annulée) et le test ne mesurerait rien.
        try {
            foreach ([
                'sales', 'purchases', 'losses', 'product_aliases', 'inventory_counts',
                'product_costs', 'product_stocks', 'promotions', 'inventory_tablet_lines',
                'product_discontinued', 'product_infinite', 'product_zero_since',
                'product_packs', 'shopping_checks', 'products', 'cafeteria_order_items',
            ] as $table) {
                $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
            }
        } catch (\Throwable) {
            self::markTestSkipped("Table produits absente de aeic_test : importez database/schema.sql.");

            return false;
        }

        $this->reset($pdo, [
            'sales', 'import_batches', 'purchases', 'losses', 'product_aliases',
            'product_costs', 'inventory_counts', 'product_stocks', 'promotions',
            'inventory_tablet_lines', 'product_discontinued', 'product_infinite',
            'product_zero_since', 'product_packs', 'shopping_checks',
            'cafeteria_order_items', 'cafeteria_orders', 'products',
        ]);
        // scan_labels est optionnelle (purge tolérante dans merge) :
        // purgée seulement si elle existe.
        try {
            $pdo->exec('TRUNCATE TABLE scan_labels');
        } catch (\Throwable) {
            // Table absente du schéma de test : non bloquant.
        }

        Model::setTestPdo($pdo);

        return true;
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
    }

    public function test_stock_casse_seule_renomme_sans_doubler(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $this->pdo->prepare('INSERT INTO product_stocks (product_key, stock) VALUES (?, ?)')
            ->execute(['Fuze Tea', 22]);

        $moved = ProductKeyMerge::merge('Fuze Tea', 'fuze tea', null);

        self::assertSame(1, $moved['stocks'], 'La ligne de stock source est absorbée.');

        /** @var list<array<string,mixed>> $rows */
        $rows = $this->pdo->query('SELECT product_key, stock FROM product_stocks')->fetchAll();
        self::assertCount(1, $rows, 'Une seule ligne de stock après fusion casse-seule (jamais 44).');
        self::assertSame('fuze tea', (string) $rows[0]['product_key'], 'La ligne porte la casse cible.');
        self::assertSame(22, (int) $rows[0]['stock'], 'Le stock est conservé, pas compté deux fois.');
    }

    public function test_pack_casse_seule_renomme_et_pas_supprime(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $this->pdo->prepare('INSERT INTO product_packs (product_key, pack_size) VALUES (?, ?)')
            ->execute(['Fuze Tea', 6]);

        $moved = ProductKeyMerge::merge('Fuze Tea', 'fuze tea', null);

        self::assertSame(1, $moved['packs'], 'La ligne de pack est renommée, pas supprimée.');

        /** @var list<array<string,mixed>> $rows */
        $rows = $this->pdo->query('SELECT product_key, pack_size FROM product_packs')->fetchAll();
        self::assertCount(1, $rows, 'La taille de pack survit au renommage casse-seule.');
        self::assertSame('fuze tea', (string) $rows[0]['product_key']);
        self::assertSame(6, (int) $rows[0]['pack_size'], 'La valeur du pack est conservée à l\'identique.');
    }

    public function test_fusion_additive_intacte_quand_les_deux_existent_binairement(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $ins = $this->pdo->prepare('INSERT INTO product_stocks (product_key, stock) VALUES (?, ?)');
        $ins->execute(['Red Bull', 5]);
        $ins->execute(['RedBull', 7]);

        $moved = ProductKeyMerge::merge('Red Bull', 'RedBull', null);

        self::assertSame(1, $moved['stocks']);

        /** @var list<array<string,mixed>> $rows */
        $rows = $this->pdo->query('SELECT product_key, stock FROM product_stocks')->fetchAll();
        self::assertCount(1, $rows, 'La cible binaire fait foi, la source disparaît.');
        self::assertSame('RedBull', (string) $rows[0]['product_key']);
        self::assertSame(12, (int) $rows[0]['stock'], '5 + 7 : la fusion additive reste intacte.');
    }

    public function test_ventes_et_couts_suivent_la_nouvelle_casse(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $this->pdo->prepare(
            "INSERT INTO sales (id, transaction_ref, sold_at, payment_method, quantity, description, product_key, price_ttc)
             VALUES (?, ?, '2026-10-01 12:00:00', 'CARTE', 1, ?, ?, 1.0)"
        )->execute(['sal_case_1', 'tx_case_1', 'Fuze Tea', 'Fuze Tea']);
        $this->pdo->prepare(
            "INSERT INTO product_costs (id, product_key, cost_price, valid_from, created_at)
             VALUES ('cost_case_1', 'Fuze Tea', 1.5, '2026-09-01', NOW())"
        )->execute();

        $moved = ProductKeyMerge::merge('Fuze Tea', 'fuze tea', null);

        self::assertSame(1, $moved['sales'], 'La vente suit la nouvelle casse (UPDATE simple).');
        self::assertSame(1, $moved['costs'], 'Le lot de coût suit la nouvelle casse.');

        self::assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM sales WHERE product_key = 'fuze tea' COLLATE utf8mb4_bin")->fetchColumn(),
            'La vente porte la nouvelle clé.'
        );
        self::assertSame(
            'fuze tea',
            (string) $this->pdo->query("SELECT product_key FROM product_costs WHERE id = 'cost_case_1'")->fetchColumn(),
            'Le lot de coût porte la nouvelle clé.'
        );
    }

    public function test_drapeau_discontinued_casse_seule_renomme_et_pas_perdu(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $this->pdo->prepare('INSERT INTO product_discontinued (product_key, updated_by) VALUES (?, ?)')
            ->execute(['Fuze Tea', 'u1']);

        $moved = ProductKeyMerge::merge('Fuze Tea', 'fuze tea', null);

        self::assertSame(1, $moved['discontinued'], 'La ligne de drapeau est renommée, pas supprimée.');

        self::assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM product_discontinued WHERE product_key = 'fuze tea' COLLATE utf8mb4_bin")->fetchColumn(),
            'Le drapeau existe toujours sous la nouvelle casse.'
        );
        self::assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM product_discontinued WHERE product_key = 'Fuze Tea' COLLATE utf8mb4_bin")->fetchColumn(),
            'L\'ancienne casse ne laisse aucune ligne.'
        );

        $author = $this->pdo->query("SELECT updated_by FROM product_discontinued WHERE product_key = 'fuze tea' COLLATE utf8mb4_bin")->fetchColumn();
        self::assertSame('u1', (string) $author, 'L\'auteur suit la ligne renommée.');
    }

    public function test_drapeau_discontinued_deux_lignes_premier_auteur_conserve(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $ins = $this->pdo->prepare('INSERT INTO product_discontinued (product_key, updated_by) VALUES (?, ?)');
        $ins->execute(['Red Bull', 'a']);
        $ins->execute(['RedBull', null]);

        $moved = ProductKeyMerge::merge('Red Bull', 'RedBull', null);

        self::assertSame(1, $moved['discontinued'], 'La ligne source est absorbée.');

        /** @var list<array<string,mixed>> $rows */
        $rows = $this->pdo->query('SELECT product_key, updated_by FROM product_discontinued')->fetchAll();
        self::assertCount(1, $rows, 'Une seule ligne de drapeau après fusion.');
        self::assertSame('RedBull', (string) $rows[0]['product_key']);
        self::assertSame('a', (string) $rows[0]['updated_by'], 'Premier auteur conservé, jamais écrasé.');
    }
}
