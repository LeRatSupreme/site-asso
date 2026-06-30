<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Discord;
use App\Models\Event;
use App\Models\EventWaitlist;
use App\Models\Notification;
use App\Models\Registration;

/**
 * CRUD des événements + visualisation des inscriptions.
 */
final class AdminEventController extends AdminBaseController
{
    public function index(): void
    {
        $this->guard();

        $this->renderAdmin('admin/events/index', [
            'title'  => 'Événements',
            'events' => Event::allForAdmin(),
        ]);
    }

    public function form(?string $slug = null): void
    {
        $this->guard();

        $event = ['is_published' => 0, 'is_featured' => 0];
        if ($slug !== null) {
            $found = Event::findBySlugAny($slug);
            if ($found !== null) {
                $event = $found;
            }
        }

        $this->renderAdmin('admin/events/form', [
            'title' => isset($event['id']) ? 'Modifier l\'événement' : 'Nouvel événement',
            'event' => $event,
        ]);
    }

    public function save(): void
    {
        $this->guard();

        $data = $_POST;
        $isNew = empty($data['id']);

        // Normalisations.
        $data['price'] = ($data['price'] ?? '') !== '' ? parseFrenchFloat((string) $data['price']) : null;
        $data['max_capacity'] = ($data['max_capacity'] ?? '') !== '' ? (int) $data['max_capacity'] : null;

        // Carte : si demandée et qu'on a un lieu, on géocode (une seule fois
        // par enregistrement ; les coords sont réutilisées à l'affichage).
        if (!empty($data['show_map']) && trim((string) ($data['location'] ?? '')) !== '') {
            $existing = !empty($data['id']) ? Event::find((string) $data['id']) : null;
            $needGeocode = ($existing === null)
                || empty($existing['map_lat'])
                || empty($existing['map_lon'])
                || ((string) ($existing['location'] ?? '') !== trim((string) $data['location']));
            if ($needGeocode) {
                $coords = Event::geocode((string) $data['location']);
                if ($coords !== null) {
                    $data['map_lat'] = $coords['lat'];
                    $data['map_lon'] = $coords['lon'];
                }
            } else {
                $data['map_lat'] = $existing['map_lat'];
                $data['map_lon'] = $existing['map_lon'];
            }
        } else {
            // Pas de carte : on conserve les coords éventuelles mais on n'affichera pas.
            $data['map_lat'] = $data['map_lat'] ?? null;
            $data['map_lon'] = $data['map_lon'] ?? null;
        }

        $id = Event::save($data);

        $this->audit($isNew ? 'event.create' : 'event.update', 'event', $id);

        // Annonce Discord (non bloquante) — uniquement à la création.
        if ($isNew) {
            try {
                Discord::notifyEvent(
                    (string) ($data['title'] ?? ''),
                    (string) ($data['date'] ?? ''),
                    (string) ($data['location'] ?? ''),
                    url('/events/' . rawurlencode((string) ($data['slug'] ?? '')))
                );
            } catch (\Throwable) {
                // Silencieux : un échec Discord ne doit pas casser la création.
            }
        }

        $this->setFlash('success', 'Événement enregistré.');
        redirect(url('/admin/events'));
    }

    public function delete(string $slug): void
    {
        $this->guard();

        $event = Event::findBySlugAny($slug);
        if ($event !== null) {
            Event::deleteRow((string) $event['id']);
            $this->audit('event.delete', 'event', (string) $event['id']);
            $this->setFlash('success', 'Événement supprimé.');
        }

        redirect(url('/admin/events'));
    }

    public function registrations(string $slug): void
    {
        $this->guard();

        $event = Event::findBySlugAny($slug);
        if ($event === null) {
            $this->abort(404);
        }

        $eventId = (string) $event['id'];

        $this->renderAdmin('admin/events/registrations', [
            'title'      => 'Inscriptions — ' . ($event['title'] ?? ''),
            'event'      => $event,
            'count'      => Event::registrationsCount($eventId),
            'regs'       => Event::registrationsNames($eventId, 1000),
            'variants'   => Event::variants($eventId),
            'waitlist'   => EventWaitlist::list($eventId),
            'checkinUrl' => url('/admin/events/' . rawurlencode($slug) . '/checkin'),
        ]);
    }

    /**
     * Page de check-in (scan QR) — GET /admin/events/{slug}/checkin.
     */
    public function checkinForm(string $slug): void
    {
        $this->guard();

        $event = Event::findBySlugAny($slug);
        if ($event === null) {
            $this->abort(404);
        }

        $eventId = (string) $event['id'];

        $this->renderAdmin('admin/events/checkin', [
            'title'    => 'Check-in — ' . ($event['title'] ?? ''),
            'event'    => $event,
            'count'    => Event::registrationsCount($eventId),
            'present'  => Event::presentCount($eventId),
        ]);
    }

    /**
     * Scan / saisie d'un token — POST /admin/events/{slug}/checkin (JSON).
     *
     * Reçoit `token` (formulaire ou scan) et marque l'inscription comme présente.
     */
    public function checkinScan(string $slug): void
    {
        $this->guard();

        $event = Event::findBySlugAny($slug);
        if ($event === null) {
            $this->json(['ok' => false, 'message' => 'Événement introuvable.'], 404);
        }

        // Le token peut venir d'un JSON body ou d'un formulaire classique.
        $input = json_decode((string) file_get_contents('php://input'), true);
        $token = '';
        if (is_array($input) && isset($input['token'])) {
            $token = (string) $input['token'];
        } else {
            $token = (string) ($_POST['token'] ?? '');
        }

        $result = Registration::checkInByToken((string) $event['id'], $token);

        if (!empty($result['ok']) && empty($result['already'])) {
            $this->audit('event.checkin', 'event', (string) $event['id'], ['name' => $result['name'] ?? '']);
        }

        $this->json($result);
    }

    /**
     * Bascule manuelle du statut présent (badge admin) — POST JSON.
     */
    public function toggleCheckedIn(string $slug): void
    {
        $this->guard();

        $event = Event::findBySlugAny($slug);
        if ($event === null) {
            $this->json(['ok' => false], 404);
        }

        $input = json_decode((string) file_get_contents('php://input'), true);
        $userId = (string) ($input['user_id'] ?? $_POST['user_id'] ?? '');
        if ($userId === '') {
            $this->json(['ok' => false, 'message' => 'Utilisateur manquant.'], 400);
        }

        $present = Registration::toggleCheckedIn($userId, (string) $event['id']);

        $this->json(['ok' => true, 'checked_in' => $present]);
    }

    /**
     * Promotion manuelle d'un utilisateur en attente — POST.
     *
     * Reçoit `user_id`. Crée l'inscription, notifie l'utilisateur et renvoie un
     * flash de confirmation.
     */
    public function promoteWaitlist(string $slug): void
    {
        $this->guard();

        $event = Event::findBySlugAny($slug);
        if ($event === null) {
            $this->abort(404);
        }

        $eventId = (string) $event['id'];
        $userId = (string) ($_POST['user_id'] ?? '');
        if ($userId === '' || !EventWaitlist::isOnList($userId, $eventId)) {
            $this->setFlash('error', 'Utilisateur introuvable dans la liste d\'attente.');
            redirect(url('/admin/events/' . rawurlencode($slug) . '/registrations'));
        }

        EventWaitlist::remove($userId, $eventId);

        try {
            Registration::create($userId, $eventId);
        } catch (\Throwable) {
            $this->setFlash('info', 'Cet utilisateur est déjà inscrit.');
            redirect(url('/admin/events/' . rawurlencode($slug) . '/registrations'));
        }

        $title = (string) ($event['title'] ?? '');
        Notification::create(
            $userId,
            'event.promoted',
            'Une place s\'est libérée pour « ' . $title . ' » !',
            'Vous êtes désormais inscrit·e.',
            url('/events/' . rawurlencode($slug))
        );

        $this->audit('event.waitlist.promote', 'event', $eventId, ['user_id' => $userId]);
        $this->setFlash('success', 'Utilisateur promu et notifié.');
        redirect(url('/admin/events/' . rawurlencode($slug) . '/registrations'));
    }
}
