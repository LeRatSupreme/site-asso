<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Router;
use PHPUnit\Framework\TestCase;

/**
 * Tests du routeur : matching, extraction de paramètres, 404/405.
 *
 * On instancie un faux contrôleur pour ne pas dépendre d'I/O.
 */
final class RouterTest extends TestCase
{
    public function test_match_route_get_simple(): void
    {
        $router = new Router();
        $router->get('/', [FakeController::class, 'index']);

        $match = $router->match('GET', '/');

        self::assertSame(200, $match['status']);
        self::assertSame([FakeController::class, 'index'], $match['handler']);
        self::assertSame([], $match['params']);
    }

    public function test_match_extraire_parametre_slug(): void
    {
        $router = new Router();
        $router->get('/events/{slug}', [FakeController::class, 'show']);

        $match = $router->match('GET', '/events/soiree-integration-2026');

        self::assertSame(200, $match['status']);
        self::assertSame(['slug' => 'soiree-integration-2026'], $match['params']);
    }

    public function test_match_extraire_parametre_entier(): void
    {
        $router = new Router();
        $router->get('/events/{id:int}', [FakeController::class, 'show']);

        $match = $router->match('GET', '/events/42');

        self::assertSame(200, $match['status']);
        self::assertSame(['id' => '42'], $match['params']);
    }

    public function test_match_404_si_route_inconnue(): void
    {
        $router = new Router();
        $router->get('/', [FakeController::class, 'index']);

        $match = $router->match('GET', '/page-inconnue');

        self::assertSame(404, $match['status']);
    }

    public function test_match_405_si_mauvaise_methode(): void
    {
        $router = new Router();
        $router->get('/events', [FakeController::class, 'index']);

        $match = $router->match('POST', '/events');

        self::assertSame(405, $match['status']);
    }

    public function test_match_ignore_la_query_string(): void
    {
        $router = new Router();
        $router->get('/events/{slug}', [FakeController::class, 'show']);

        $match = $router->match('GET', '/events/lan?ref=home');

        self::assertSame(200, $match['status']);
        self::assertSame(['slug' => 'lan'], $match['params']);
    }
}

/**
 * Contrôleur factice utilisé uniquement pour les tests.
 */
final class FakeController
{
    public function index(): void
    {
    }

    public function show(string $slug = ''): void
    {
    }
}
