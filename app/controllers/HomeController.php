<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Compta\StockPublic;
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
            : asset('img/favicon.svg');

        $orgLd = json_encode([
            '@context' => 'https://schema.org',
            '@graph'   => [
                [
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
                    'alternateName' => [
                        'Association des Étudiants Informatique de Calais',
                        'Association Étudiante Informatique de Calais',
                        'Association étudiante Calais',
                        'BDE Informatique Calais',
                        'BUT Informatique Calais',
                        'IUT de Calais',
                        'ULCO Informatique',
                        'IUT ULCO',
                        'Association informatique Calais',
                    ],
                    'slogan'      => 'La vie étudiante du BUT Informatique de Calais',
                    'keywords'    => 'AEIC, association étudiante Calais, BUT informatique, BUT informatique Calais, IUT de Calais, IUT Calais, informatique Calais, association Calais, rentrée IUT Calais, ULCO, ULCO IUT, IUT ULCO, ULCO informatique, Université du Littoral Côte d\'Opale, Côte d\'Opale, informatique association, IUT association, vie étudiante, BDE, Adrien Remond',
                    'areaServed'  => [
                        '@type' => 'City',
                        'name'  => 'Calais',
                    ],
                    'sameAs'      => [
                        'https://www.facebook.com/IUTinfoCalais/',
                    ],
                ],
                [
                    '@type'      => 'WebSite',
                    'name'       => $siteName . ' — Association Étudiante Informatique de Calais',
                    'url'        => APP_URL,
                    'inLanguage' => 'fr-FR',
                    'potentialAction' => [
                        '@type'       => 'SearchAction',
                        'target'      => [
                            '@type'       => 'EntryPoint',
                            'urlTemplate' => APP_URL . '/search?q={search_term_string}',
                        ],
                        'query-input' => 'required name=search_term_string',
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->render('home', [
            'title'           => 'AEIC — Association Étudiante Informatique de Calais',
            'description'     => 'L\'AEIC est l\'association étudiante du BUT Informatique de l\'IUT de Calais : événements, cafétéria, jeux, vie associative et entraide entre étudiants à Calais.',
            'jsonLd'          => $orgLd,
            'siteName'        => $siteName,
            'upcoming'        => Event::featured(3),
            'menuCategories'  => $this->buildMenu(),
            'promotions'      => Promotion::active(),
            'maintenanceMode' => Setting::getBool('maintenance_mode', false),
        ]);
    }

    /**
     * Construit la carte de la cafétéria : catégories actives avec leurs
     * produits disponibles (is_active = 1 ET is_available = 1).
     *
     * Le stock affiché est le stock THÉORIQUE issu de l'inventaire compta
     * (cache 5 min, voir StockPublic::menuStockMap()) apparié au nom du
     * produit ; `menu_stock` vaut null quand aucune clé d'inventaire ne
     * correspond (aucun repli sur products.stock, obsolète).
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
        $stockMap = StockPublic::menuStockMap();

        $byCat = [];
        foreach ($products as $product) {
            $catId = (string) ($product['category_id'] ?? '');
            if ($catId === '') {
                continue;
            }
            $product['menu_stock'] = StockPublic::stockForMenuProduct(
                (string) ($product['name'] ?? ''),
                $stockMap
            );
            $byCat[$catId][] = $product;
        }

        // Les produits disponibles d'abord, stock inconnu ensuite, les
        // épuisés en fin de groupe pour mettre en avant ce qui est vendable.
        $stockPriority = static function (array $p): int {
            $s = $p['menu_stock'] ?? null;
            if ($s === null) {
                return 1;   // stock inconnu (pas de clé d'inventaire)
            }

            return $s <= 0 ? 2 : 0;   // épuisé en dernier
        };

        foreach ($byCat as $catId => $items) {
            usort($items, static function (array $a, array $b) use ($stockPriority): int {
                $prio = $stockPriority($a) <=> $stockPriority($b);
                if ($prio !== 0) {
                    return $prio;
                }

                return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
            });
            $byCat[$catId] = $items;
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
            'title'       => 'Galerie photos — AEIC, association étudiante Calais',
            'description' => 'Photos des événements et de la vie étudiante de l\'AEIC, l\'association du BUT Informatique de l\'IUT de Calais.',
            'groups'      => $groups,
        ]);
    }
}
