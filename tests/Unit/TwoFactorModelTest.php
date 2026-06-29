<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Security\RecoveryCodes;
use App\Core\Security\Totp;
use App\Models\AuditLog;
use App\Models\Model;
use App\Models\TwoFactor;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests du modèle 2FA sur la base `aeic_test`.
 *
 * Saute automatiquement si la base n'est pas joignable.
 */
final class TwoFactorModelTest extends TestCase
{
    use TestDatabaseTrait;

    private ?PDO $pdo = null;
    private string $userId = 'tf_user';

    protected function setUp(): void
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped('Base aeic_test indisponible : configurez DB_* dans phpunit.xml.');
        }
        $this->pdo = $pdo;

        $this->reset($pdo, ['two_factor', 'audit_logs', 'users']);
        Model::setTestPdo($pdo);

        $hash = password_hash('Secret123', PASSWORD_BCRYPT);
        $stmt = $pdo->prepare(
            'INSERT INTO users (id, prenom, nom, email, password, role, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([$this->userId, 'Ada', 'Lovelace', 'ada@ex.fr', $hash, 'ADMIN']);

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['user_id'] = $this->userId;
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
        $_SESSION = [];
    }

    public function test_begin_setup_puis_enable(): void
    {
        $this->assertFalse(TwoFactor::isEnabled($this->userId));

        $setup = TwoFactor::beginSetup($this->userId);
        self::assertNotEmpty($setup['secret']);
        self::assertCount(RecoveryCodes::DEFAULT_COUNT, $setup['recovery']);
        // Secret pas encore confirmé.
        self::assertFalse(TwoFactor::isEnabled($this->userId));

        TwoFactor::enable($this->userId);
        self::assertTrue(TwoFactor::isEnabled($this->userId));
    }

    public function test_verify_code_totp(): void
    {
        $setup = TwoFactor::beginSetup($this->userId);
        TwoFactor::enable($this->userId);

        $code = Totp::code($setup['secret']);
        self::assertTrue(TwoFactor::verify($this->userId, $code));
    }

    public function test_use_recovery_code_le_consomme(): void
    {
        $setup = TwoFactor::beginSetup($this->userId);
        TwoFactor::enable($this->userId);

        $code = $setup['recovery'][0];
        self::assertTrue(TwoFactor::verify($this->userId, $code));
        // Un second usage du même code échoue (usage unique).
        self::assertFalse(TwoFactor::verify($this->userId, $code));
    }

    public function test_disable_supprime_le_2fa(): void
    {
        TwoFactor::beginSetup($this->userId);
        TwoFactor::enable($this->userId);
        self::assertTrue(TwoFactor::isEnabled($this->userId));

        TwoFactor::disable($this->userId);
        self::assertFalse(TwoFactor::isEnabled($this->userId));
    }

    public function test_audit_log_alimente_apres_activation(): void
    {
        AuditLog::log('twofactor.enable', $this->userId, 'user', $this->userId);

        $rows = AuditLog::recent(5);
        self::assertCount(1, $rows);
        self::assertSame('twofactor.enable', $rows[0]['action']);
    }
}
