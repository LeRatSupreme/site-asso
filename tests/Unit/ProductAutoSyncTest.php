<?php

declare(strict_types=1);

namespace Tests\Unit;

require_once __DIR__ . '/db_test_bridge.php';

use App\Core\Compta\ProductAutoSync;
use App\Core\Compta\PurchaseSaver;
use App\Models\ImportBatch;
use App\Models\Model;
use App\Models\Sale;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Gardes structurels de ProductAutoSync (mission E — les trois trous par
 * lesquels les fiches « pourries » entraient) :
 *
 *  A. un achat « hors stock » (no_stock = 1 : conso bureau, fournitures)
 *     ne crée JAMAIS de fiche carte, ni par la passe ciblée
 *     (PurchaseSaver) ni par la passe globale sync() ;
 *  B. un libellé OCR de facture brut (clé encore égale à une entrée de
 *     la file scan_labels, règle PurchaseSaver::isRawScanLabel) est
 *     exclu MÊME via sync() global — y compris s'il a été vendu tel
 *     quel à la borne ;
 *  C. une fiche publique n'est créée que sur VENTE réelle : un achat,
 *     une perte, un comptage ou un stock de référence seul ne suffit
 *     jamais ; « Bowling » (service, pas un produit) n'atterrit pas
 *     en Snacks : exclu de la création automatique.
 *
 * Positive : la vente d'un nouveau libellé (« Minute Maid Pomme »)
 * crée bien la fiche, idempotemment — c'est le chemin que le cron
 * SumUp (scripts/sync_sumup_sales.php) active après insertion.
 *
 * Saute automatiquement si la base aeic_test est indisponible.
 * JAMAIS la base de production (garde TestDatabaseTrait/db_guard).
 */
final class ProductAutoSyncTest extends TestCase
{
    use TestDatabaseTrait;

    protected function setUp(): void
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped('Base aeic_test indisponible : test ProductAutoSync ignoré.');

            return;
        }

        foreach (
            [
                'products', 'product_categories', 'sales', 'import_batches', 'purchases',
                'losses', 'inventory_counts', 'product_stocks', 'product_aliases',
                'scan_labels', 'audit_logs',
            ] as $table
        ) {
            try {
                $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
            } catch (\Throwable) {
                self::markTestSkipped("Table `$table` absente de aeic_test : importez database/schema.sql.");

                return;
            }
        }

        $this->reset($pdo, [
            'products', 'product_categories', 'sales', 'import_batches', 'purchases',
            'losses', 'inventory_counts', 'product_stocks', 'product_aliases',
            'scan_labels', 'audit_logs',
        ]);
        $this->applyImportDedupMigration($pdo);

        // Catégories standards de la carte (mêmes ids que la migration
        // 2026_menu_nouveaux_produits.sql) : les fiches créées pointent
        // dessus (FK products.category_id → product_categories.id).
        $pdo->exec(
            "INSERT INTO product_categories (id, name, `order`, is_active) VALUES
             ('cat_boissons', 'Boissons', 1, 1),
             ('cat_snacks', 'Snacks', 2, 1),
             ('cat_special', 'Spécial', 3, 1)"
        );

        Model::setTestPdo($pdo);
        // Pont db() actif : ProductAutoSync interroge aussi la connexion
        // globale (prix déduit, achats) ; désactivé en tearDown.
        db_test_bridge_enable();
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
        db_test_bridge_disable();
    }

    // -----------------------------------------------------------------
    //  Garde A — achats « hors stock »
    // -----------------------------------------------------------------

    /** Achat « hors stock » seul : aucune fiche carte, ni via sync() ni via ensureKeys(). */
    public function test_achat_hors_stock_ne_cree_pas_de_fiche(): void
    {
        $this->insertPurchase('Fournitures bureau', 1);

        $global = ProductAutoSync::sync();
        self::assertSame([], $global['created'], 'Un achat no_stock=1 ne doit jamais créer de fiche (passe globale).');
        self::assertSame(0, $this->productCount());

        $targeted = ProductAutoSync::ensureKeys(['Fournitures bureau']);
        self::assertSame([], $targeted['created'], 'Un achat no_stock=1 ne doit jamais créer de fiche (passe ciblée).');
        self::assertSame(0, $this->productCount());
    }

    /** PurchaseSaver (page Achats / livre kiosque) : une ligne « hors stock » ne déclenche pas la synchro carte. */
    public function test_purchase_saver_ligne_hors_stock_sans_fiche(): void
    {
        $res = PurchaseSaver::save(
            ['purchased_at' => '2026-10-22', 'supplier' => 'METRO', 'vat_raw' => '', 'amount_basis' => 'ttc'],
            [
                ['key' => 'Sacs poubelle', 'qty' => '1', 'total_raw' => '12,40', 'vat_raw' => '', 'no_stock' => true, 'raw_label' => ''],
                ['key' => 'Essuie-tout', 'qty' => '2', 'total_raw' => '3,10', 'vat_raw' => '', 'no_stock' => true, 'raw_label' => ''],
            ]
        );

        self::assertSame(2, $res['inserted']);
        self::assertSame(2, $res['no_stock']);
        self::assertStringNotContainsString('Carte mise à jour', $res['message'], 'Aucune fiche carte ne doit être annoncée pour des achats hors stock.');
        self::assertSame(0, $this->productCount(), 'Aucune fiche carte créée depuis des achats hors stock.');
    }

    // -----------------------------------------------------------------
    //  Garde C — une fiche publique est justifiée par une VENTE
    // -----------------------------------------------------------------

    /** Achat en stock + comptage + stock de référence, mais JAMAIS vendu : aucune fiche. */
    public function test_libelle_jamais_vendu_ne_cree_pas_de_fiche(): void
    {
        $this->insertPurchase('Chips paprika');
        $this->insertCount('Chips paprika', 5);
        $this->pdo()->exec("INSERT INTO product_stocks (product_key, stock) VALUES ('Chips paprika', 5)");

        $global = ProductAutoSync::sync();
        self::assertSame([], $global['created'], 'Un libellé jamais vendu ne doit pas créer de fiche, même acheté/compté.');
        self::assertSame(0, $this->productCount());

        $targeted = ProductAutoSync::ensureKeys(['Chips paprika']);
        self::assertSame([], $targeted['created']);
        self::assertSame(0, $this->productCount());
    }

    /** Une perte seule ne crée pas non plus de fiche (même garde C). */
    public function test_perte_seule_ne_cree_pas_de_fiche(): void
    {
        $this->pdo()->exec("INSERT INTO losses (id, product_key, quantity, lost_at) VALUES ('loss_t1', 'Sirop menthe', 1, '2026-10-22')");

        $global = ProductAutoSync::sync();
        self::assertSame([], $global['created']);
        self::assertSame(0, $this->productCount());
    }

    /** « Bowling » est un service, pas un produit : jamais de fiche automatique (jamais en Snacks). */
    public function test_bowling_ne_cree_pas_de_fiche_snacks(): void
    {
        $this->insertSale('T-BOWL-1', 'Bowling');

        $global = ProductAutoSync::sync();
        self::assertSame([], $global['created'], '« Bowling » est exclu de la création automatique de fiches.');

        $targeted = ProductAutoSync::ensureKeys(['Bowling']);
        self::assertSame([], $targeted['created']);

        self::assertSame(0, $this->productCount(), 'Aucune fiche « Bowling », donc rien en Snacks.');
    }

    // -----------------------------------------------------------------
    //  Garde B — libellés OCR bruts (file scan_labels)
    // -----------------------------------------------------------------

    /**
     * Libellé OCR brut exclu MÊME via sync() global : la clé d'achat EST
     * le libellé de facture (isRawScanLabel), la vente seule ne suffit
     * pas à la nommer — la fiche attend le nom court saisi par
     * l'utilisateur (alias), pas l'heuristique.
     */
    public function test_libelle_ocr_brut_exclu_meme_via_sync_global(): void
    {
        $this->pdo()->exec(
            "INSERT INTO scan_labels (id, label, occurrences) VALUES ('scan_t1', 'EVIEN TROPICAL BTE 33CL', 1)"
        );
        $this->insertPurchase('evien tropical bte 33cl');
        // Vendu tel quel à la borne : la garde C ne suffit pas, c'est la
        // garde B (isRawScanLabel) qui doit bloquer.
        $this->insertSale('T-EVIEN-1', 'EVIEN TROPICAL BTE 33CL');

        $global = ProductAutoSync::sync();
        self::assertSame([], $global['created'], 'Un libellé OCR brut (file scan_labels) ne doit créer aucune fiche, même vendu tel quel.');

        $targeted = ProductAutoSync::ensureKeys(['EVIEN TROPICAL BTE 33CL']);
        self::assertSame([], $targeted['created']);

        self::assertSame(0, $this->productCount());
    }

    // -----------------------------------------------------------------
    //  Positive — la VENTE crée la fiche (chemin cron SumUp)
    // -----------------------------------------------------------------

    /** Vente d'un nouveau libellé : fiche créée (une seule fois), prix = moyenne des ventes. */
    public function test_vente_cree_la_fiche_une_seule_fois(): void
    {
        $this->insertSale('T-MM-1', 'Minute Maid Pomme', 1.5);
        $this->insertSale('T-MM-2', 'Minute Maid Pomme', 1.5);

        $first = ProductAutoSync::ensureKeys(['Minute Maid Pomme']);
        self::assertSame(['Minute Maid Pomme'], $first['created'], 'La vente justifie la fiche.');

        $row = $this->productByName('Minute Maid Pomme');
        self::assertNotNull($row);
        self::assertSame('cat_boissons', (string) $row['category_id'], '« minute maid » est un mot-clé Boissons.');
        self::assertEqualsWithDelta(1.5, (float) $row['price'], 0.001, 'Prix = moyenne des ventes arrondie au 5 cts.');

        // Idempotent : la fiche existe, aucune création de doublon.
        $second = ProductAutoSync::ensureKeys(['Minute Maid Pomme']);
        self::assertSame([], $second['created']);
        $global = ProductAutoSync::sync();
        self::assertSame([], $global['created']);
        self::assertSame(1, $this->productCount());
    }

    /** Achat no_stock sur une clé réellement vendue : la fiche vient de la vente, pas de l'achat. */
    public function test_achat_hors_stock_sur_cle_vendue_ne_bloque_pas_la_fiche(): void
    {
        $this->insertSale('T-COLA-1', 'Coca cola');
        $this->insertPurchase('Coca cola', 1);

        $global = ProductAutoSync::sync();
        self::assertSame(['Coca cola'], $global['created'], 'La vente (« Coca cola ») justifie la fiche malgré l\'achat hors stock.');
        self::assertSame(1, $this->productCount());
    }

    // -----------------------------------------------------------------
    //  Mission W1 — appariement à frontière de mot (variantes ≠ fiches)
    // -----------------------------------------------------------------

    /**
     * « oasis pomme poire » (variante vivante) crée SA fiche : la fiche
     * famille « Oasis » ne l'avale plus (ancienne inclusion sans
     * frontière — clé du dossier dev-couts M3).
     */
    public function test_variante_oasis_pomme_poire_ne_matche_plus_la_fiche_oasis(): void
    {
        $this->insertCard('Oasis');
        $this->insertSale('T-OPP-1', 'oasis pomme poire');

        $res = ProductAutoSync::ensureKeys(['oasis pomme poire']);
        self::assertSame(['Oasis pomme poire'], $res['created'], '« oasis pomme poire » doit redevenir créable : ≠ « Oasis ».');
        self::assertSame(2, $this->productCount(), 'La variante obtient sa propre fiche à côté de la famille.');
    }

    /** « cafe allonge » ≠ « cafe » : variante créable (aiguille de 4 caractères exactement). */
    public function test_cafe_allonge_cree_sa_fiche(): void
    {
        $this->insertCard('cafe');
        $this->insertSale('T-CA-1', 'cafe allonge');

        $res = ProductAutoSync::ensureKeys(['cafe allonge']);
        self::assertSame(['Cafe allonge'], $res['created'], 'L\'allongé (2 dosettes) est un AUTRE produit que le café simple.');
    }

    /** « Coca Zero » ≠ « Coca » : le qualificatif de variante ne fond jamais le libellé dans la famille. */
    public function test_coca_zero_ne_matche_pas_coca(): void
    {
        $this->insertCard('Coca');
        $this->insertSale('T-CZ-1', 'Coca Zero');

        $res = ProductAutoSync::ensureKeys(['Coca Zero']);
        self::assertSame(['Coca Zero'], $res['created']);
    }

    /**
     * « Pepsi » ≠ « Pepsi Zero » : une clé courte ne s'attache pas à une
     * fiche VARIANTE (reste d'un seul mot = qualificatif, pas une forme
     * de produit) → la fiche « Pepsi » est créée.
     */
    public function test_pepsi_ne_s_attache_pas_a_pepsi_zero(): void
    {
        $this->insertCard('Pepsi Zero');
        $this->insertSale('T-PEP-1', 'Pepsi');

        $res = ProductAutoSync::ensureKeys(['Pepsi']);
        self::assertSame(['Pepsi'], $res['created'], 'Le Pepsi classique est un produit distinct du Pepsi Zero.');
    }

    /**
     * « Crunch » reste rattaché à « crunch Snak Barres » (même produit,
     * arbitrage W1) : descripteur MULTI-mots « Snak Barres » unique dans
     * la carte → raccourci non ambigu, aucune fiche doublon créée.
     */
    public function test_crunch_raccourci_non_ambigu_reste_rattache(): void
    {
        $this->insertCard('crunch Snak Barres');
        $this->insertSale('T-CRU-1', 'Crunch');

        $res = ProductAutoSync::ensureKeys(['Crunch']);
        self::assertSame([], $res['created'], '« Crunch » = « crunch Snak Barres » : le rapprochement doit rester.');
        self::assertSame(1, $this->productCount(), 'Aucune fiche doublon « Crunch » à côté de « crunch Snak Barres ».');
    }

    /**
     * Raccourci AMBIGU refusé : « Fanta » face à plusieurs fiches « Fanta … »
     * ne choisit pas — la fiche « Fanta » est créée (l'admin arbitrera).
     */
    public function test_raccourci_ambigu_refuse(): void
    {
        $this->insertCard('Fanta Orange');
        $this->insertCard('Fanta Citron');
        $this->insertSale('T-FA-1', 'Fanta');

        $res = ProductAutoSync::ensureKeys(['Fanta']);
        self::assertSame(['Fanta'], $res['created'], 'Deux candidats → refus de choisir par heuristique.');
    }

    /**
     * Conditionnement seul = même produit : « Sucette 1 sucette » reste
     * sur « sucette » et « Coca-Cola 33cl » sur « Coca Cola » (mêmes mots
     * alphabétiques, seuls les jetons chiffrés diffèrent).
     */
    public function test_conditionnement_seul_reste_rattache(): void
    {
        $this->insertCard('sucette');
        $this->insertSale('T-SU-1', 'Sucette 1 sucette');
        self::assertSame(
            [],
            ProductAutoSync::ensureKeys(['Sucette 1 sucette'])['created'],
            '« Sucette 1 sucette » reste rattaché à « sucette » (conditionnement seul).'
        );

        $this->insertCard('Coca Cola');
        $this->insertSale('T-CC-1', 'Coca-Cola 33cl');
        self::assertSame(
            [],
            ProductAutoSync::ensureKeys(['Coca-Cola 33cl'])['created'],
            '« Coca-Cola 33cl » reste rattaché à « Coca Cola » (contenance seule).'
        );
        self::assertSame(2, $this->productCount());
    }

    /**
     * Alias admin (product_aliases) : vérité curatée — « Oasis » mène à
     * la fiche « Oasis Tropical », aucune création (même lecture que
     * StockPublic::matchKeyViaAlias : égalité normalisée, jamais d'inclusion).
     */
    public function test_alias_admin_relie_le_libelle_a_sa_fiche(): void
    {
        $this->insertCard('Oasis Tropical');
        $this->pdo()->exec(
            "INSERT INTO product_aliases (id, raw_description, product_key, kind) VALUES ('al_w1', 'Oasis', 'Oasis Tropical', 'label')"
        );
        $this->insertSale('T-OA-1', 'Oasis');

        self::assertSame([], ProductAutoSync::ensureKeys(['Oasis'])['created']);
        self::assertSame(1, $this->productCount());
    }

    // -----------------------------------------------------------------
    //  Helpers
    // -----------------------------------------------------------------

    /** Insère une fiche carte existante (pré-condition des tests W1). */
    private function insertCard(string $name): void
    {
        $this->pdo()->prepare(
            'INSERT INTO products (id, name, category_id, price, stock, is_available, is_active, `order`)
             VALUES (?, ?, ?, 1.000, 0, 1, 1, 1)'
        )->execute(['prod_' . bin2hex(random_bytes(6)), $name, 'cat_boissons']);
    }

    private function pdo(): PDO
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::fail('Base aeic_test perdue en cours de test.');
        }

        return $pdo;
    }

    private function insertSale(string $ref, string $description, float $price = 1.0): void
    {
        $batchId = ImportBatch::create(['filename' => 'autosync-test.csv']);
        Sale::importBatch($batchId, [
            [
                'transaction_ref'  => $ref,
                'sold_at'          => '2026-10-22 10:00:00',
                'payment_method'   => 'CARTE',
                'payment_raw'      => 'Visa - Débit',
                'quantity'         => 1,
                'description'      => $description,
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
            ],
        ]);
    }

    private function insertPurchase(string $key, int $noStock = 0): void
    {
        $this->pdo()->prepare(
            "INSERT INTO purchases (id, purchased_at, product_key, quantity, unit_cost, total_ttc, total_ht, no_stock)
             VALUES (?, '2026-10-22', ?, 1, 1.000, 1.000, 1.000, ?)"
        )->execute(['pur_' . bin2hex(random_bytes(6)), $key, $noStock]);
    }

    private function insertCount(string $key, int $qty): void
    {
        $this->pdo()->prepare(
            "INSERT INTO inventory_counts (id, counted_at, product_key, counted_qty, theoretical_qty, gap)
             VALUES (?, '2026-10-22 09:00:00', ?, ?, ?, 0)"
        )->execute(['inv_' . bin2hex(random_bytes(6)), $key, $qty, $qty]);
    }

    private function productCount(): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM products')->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    private function productByName(string $name): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM products WHERE name = ?');
        $stmt->execute([$name]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}
