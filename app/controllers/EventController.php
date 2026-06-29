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
        $upcoming = Event::upcoming();
        $past = Event::past(12);

        $this->render('events/index', [
            'title'         => 'Événements — AEIC',
            'description'   => 'Agenda des prochains rendez-vous de l\'AEIC : soirées, LAN, conférences.',
            'upcoming'      => $upcoming,
            'past'          => $past,
            'countUpcoming' => count($upcoming),
            'countPast'     => count($past),
        ]);
    }

    /**
     * Détail d'un événement par son slug.
     */
    public function show(string $slug): void
    {
        $event = Event::findBySlug($slug);

        if ($event === null) {
            $this->abort(404);
        }

        $eventId = (string) $event['id'];

        $isPast = false;
        if (!empty($event['date'])) {
            try {
                $isPast = new \DateTimeImmutable($event['date']) < new \DateTimeImmutable('now');
            } catch (\Throwable) {
                $isPast = false;
            }
        }

        $this->render('events/show', [
            'title'              => ($event['title'] ?? 'Événement') . ' — AEIC',
            'description'        => $event['excerpt'] ?? '',
            'event'              => $event,
            'variants'           => Event::variants($eventId),
            'registrationsCount' => Event::registrationsCount($eventId),
            'participants'       => Event::registrationsNames($eventId, 10),
            'photos'             => Event::photos($eventId),
            'isPast'             => $isPast,
        ]);
    }
}
