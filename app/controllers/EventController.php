<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Models\Event;

/**
 * Liste et détail des événements publics.
 */
final class EventController extends Controller
{
    /**
     * Agenda : événements à venir + archives.
     */
    public function index(): void
    {
        $upcoming = Event::publishedUpcoming();
        $past = Event::publishedPast(12);

        $this->render('events/index', [
            'title'       => 'Événements — AEIC',
            'description' => 'Agenda des prochains rendez-vous de l\'AEIC : soirées, LAN, conférences.',
            'upcoming'    => $upcoming,
            'past'        => $past,
            'countUpcoming' => count($upcoming),
            'countPast'     => count($past),
        ]);
    }

    /**
     * Détail d'un événement par son slug.
     *
     * (Phase 1 : renvoie 404. Le détail complet arrive en Phase 2.)
     */
    public function show(string $slug): void
    {
        $event = Event::findBySlug($slug);

        if ($event === null) {
            $this->abort(404);
        }

        // Phase 1 : la page détail n'est pas encore implémentée.
        $this->abort(404);
    }
}
