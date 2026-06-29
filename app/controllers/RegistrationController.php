<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Middleware;
use App\Models\Event;
use App\Models\Registration;

/**
 * Inscription / désinscription aux événements (connexion requise).
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

        try {
            Registration::create($userId, $eventId, $selected);
        } catch (\Throwable) {
            // Doublon (unicité DB) : déjà inscrit.
            $this->setFlash('info', 'Vous êtes déjà inscrit à cet événement.');
            redirect(url('/events/' . $slug));
        }

        $this->setFlash('success', 'Inscription confirmée ! Rendez-vous sur votre espace pour le suivi.');
        redirect(url('/events/' . $slug));
    }

    /**
     * Désinscrit l'utilisateur d'un événement (POST /events/{slug}/unregister).
     */
    public function unregister(string $slug): void
    {
        Middleware::requireLogin();

        $event = Event::findBySlug($slug);
        if ($event === null) {
            $this->abort(404);
        }

        Registration::unregister((string) Auth::id(), (string) $event['id']);

        $this->setFlash('success', 'Vous êtes désinscrit de cet événement.');
        redirect(url('/events/' . $slug));
    }
}
