<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Model;
use App\Models\TeamMember;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests du modèle TeamMember sur la base de test `aeic_test`.
 *
 * Vérifie le filtrage (is_active / is_highlight) et le tri par `order`.
 *
 * ⚠️ Nécessite la base `aeic_test` (voir EventModelTest pour le setup).
 *    Sans base, les tests sont automatiquement ignorés.
 */
final class TeamMemberTest extends TestCase
{
    use TestDatabaseTrait;

    protected function setUp(): void
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped(
                'Base aeic_test indisponible : sautez TeamMemberTest ou configurez DB_* dans phpunit.xml.'
            );
        }

        $this->reset($pdo, ['team_members']);

        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'INSERT INTO team_members
                (id, prenom, nom, role, pole, bio, photo, is_highlight, `order`, is_active, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        // Ordre volontairement dispersé pour valider le tri par `order`.
        $stmt->execute(['tm1', 'Alex', 'Martin', 'Président', 'bureau', null, null, 1, 3, 1, $now, $now]);
        $stmt->execute(['tm2', 'Sarah', 'Lopez', 'VP', 'bureau', null, null, 1, 1, 1, $now, $now]);
        $stmt->execute(['tm3', 'Tom', 'Bernard', 'Resp. comm', 'communication', null, null, 0, 2, 1, $now, $now]);
        $stmt->execute(['tm4', 'Inès', 'Dubois', 'Ancien', 'bureau', null, null, 1, 4, 0, $now, $now]); // inactif
        $stmt->execute(['tm5', 'Luc', 'Petit', 'Membre', 'evenements', null, null, 0, 5, 1, $now, $now]);

        Model::setTestPdo($pdo);
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
    }

    public function test_active_exclut_les_inactifs(): void
    {
        $rows = TeamMember::active();

        $ids = array_column($rows, 'id');
        sort($ids);
        // tm4 (inactif) exclu.
        self::assertSame(['tm1', 'tm2', 'tm3', 'tm5'], $ids);
    }

    public function test_highlighted_ne_renvoie_que_les_actifs_mis_en_avant(): void
    {
        $rows = TeamMember::highlighted();

        $ids = array_column($rows, 'id');
        sort($ids);
        // tm1 & tm2 mis en avant et actifs ; tm4 mis en avant mais inactif => exclu.
        self::assertSame(['tm1', 'tm2'], $ids);
    }

    public function test_highlighted_est_trie_par_order(): void
    {
        $rows = TeamMember::highlighted();

        $orders = array_column($rows, 'order');
        self::assertSame([1, 3], $orders);
    }

    public function test_active_place_les_mis_en_avant_en_premier(): void
    {
        $rows = TeamMember::active();

        // Les deux premiers doivent être les highlighted (tm2 order 1, tm1 order 3).
        self::assertSame('tm2', $rows[0]['id']);
        self::assertSame('tm1', $rows[1]['id']);
    }
}
