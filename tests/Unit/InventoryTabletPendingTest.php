<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\InventoryTablet;
use App\Models\Model;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests d'InventoryTablet::pendingSessions() : les sessions de relevé
 * tablette affichées dans le rappel de la page Inventaire — uniquement
 * celles ayant encore au moins un écart NON corrigé (pending_count > 0),
 * de la plus récente à la plus ancienne.
 *
 * Saute automatiquement si la base aeic_test est indisponible.
 */
final class InventoryTabletPendingTest extends TestCase
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
            foreach (['inventory_tablet_sessions', 'inventory_tablet_lines'] as $table) {
                $pdo->query('SELECT 1 FROM `' . $table . '` LIMIT 1');
            }
        } catch (\Throwable) {
            self::markTestSkipped('Tables inventaire tablette absentes de aeic_test : importez database/schema.sql.');
        }

        $this->reset($pdo, ['inventory_tablet_sessions', 'inventory_tablet_lines']);
        Model::setTestPdo($pdo);
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
    }

    private function addSession(string $id, string $countedAt): void
    {
        $this->pdo->prepare(
            'INSERT INTO inventory_tablet_sessions (id, counted_at, created_at)
             VALUES (?, ?, NOW())'
        )->execute([$id, $countedAt]);
    }

    private function addLine(string $sessionId, string $key, ?int $gap, int $corrected = 0, ?float $lossValue = null): void
    {
        $this->pdo->prepare(
            'INSERT INTO inventory_tablet_lines
                (id, session_id, product_key, tablet_qty, site_theoretical, gap, unit_cost, loss_value, corrected, created_at)
             VALUES (?, ?, ?, 4, 5, ?, 1.5, ?, ?, NOW())'
        )->execute(['tabl_' . bin2hex(random_bytes(6)), $sessionId, $key, $gap, $lossValue, $corrected]);
    }

    public function test_pending_sessions_ignore_les_releves_fully_corriges(): void
    {
        // Session A : deux écarts dont un corrigé → reste 1 écart en attente.
        $this->addSession('tabt_a', date('Y-m-d'));
        $this->addLine('tabt_a', 'coca', -1, 1, 1.5);
        $this->addLine('tabt_a', 'eau', 2, 0);

        // Session B : un seul écart, corrigé → entièrement traitée, exclue.
        $this->addSession('tabt_b', date('Y-m-d'));
        $this->addLine('tabt_b', 'mate', -3, 1, 4.5);

        // Session C : plus ancienne, un écart non corrigé → incluse, après A.
        $this->addSession('tabt_c', date('Y-m-d', strtotime('-1 day')));
        $this->addLine('tabt_c', 'mate', -2, 0, 3.0);

        $sessions = InventoryTablet::pendingSessions();

        self::assertSame(['tabt_a', 'tabt_c'], array_column($sessions, 'id'), 'B (fully corrigée) exclue ; ordre du plus récent au plus ancien.');

        $a = $sessions[0];
        self::assertSame(2, $a['lines_count']);
        self::assertSame(2, $a['gaps_count']);
        self::assertSame(1, $a['corrected_count']);
        self::assertSame(1, $a['pending_count']);
        self::assertSame(1.5, $a['loss_value_sum']);

        $c = $sessions[1];
        self::assertSame(1, $c['gaps_count']);
        self::assertSame(0, $c['corrected_count']);
        self::assertSame(1, $c['pending_count']);
    }

    public function test_pending_sessions_sans_écarts_non_corrigés_renvoie_vide(): void
    {
        // Session sans aucun écart (que des gap NULL / 0) : exclue aussi.
        $this->addSession('tabt_ok', date('Y-m-d'));
        $this->addLine('tabt_ok', 'coca', null);
        $this->addLine('tabt_ok', 'eau', 0);

        self::assertSame([], InventoryTablet::pendingSessions());
    }
}
