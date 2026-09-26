<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Model;
use App\Models\ProductStock;
use App\Models\Purchase;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests du modèle Purchase : création par montant total (coût unitaire
 * dérivé à 3 décimales), calcul HT/TTC selon le taux de TVA et sommes
 * de période.
 *
 * Saute automatiquement si la base aeic_test est indisponible.
 */
final class PurchaseTest extends TestCase
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
        $this->applyPurchasesVatMigration($pdo);
        Model::setTestPdo($pdo);
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
    }

    /**
     * Applique (best effort) les migrations achats
     * (database/migrations/2026_purchases_vat.sql,
     * 2026_purchases_ht3.sql puis 2026_purchases_ttc3.sql,
     * 2026_purchases_no_stock.sql)
     * sur la base de test.
     *
     * Une erreur (colonne déjà présente, type déjà modifié) est ignorée :
     * les migrations sont ré-idempotentes en pratique.
     */
    private function applyPurchasesVatMigration(PDO $pdo): void
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
            'ALTER TABLE purchases ADD COLUMN no_stock TINYINT(1) NOT NULL DEFAULT 0 AFTER total_ht',
            'ALTER TABLE product_costs MODIFY cost_price DECIMAL(10,3) NOT NULL',
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
     * 18,60 € HT les 120 + TVA 20 % : coût unitaire dérivé 0,155 €,
     * HT 18,600 €, TTC 22,320 €.
     */
    public function test_create_avec_tva_20_calcule_ht_et_ttc(): void
    {
        $id = Purchase::create([
            'purchased_at' => '2026-09-10',
            'product_key'  => 'Coca 33cl',
            'quantity'     => 120,
            'total_ht'     => 18.60,
            'vat_rate'     => 20.0,
            'supplier'     => 'Metro',
        ]);

        self::assertNotSame('', $id);

        $row = $this->fetchPurchase($id);
        self::assertSame('0.155', $row['unit_cost'], '18,60 € / 120 = 0,155 € par unité.');
        self::assertSame('18.600', $row['total_ht']);
        self::assertSame('22.320', $row['total_ttc']);
        self::assertSame('20.00', (string) $row['vat_rate']);

        // Effet de bord conservé : l'achat entre en stock théorique.
        self::assertSame(120, ProductStock::get('Coca 33cl'));
    }

    /**
     * Sans taux (montant saisi déjà TTC) : total_ht = total_ttc, vat_rate
     * NULL (comportement historique) ; le coût unitaire reste dérivé du
     * montant : 25,152 € / 24 = 1,048 €.
     */
    public function test_create_sans_tva_prix_deja_ttc(): void
    {
        $id = Purchase::create([
            'purchased_at' => '2026-09-10',
            'product_key'  => 'Bueno',
            'quantity'     => 24,
            'total_ht'     => 25.152,
            'vat_rate'     => null,
        ]);

        self::assertNotSame('', $id);

        $row = $this->fetchPurchase($id);
        self::assertSame('1.048', $row['unit_cost']);
        self::assertSame('25.152', $row['total_ht']);
        self::assertSame('25.152', $row['total_ttc'], 'Sans TVA, HT et TTC sont identiques.');
        self::assertNull($row['vat_rate']);
    }

    /**
     * Le coût unitaire dérivé garde ses 3 décimales : 15,50 € les 100 →
     * 0,155 € ne doit pas être arrondi à 0,16 € (E1).
     */
    public function test_create_conserve_3_decimales(): void
    {
        $id = Purchase::create([
            'purchased_at' => '2026-09-10',
            'product_key'  => 'Bonbon',
            'quantity'     => 100,
            'total_ht'     => 15.50,
            'vat_rate'     => 5.5,
        ]);

        $row = $this->fetchPurchase($id);
        self::assertSame('0.155', $row['unit_cost']);
        self::assertSame('15.500', $row['total_ht']);
        self::assertSame('16.353', $row['total_ttc'], '15,500 € HT + TVA 5,5 % = 16,353 € (et non 16,35 €).');
    }

    /**
     * Total HT exact à 3 décimales non exactes en centimes :
     * 0,465 € pour 3 unités (unité dérivée 0,155 €) — DECIMAL(10,2)
     * aurait stocké 0,47 €.
     */
    public function test_create_total_ht_3_decimales_non_exactes(): void
    {
        $id = Purchase::create([
            'purchased_at' => '2026-09-10',
            'product_key'  => 'Bonbon',
            'quantity'     => 3,
            'total_ht'     => 0.465,
            'vat_rate'     => 5.5,
        ]);

        $row = $this->fetchPurchase($id);
        self::assertSame('0.155', $row['unit_cost'], '0,465 € / 3 = 0,155 € par unité.');
        self::assertSame('0.465', $row['total_ht'], '0,465 € stocké exactement.');
        self::assertSame('0.491', $row['total_ttc'], '0,465 € × 1,055 = 0,490575 €, stocké 0,491 € (DECIMAL(10,2) aurait stocké 0,49 €).');
    }

    /**
     * TTC à 3 décimales calculé depuis le HT : 25,152 € HT + TVA 5,5 %
     * = 26,53536 € → 26,535 € (DECIMAL(10,2) et un arrondi au centime
     * auraient donné 26,54 €).
     */
    public function test_create_ttc_3_decimales_depuis_ht_non_arrondi(): void
    {
        $id = Purchase::create([
            'purchased_at' => '2026-09-10',
            'product_key'  => 'Sirop',
            'quantity'     => 24,
            'total_ht'     => 25.152,
            'vat_rate'     => 5.5,
        ]);

        $row = $this->fetchPurchase($id);
        self::assertSame('1.048', $row['unit_cost'], '25,152 € / 24 = 1,048 € par unité.');
        self::assertSame('25.152', $row['total_ht']);
        self::assertSame('26.535', $row['total_ttc'], '25,152 € HT + TVA 5,5 % = 26,535 € (et non 26,54 €).');
    }

    /**
     * Division non exacte : 10 € pour 3 unités → coût unitaire arrondi
     * à 3 décimales (3,333 €), le total reste le montant saisi.
     */
    public function test_create_division_non_exacte(): void
    {
        $id = Purchase::create([
            'purchased_at' => '2026-09-10',
            'product_key'  => 'Kit',
            'quantity'     => 3,
            'total_ht'     => 10.00,
            'vat_rate'     => 20.0,
        ]);

        $row = $this->fetchPurchase($id);
        self::assertSame('3.333', $row['unit_cost'], '10 € / 3 = 3,333333… → 3,333 €.');
        self::assertSame('10.000', $row['total_ht'], 'Le montant saisi fait foi.');
        self::assertSame('12.000', $row['total_ttc'], '10,000 € HT + TVA 20 % = 12,000 €.');
    }

    /**
     * Données invalides : pas de création (montant absent, nul ou
     * négatif, produit vide, quantité nulle).
     */
    public function test_create_refuse_donnees_invalides(): void
    {
        self::assertSame('', Purchase::create([
            'purchased_at' => '2026-09-10',
            'product_key'  => '',
            'quantity'     => 1,
            'total_ht'     => 10.0,
        ]));
        self::assertSame('', Purchase::create([
            'purchased_at' => '2026-09-10',
            'product_key'  => 'Bueno',
            'quantity'     => 0,
            'total_ht'     => 10.0,
        ]));
        self::assertSame('', Purchase::create([
            'purchased_at' => '2026-09-10',
            'product_key'  => 'Bueno',
            'quantity'     => 1,
            'total_ht'     => 0.0,
        ]), 'Montant total nul : refusé.');
        self::assertSame('', Purchase::create([
            'purchased_at' => '2026-09-10',
            'product_key'  => 'Bueno',
            'quantity'     => 1,
        ]), 'Montant total absent : refusé.');
    }

    /**
     * sumsBetween : HT (lignes historiques total_ht NULL comptées en TTC),
     * TTC et TVA cohérents sur une plage de jours.
     */
    public function test_sums_between_ht_tva_et_ttc(): void
    {
        // Achat HT + TVA 20 % : 25,20 € HT / 30,24 € TTC.
        Purchase::create([
            'purchased_at' => '2026-09-10',
            'product_key'  => 'Coca 33cl',
            'quantity'     => 24,
            'total_ht'     => 25.20,
            'vat_rate'     => 20.0,
        ]);
        // Achat déjà TTC (vat null) : 10,00 € comptés en HT et TTC.
        Purchase::create([
            'purchased_at' => '2026-09-11',
            'product_key'  => 'Bueno',
            'quantity'     => 10,
            'total_ht'     => 10.00,
            'vat_rate'     => null,
        ]);
        // Ligne « historique » (avant la TVA) : total_ht NULL.
        $this->pdo->exec(
            "INSERT INTO purchases (id, purchased_at, product_key, quantity, unit_cost, total_ttc, total_ht, created_at)
             VALUES ('purchase_hist', '2026-09-12', 'Fanta', 4, 2.000, 8.00, NULL, NOW())"
        );
        // Hors période : ignoré.
        Purchase::create([
            'purchased_at' => '2026-08-01',
            'product_key'  => 'Café',
            'quantity'     => 1,
            'total_ht'     => 50.00,
            'vat_rate'     => 20.0,
        ]);

        $sums = Purchase::sumsBetween('2026-09-01', '2026-09-30');

        self::assertSame(43.20, $sums['ht'], 'Ligne historique (total_ht NULL) comptée en TTC.');
        self::assertSame(48.24, $sums['ttc']);
        self::assertSame(5.04, $sums['vat'], 'TVA = TTC − HT.');

        // Plage vide : zéros.
        $empty = Purchase::sumsBetween('2027-01-01', '2027-01-31');
        self::assertSame(['ht' => 0.0, 'ttc' => 0.0, 'vat' => 0.0], $empty);
    }

    /**
     * Achat « hors stock » (no_stock) : la ligne et son montant restent
     * dans la compta (totalBetween), mais ni le stock de référence ni
     * qtySince() ne bougent ; un achat normal, lui, ajuste le stock.
     */
    public function test_create_hors_stock_n_alimente_pas_le_stock(): void
    {
        // Achat normal : le stock de référence suit (+24).
        Purchase::create([
            'purchased_at' => '2026-09-10',
            'product_key'  => 'Coca 33cl',
            'quantity'     => 24,
            'total_ht'     => 12.00,
            'vat_rate'     => 20.0,
        ]);
        self::assertSame(24, ProductStock::get('Coca 33cl'));

        // Achat « hors stock » : comptabilisé, stock intact.
        $id = Purchase::create([
            'purchased_at' => '2026-09-11',
            'product_key'  => 'Coca 33cl',
            'quantity'     => 10,
            'total_ht'     => 5.00,
            'vat_rate'     => 20.0,
            'no_stock'     => true,
        ]);

        $row = $this->fetchPurchase($id);
        self::assertSame('1', (string) $row['no_stock'], 'Le drapeau no_stock est enregistré.');
        self::assertSame('6.000', $row['total_ttc'], 'La ligne reste une dépense réelle (5,00 € HT + TVA 20 %).');

        self::assertSame(24, ProductStock::get('Coca 33cl'), 'Le stock de référence ne bouge pas.');
        self::assertSame(24, Purchase::qtySince('Coca 33cl', '2026-01-01'), 'qtySince ignore les achats hors stock.');

        // La compta, elle, compte la ligne : 14,40 + 6,00 = 20,40 € TTC.
        self::assertSame(20.40, Purchase::totalBetween('2026-09-01', '2026-09-30'));
    }

    private function fetchPurchase(string $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM purchases WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        self::assertNotFalse($row, 'La ligne achat doit exister.');

        return $row;
    }
}
