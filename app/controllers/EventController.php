<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Event;
use App\Models\EventWaitlist;
use App\Models\Registration;

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

        $isRegistered = Auth::check() ? Registration::isRegistered((string) Auth::id(), $eventId) : false;
        $isOnWaitlist = Auth::check() ? EventWaitlist::isOnList((string) Auth::id(), $eventId) : false;

        $maxCapacity = $event['max_capacity'] ?? null;
        $registrationsCount = Event::registrationsCount($eventId);
        $isFull = $maxCapacity !== null && (int) $maxCapacity > 0 && $registrationsCount >= (int) $maxCapacity;
        $remaining = ($maxCapacity !== null && (int) $maxCapacity > 0)
            ? max(0, (int) $maxCapacity - $registrationsCount)
            : null;

        $this->render('events/show', [
            'title'              => ($event['title'] ?? 'Événement') . ' — AEIC',
            'description'        => $event['excerpt'] ?? '',
            'ogType'             => 'article',
            'ogImage'            => $event['image'] ?? '',
            'event'              => $event,
            'variants'           => Event::variants($eventId),
            'registrationsCount' => $registrationsCount,
            'participants'       => Event::registrationsNames($eventId, 10),
            'photos'             => Event::photos($eventId),
            'isPast'             => $isPast,
            'isRegistered'       => $isRegistered,
            'isOnWaitlist'       => $isOnWaitlist,
            'waitlistPosition'   => $isOnWaitlist
                ? EventWaitlist::position((string) Auth::id(), $eventId)
                : 0,
            'isFull'             => $isFull,
            'remaining'          => $remaining,
            'qrToken'            => $isRegistered
                ? Registration::qrTokenForUser((string) Auth::id(), $eventId)
                : null,
            'jsonLd'             => $this->eventJsonLd($event),
        ]);
    }

    /**
     * Données structurées JSON-LD (schema.org/Event) pour le SEO.
     *
     * @param array<string,mixed> $event
     */
    private function eventJsonLd(array $event): string
    {
        $data = [
            '@context'  => 'https://schema.org',
            '@type'     => 'Event',
            'name'      => $event['title'] ?? '',
            'url'       => APP_URL . '/events/' . rawurlencode((string) ($event['slug'] ?? '')),
            'startDate' => !empty($event['date']) ? date('c', strtotime((string) $event['date'])) : null,
        ];
        if (!empty($event['description'])) {
            $data['description'] = trim(strip_tags((string) $event['description']));
        }
        if (!empty($event['location'])) {
            $data['location'] = ['@type' => 'Place', 'name' => $event['location']];
        }
        if (!empty($event['image'])) {
            $img = $event['image'];
            $data['image'] = is_absolute_url((string) $img) ? $img : APP_URL . '/' . ltrim((string) $img, '/');
        }
        if (!empty($event['price'])) {
            $data['offers'] = [
                '@type'         => 'Offer',
                'price'         => (float) $event['price'],
                'priceCurrency' => 'EUR',
                'availability'  => 'https://schema.org/InStock',
            ];
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
