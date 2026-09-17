<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Model;
use App\Models\ProductCost;
use App\Models\ProductStock;
use App\Models\Purchase;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Cascade de suppression d'un achat : le lot de coût de revient lié
 * (product_costs.purchase_id) est supprimé, le lot antérieur « en cours »
 * est réouvert et le stock de référence est contre-passé
 * (ProductStock::adjust(-qty), inverse de Purchase::create).
 *
 * Les étapes reproduisent exactement la recette du contrôleur
 * AdminStockController::deletePurchase() : find → deleteByPurchase →
 * Purchase::delete → ProductStock::adjust.
 *
 * Saute automatiquement si la base aeic_test est indisponible.
 */
final class CostLotCascadeTest extends TestCase
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
     * Applique (best effort) les migrations nécessaires : tables achats/TVA
     * (comme PurchaseTest) et le lien achat -> lot
     * (database/migrations/2026_cost_lot_link.sql).
     *
     * Chaque statement est indépendant : une erreur (colonne ou index déjà
     * présent) est ignorée, les migrations sont ré-idempotentes en pratique.
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
     * Deux achats avec lot lié chacun (option update_cost) : supprimer le
     * second achat supprime son lot, réouvre le lot antérieur (valid_to
     * repasse à NULL) et contre-passe le stock (24 → 12).
     */
    public function test_suppression_achat_cascade_lot_et_stock(): void
    {
        // Achat + lot n°1 (08/09).
        $idA = Purchase::create([
            'purchased_at' => '2026-09-08',
            'product_key'  => 'Monster Blanche',
            'quantity'     => 12,
            'total_ht'     => 24.00,
            'vat_rate'     => 20.0,
        ]);
        self::assertNotSame('', $idA);
        $lotA = ProductCost::create([
            'product_key' => 'Monster Blanche',
            'cost_price'  => 2.4,
            'valid_from'  => '2026-09-08',
            'purchase_id' => $idA,
        ]);

        // Achat + lot n°2 (16/09) : clôt le lot n°1 (valid_to = 15/09).
        $idB = Purchase::create([
            'purchased_at' => '2026-09-16',
            'product_key'  => 'Monster Blanche',
            'quantity'     => 12,
            'total_ht'     => 30.00,
            'vat_rate'     => 20.0,
        ]);
        $lotB = ProductCost::create([
            'product_key' => 'Monster Blanche',
            'cost_price'  => 2.5,
            'valid_from'  => '2026-09-16',
            'purchase_id' => $idB,
        ]);
        self::assertSame(24, ProductStock::get('Monster Blanche'));

        // Recette du contrôleur deletePurchase() : lecture avant suppression.
        $purchase = Purchase::find($idB);
        self::assertNotNull($purchase, 'L\'achat doit être retrouvé avant suppression.');

        $lotsDeleted = ProductCost::deleteByPurchase($idB);
        Purchase::delete($idB);
        ProductStock::adjust('Monster Blanche', -(int) $purchase['quantity']);

        // Le lot lié est parti, pas l'autre.
        self::assertSame(1, $lotsDeleted);
        self::assertNull(Model::find($lotB), 'Le lot lié à l\'achat supprimé doit partir.');
        self::assertNull(Purchase::find($idB));

        // Le lot antérieur est réouvert (valid_to = NULL).
        $lots = ProductCost::forProduct('Monster Blanche');
        self::assertCount(1, $lots);
        self::assertSame($lotA, (string) $lots[0]['id']);
        self::assertNull($lots[0]['valid_to'], 'Le lot antérieur doit être réouvert (valid_to NULL).');

        // Stock de référence contre-passé : 24 − 12 = 12.
        self::assertSame(12, ProductStock::get('Monster Blanche'));
    }

    /**
     * Achat sans lot lié (option update_cost décochée) : la cascade ne
     * supprime aucun lot, ne lève aucune erreur et contre-passe le stock.
     */
    public function test_suppression_achat_sans_lot(): void
    {
        $id = Purchase::create([
            'purchased_at' => '2026-09-16',
            'product_key'  => 'Bueno',
            'quantity'     => 5,
            'total_ht'     => 10.00,
            'vat_rate'     => null,
        ]);
        self::assertNotSame('', $id);
        self::assertSame(5, ProductStock::get('Bueno'));

        self::assertSame(0, ProductCost::deleteByPurchase($id));
        self::assertTrue(Purchase::delete($id));
        ProductStock::adjust('Bueno', -5);

        self::assertNull(Purchase::find($id));
        self::assertSame([], ProductCost::forProduct('Bueno'));
        self::assertSame(0, ProductStock::get('Bueno'));
    }

    /**
     * Suppression manuelle d'un lot « en cours » (🗑 de la page Coûts) :
     * le lot antérieur est réouvert au lieu de laisser un trou.
     */
    public function test_delete_lot_en_cours_reouvre_le_precedent(): void
    {
        $lotA = ProductCost::create([
            'product_key' => 'Redbull',
            'cost_price'  => 1.0,
            'valid_from'  => '2026-09-01',
        ]);
        $lotB = ProductCost::create([
            'product_key' => 'Redbull',
            'cost_price'  => 2.0,
            'valid_from'  => '2026-09-10',
        ]);
        // État initial : lotA clôturé au 09/09, lotB en cours.
        $lots = ProductCost::forProduct('Redbull');
        self::assertSame('2026-09-09', substr((string) $lots[1]['valid_to'], 0, 10));

        self::assertTrue(ProductCost::delete($lotB));

        $lots = ProductCost::forProduct('Redbull');
        self::assertCount(1, $lots);
        self::assertSame($lotA, (string) $lots[0]['id']);
        self::assertNull($lots[0]['valid_to'], 'Le lot antérieur doit être réouvert.');
        self::assertSame('1.0', (string) (float) $lots[0]['cost_price']);
    }

    /**
     * Suppression manuelle d'un lot déjà clôturé : la chaîne est intacte
     * (ni réouverture du lot antérieur, ni changement du lot en cours).
     */
    public function test_delete_lot_deja_cloture_ne_casse_pas_la_chaine(): void
    {
        ProductCost::create(['product_key' => 'Fanta', 'cost_price' => 1.0, 'valid_from' => '2026-09-01']);
        $lotB = ProductCost::create(['product_key' => 'Fanta', 'cost_price' => 2.0, 'valid_from' => '2026-09-10']);
        ProductCost::create(['product_key' => 'Fanta', 'cost_price' => 3.0, 'valid_from' => '2026-09-20']);
        // lot1 valid_to 09/09, lotB valid_to 19/09, lot3 en cours.
        self::assertTrue(ProductCost::delete($lotB));

        $lots = ProductCost::forProduct('Fanta');
        self::assertCount(2, $lots);
        self::assertSame('3.0', (string) (float) $lots[0]['cost_price']);
        self::assertNull($lots[0]['valid_to'], 'Le lot en cours reste en cours.');
        self::assertSame('2026-09-09', substr((string) $lots[1]['valid_to'], 0, 10), 'Le lot antérieur garde sa clôture d\'origine.');
    }

    /**
     * Cibles inconnues : aucune suppression, aucune erreur.
     */
    public function test_suppressions_sur_cibles_inconnues(): void
    {
        self::assertSame(0, ProductCost::deleteByPurchase('purchase_inconnu'));
        self::assertFalse(ProductCost::delete('cost_inconnu'));
        self::assertFalse(Purchase::delete('purchase_inconnu'));
    }
}
