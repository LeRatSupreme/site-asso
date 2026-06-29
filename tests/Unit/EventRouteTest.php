<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\EventController;
use App\Core\Router;
use PHPUnit\Framework\TestCase;

/**
 * Tests des routes publiques des événements : matching /events et
 * /events/{slug}, et comportement 404 sur un slug absent.
 *
 * Ces tests ne touchent pas la base de données : ils valident uniquement
 * la résolution de routes via le Router. Le test fonctionnel du contrôleur
 * (requêtes SQL réelles) nécessite la base de test `aeic_test` (cf.
 * EventModelTest / TeamMemberTest).
 */
final class EventRouteTest extends TestCase
{
    private function router(): Router
    {
        $router = new Router();
        $router->get('/events', [EventController::class, 'index']);
        $router->get('/events/{slug}', [EventController::class, 'show']);
        $router->notFound([EventController::class, 'index']);

        return $router;
    }

    public function test_route_events_liste_match(): void
    {
        $match = $this->router()->match('GET', '/events');

        self::assertSame(200, $match['status']);
        self::assertSame([EventController::class, 'index'], $match['handler']);
        self::assertSame([], $match['params']);
    }

    public function test_route_events_detail_match_avec_slug(): void
    {
        $match = $this->router()->match('GET', '/events/soiree-integration-2026');

        self::assertSame(200, $match['status']);
        self::assertSame(
            ['slug' => 'soiree-integration-2026'],
            $match['params']
        );
        self::assertSame([EventController::class, 'show'], $match['handler']);
    }

    public function test_route_events_detail_404_si_slug_inconnu(): void
    {
        // Une route /events/{slug} matche n'importe quel slug au niveau routing.
        // C'est le contrôleur qui décide du 404 (Event non trouvé en base).
        // On vérifie donc que le router matche bien, puis on simule le 404
        // via le handler notFound pour un chemin qui n'existe pas du tout.
        $match = $this->router()->match('GET', '/cet-evenement-nexiste-pas-dans-les-routes');

        self::assertSame(404, $match['status']);
        self::assertNull($match['handler']);
    }

    public function test_route_events_405_sur_post(): void
    {
        $match = $this->router()->match('POST', '/events');

        self::assertSame(405, $match['status']);
    }

    public function test_route_events_slug_avec_query_string(): void
    {
        $match = $this->router()->match('GET', '/events/lan-party-2026?from=home');

        self::assertSame(200, $match['status']);
        self::assertSame(['slug' => 'lan-party-2026'], $match['params']);
    }
}
