<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Models\AuditLog;
use App\Models\Model;
use App\Models\User;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la gestion des comptes en admin : rôles, activation, audit.
 *
 * Saute si la base aeic_test est indisponible.
 */
final class AdminUserModelTest extends TestCase
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

        $this->reset($pdo, ['audit_logs', 'users']);
        Model::setTestPdo($pdo);

        $hash = password_hash('Secret123', PASSWORD_BCRYPT);
        $this->seedUser($pdo, 'adm1', 'Admin', 'Un', 'a@ex.fr', Auth::ROLE_ADMIN, 1, $hash);
        $this->seedUser($pdo, 'adm2', 'Admin', 'Deux', 'b@ex.fr', Auth::ROLE_ADMIN, 1, $hash);
        $this->seedUser($pdo, 'ele1', 'Eleve', 'Un', 'e@ex.fr', Auth::ROLE_ELEVE, 1, $hash);

        // Simule une session admin pour l'audit (client_ip / Auth::id).
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['user_id'] = 'adm1';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
        $_SESSION = [];
    }

    public function test_count_active_admins(): void
    {
        self::assertSame(2, User::countActiveAdmins());
    }

    public function test_set_role_change_le_role(): void
    {
        User::setRole('ele1', Auth::ROLE_ADMIN);

        self::assertSame(Auth::ROLE_ADMIN, User::find('ele1')['role']);
        self::assertSame(3, User::countActiveAdmins());
    }

    public function test_set_active_desactive_puis_reactive(): void
    {
        User::setActive('adm2', false);
        self::assertSame('0', User::find('adm2')['is_active']);
        self::assertSame(1, User::countActiveAdmins());

        User::setActive('adm2', true);
        self::assertSame('1', User::find('adm2')['is_active']);
        self::assertSame(2, User::countActiveAdmins());
    }

    public function test_audit_log_enregistre_la_promotion(): void
    {
        AuditLog::log('user.role_change', 'adm1', 'user', 'ele1', [
            'from' => Auth::ROLE_ELEVE,
            'to'   => Auth::ROLE_ADMIN,
        ]);

        $rows = AuditLog::recent(10);

        self::assertCount(1, $rows);
        self::assertSame('user.role_change', $rows[0]['action']);
        self::assertSame('ele1', $rows[0]['entity_id']);
        self::assertSame('adm1', $rows[0]['user_id']);
    }

    public function test_all_for_admin_retourne_tous_les_utilisateurs(): void
    {
        self::assertCount(3, User::allForAdmin());
    }

    private function seedUser(PDO $pdo, string $id, string $prenom, string $nom, string $email, string $role, int $active, string $hash): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO users (id, prenom, nom, email, password, role, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id, $prenom, $nom, $email, $hash, $role, $active]);
    }
}
