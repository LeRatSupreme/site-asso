<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Model;
use App\Models\Product;
use App\Models\ProductDeletionRefusedException;
use App\Models\ProductKeyMerge;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * RÈGLE « L'INVENTAIRE EST SACRÉ » (garde Product::deleteRow) : une
 * clé/fiche produit qui porte des comptages (inventory_counts) ou des
 * ventes (sales) n'est JAMAIS supprimée durablement — la suppression
 * est REFUSÉE (désactivation ou fusion de clé à la place).
 *
 *  - refus avec comptages, avec ventes (clé exacte OU variante
 *    d'orthographe « Coca_cherry » ~ « Coca cherry », repli normalisé
 *    partagé StockPublic::normalizeKey) ;
 *  - acceptation sans historique (ni comptages ni ventes) ;
 *  - le chemin de fusion ProductKeyMerge (déplacement complet préalable,
 *    audité) reste NON BLOQUÉ : Product::deleteRowAfterMerge, seule
 *    exception documentée à la garde.
 *
 * Saute automatiquement si la base aeic_test est indisponible.
 * JAMAIS la base de production (garde TestDatabaseTrait/db_guard).
 */
final class ProductTest extends TestCase
{
    use TestDatabaseTrait;

    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped('Base aeic_test indisponible : test Product ignoré.');

            return;
        }
        $this->pdo = $pdo;

        try {
            foreach (['products', 'sales', 'inventory_counts', 'purchases', 'product_aliases'] as $table) {
                $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
            }
        } catch (\Throwable) {
            self::markTestSkipped('Tables produits absentes de aeic_test : importez database/schema.sql.');

            return;
        }

        $this->reset($pdo, [
            'products', 'sales', 'purchases', 'product_aliases', 'inventory_counts',
        ]);
        Model::setTestPdo($pdo);
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
        $this->pdo = null;
    }

    // -----------------------------------------------------------------
    //  Refus : historique inventaire/ventes présent
    // -----------------------------------------------------------------

    public function test_delete_row_refuse_avec_comptages(): void
    {
        $id = $this->addFiche('Cristaline', 5);
        $this->addCount('Cristaline', 5);

        try {
            Product::deleteRow($id);
            self::fail('Une fiche avec comptages doit être refusée à la suppression.');
        } catch (ProductDeletionRefusedException $e) {
            self::assertStringContainsString('historique inventaire/ventes', $e->getMessage());
            self::assertStringContainsString('Cristaline', $e->getMessage());
        }

        self::assertTrue($this->ficheExiste($id), 'La fiche protégée reste en place.');
    }

    public function test_delete_row_refuse_avec_ventes(): void
    {
        $id = $this->addFiche('Eau', 2);
        $this->addSale('Eau', 'Eau');

        try {
            Product::deleteRow($id);
            self::fail('Une fiche avec ventes doit être refusée à la suppression.');
        } catch (ProductDeletionRefusedException $e) {
            self::assertStringContainsString('désactivez-le ou fusionnez la clé', $e->getMessage());
        }

        self::assertTrue($this->ficheExiste($id), 'La fiche protégée reste en place.');
    }

    /** Variante d'orthographe : vente sous « Coca_cherry », fiche « Coca cherry ». */
    public function test_delete_row_refuse_avec_vente_sous_variante_d_orthographe(): void
    {
        $id = $this->addFiche('Coca cherry', 0);
        // Vente SANS clé résolue, description à la ponctuation différente :
        // la passe normalisée de la garde doit la rattraper.
        $this->addSale('Coca_cherry', null);

        try {
            Product::deleteRow($id);
            self::fail('Une fiche dont la clé normalisée porte des ventes doit être refusée.');
        } catch (ProductDeletionRefusedException) {
            // Attendu : l'inventaire est sacré, même à l'écriture près.
        }

        self::assertTrue($this->ficheExiste($id));
    }

    // -----------------------------------------------------------------
    //  Acceptation : aucun historique protégé
    // -----------------------------------------------------------------

    public function test_delete_row_accepte_sans_historique(): void
    {
        $id = $this->addFiche('Sablé oublié', 3);
        // Des ACHATS sous la clé ne sont pas un historique protégé (la
        // garde ne couvre que comptages + ventes) : suppression acceptée.
        $this->addPurchase('Sablé oublié', 4);

        Product::deleteRow($id);

        self::assertFalse($this->ficheExiste($id), 'Sans comptages ni ventes, la fiche est supprimée.');
    }

    public function test_delete_row_fiche_inconnue_efface_rien(): void
    {
        Product::deleteRow('prod_inconnu');

        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM products')->fetchColumn());
    }

    // -----------------------------------------------------------------
    //  Exception documentée : chemin fusion ProductKeyMerge non bloqué
    // -----------------------------------------------------------------

    public function test_delete_row_after_merge_supprime_malgre_historique(): void
    {
        $id = $this->addFiche('Cristaline', 5);
        $this->addCount('Cristaline', 5);
        $this->addSale('Cristaline', 'Cristaline');

        // Chemin réservé à la fusion (déplacement complet préalable) :
        // jamais bloqué par la garde, et l'historique n'est PAS touché.
        Product::deleteRowAfterMerge($id);

        self::assertFalse($this->ficheExiste($id), 'Le chemin fusion supprime la fiche.');
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM inventory_counts')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn());
    }

    /** Fusion de bout en bout : ventes + comptages DÉPLACÉS puis fiche source supprimée. */
    public function test_merge_avec_historique_non_bloque_par_la_garde(): void
    {
        $this->reset($this->pdo, [
            'products', 'sales', 'import_batches', 'purchases', 'losses', 'product_aliases',
            'product_costs', 'inventory_counts', 'product_stocks', 'product_discontinued',
            'product_infinite', 'product_zero_since', 'product_packs', 'shopping_checks',
            'promotions', 'scan_labels', 'cafeteria_order_items', 'cafeteria_orders',
            'inventory_tablet_sessions', 'inventory_tablet_lines',
        ]);

        $srcId = $this->addFiche('Cristaline', 5);
        $this->addFiche('Eau', 7);
        $this->addCount('Cristaline', 5);
        $this->addSale('Cristaline', 'Cristaline');

        // Sans la garde reconnue, ce merge devrait lever l'exception de
        // suppression : il déplace TOUT d'abord (ventes, comptages) puis
        // supprime la fiche source par le chemin dédié.
        $moved = ProductKeyMerge::merge('Cristaline', 'Eau', 'user_test');

        self::assertSame(1, $moved['fiches'], 'La fiche source est fusionnée (supprimée).');
        self::assertFalse($this->ficheExiste($srcId));
        self::assertSame(
            'Eau',
            (string) $this->pdo->query("SELECT product_key FROM sales LIMIT 1")->fetchColumn(),
            'Les ventes suivent la clé cible avant la suppression.'
        );
        self::assertSame(
            'Eau',
            (string) $this->pdo->query('SELECT product_key FROM inventory_counts LIMIT 1')->fetchColumn(),
            'Les comptages suivent la clé cible avant la suppression.'
        );
    }

    // -----------------------------------------------------------------
    //  Aides de fixtures (aeic_test)
    // -----------------------------------------------------------------

    private function addFiche(string $name, int $stock): string
    {
        $id = 'prod_' . bin2hex(random_bytes(6));
        $this->pdo->prepare(
            'INSERT INTO products (id, name, stock, is_active, is_available) VALUES (?, ?, ?, 1, 1)'
        )->execute([$id, $name, $stock]);

        return $id;
    }

    private function addCount(string $key, int $qty): void
    {
        $this->pdo->prepare(
            'INSERT INTO inventory_counts (id, counted_at, product_key, counted_qty, theoretical_qty, gap, created_at)
             VALUES (?, NOW(), ?, ?, ?, 0, NOW())'
        )->execute(['inv_' . bin2hex(random_bytes(6)), $key, $qty, $qty]);
    }

    private function addSale(string $description, ?string $productKey): void
    {
        $this->pdo->prepare(
            "INSERT INTO sales (id, transaction_ref, sold_at, payment_method, quantity, description, product_key, price_ttc)
             VALUES (?, ?, NOW(), 'CARTE', 1, ?, ?, 1.0)"
        )->execute(['sal_' . bin2hex(random_bytes(6)), 'tx_' . bin2hex(random_bytes(8)), $description, $productKey]);
    }

    private function addPurchase(string $key, int $qty): void
    {
        $this->pdo->prepare(
            'INSERT INTO purchases (id, purchased_at, product_key, quantity, unit_cost, total_ttc, created_at)
             VALUES (?, NOW(), ?, ?, 1.0, 1.0, NOW())'
        )->execute(['pur_' . bin2hex(random_bytes(6)), $key, $qty]);
    }

    private function ficheExiste(string $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM products WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() !== false;
    }
}
