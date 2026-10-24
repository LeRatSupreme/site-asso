<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Model;
use App\Models\ProductCost;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests des invariants des lots de coût de revient (table product_costs) :
 *
 *  - « au plus un lot en cours » par produit : create ferme le lot
 *    précédent, normalizePeriods répare les états impossibles (fusions
 *    brutes de product_key, update() de valid_from sans rééquilibrage),
 *    delete réouvre le lot antérieur le plus récent, close ne peut pas
 *    clôturer un lot avant son propre début, reassign laisse la cible
 *    avec exactement un lot ouvert ;
 *  - quantité achetée qty_purchased : écrite par create/update (NULL
 *    accepté), relue telle quelle.
 *
 * Saute automatiquement si la base aeic_test est indisponible.
 */
final class CostLotInvariantsTest extends TestCase
{
    use TestDatabaseTrait;

    private function requireDatabase(): bool
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped('Base aeic_test indisponible : test des invariants de lots ignoré.');

            return false;
        }

        try {
            $pdo->query('SELECT 1 FROM `product_costs` LIMIT 1');
        } catch (\Throwable) {
            self::markTestSkipped('Table `product_costs` absente de aeic_test : importez database/schema.sql.');

            return false;
        }

        $this->reset($pdo, ['product_costs']);
        $this->applyCostQtyMigration($pdo);
        Model::setTestPdo($pdo);

        return true;
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
    }

    /**
     * Applique (best effort) la migration qty_purchased
     * (database/migrations/2026_cost_qty_repair.sql) sur la base de test :
     * une erreur (colonne déjà présente) est ignorée.
     */
    private function applyCostQtyMigration(PDO $pdo): void
    {
        try {
            $pdo->exec(
                'ALTER TABLE product_costs ADD COLUMN IF NOT EXISTS qty_purchased INT NULL AFTER cost_price'
            );
        } catch (\Throwable) {
            // Déjà appliqué : non bloquant.
        }
    }

    /**
     * Insère un lot en SQL direct (état potentiellement buggé assumé).
     */
    private function insertLot(string $key, string $from, ?string $to, string $createdAt): string
    {
        $id = 'cost_' . bin2hex(random_bytes(6));
        $this->connect()->prepare(
            'INSERT INTO product_costs (id, product_key, cost_price, valid_from, valid_to, created_at)
             VALUES (?, ?, 1.5, ?, ?, ?)'
        )->execute([$id, $key, $from, $to, $createdAt]);

        return $id;
    }

    /**
     * Nombre de lots « en cours » (valid_to NULL) d'un produit.
     */
    private function openCount(string $key): int
    {
        $stmt = $this->connect()->prepare(
            'SELECT COUNT(*) FROM product_costs WHERE product_key = ? AND valid_to IS NULL'
        );
        $stmt->execute([$key]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Lots d'un produit, ordonnés comme normalizePeriods.
     *
     * @return list<array<string,mixed>>
     */
    private function lotsOf(string $key): array
    {
        $stmt = $this->connect()->prepare(
            'SELECT * FROM product_costs
              WHERE product_key = ?
              ORDER BY valid_from ASC, created_at ASC, id ASC'
        );
        $stmt->execute([$key]);

        /** @var list<array<string,mixed>> $rows */
        return $stmt->fetchAll();
    }

    private function lotById(string $id): array
    {
        $stmt = $this->connect()->prepare('SELECT * FROM product_costs WHERE id = ?');
        $stmt->execute([$id]);
        $lot = $stmt->fetch();
        self::assertNotFalse($lot, 'Le lot ' . $id . ' doit exister.');

        /** @var array<string,mixed> $lot */
        return $lot;
    }

    /**
     * Invariant complet : au plus un lot ouvert (le dernier), aucun
     * chevauchement entre lots consécutifs.
     */
    private function assertSingleOpenNoOverlap(string $key): void
    {
        $lots = $this->lotsOf($key);
        $count = count($lots);
        for ($i = 0; $i < $count; $i++) {
            $isLast = $i === $count - 1;
            if ($isLast) {
                continue; // le dernier lot peut être ouvert ou clôturé.
            }
            self::assertNotNull($lots[$i]['valid_to'], 'Un lot non final doit être clôturé.');
            self::assertLessThan(
                (string) $lots[$i + 1]['valid_from'],
                (string) $lots[$i]['valid_to'],
                'Aucun chevauchement : le lot se termine avant le suivant.'
            );
        }
        self::assertSame(1, $this->openCount($key), 'Exactement un lot en cours.');
    }

    // ── Invariant « au plus un lot en cours » ──────────────────────────

    public function test_create_ferme_le_lot_precedent(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        ProductCost::create(['product_key' => 'eau', 'cost_price' => 1.0, 'valid_from' => '2026-09-01']);
        ProductCost::create(['product_key' => 'eau', 'cost_price' => 1.2, 'valid_from' => '2026-10-08']);

        self::assertSame(1, $this->openCount('eau'), 'Un seul lot en cours après deux créations.');

        $lots = $this->lotsOf('eau');
        self::assertSame('2026-09-01', (string) $lots[0]['valid_from']);
        self::assertSame('2026-10-07', (string) $lots[0]['valid_to'], 'Le premier lot ferme à la veille du nouveau.');
        self::assertNull($lots[1]['valid_to']);
    }

    public function test_normalize_repare_plusieurs_lots_ouverts(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        // État bugué simulé : un lot clôturé + DEUX lots ouverts
        // (historique : fusions brutes / update sans rééquilibrage).
        $closed = $this->insertLot('eau', '2026-08-01', '2026-09-18', '2026-08-01 09:00:00');
        $old = $this->insertLot('eau', '2026-09-19', null, '2026-09-19 09:00:00');
        $recent = $this->insertLot('eau', '2026-10-08', null, '2026-10-08 09:00:00');

        ProductCost::normalizePeriods('eau');

        self::assertSame(1, $this->openCount('eau'), 'Un seul lot ouvert après normalisation.');

        $oldLot = $this->lotById($old);
        self::assertSame('2026-10-07', (string) $oldLot['valid_to'], 'L\'ancien lot ouvert ferme à la veille du plus récent.');

        $recentLot = $this->lotById($recent);
        self::assertNull($recentLot['valid_to'], 'Le lot le plus récent reste en cours.');

        $closedLot = $this->lotById($closed);
        self::assertSame('2026-09-18', (string) $closedLot['valid_to'], 'Le trou volontaire déjà conforme est conservé.');

        $this->assertSingleOpenNoOverlap('eau');
    }

    public function test_update_valid_from_avance_sans_chevauchement(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $id1 = ProductCost::create(['product_key' => 'cafe', 'cost_price' => 1.0, 'valid_from' => '2026-09-01']);
        $id2 = ProductCost::create(['product_key' => 'cafe', 'cost_price' => 1.1, 'valid_from' => '2026-10-01']);
        ProductCost::create(['product_key' => 'cafe', 'cost_price' => 1.2, 'valid_from' => '2026-11-01']);

        // Le premier lot avance AU-DELÀ du deuxième : chevauchement
        // potentiel, la normalisation doit le résoudre.
        self::assertTrue(ProductCost::update($id1, ['valid_from' => '2026-10-15', 'cost_price' => 1.0]));

        $lot2 = $this->lotById($id2);
        self::assertSame('2026-10-14', (string) $lot2['valid_to'], 'Le lot chevauché est clampé à la veille du nouveau valid_from.');

        $this->assertSingleOpenNoOverlap('cafe');
    }

    public function test_delete_lot_ouvert_rouvre_le_plus_recent(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $id1 = ProductCost::create(['product_key' => 'eau', 'cost_price' => 1.0, 'valid_from' => '2026-09-01']);
        $id2 = ProductCost::create(['product_key' => 'eau', 'cost_price' => 1.1, 'valid_from' => '2026-10-08']);
        self::assertSame('2026-10-07', (string) $this->lotById($id1)['valid_to']);

        self::assertTrue(ProductCost::delete($id2));

        $lot1 = $this->lotById($id1);
        self::assertNull($lot1['valid_to'], 'Le lot antérieur le plus récent est réouvert.');
        self::assertSame(1, $this->openCount('eau'), 'Le produit garde exactement un lot en cours.');
    }

    public function test_close_lot_cree_aujourdhui_ne_precede_pas_son_debut(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $today = date('Y-m-d');
        $id = ProductCost::create(['product_key' => 'eau', 'cost_price' => 1.0, 'valid_from' => $today]);
        self::assertTrue(ProductCost::close($id));

        $lot = $this->lotById($id);
        self::assertGreaterThanOrEqual(
            strtotime((string) $lot['valid_from']),
            strtotime((string) $lot['valid_to']),
            'valid_to >= valid_from, même pour un lot créé aujourd\'hui.'
        );
    }

    // ── Quantité achetée qty_purchased ─────────────────────────────────

    public function test_qty_purchased_create_update_et_null(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $id = ProductCost::create([
            'product_key'   => 'eau',
            'cost_price'    => 1.0,
            'valid_from'    => '2026-09-01',
            'qty_purchased' => 24,
        ]);
        self::assertSame(24, (int) $this->lotById($id)['qty_purchased'], 'La quantité créée est relue telle quelle.');

        // Mise à jour de la quantité (valid_from requis par update()).
        self::assertTrue(ProductCost::update($id, [
            'valid_from'    => '2026-09-01',
            'cost_price'    => 1.0,
            'qty_purchased' => 12,
        ]));
        self::assertSame(12, (int) $this->lotById($id)['qty_purchased']);

        // Retour à « pas de suivi » (NULL accepté).
        self::assertTrue(ProductCost::update($id, [
            'valid_from'    => '2026-09-01',
            'cost_price'    => 1.0,
            'qty_purchased' => null,
        ]));
        self::assertNull($this->lotById($id)['qty_purchased']);

        // Sans la clé : create → NULL, update → quantité inchangée.
        $id2 = ProductCost::create(['product_key' => 'cafe', 'cost_price' => 2.0, 'valid_from' => '2026-09-01']);
        self::assertNull($this->lotById($id2)['qty_purchased'], 'Absent de create → NULL.');

        self::assertTrue(ProductCost::update($id, ['valid_from' => '2026-09-01', 'cost_price' => 1.0]));
        self::assertNull($this->lotById($id)['qty_purchased'], 'Absent de update → quantité intacte.');

        self::assertTrue(ProductCost::update($id, [
            'valid_from'    => '2026-09-01',
            'cost_price'    => 1.0,
            'qty_purchased' => 6,
        ]));
        self::assertTrue(ProductCost::update($id, ['valid_from' => '2026-09-01', 'cost_price' => 1.0]));
        self::assertSame(6, (int) $this->lotById($id)['qty_purchased'], 'Absent de update → la quantité n\'est pas touchée.');
    }

    public function test_reassign_un_seul_lot_ouvert_sur_la_cible(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        ProductCost::create(['product_key' => 'ancien', 'cost_price' => 1.0, 'valid_from' => '2026-09-01']);
        ProductCost::create(['product_key' => 'cible', 'cost_price' => 1.2, 'valid_from' => '2026-10-01']);

        $moved = ProductCost::reassign('ancien', 'cible');
        self::assertSame(1, $moved);

        $this->assertSingleOpenNoOverlap('cible');

        $lots = $this->lotsOf('cible');
        self::assertSame('2026-09-01', (string) $lots[0]['valid_from']);
        self::assertSame('2026-09-30', (string) $lots[0]['valid_to'], 'Le lot déplacé ferme à la veille du lot de la cible.');

        self::assertSame(0, $this->openCount('ancien'), 'La source vidée n\'a plus aucun lot.');
    }
}
