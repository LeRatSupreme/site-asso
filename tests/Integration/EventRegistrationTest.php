<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Tests d'intégration de l'inscription aux événements
 * (route POST /events/{slug}/register + base event_registrations).
 */
final class EventRegistrationTest extends IntegrationTestCase
{
    private string $userId = 'u_evt_int';
    private string $eventId = 'evt_int_test';
    private string $slug = 'soiree-test-int';

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->requireDatabase();
        $this->reset([
            'event_registration_choices', 'event_registrations',
            'event_variant_choices', 'event_variants',
            'events', 'users', 'settings',
        ]);

        $this->seedUser($this->userId, 'evt@exemple.fr');

        $future = date('Y-m-d H:i:s', time() + 86400 * 7);
        $pdo->prepare(
            'INSERT INTO events (id, slug, title, date, is_published) VALUES (?,?,?,? ,1)'
        )->execute([$this->eventId, $this->slug, 'Soirée test', $future]);
    }

    public function test_inscription_cree_une_ligne_event_registrations(): void
    {
        $this->login('evt@exemple.fr', 'Password1');

        $response = $this->request('POST', '/events/' . $this->slug . '/register');

        self::assertStringContainsString('/events/' . $this->slug, $this->location($response));

        $count = (int) $this->pdo
            ->query("SELECT COUNT(*) FROM event_registrations WHERE user_id = '{$this->userId}'")
            ->fetchColumn();
        self::assertSame(1, $count);
    }

    public function test_double_inscription_rejete_l_unicite(): void
    {
        $this->login('evt@exemple.fr', 'Password1');

        $this->request('POST', '/events/' . $this->slug . '/register');
        $this->request('POST', '/events/' . $this->slug . '/register');

        $count = (int) $this->pdo
            ->query("SELECT COUNT(*) FROM event_registrations WHERE user_id = '{$this->userId}'")
            ->fetchColumn();
        self::assertSame(1, $count, 'Aucun doublon possible (contrainte unique).');
    }
}
