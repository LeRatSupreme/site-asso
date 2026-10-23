<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Model;
use App\Models\ProductAlias;
use App\Models\Sale;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests du repointage des clés produits déplacées par le ré-applique
 * du mapping (compta.alias.apply) : quand la clé canonique d'un
 * libellé change, les ventes suivent la nouvelle clé ET les autres
 * tables à product_key (coûts, stocks…) sont fusionnées derrière via
 * ProductKeyMerge, au lieu de rester orphelines.
 *
 *  - Sale::displacedKeyPlan() : méthode PURE, testée sans base ;
 *  - intégration sur aeic_test : une vente, un alias, un lot de coûts
 *    et un stock sous l'ancienne clé doivent tous suivre la nouvelle.
 *
 * Saute automatiquement si la base aeic_test est indisponible.
 */
final class AliasKeyRepointTest extends TestCase
{
    use TestDatabaseTrait;

    private ?PDO $pdo = null;

    private function requireDatabase(): bool
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped('Base aeic_test indisponible : test de repointage ignoré.');

            return false;
        }
        $this->pdo = $pdo;

        // Toutes les tables touchées par ProductKeyMerge::merge pendant
        // le repointage : si l'une manque, la fusion échouerait à mi-course
        // (le repointage est alors journalisé, pas appliqué).
        try {
            foreach ([
                'sales', 'product_aliases', 'product_costs', 'product_stocks',
                'promotions', 'inventory_tablet_lines', 'product_discontinued',
                'product_infinite', 'product_zero_since', 'product_packs',
                'shopping_checks', 'products', 'cafeteria_order_items',
            ] as $table) {
                $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
            }
        } catch (\Throwable) {
            self::markTestSkipped("Table produits absente de aeic_test : importez database/schema.sql.");

            return false;
        }

        $this->reset($pdo, [
            'sales', 'import_batches', 'product_aliases', 'product_costs',
            'promotions', 'inventory_tablet_lines', 'product_discontinued',
            'product_infinite', 'product_zero_since', 'product_packs',
            'shopping_checks', 'products', 'cafeteria_order_items', 'product_stocks',
        ]);
        // scan_labels est optionnelle (purge tolérante dans merge) :
        // purgeée seulement si elle existe.
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

    public function test_plan_cle_entierement_deplacee(): void
    {
        $plan = Sale::displacedKeyPlan(
            ['Lipton' => ['Lipton']],
            ['Lipton' => ['Lipton Ice Tea']]
        );

        self::assertSame(['Lipton' => 'Lipton Ice Tea'], $plan);
    }

    public function test_plan_cle_encore_presente_ailleurs_ignoree(): void
    {
        // « AncienneCle » disparaît du libellé « A » mais reste portée
        // par le libellé « B » (non résolu, sa clé fait foi) : rien à
        // fusionner, la clé est toujours en vie dans sales.
        $plan = Sale::displacedKeyPlan(
            ['A' => ['AncienneCle'], 'B' => ['AncienneCle']],
            ['A' => ['NouvelleCle'], 'B' => ['AncienneCle']]
        );

        self::assertSame([], $plan);
    }

    public function test_plan_deux_cibles_distinctes_ignoree(): void
    {
        // Deux libellés portaient « K1 » et résolvent vers des clés
        // distinctes : ambigu, conservateur → ignoré.
        $plan = Sale::displacedKeyPlan(
            ['Libelle un' => ['K1'], 'Libelle deux' => ['K1']],
            ['Libelle un' => ['Cible un'], 'Libelle deux' => ['Cible deux']]
        );

        self::assertSame([], $plan);
    }

    public function test_plan_cle_identique_a_la_cible_ignoree(): void
    {
        // La clé est toujours là après réécriture : rien à faire.
        $plan = Sale::displacedKeyPlan(
            ['Libelle' => ['K1']],
            ['Libelle' => ['K1']]
        );

        self::assertSame([], $plan);
    }

    public function test_plan_casse_differente_renvolee(): void
    {
        // Consolider deux variantes de casse est un renommage légitime.
        $plan = Sale::displacedKeyPlan(
            ['Bowling' => ['Bowling']],
            ['Bowling' => ['bowling']]
        );

        self::assertSame(['Bowling' => 'bowling'], $plan);
    }

    public function test_plan_plusieurs_anciennes_cles_vers_une_cible(): void
    {
        // Un libellé portait deux anciennes clés, toutes deux disparues
        // au profit de la même cible : les deux paires sont émises.
        $plan = Sale::displacedKeyPlan(
            ['Libelle' => ['K1', 'K2']],
            ['Libelle' => ['Cible']]
        );

        self::assertSame(['K1' => 'Cible', 'K2' => 'Cible'], $plan);
    }

    public function test_apply_repointe_couts_et_stocks_vers_la_nouvelle_cle(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        // Alias dont la cible change : les ventes « LibelleX » portaient
        // l'ancienne clé, l'appliquer les réécrit vers la nouvelle.
        ProductAlias::save(['raw_description' => 'LibelleX', 'product_key' => 'NouvelleCle']);
        $this->pdo->prepare(
            "INSERT INTO sales (id, transaction_ref, sold_at, payment_method, quantity, description, product_key, price_ttc)
             VALUES (?, ?, '2026-10-01 12:00:00', 'CARTE', 1, ?, ?, 1.0)"
        )->execute(['sal_repoint_1', 'tx_repoint_1', 'LibelleX', 'AncienneCle']);

        // Lot de coûts et stock de référence sous l'ANCIENNE clé : ce
        // sont eux qui devenaient orphelins avant le repointage.
        $this->pdo->prepare(
            "INSERT INTO product_costs (id, product_key, cost_price, valid_from, created_at)
             VALUES ('cost_test1', 'AncienneCle', 1.5, '2026-09-01', NOW())"
        )->execute();
        $this->pdo->prepare(
            "INSERT INTO product_stocks (product_key, stock) VALUES ('AncienneCle', 5)"
        )->execute();

        $result = Sale::reapplyAliases();

        // Ventes et plan de repointage.
        $stmt = $this->pdo->prepare('SELECT product_key FROM sales WHERE description = ?');
        $stmt->execute(['LibelleX']);
        self::assertSame('NouvelleCle', (string) $stmt->fetchColumn(), 'Les ventes suivent la nouvelle clé.');
        self::assertSame(['AncienneCle' => 'NouvelleCle'], $result['repointed'], 'La clé déplacée est repointée.');

        // Le lot de coûts suit, montant conservé.
        $cost = $this->pdo->query(
            "SELECT product_key, cost_price FROM product_costs WHERE id = 'cost_test1'"
        )->fetch();
        self::assertNotFalse($cost, 'Le lot de coûts existe toujours (jamais supprimé).');
        self::assertSame('NouvelleCle', (string) $cost['product_key']);
        self::assertSame(1.5, (float) $cost['cost_price'], 'Le coût est conservé à l\'identique.');

        // La ligne de stock suit (ancienne clé disparue, stock conservé).
        self::assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM product_stocks WHERE product_key = 'AncienneCle'")->fetchColumn(),
            'L\'ancienne clé ne laisse aucune ligne de stock orpheline.'
        );
        $stock = $this->pdo->query(
            "SELECT stock FROM product_stocks WHERE product_key = 'NouvelleCle'"
        )->fetchColumn();
        self::assertNotFalse($stock, 'La ligne de stock est déplacée vers la nouvelle clé.');
        self::assertSame(5, (int) $stock);
    }
}
