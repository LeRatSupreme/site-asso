<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Model;
use App\Models\ProductAlias;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests du « kind » d'alias : 'appellation' (libellé long de
 * facture/scan, listé sur la page « Ajouter une appellation ») vs
 * 'label' (alias produit court, listé sur « Mapping des libellés »).
 *
 * Les deux kinds résolvent pareil aux imports (resolve()) : seule la
 * présentation par page change.
 *
 * Saute automatiquement si la base aeic_test est indisponible.
 */
final class ProductAliasKindTest extends TestCase
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
            $pdo->query('SELECT 1 FROM product_aliases LIMIT 1');
        } catch (\Throwable) {
            self::markTestSkipped('Table product_aliases absente de aeic_test : importez database/schema.sql.');
        }

        $this->applyAliasKindMigration($pdo);
        $this->reset($pdo, ['product_aliases']);
        Model::setTestPdo($pdo);
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
    }

    public function test_une_appellation_vit_sur_sa_page_et_resolution_pareille(): void
    {
        ProductAlias::save(['raw_description' => 'CRISTALI 5OCL PET', 'product_key' => 'Eau', 'kind' => 'appellation']);
        ProductAlias::save(['raw_description' => 'Eau', 'product_key' => 'Eau', 'category' => 'Boissons']);

        $labels = array_column(ProductAlias::allLabels(), 'raw_description');
        $apps = array_column(ProductAlias::appellations(), 'raw_description');

        self::assertSame(['Eau'], $labels, 'Le Mapping des libellés ne montre que les alias produits.');
        self::assertSame(['CRISTALI 5OCL PET'], $apps, 'La page appellations liste les libellés longs.');

        self::assertSame('Eau', ProductAlias::resolve('CRISTALI 5OCL PET'), 'Une appellation résout comme un alias.');
    }

    public function test_mise_a_jour_sans_kind_preserve_la_classe(): void
    {
        ProductAlias::save(['raw_description' => 'VINAIGRE RIZ ÎL', 'product_key' => 'Epicerie', 'kind' => 'appellation']);

        // Grille des catégories du Mapping : save() sans kind ne doit
        // JAMAIS reclasser une appellation en alias produit.
        ProductAlias::save(['raw_description' => 'VINAIGRE RIZ ÎL', 'product_key' => 'Epicerie', 'category' => 'Divers']);

        $apps = ProductAlias::appellations();
        self::assertCount(1, $apps);
        self::assertSame('Divers', (string) $apps[0]['category'], 'La catégorie suit, le kind reste appellation.');
        self::assertSame([], array_column(ProductAlias::allLabels(), 'raw_description'), 'Toujours hors Mapping.');
    }

    public function test_ressaisir_un_libelle_le_reclasse_en_appellation(): void
    {
        ProductAlias::save(['raw_description' => 'Cristaline', 'product_key' => 'Eau']);

        // L'utilisateur ressaisit « Cristaline » sur la page appellations.
        ProductAlias::save(['raw_description' => 'Cristaline', 'product_key' => 'Eau', 'kind' => 'appellation']);

        self::assertSame(['Cristaline'], array_column(ProductAlias::appellations(), 'raw_description'));
        self::assertSame([], array_column(ProductAlias::allLabels(), 'raw_description'));
    }

    public function test_kind_inconnu_refuse(): void
    {
        $id = ProductAlias::save(['raw_description' => 'Test', 'product_key' => 'P', 'kind' => 'nimportequoi']);

        $stmt = $this->pdo->prepare('SELECT kind FROM product_aliases WHERE id = ?');
        $stmt->execute([$id]);
        self::assertSame('label', (string) $stmt->fetchColumn(), 'Un kind invalide retombe sur label.');
    }
}
