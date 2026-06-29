<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Models\Event;
use App\Models\Model;
use App\Models\Registration;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests des inscriptions aux événements (unicité, variantes obligatoires).
 *
 * Saute automatiquement si la base `aeic_test` n'est pas joignable.
 */
final class RegistrationModelTest extends TestCase
{
    use TestDatabaseTrait;

    private PDO $pdo;
    private string $eventId = 'evt_reg_test';
    private string $userId = 'u_reg_test';
    private string $variantId = 'var_test';
    private string $choiceId = 'choice_test';

    protected function setUp(): void
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped('Base aeic_test indisponible : configurez DB_* dans phpunit.xml.');
        }
        $this->pdo = $pdo;

        $this->reset($pdo, [
            'event_registration_choices', 'event_registrations',
            'event_variant_choices', 'event_variants',
            'events', 'users',
        ]);
        Model::setTestPdo($pdo);

        $this->seed();
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
    }

    public function test_create_puis_is_registered(): void
    {
        self::assertFalse(Registration::isRegistered($this->userId, $this->eventId));

        Registration::create($this->userId, $this->eventId);

        self::assertTrue(Registration::isRegistered($this->userId, $this->eventId));
    }

    public function test_double_inscription_rejetee_par_unicite(): void
    {
        Registration::create($this->userId, $this->eventId);

        $this->expectException(\Throwable::class);
        Registration::create($this->userId, $this->eventId);
    }

    public function test_create_avec_choix_de_variante_enregistre_la_ligne(): void
    {
        $id = Registration::create($this->userId, $this->eventId, [
            $this->variantId => $this->choiceId,
        ]);

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM event_registration_choices WHERE registration_id = ?'
        );
        $stmt->execute([$id]);

        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function test_unregister_supprime_l_inscription(): void
    {
        Registration::create($this->userId, $this->eventId);

        Registration::unregister($this->userId, $this->eventId);

        self::assertFalse(Registration::isRegistered($this->userId, $this->eventId));
    }

    public function test_for_user_renvoie_les_evenements_inscrits_avec_statut(): void
    {
        Registration::create($this->userId, $this->eventId);

        $rows = Registration::forUser($this->userId);

        self::assertCount(1, $rows);
        self::assertSame('LAN test', $rows[0]['title']);
        self::assertArrayHasKey('is_past', $rows[0]);
    }

    private function seed(): void
    {
        $now = date('Y-m-d H:i:s');
        $future = date('Y-m-d H:i:s', time() + 86400);

        $this->pdo->prepare(
            'INSERT INTO users (id, prenom, nom, email, role, is_active) VALUES (?,?,?,?,?,1)'
        )->execute([$this->userId, 'Test', 'User', 'regtest@exemple.fr', Auth::ROLE_ELEVE]);

        $this->pdo->prepare(
            'INSERT INTO events (id, slug, title, date, is_published, created_at, updated_at)
             VALUES (?,?,?,?,1,?,?)'
        )->execute([$this->eventId, 'lan-test', 'LAN test', $future, $now, $now]);

        $this->pdo->prepare(
            'INSERT INTO event_variants (id, event_id, label, required, `order`) VALUES (?,?,?,?,1)'
        )->execute([$this->variantId, $this->eventId, 'Menu', 1]);

        $this->pdo->prepare(
            'INSERT INTO event_variant_choices (id, variant_id, label, `order`) VALUES (?,?,?,1)'
        )->execute([$this->choiceId, $this->variantId, 'Végétarien']);
    }
}
