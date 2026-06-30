<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Models\Event;
use App\Models\Setting;
use App\Models\User;

/**
 * Page d'accueil publique.
 */
final class HomeController extends Controller
{
    public function index(): void
    {
        $siteName = Setting::get('site_name', 'AEIC');
        $logoUrl = is_absolute_url(Setting::get('og_image', ''))
            ? Setting::get('og_image', '')
            : APP_URL . asset('img/favicon.svg');

        $orgLd = json_encode([
            '@context'    => 'https://schema.org',
            '@type'       => 'Organization',
            'name'        => $siteName,
            'url'         => APP_URL,
            'logo'        => $logoUrl,
            'description' => Setting::get('site_description'),
            'email'       => Setting::get('contact_email', ''),
            'address'     => [
                '@type'           => 'PostalAddress',
                'addressLocality' => 'Calais',
                'addressCountry'  => 'FR',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->render('home', [
            'title'           => $siteName,
            'description'     => Setting::get('site_description'),
            'jsonLd'          => $orgLd,
            'siteName'        => $siteName,
            'upcoming'        => Event::featured(3),
            'eventsCount'     => Event::count(),
            'usersCount'      => User::countActive(),
            'maintenanceMode' => Setting::getBool('maintenance_mode', false),
        ]);
    }

    /**
     * Galerie photos publique : photos des événements passés, groupées par événement.
     */
    public function galerie(): void
    {
        $rows = Event::pastPhotos();

        // Regroupement par événement (en gardant l'ordre : événements récents d'abord).
        $groups = [];
        foreach ($rows as $row) {
            $eventId = (string) $row['event_id'];
            if (!isset($groups[$eventId])) {
                $groups[$eventId] = [
                    'event_id'    => $eventId,
                    'event_title' => (string) ($row['event_title'] ?? ''),
                    'event_slug'  => (string) ($row['event_slug'] ?? ''),
                    'event_date'  => (string) ($row['event_date'] ?? ''),
                    'photos'      => [],
                ];
            }
            $groups[$eventId]['photos'][] = [
                'url'       => (string) ($row['url'] ?? ''),
                'caption'   => (string) ($row['caption'] ?? ''),
                'photo_id'  => (string) ($row['photo_id'] ?? ''),
            ];
        }

        $this->render('galerie/index', [
            'title'       => 'Galerie — AEIC',
            'description' => 'Photos des événements passés de l\'AEIC : soirées, LAN, conférences et plus.',
            'groups'      => array_values($groups),
        ]);
    }
}
