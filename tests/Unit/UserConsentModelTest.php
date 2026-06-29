<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Models\Consent;
use App\Models\Model;
use App\Models\User;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests des modèles User & Consent sur la base `aeic_test`.
 *
 * Saute automatiquement si la base n'est pas joignable (voir TestDatabaseTrait).
 */
final class UserConsentModelTest extends TestCase
{
    use TestDatabaseTrait;

    protected function setUp(): void
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped('Base aeic_test indisponible : configurez DB_* dans phpunit.xml.');
        }

        $this->reset($pdo, ['consents', 'users']);
        Model::setTestPdo($pdo);

        $this->seedUser($pdo, 'u_existing', 'Alex', 'Martin', 'alex@exemple.fr', password_hash('Secret123', PASSWORD_BCRYPT));
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
    }

    public function test_find_by_email_trouve_l_utilisateur(): void
    {
        $user = User::findByEmail('ALEX@EXEMPLE.FR'); // insensible à la casse

        self::assertNotNull($user);
        self::assertSame('alex@exemple.fr', $user['email']);
    }

    public function test_email_exists_renvoie_vrai_si_present(): void
    {
        self::assertTrue(User::emailExists('alex@exemple.fr'));
        self::assertFalse(User::emailExists('inconnu@exemple.fr'));
    }

    public function test_create_insere_un_nouvel_eleve(): void
    {
        $id = User::create([
            'prenom'   => 'Sarah',
            'nom'      => 'Lopez',
            'email'    => 'sarah@exemple.fr',
            'password' => password_hash('Secret123', PASSWORD_BCRYPT),
        ]);

        self::assertNotEmpty($id);

        $user = User::findByEmail('sarah@exemple.fr');
        self::assertNotNull($user);
        self::assertSame(Auth::ROLE_ELEVE, $user['role']);
        self::assertEquals(1, $user['is_active']);
    }

    public function test_anonymize_efface_les_donnees_personnelles(): void
    {
        User::anonymize('u_existing');

        $user = User::find('u_existing');

        self::assertSame('Compte supprimé', $user['prenom']);
        // email NOT NULL/UNIQUE en base : anonymisé en sentinelle invalide.
        self::assertNotSame('alex@exemple.fr', $user['email']);
        self::assertSame('deleted_u_existing@invalid.local', $user['email']);
        self::assertNull($user['password']);
        self::assertSame('0', (string) $user['is_active']);
    }

    public function test_consent_log_enregistre_et_retrouve(): void
    {
        $id = Consent::log('registration', true, 'cgu-v1', [
            'user_id'    => 'u_existing',
            'email'      => 'alex@exemple.fr',
            'ip_address' => '127.0.0.1',
        ]);

        self::assertNotEmpty($id);

        $rows = Consent::forUser('u_existing');

        self::assertCount(1, $rows);
        self::assertSame('registration', $rows[0]['consent_type']);
        self::assertEquals(1, $rows[0]['granted']);
    }

    private function seedUser(PDO $pdo, string $id, string $prenom, string $nom, string $email, string $hash): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO users (id, prenom, nom, email, password, role, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([$id, $prenom, $nom, $email, $hash, Auth::ROLE_ELEVE]);
    }
}
