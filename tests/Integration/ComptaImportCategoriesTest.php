<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Test d'intégration des garde-fous catégories de l'import SumUp
 * (route POST /admin/compta/import — mission E) :
 *
 *  - une catégorie EXOTIQUE (colonne « Catégorie » du CSV / mapping
 *    d'alias, ex. « Boissond ») ne crée AUCUNE nouvelle catégorie
 *    carte : le quasi-doublon est ABSORBÉ par « Boissons » existant
 *    (ProductCategory::ensure) ;
 *  - une catégorie auto-créée par un import ANTÉRIEUR et devenue vide
 *    (alias renommé entre-temps, 0 produit) est PURGÉE par l'import ;
 *  - une catégorie créée via le formulaire admin (audit
 *    'category.save') reste INTOUCHABLE.
 *
 * Le login admin est forcé (bypass 2FA via APP_TESTING) pour isoler le
 * test du flux d'authentification, déjà couvert par ailleurs.
 */
final class ComptaImportCategoriesTest extends IntegrationTestCase
{
    private string $adminId = 'u_admin_cats';
    private string $csvPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->requireDatabase();
        $this->reset([
            'sale_adjustments', 'sales', 'import_batches', 'product_aliases', 'product_categories',
            'products', 'audit_logs', 'purchases', 'losses', 'inventory_counts',
            'product_stocks', 'scan_labels', 'settings', 'users',
        ]);
        $this->applyImportDedupMigration();

        $this->seedUser($this->adminId, 'admin-cats@exemple.fr', 'Password123456', 'ADMIN');
    }

    protected function tearDown(): void
    {
        if (is_file($this->csvPath)) {
            @unlink($this->csvPath);
        }
        parent::tearDown();
    }

    /**
     * Import d'un CSV dont le mapping d'alias porte une catégorie
     * exotique (« Boissond », faute de frappe de « Boissons ») : aucune
     * nouvelle catégorie carte n'apparaît, la purge nettoie au passage
     * une catégorie auto-créée d'un import antérieur devenue vide.
     */
    public function test_import_categorie_exotique_aucune_nouvelle_categorie_carte(): void
    {
        // Catégories légitimes préexistantes sur la carte (les trois
        // standards + celle visée par la faute de frappe).
        $this->pdo->exec(
            "INSERT INTO product_categories (id, name, `order`, is_active) VALUES
             ('cat_boissons', 'Boissons', 1, 1),
             ('cat_snacks', 'Snacks', 2, 1),
             ('cat_special', 'Spécial', 3, 1)"
        );

        // Alias du mapping portant la catégorie exotique du CSV (faute de
        // frappe : « Boissond » au lieu de « Boissons »).
        $this->pdo->exec(
            "INSERT INTO product_aliases (id, raw_description, product_key, category, kind)
             VALUES ('al_t_coca', 'Coca zero', 'Coca zero', 'Boissond', 'label')"
        );

        // Cicatrice d'un import ANTÉRIEUR : catégorie auto-créée à l'époque
        // (trace laissée par ProductCategory::ensure : audit
        // 'category.ensure' + entité = la catégorie) puis vidée (son
        // alias a été renommé entre-temps) — elle doit être purgée par
        // CET import.
        $this->pdo->exec(
            "INSERT INTO product_categories (id, name, `order`, is_active) VALUES ('cat_t_vieux', 'Vieux rayon', 5, 1)"
        );
        $this->pdo->exec(
            "INSERT INTO audit_logs (id, action, entity_type, entity_id)
             VALUES ('aud_t_ensure', 'category.ensure', 'product_category', 'cat_t_vieux')"
        );
        // Au moment de cet import antérieur, un alias référençait encore
        // la catégorie ; l'admin l'a depuis renommée → « Vieux rayon »
        // n'est plus référencée nulle part (0 produit, 0 alias).
        $this->pdo->exec(
            "INSERT INTO product_aliases (id, raw_description, product_key, category, kind)
             VALUES ('al_t_vieux', 'Vieux produit', 'Vieux produit', 'Vieux rayon', 'label')"
        );
        $this->pdo->exec("UPDATE product_aliases SET category = 'Boissons' WHERE id = 'al_t_vieux'");

        $this->writeCsv(
            "1 juin 2026 09:59,Vente,TCAT001,Visa - Débit,1,Coca zero,Boissond,,EUR,1,0,1,1,0,,Alex"
        );

        $r = $this->request('POST', '/admin/compta/import', [], $this->files(), $this->adminId);
        self::assertStringContainsString('/admin/compta/import', $this->location($r));

        // Aucune catégorie carte en plus : « Boissond » a été absorbée par
        // « Boissons », « Vieux rayon » a été purgée.
        $categories = $this->pdo->query('SELECT name FROM product_categories ORDER BY name')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(
            ['Boissons', 'Snacks', 'Spécial'],
            array_map('strval', $categories),
            'Ni « Boissond » (absorbée), ni « Vieux rayon » (purgée) : seulement les catégories d\'origine.'
        );

        // La vente du lot crée bien sa fiche (garde C positive) — c'est
        // le comportement voulu : la VENTE justifie la fiche.
        $fiche = (int) $this->pdo->query("SELECT COUNT(*) FROM products WHERE name = 'Coca zero'")->fetchColumn();
        self::assertSame(1, $fiche, 'La ligne vendue obtient sa fiche carte via ProductAutoSync.');

        // La purge est tracée.
        $purged = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'category.purge' AND entity_id = 'cat_t_vieux'"
        )->fetchColumn();
        self::assertSame(1, $purged, 'La purge de « Vieux rayon » est auditée.');
    }

    /** Une catégorie créée via le formulaire admin reste en place après un import. */
    public function test_import_ne_purge_pas_une_categorie_admin(): void
    {
        // Catégories standards (la fiche de la ligne vendue pointera
        // cat_snacks) + la catégorie du formulaire admin, vide.
        $this->pdo->exec(
            "INSERT INTO product_categories (id, name, `order`, is_active) VALUES
             ('cat_boissons', 'Boissons', 1, 1),
             ('cat_snacks', 'Snacks', 2, 1),
             ('cat_special', 'Spécial', 3, 1),
             ('cat_t_manuelle', 'Manuelle vide', 9, 1)"
        );
        $this->pdo->exec(
            "INSERT INTO audit_logs (id, action, entity_type, entity_id)
             VALUES ('aud_t_cat1', 'category.save', 'product_category', 'cat_t_manuelle')"
        );

        $this->writeCsv(
            "1 juin 2026 10:01,Vente,TCAT002,Visa - Débit,1,Bueno,Nourriture,,EUR,1,0,1,1,0,,Alex"
        );

        $r = $this->request('POST', '/admin/compta/import', [], $this->files(), $this->adminId);
        self::assertStringContainsString('/admin/compta/import', $this->location($r));

        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM product_categories WHERE id = 'cat_t_manuelle'"
        )->fetchColumn();
        self::assertSame(1, $count, 'Une catégorie du formulaire admin (même vide) n\'est jamais purgée.');
    }

    // -----------------------------------------------------------------
    //  Helpers
    // -----------------------------------------------------------------

    private function writeCsv(string $dataLine): void
    {
        $csv = "Date,Type,Réf. transaction,Moyen de paiement,Quantité,Description,Catégorie,SKU,Devise,Prix avant réduction,Réduction,Prix (TTC),Prix (HT),TVA,Taux de TVA,Compte"
            . "\n" . $dataLine;
        $this->csvPath = sys_get_temp_dir() . '/aeic_import_cats_' . bin2hex(random_bytes(4)) . '.csv';
        file_put_contents($this->csvPath, $csv);
    }

    /** @return array<string,array<string,string>> */
    private function files(): array
    {
        return ['csv' => ['name' => 'rapport-categories.csv', 'tmp_name' => $this->csvPath]];
    }

    /**
     * Applique (best effort) database/migrations/2026_import_dedup.sql :
     * chaque statement est indépendant, une erreur (déjà appliqué) est ignorée.
     */
    private function applyImportDedupMigration(): void
    {
        $statements = [
            "UPDATE sales SET description = '' WHERE description IS NULL",
            'ALTER TABLE sales MODIFY description VARCHAR(255) NOT NULL DEFAULT \'\'',
            'ALTER TABLE import_batches ADD COLUMN file_hash CHAR(64) NULL AFTER filename',
            'ALTER TABLE import_batches ADD UNIQUE KEY uniq_import_hash (file_hash)',
        ];
        foreach ($statements as $sql) {
            try {
                $this->pdo->exec($sql);
            } catch (\PDOException) {
                // Déjà appliqué : non bloquant.
            }
        }
    }
}
