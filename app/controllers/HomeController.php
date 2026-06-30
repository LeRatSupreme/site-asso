<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Models\Event;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Promotion;
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
            'menuCategories'  => $this->buildMenu(),
            'promotions'      => Promotion::active(),
            'maintenanceMode' => Setting::getBool('maintenance_mode', false),
        ]);
    }

    /**
     * Construit la carte de la cafétéria : catégories actives avec leurs
     * produits disponibles (is_active = 1 ET is_available = 1).
     *
     * @return list<array<string,mixed>>
     */
    private function buildMenu(): array
    {
        $categories = ProductCategory::active();
        if ($categories === []) {
            return [];
        }

        $products = Product::available();

        $byCat = [];
        foreach ($products as $product) {
            $catId = (string) ($product['category_id'] ?? '');
            if ($catId === '') {
                continue;
            }
            $byCat[$catId][] = $product;
        }

        $menu = [];
        foreach ($categories as $category) {
            $catId = (string) $category['id'];
            $items = $byCat[$catId] ?? [];
            if ($items === []) {
                continue;
            }
            $menu[] = [
                'id'          => $catId,
                'name'        => (string) $category['name'],
                'description' => (string) ($category['description'] ?? ''),
                'products'    => $items,
            ];
        }

        return $menu;
    }

    /**
     * Galerie photos publique : tous les médias uploadés.
     */
    public function galerie(): void
    {
        $photos = [];

        // Médias uploadés (table media).
        try {
            $stmt = db()->query(
                'SELECT id, name, url, alt
                 FROM media
                 ORDER BY created_at DESC
                 LIMIT 50'
            );
            $photos = $stmt->fetchAll();
        } catch (\Throwable $e) {
            // Log silencieux.
            if (defined('APP_DEBUG') && APP_DEBUG) {
                error_log('Galerie media query error: ' . $e->getMessage());
            }
        }

        $groups = [];
        if (!empty($photos)) {
            $groups[] = [
                'event_id'    => 'media',
                'event_title' => 'Photos',
                'event_slug'  => '',
                'event_date'  => '',
                'photos'      => array_map(static function (array $m): array {
                    return [
                        'url'      => (string) ($m['url'] ?? ''),
                        'caption'  => (string) ($m['alt'] ?? $m['name'] ?? ''),
                        'photo_id' => (string) ($m['id'] ?? ''),
                    ];
                }, $photos),
            ];
        }

        // Aussi les photos d'événements (table photos) si elles existent.
        try {
            $eventPhotos = Event::pastPhotos();
            $byEvent = [];
            foreach ($eventPhotos as $row) {
                $eid = (string) $row['event_id'];
                if (!isset($byEvent[$eid])) {
                    $byEvent[$eid] = [
                        'event_id'    => $eid,
                        'event_title' => (string) ($row['event_title'] ?? ''),
                        'event_slug'  => (string) ($row['event_slug'] ?? ''),
                        'event_date'  => (string) ($row['event_date'] ?? ''),
                        'photos'      => [],
                    ];
                }
                $byEvent[$eid]['photos'][] = [
                    'url'      => (string) ($row['url'] ?? ''),
                    'caption'  => (string) ($row['caption'] ?? ''),
                    'photo_id' => (string) ($row['photo_id'] ?? ''),
                ];
            }
            $groups = array_merge($groups, array_values($byEvent));
        } catch (\Throwable) {
            // Ignore.
        }

        $this->render('galerie/index', [
            'title'       => 'Galerie — AEIC',
            'description' => 'Photos des événements et activités de l\'AEIC.',
            'groups'      => $groups,
        ]);
    }
}
