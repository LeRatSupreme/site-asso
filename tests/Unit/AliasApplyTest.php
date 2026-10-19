<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Model;
use App\Models\ProductAlias;
use App\Models\Sale;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests du « ré-appliquer le mapping aux ventes » (action
 * compta.alias.apply) : une vente déjà rattachée à une clé dont le
 * libellé ne résout AUCUN alias garde sa clé (un « appliquer » ne
 * ressuscite pas les clés fusionnées), une vente sans clé reçoit le
 * libellé brut, et un libellé résolu propage la clé canonique du
 * mapping (y compris quand la cible d'un alias change).
 *
 * Saute automatiquement si la base aeic_test est indisponible.
 */
final class AliasApplyTest extends TestCase
{
    use TestDatabaseTrait;

    private ?PDO $pdo = null;

    private function requireDatabase(): bool
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped('Base aeic_test indisponible : test d\'apply ignoré.');

            return false;
        }
        $this->pdo = $pdo;

        foreach (['sales', 'product_aliases'] as $table) {
            try {
                $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
            } catch (\Throwable) {
                self::markTestSkipped("Table `$table` absente de aeic_test : importez database/schema.sql.");

                return false;
            }
        }

        $this->reset($pdo, ['sales', 'import_batches', 'product_aliases']);
        Model::setTestPdo($pdo);

        return true;
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
    }

    private function addSale(string $description, ?string $productKey): void
    {
        $this->pdo->prepare(
            "INSERT INTO sales (id, transaction_ref, sold_at, payment_method, quantity, description, product_key, price_ttc)
             VALUES (?, ?, '2026-10-01 12:00:00', 'CARTE', 1, ?, ?, 1.0)"
        )->execute([
            'sal_' . bin2hex(random_bytes(6)),
            'tx_' . bin2hex(random_bytes(8)),
            $description,
            $productKey,
        ]);
    }

    private function keyOf(string $description): ?string
    {
        $stmt = $this->pdo->prepare('SELECT product_key FROM sales WHERE description = ? LIMIT 1');
        $stmt->execute([$description]);

        $key = $stmt->fetchColumn();

        return $key !== false ? (string) $key : null;
    }

    public function test_apply_garde_la_cle_existante_d_un_libelle_non_resolution(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        // Vente déjà rattachée à « Cible » (fusion passée) et vente sans
        // clé : aucun alias ne couvre ces libellés.
        $this->addSale('Kakaoxy', 'Cible');
        $this->addSale('Kakaoxy2', null);

        self::assertNull(ProductAlias::resolve('Kakaoxy'));
        self::assertNull(ProductAlias::resolve('Kakaoxy2'));

        // Décision par vente (helper du modèle) : clé existante
        // conservée, libellé brut pour une vente sans clé.
        self::assertNull(Sale::resolvedKeyForApply('Kakaoxy', 'Cible'), 'Vente déjà cléée : ne pas toucher.');
        self::assertSame('Kakaoxy2', Sale::resolvedKeyForApply('Kakaoxy2', null), 'Vente sans clé : le libellé devient la clé.');
        self::assertSame('Kakaoxy2', Sale::resolvedKeyForApply('Kakaoxy2', ''), 'Clé vide (chaîne) : le libellé devient la clé.');

        Sale::reapplyAliases();

        self::assertSame('Cible', $this->keyOf('Kakaoxy'), 'La clé existante fait foi : jamais de résurrection.');
        self::assertSame('Kakaoxy2', $this->keyOf('Kakaoxy2'), 'La vente sans clé reçoit son libellé brut.');
    }

    public function test_apply_propage_la_cle_canonique_d_un_libelle_resolution(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        // Alias explicite : la cible canonique doit s'appliquer à TOUTES
        // les ventes du libellé, clé renseignée ou non (changement de
        // cible d'un alias).
        ProductAlias::save(['raw_description' => 'Kakaoxy3', 'product_key' => 'Cible3', 'category' => 'Boissons']);
        $this->addSale('Kakaoxy3', null);
        $this->addSale('Kakaoxy3', 'AncienneCle');

        self::assertSame('Cible3', Sale::resolvedKeyForApply('Kakaoxy3', 'AncienneCle'));

        Sale::reapplyAliases();

        $stmt = $this->pdo->prepare('SELECT product_key, category FROM sales WHERE description = ? ORDER BY product_key');
        $stmt->execute(['Kakaoxy3']);
        $rows = $stmt->fetchAll();

        self::assertSame('Boissons', (string) $rows[0]['category'], 'La catégorie de l\'alias comble les ventes.');
        foreach ($rows as $row) {
            self::assertSame('Cible3', (string) $row['product_key'], 'La clé canonique est propagée partout.');
        }
    }
}
