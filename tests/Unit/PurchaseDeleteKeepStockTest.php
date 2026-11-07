<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Admin\AdminStockController;
use App\Models\Model;
use App\Models\ProductStock;
use App\Models\Purchase;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Option « ne pas corriger le stock » (keep_stock) de la cascade de
 * suppression d'achats (AdminStockController::deletePurchaseLines) :
 *
 *  - défaut (false / paramètre absent) : comportement historique — le
 *    stock de référence des lignes non « hors stock » est contre-passé
 *    (−qty) et stock_kept_lines vaut 0 ;
 *  - keepStock = true : AUCUN ProductStock::adjust, même pour un achat
 *    en stock (resaisie de facture déjà comptabilisée, stock physique
 *    correct) ; chaque ligne non « hors stock » est comptée dans
 *    stock_kept_lines, les lignes « hors stock » restent comptées dans
 *    no_stock_lines.
 *
 * Le contrôleur n'a pas de constructeur : la méthode privée est appelée
 * par réflexion, avec les mêmes lignes complètes (Purchase::find) que
 * deletePurchase() / deletePurchaseGroup() (recette CostLotCascadeTest).
 *
 * Saute automatiquement si la base aeic_test est indisponible.
 */
final class PurchaseDeleteKeepStockTest extends TestCase
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

        // S'assure que les tables existent (le schéma doit avoir été importé
        // dans aeic_test au préalable).
        try {
            $pdo->query('SELECT 1 FROM purchases LIMIT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('Table purchases absente de aeic_test : importez database/schema.sql.');
        }

        $this->reset($pdo, ['purchases', 'product_costs', 'product_stocks']);
        $this->applyCascadeMigrations($pdo);
        Model::setTestPdo($pdo);
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
    }

    /**
     * Migrations nécessaires (identiques à CostLotCascadeTest) : ré-idempotentes,
     * chaque statement indépendant est avalé s'il est déjà appliqué.
     */
    private function applyCascadeMigrations(PDO $pdo): void
    {
        $statements = [
            'CREATE TABLE IF NOT EXISTS product_stocks (
                product_key VARCHAR(255) NOT NULL,
                stock       INT NOT NULL DEFAULT 0,
                updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (product_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'ALTER TABLE purchases
                MODIFY unit_cost DECIMAL(10,3) NOT NULL DEFAULT 0,
                ADD COLUMN vat_rate DECIMAL(5,2) NULL AFTER unit_cost,
                ADD COLUMN total_ht DECIMAL(10,3) NULL AFTER total_ttc',
            'ALTER TABLE purchases MODIFY total_ht DECIMAL(10,3) NULL',
            'ALTER TABLE purchases MODIFY total_ttc DECIMAL(10,3) NOT NULL DEFAULT 0',
            'ALTER TABLE product_costs MODIFY cost_price DECIMAL(10,3) NOT NULL',
            'ALTER TABLE purchases ADD COLUMN no_stock TINYINT(1) NOT NULL DEFAULT 0 AFTER total_ht',
            'ALTER TABLE product_costs ADD COLUMN purchase_id VARCHAR(255) NULL AFTER notes',
            'ALTER TABLE product_costs ADD KEY idx_pc_purchase (purchase_id)',
        ];
        foreach ($statements as $sql) {
            try {
                $pdo->exec($sql);
            } catch (\PDOException) {
                // Déjà appliqué : non bloquant.
            }
        }
    }

    /**
     * Appelle la cascade privée par réflexion, comme le font les deux
     * routes de suppression (une ligne / un groupe).
     *
     * @param list<array<string,mixed>> $rows
     *
     * @return array{lines:int, lots_deleted:int, stock_adjusted:int, no_stock_lines:int, stock_kept_lines:int, ids:list<string>}
     */
    private function deleteLines(array $rows, bool $keepStock = false): array
    {
        $method = new ReflectionMethod(AdminStockController::class, 'deletePurchaseLines');
        $method->setAccessible(true);

        return $method->invoke(new AdminStockController(), $rows, $keepStock);
    }

    /**
     * Comportement par défaut inchangé (paramètre absent) : le stock est
     * contre-passé (7 → 0) et stock_kept_lines vaut 0.
     */
    public function test_defaut_contre_passe_le_stock_et_stock_kept_lines_a_zero(): void
    {
        $id = Purchase::create([
            'purchased_at' => '2026-10-01',
            'product_key'  => 'Monster Blanche',
            'quantity'     => 7,
            'total_ht'     => 14.00,
            'vat_rate'     => 20.0,
        ]);
        self::assertNotSame('', $id);
        self::assertSame(7, ProductStock::get('Monster Blanche'));

        $row = Purchase::find($id);
        self::assertNotNull($row, 'L\'achat doit être retrouvé avant suppression.');

        $summary = $this->deleteLines([$row]);

        self::assertSame(1, $summary['lines']);
        self::assertSame(-7, $summary['stock_adjusted']);
        self::assertSame(0, $summary['stock_kept_lines']);
        self::assertSame(0, $summary['no_stock_lines']);
        self::assertSame(0, ProductStock::get('Monster Blanche'), 'Défaut : le stock reste contre-passé.');
        self::assertNull(Purchase::find($id));
    }

    /**
     * keepStock = true : aucun ProductStock::adjust pour un achat en
     * stock — le stock reste à 7 — et la ligne est comptée dans
     * stock_kept_lines (l'achat est bien supprimé).
     */
    public function test_keep_stock_n_ajuste_pas_le_stock_et_compte_la_ligne(): void
    {
        $id = Purchase::create([
            'purchased_at' => '2026-10-02',
            'product_key'  => 'Bueno',
            'quantity'     => 7,
            'total_ht'     => 14.00,
            'vat_rate'     => null,
        ]);
        self::assertNotSame('', $id);
        self::assertSame(7, ProductStock::get('Bueno'));

        $row = Purchase::find($id);
        self::assertNotNull($row);

        $summary = $this->deleteLines([$row], true);

        self::assertSame(1, $summary['lines']);
        self::assertSame(0, $summary['stock_adjusted']);
        self::assertSame(1, $summary['stock_kept_lines']);
        self::assertSame(0, $summary['no_stock_lines']);
        self::assertSame(7, ProductStock::get('Bueno'), 'Stock volontairement conservé : aucune contre-passation.');
        self::assertNull(Purchase::find($id), 'L\'achat est supprimé même quand le stock est conservé.');
    }

    /**
     * Groupe mixte avec keepStock = true : la ligne en stock est
     * conservée (stock inchangé, comptée dans stock_kept_lines), la
     * ligne « hors stock » reste comptée dans no_stock_lines.
     */
    public function test_keep_stock_groupe_mixte_separe_en_stock_et_hors_stock(): void
    {
        $idA = Purchase::create([
            'purchased_at' => '2026-10-03',
            'product_key'  => 'Redbull',
            'quantity'     => 4,
            'total_ht'     => 8.00,
            'vat_rate'     => 20.0,
        ]);
        $idB = Purchase::create([
            'purchased_at' => '2026-10-03',
            'product_key'  => 'Fournitures',
            'quantity'     => 9,
            'total_ht'     => 18.00,
            'vat_rate'     => 20.0,
            'no_stock'     => true,
        ]);
        self::assertNotSame('', $idA);
        self::assertNotSame('', $idB);
        self::assertSame(4, ProductStock::get('Redbull'));
        // « Hors stock » : aucune ligne product_stocks n'est jamais créée
        // (contrat de ProductStock::get : null si non défini).
        self::assertNull(ProductStock::get('Fournitures'));

        $rowA = Purchase::find($idA);
        $rowB = Purchase::find($idB);
        self::assertNotNull($rowA);
        self::assertNotNull($rowB);

        $summary = $this->deleteLines([$rowA, $rowB], true);

        self::assertSame(2, $summary['lines']);
        self::assertSame([$idA, $idB], $summary['ids']);
        self::assertSame(0, $summary['stock_adjusted']);
        self::assertSame(1, $summary['stock_kept_lines'], 'Seule la ligne en stock compte dans stock_kept_lines.');
        self::assertSame(1, $summary['no_stock_lines'], 'La ligne hors stock reste comptée à part.');
        self::assertSame(4, ProductStock::get('Redbull'), 'Stock conservé : aucune contre-passation.');
        self::assertNull(ProductStock::get('Fournitures'), 'Toujours aucune ligne de stock pour le « hors stock ».');
        self::assertNull(Purchase::find($idA));
        self::assertNull(Purchase::find($idB));
    }

    /**
     * keepStock = true sur un achat DÉJÀ « hors stock » : aucun effet de
     * bord — rien n'avait été ajouté au stock, il reste à 0 et la ligne
     * reste comptée dans no_stock_lines (pas dans stock_kept_lines).
     */
    public function test_keep_stock_sur_achat_hors_stock_ne_change_rien(): void
    {
        $id = Purchase::create([
            'purchased_at' => '2026-10-04',
            'product_key'  => 'Conso bureau',
            'quantity'     => 3,
            'total_ht'     => 12.00,
            'vat_rate'     => 20.0,
            'no_stock'     => true,
        ]);
        self::assertNotSame('', $id);
        // Achat « hors stock » : jamais de ligne product_stocks (null).
        self::assertNull(ProductStock::get('Conso bureau'));

        $row = Purchase::find($id);
        self::assertNotNull($row);

        $summary = $this->deleteLines([$row], true);

        self::assertSame(1, $summary['lines']);
        self::assertSame(0, $summary['stock_adjusted']);
        self::assertSame(0, $summary['stock_kept_lines']);
        self::assertSame(1, $summary['no_stock_lines']);
        self::assertNull(ProductStock::get('Conso bureau'), 'Rien n\'a changé : toujours aucune ligne de stock.');
        self::assertNull(Purchase::find($id));
    }
}
