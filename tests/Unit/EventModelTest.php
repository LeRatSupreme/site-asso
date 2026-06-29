<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Model;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests du modèle Event sur la base de test `aeic_test`.
 *
 * ⚠️ Nécessite une base MySQL/MariaDB `aeic_test` accessible avec le schéma
 *    (database/schema.sql) importé. Les identifiants sont lus dans
 *    phpunit.xml (env DB_HOST / DB_NAME / DB_USER / DB_PASS).
 *
 * Lancement :
 *   1. mysql -u aeic -p -e "CREATE DATABASE IF NOT EXISTS aeic_test"
 *   2. mysql -u aeic -p aeic_test < database/schema.sql
 *   3. vendor/bin/phpunit --testdox tests/Unit/EventModelTest.php
 *
 * Si la base n'est pas joignable, tous les tests sont automatiquement
 * ignorés (markTestSkipped) — les autres tests (sans DB) continuent de passer.
 */
final class EventModelTest extends TestCase
{
    use TestDatabaseTrait;

    protected function setUp(): void
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped(
                'Base aeic_test indisponible : sautez EventModelTest ou configurez DB_* dans phpunit.xml.'
            );
        }

        $this->reset($pdo, ['event_variant_choices', 'event_variants', 'photos', 'event_registrations', 'events', 'users']);

        // Seed minimal déterministe.
        $now = date('Y-m-d H:i:s');
        $futureA = date('Y-m-d H:i:s', time() + 86400);
        $futureB = date('Y-m-d H:i:s', time() + 2 * 86400);
        $futureC = date('Y-m-d H:i:s', time() + 3 * 86400);
        $past = date('Y-m-d H:i:s', time() - 86400);

        $stmt = $pdo->prepare(
            'INSERT INTO events (id, slug, title, date, location, price, is_featured, is_published, excerpt, description, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute(['e_future_feat', 'futur-mis-en-avant', 'Soirée', $futureA, 'Amphi A', 5.00, 1, 1, 'excerpt', '<p>desc</p>', $now, $now]);
        $stmt->execute(['e_future', 'futur-normal', 'LAN', $futureB, 'Salle', null, 0, 1, 'excerpt', '<p>desc</p>', $now, $now]);
        $stmt->execute(['e_future_2', 'futur-normal-2', 'Conf', $futureC, 'Amphi B', 0, 0, 1, 'excerpt', '<p>desc</p>', $now, $now]);
        $stmt->execute(['e_past', 'passe', 'Ancien event', $past, 'Lieu', 10, 1, 1, 'excerpt', '<p>desc</p>', $now, $now]);
        $stmt->execute(['e_draft', 'brouillon', 'Brouillon', $futureA, 'Lieu', null, 0, 0, 'excerpt', '<p>desc</p>', $now, $now]);

        Model::setTestPdo($pdo);
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
    }

    public function test_count_ne_compte_que_les_publies(): void
    {
        // 4 publiés (3 futurs + 1 passé), 1 brouillon exclu.
        self::assertSame(4, \App\Models\Event::count());
    }

    public function test_upcoming_ne_renvoie_que_les_futurs_publies(): void
    {
        $rows = \App\Models\Event::upcoming();

        self::assertCount(3, $rows);
        $slugs = array_column($rows, 'slug');
        sort($slugs);
        self::assertSame(['futur-mis-en-avant', 'futur-normal', 'futur-normal-2'], $slugs);
    }

    public function test_upcoming_limite_le_nombre(): void
    {
        self::assertCount(2, \App\Models\Event::upcoming(2));
    }

    public function test_past_ne_renvoie_que_les_anciens(): void
    {
        $rows = \App\Models\Event::past();

        self::assertCount(1, $rows);
        self::assertSame('passe', $rows[0]['slug']);
    }

    public function test_featured_priorise_les_mis_en_avant(): void
    {
        $rows = \App\Models\Event::featured(3);

        self::assertCount(3, $rows);
        // Le premier doit être l'événement mis en avant.
        self::assertSame('futur-mis-en-avant', $rows[0]['slug']);
    }

    public function test_find_by_slug_trouve_un_publie(): void
    {
        $event = \App\Models\Event::findBySlug('futur-normal');

        self::assertNotNull($event);
        self::assertSame('LAN', $event['title']);
    }

    public function test_find_by_slug_renvoie_null_si_absent(): void
    {
        self::assertNull(\App\Models\Event::findBySlug('n-existe-pas'));
    }

    public function test_find_by_slug_ignore_les_brouillons(): void
    {
        self::assertNull(\App\Models\Event::findBySlug('brouillon'));
    }

    public function test_registrations_count_zero_si_aucune_inscription(): void
    {
        self::assertSame(0, \App\Models\Event::registrationsCount('e_future'));
    }

    public function test_variants_et_photos_renvoient_listes_vides_si_aucune(): void
    {
        self::assertSame([], \App\Models\Event::variants('e_future'));
        self::assertSame([], \App\Models\Event::photos('e_future'));
    }
}
