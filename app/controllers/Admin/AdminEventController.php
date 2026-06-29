<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Event;

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
            'title'    => 'Inscriptions — ' . ($event['title'] ?? ''),
            'event'    => $event,
            'count'    => Event::registrationsCount($eventId),
            'regs'     => Event::registrationsNames($eventId, 1000),
            'variants' => Event::variants($eventId),
        ]);
    }
}
