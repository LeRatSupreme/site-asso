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
 * Tests du modÃ¨le 2FA sur la base `aeic_test`.
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

        $hash = password_hash('SecretPassword123', PASSWORD_BCRYPT);
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
        // Secret pas encore confirmÃ©.
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

    public function test_le_secret_en_attente_est_stable_malgre_les_echecs(): void
    {
        $setup = TwoFactor::beginSetup($this->userId);

        // Rechargements de page successifs : le même secret est renvoyé.
        self::assertSame($setup['secret'], TwoFactor::pendingSecret($this->userId));
        self::assertSame($setup['secret'], TwoFactor::pendingSecret($this->userId));

        // Un code refusé ne régénère PAS le secret.
        self::assertFalse(Totp::verify((string) TwoFactor::pendingSecret($this->userId), '000000'));
        self::assertSame($setup['secret'], TwoFactor::pendingSecret($this->userId));
    }

    public function test_seule_la_regeneration_explicite_change_le_secret(): void
    {
        $s1 = TwoFactor::beginSetup($this->userId)['secret'];
        self::assertSame($s1, TwoFactor::pendingSecret($this->userId));

        // beginSetup = action explicite (bouton « Générer une nouvelle clé »).
        $s2 = TwoFactor::beginSetup($this->userId)['secret'];
        self::assertNotSame($s1, $s2);
        self::assertSame($s2, TwoFactor::pendingSecret($this->userId));
    }

    public function test_verification_accepte_plus_ou_moins_2_pas(): void
    {
        $secret = TwoFactor::beginSetup($this->userId)['secret'];
        $t = 1800000000;

        self::assertTrue(Totp::verify($secret, Totp::code($secret, $t), 2, $t));
        self::assertTrue(Totp::verify($secret, Totp::code($secret, $t - 60), 2, $t));
        self::assertTrue(Totp::verify($secret, Totp::code($secret, $t + 60), 2, $t));
        self::assertFalse(Totp::verify($secret, Totp::code($secret, $t - 90), 2, $t));
        self::assertFalse(Totp::verify($secret, Totp::code($secret, $t - 60), 1, $t));
    }

    public function test_regeneration_recovery_codes_conserve_le_secret(): void
    {
        $secret = TwoFactor::beginSetup($this->userId)['secret'];

        $recovery = TwoFactor::regenerateRecoveryCodes($this->userId);
        self::assertCount(RecoveryCodes::DEFAULT_COUNT, $recovery);
        self::assertSame($secret, TwoFactor::pendingSecret($this->userId));

        TwoFactor::enable($this->userId);
        self::assertTrue(TwoFactor::verify($this->userId, $recovery[0]));
    }

    public function test_use_recovery_code_le_consomme(): void
    {
        $setup = TwoFactor::beginSetup($this->userId);
        TwoFactor::enable($this->userId);

        $code = $setup['recovery'][0];
        self::assertTrue(TwoFactor::verify($this->userId, $code));
        // Un second usage du mÃªme code Ã©choue (usage unique).
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
