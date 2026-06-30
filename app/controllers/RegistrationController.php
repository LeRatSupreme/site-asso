<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Mailer;
use App\Core\Middleware;
use App\Models\Event;
use App\Models\EventWaitlist;
use App\Models\Notification;
use App\Models\Registration;

/**
 * Inscription / désinscription aux événements (connexion requise).
 *
 * Gère la capacité maximale : lorsqu'un événement est complet, l'utilisateur
 * rejoint la liste d'attente ; une désinscription libère une place et promeut
 * automatiquement le premier de la file (notification + email).
 */
final class RegistrationController extends Controller
{
    /**
     * Inscrit l'utilisateur connecté à un événement (POST /events/{slug}/register).
     */
    public function register(string $slug): void
    {
        Middleware::requireLogin();

        $event = Event::findBySlug($slug);
        if ($event === null) {
            $this->abort(404);
        }

        $eventId = (string) $event['id'];
        $userId = (string) Auth::id();

        // Événement passé : pas d'inscription.
        $isPast = !empty($event['date']) && strtotime((string) $event['date']) < time();
        if ($isPast) {
            $this->setFlash('error', 'Cet événement est terminé : inscription impossible.');
            redirect(url('/events/' . $slug));
        }

        // Déjà inscrit ?
        if (Registration::isRegistered($userId, $eventId)) {
            $this->setFlash('info', 'Vous êtes déjà inscrit à cet événement.');
            redirect(url('/events/' . $slug));
        }

        // Validation des variantes obligatoires.
        $variants = Event::variants($eventId);
        $selected = $_POST['variants'] ?? [];
        if (!is_array($selected)) {
            $selected = [];
        }

        $errors = [];
        foreach ($variants as $variant) {
            if (!empty($variant['required'])) {
                $variantId = (string) $variant['id'];
                if (empty($selected[$variantId])) {
                    $errors[] = sprintf('Le champ « %s » est obligatoire.', (string) $variant['label']);
                }
            }
        }

        if ($errors !== []) {
            $this->setFlash('error', implode(' ', $errors));
            redirect(url('/events/' . $slug));
        }

        // Capacité : événement complet -> liste d'attente.
        $maxCapacity = $event['max_capacity'] ?? null;
        $isFull = $maxCapacity !== null
            && (int) $maxCapacity > 0
            && Event::registrationsCount($eventId) >= (int) $maxCapacity;

        if ($isFull) {
            if (EventWaitlist::isOnList($userId, $eventId)) {
                $position = EventWaitlist::position($userId, $eventId);
                $this->setFlash('info', 'Vous êtes déjà sur la liste d\'attente (position ' . $position . ').');
                redirect(url('/events/' . $slug));
            }

            $position = EventWaitlist::add($userId, $eventId);

            Notification::create(
                $userId,
                'event.waitlist',
                'Liste d\'attente — « ' . (string) $event['title'] . ' »',
                'L\'événement est complet. Vous êtes en position ' . $position . '.',
                url('/events/' . $slug)
            );

            $this->setFlash('warning', 'Événement complet : vous êtes sur la liste d\'attente (position ' . $position . ').');
            redirect(url('/events/' . $slug));
        }

        try {
            Registration::create($userId, $eventId, $selected);
        } catch (\Throwable) {
            // Doublon (unicité DB) : déjà inscrit.
            $this->setFlash('info', 'Vous êtes déjà inscrit à cet événement.');
            redirect(url('/events/' . $slug));
        }

        Notification::create(
            $userId,
            'event.registered',
            'Vous êtes inscrit à « ' . (string) $event['title'] . ' »',
            'Votre inscription est confirmée. Retrouvez les détails dans votre espace.',
            url('/events/' . $slug)
        );

        $this->setFlash('success', 'Inscription confirmée ! Rendez-vous sur votre espace pour le suivi.');
        redirect(url('/events/' . $slug));
    }

    /**
     * Désinscrit l'utilisateur d'un événement (POST /events/{slug}/unregister).
     *
     * Si une liste d'attente existe, le premier de la file est promu en
     * inscription, notifié in-app et par email.
     */
    public function unregister(string $slug): void
    {
        Middleware::requireLogin();

        $event = Event::findBySlug($slug);
        if ($event === null) {
            $this->abort(404);
        }

        $eventId = (string) $event['id'];

        // On retire aussi une éventuelle entrée d'attente.
        EventWaitlist::remove((string) Auth::id(), $eventId);

        Registration::unregister((string) Auth::id(), $eventId);

        // Promotion automatique du premier de la file si l'événement a une capacité.
        $this->promoteFromWaitlist($event);

        $this->setFlash('success', 'Vous êtes désinscrit de cet événement.');
        redirect(url('/events/' . $slug));
    }

    /**
     * Promeut le premier utilisateur en attente d'un événement, le notifie et
     * lui envoie un email (si SMTP configuré).
     *
     * @param array<string,mixed> $event
     */
    private function promoteFromWaitlist(array $event): void
    {
        $eventId = (string) ($event['id'] ?? '');
        if ($eventId === '') {
            return;
        }

        $next = EventWaitlist::first($eventId);
        if ($next === null) {
            return;
        }

        $userId = (string) $next['user_id'];

        // On vérifie qu'une place est réellement disponible (capacité non dépassée).
        $maxCapacity = $event['max_capacity'] ?? null;
        if ($maxCapacity !== null && (int) $maxCapacity > 0
            && Event::registrationsCount($eventId) >= (int) $maxCapacity) {
            return;
        }

        // Retrait de la file + création de l'inscription (avec token QR).
        EventWaitlist::remove($userId, $eventId);

        try {
            Registration::create($userId, $eventId);
        } catch (\Throwable) {
            // Déjà inscrit (cas très rare) : on consomme juste l'entrée d'attente.
            return;
        }

        $title = (string) ($event['title'] ?? '');
        $eventUrl = url('/events/' . rawurlencode((string) ($event['slug'] ?? '')));

        Notification::create(
            $userId,
            'event.promoted',
            'Une place s\'est libérée pour « ' . $title . ' » !',
            'Vous étiez sur liste d\'attente : vous êtes désormais inscrit·e.',
            $eventUrl
        );

        // Email si SMTP / mail() disponible.
        $to = (string) ($next['email'] ?? '');
        if ($to !== '') {
            try {
                Mailer::send(
                    'waitlist_promoted',
                    $to,
                    'Une place s\'est libérée — « ' . $title . ' »',
                    [
                        'prenom'    => (string) ($next['prenom'] ?? ''),
                        'eventTitle'=> $title,
                        'eventUrl'  => $eventUrl,
                    ]
                );
            } catch (\Throwable) {
                // L'envoi mail ne doit jamais casser la désinscription.
            }
        }
    }
}
