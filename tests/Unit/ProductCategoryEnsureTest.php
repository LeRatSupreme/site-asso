<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Model;
use App\Models\Product;
use App\Models\ProductCategory;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Gardes structurels de ProductCategory (mission E — le trou par
 * lequel les catégories pourries entraient) :
 *
 *  - ensure() normalise le nom (trim, casse, accents, séparateurs —
 *    AliasSuggester::normalizeKey) et ABSORBE les quasi-doublons :
 *    « Boissond »/« Boisson » ne créent aucune ligne quand
 *    « Boissons » existe (distance de Levenshtein <= 1, noms >= 4
 *    caractères) ; « Evenement » ≡ « Événement » (formes normalisées
 *    identiques) ;
 *  - chaque création effective est auditée ('category.ensure', source
 *    « synchro » ; $auto = false → 'category.save', source « admin ») ;
 *  - purgeAutoCreatedEmpty() ne supprime QUE les catégories
 *    auto-créées (tracées 'category.ensure') devenues VIDES
 *    (0 produit, 0 alias au nom) : une catégorie créée via le
 *    formulaire admin ('category.save') ou sans trace d'audit
 *    (historique) est INTOUCHABLE.
 *
 * Saute automatiquement si la base aeic_test est indisponible.
 * JAMAIS la base de production (garde TestDatabaseTrait/db_guard).
 */
final class ProductCategoryEnsureTest extends TestCase
{
    use TestDatabaseTrait;

    protected function setUp(): void
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped('Base aeic_test indisponible : test ProductCategory ignoré.');

            return;
        }

        foreach (['product_categories', 'products', 'product_aliases', 'audit_logs'] as $table) {
            try {
                $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
            } catch (\Throwable) {
                self::markTestSkipped("Table `$table` absente de aeic_test : importez database/schema.sql.");

                return;
            }
        }

        $this->reset($pdo, ['product_categories', 'products', 'product_aliases', 'audit_logs']);
        Model::setTestPdo($pdo);
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
    }

    // -----------------------------------------------------------------
    //  Normalisation + absorption des quasi-doublons
    // -----------------------------------------------------------------

    /** « Boissond » (faute de frappe) est absorbé par « Boissons » : aucune nouvelle ligne. */
    public function test_boissond_absorbe_par_boissons_existant(): void
    {
        $boissons = ProductCategory::ensure('Boissons', 'u_test');
        self::assertNotNull($boissons, 'Premier appel : la catégorie est créée.');

        // Fautes de frappe proches : absorbées, aucune ligne en plus.
        self::assertNull(ProductCategory::ensure('Boissond', 'u_test'), 'Distance 1 → id existant réutilisé, pas de ligne.');
        self::assertNull(ProductCategory::ensure('Boisson', 'u_test'), 'Distance 1 → absorbée aussi.');
        self::assertNull(ProductCategory::ensure('boissons', 'u_test'), 'Casse ignorée.');
        self::assertNull(ProductCategory::ensure(' Boissons ', 'u_test'), 'Trim.');

        self::assertSame(1, $this->categoryCount(), 'Une seule ligne « Boissons » — aucun quasi-doublon créé.');
    }

    /** « Evenement » ≡ « Événement » une fois normalisés : doublon absorbé. */
    public function test_evenement_dup_absorbe(): void
    {
        ProductCategory::ensure('Événement', 'u_test');

        self::assertNull(ProductCategory::ensure('Evenement', 'u_test'), 'Sans accent = même forme normalisée → absorbé.');
        self::assertNull(ProductCategory::ensure('ÉVÉNEMENT', 'u_test'), 'Casse + accents → absorbé.');

        self::assertSame(1, $this->categoryCount());
    }

    /** Un VRAI nouveau nom crée bien la ligne (l'absorption ne bloque pas tout). */
    public function test_nom_reellement_nouveau_cree(): void
    {
        ProductCategory::ensure('Boissons', 'u_test');

        $id = ProductCategory::ensure('Hygiène', 'u_test');
        self::assertNotNull($id);
        self::assertSame(2, $this->categoryCount(), '« Hygiène » (distance > 1 de tout existant) est créée.');
    }

    /** Deux mots courts différents (distance 1 mais < 4 caractères) ne sont PAS fusionnés. */
    public function test_mots_cours_non_fusionnes(): void
    {
        ProductCategory::ensure('Eau', 'u_test');

        self::assertNotNull(ProductCategory::ensure('Eaux', 'u_test'), 'Distance 1 mais noms < 4 caractères : la règle prudente ne fusionne pas.');
        self::assertSame(2, $this->categoryCount());
    }

    // -----------------------------------------------------------------
    //  Audit de création
    // -----------------------------------------------------------------

    /** La création effective (auto) écrit une entrée audit 'category.ensure' avec l'id créé. */
    public function test_creation_auto_auditee(): void
    {
        $id = ProductCategory::ensure('Truc exotique', 'u_test');
        self::assertNotNull($id);

        self::assertSame(1, $this->auditCount('category.ensure', $id), 'Audit category.ensure présent, entité = la catégorie créée.');
        self::assertSame(0, $this->auditCount('category.save', $id), 'Création auto : pas d\'audit category.save.');
    }

    /** La création manuelle ($auto = false, bouton admin) est auditée 'category.save' — jamais purgeable. */
    public function test_creation_manuelle_auditee_category_save(): void
    {
        $id = ProductCategory::ensure('Catégorie manuelle', 'u_test', false);
        self::assertNotNull($id);

        self::assertSame(1, $this->auditCount('category.save', $id));
        self::assertSame(0, $this->auditCount('category.ensure', $id));
    }

    // -----------------------------------------------------------------
    //  Purge des catégories auto-créées vides
    // -----------------------------------------------------------------

    /** Catégorie auto-créée devenue vide (0 produit, 0 alias) : purgée, avec audit. */
    public function test_purge_categorie_auto_creee_devenue_vide(): void
    {
        $id = ProductCategory::ensure('Snacks divers', 'u_test');
        self::assertNotNull($id);
        self::assertSame(1, $this->categoryCount());

        $purged = ProductCategory::purgeAutoCreatedEmpty('u_test');

        self::assertSame(1, $purged);
        self::assertSame(0, $this->categoryCount(), 'La catégorie auto-créée vide est supprimée après import.');
        self::assertSame(1, $this->auditCount('category.purge', $id), 'La suppression est auditée.');
    }

    /** Catégorie auto-créée HABITÉE par un produit : jamais purgée. */
    public function test_purge_epargne_categorie_habitee_par_un_produit(): void
    {
        $boissons = ProductCategory::ensure('Boissons', 'u_test');
        self::assertNotNull($boissons);

        Product::save([
            'name'        => 'Coca cola',
            'price'       => 1.0,
            'category_id' => $boissons,
            'stock'       => 0,
            'is_active'   => 1,
            'order'       => 1,
        ]);

        self::assertSame(0, ProductCategory::purgeAutoCreatedEmpty('u_test'));
        self::assertSame(1, $this->categoryCount(), 'Une catégorie avec un produit reste en place.');
    }

    /** Catégorie auto-créée référencée par un ALIAS (par nom) : jamais purgée. */
    public function test_purge_epargne_categorie_referencee_par_alias(): void
    {
        $id = ProductCategory::ensure('Redbull', 'u_test');
        self::assertNotNull($id);

        $name = (string) $this->categoryName($id);
        $this->pdo()->exec(
            "INSERT INTO product_aliases (id, raw_description, product_key, category) VALUES ('al_t1', 'Red bull blue', 'Redbull blue', " . $this->pdo()->quote($name) . ')'
        );

        self::assertSame(0, ProductCategory::purgeAutoCreatedEmpty('u_test'));
        self::assertSame(1, $this->categoryCount(), 'Une catégorie citée par un alias reste en place.');
    }

    /** Catégorie créée via le formulaire admin (sans trace 'category.ensure') : INTOUCHABLE. */
    public function test_purge_epargne_categorie_manuelle_meme_vide(): void
    {
        // Chemin formulaire : ProductCategory::save() (saveCategory admin),
        // audité 'category.save' par le contrôleur.
        ProductCategory::save(['name' => 'Manuelle vide', 'order' => 1, 'is_active' => 1]);

        // Chemin bouton mapping admin : ensure($auto = false).
        ProductCategory::ensure('Autre manuelle', 'u_test', false);

        self::assertSame(0, ProductCategory::purgeAutoCreatedEmpty('u_test'));
        self::assertSame(2, $this->categoryCount(), 'Les catégories manuelles vides ne sont JAMAIS purgées.');
    }

    /** Une catégorie auto-créée puis RETOUCHÉE via le formulaire admin (audit 'category.save') devient intouchable. */
    public function test_purge_epargne_categorie_auto_puis_editee_admin(): void
    {
        $id = ProductCategory::ensure('Editable', 'u_test');
        self::assertNotNull($id);

        // Le formulaire admin repasse par save() + audit 'category.save'.
        ProductCategory::save(['id' => $id, 'name' => 'Editable', 'description' => 'retouchée', 'order' => 5, 'is_active' => 1]);
        $this->pdo()->exec(
            "INSERT INTO audit_logs (id, action, entity_type, entity_id) VALUES ('aud_t1', 'category.save', 'product_category', " . $this->pdo()->quote($id) . ')'
        );

        self::assertSame(0, ProductCategory::purgeAutoCreatedEmpty('u_test'));
        self::assertSame(1, $this->categoryCount(), 'Retouchée à la main → pilotée manuellement, jamais purgée.');
    }

    /** Purge idempotente : rien à purger → 0, aucune erreur. */
    public function test_purge_sans_candidat(): void
    {
        self::assertSame(0, ProductCategory::purgeAutoCreatedEmpty('u_test'));
    }

    // -----------------------------------------------------------------
    //  Helpers
    // -----------------------------------------------------------------

    private function pdo(): PDO
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::fail('Base aeic_test perdue en cours de test.');
        }

        return $pdo;
    }

    private function categoryCount(): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM product_categories')->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    private function categoryRow(string $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM product_categories WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function categoryName(string $id): string
    {
        $row = $this->categoryRow($id);
        self::assertNotNull($row);

        return (string) ($row['name'] ?? '');
    }

    private function auditCount(string $action, string $entityId): int
    {
        $stmt = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM audit_logs WHERE action = ? AND entity_type = \'product_category\' AND entity_id = ?'
        );
        $stmt->execute([$action, $entityId]);

        return (int) $stmt->fetchColumn();
    }
}
