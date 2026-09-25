<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Event;
use App\Models\Page;
use App\Models\Poll;
use App\Models\Setting;

/**
 * SEO : sitemap.xml dynamique et recherche globale.
 *
 * Liste les pages statiques, les événements publiés, les sondages publiés
 * et les pages CMS publiées.
 */
final class SeoController extends Controller
{
    public function sitemap(): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        header('X-Robots-Tag: noindex');

        echo self::buildSitemap();
    }

    /**
     * Génère le XML du sitemap (testable isolément, sans effet de bord HTTP).
     */
    public static function buildSitemap(): string
    {
        $base = rtrim(APP_URL, '/');
        $urls = [];

        // Convertit un chemin local (/uploads/x.jpg) en URL absolue.
        $abs = static function (string $u) use ($base): string {
            $u = trim($u);
            if ($u === '') {
                return '';
            }
            return preg_match('#^https?://#i', $u) === 1 ? $u : $base . '/' . ltrim($u, '/');
        };

        // Dernière mise à jour réelle des listes d'événements et de sondages
        // (sert de lastmod aux pages index ; les modèles échouent silencieusement).
        $eventsLastmod = null;
        $pollsLastmod = null;
        foreach (Event::published() as $ev) {
            foreach (['updated_at', 'date'] as $k) {
                if (!empty($ev[$k])) {
                    $ts = strtotime((string) $ev[$k]);
                    if ($ts !== false && ($eventsLastmod === null || $ts > strtotime((string) $eventsLastmod))) {
                        $eventsLastmod = date('Y-m-d', $ts);
                    }
                }
            }
        }
        foreach (Poll::published() as $poll) {
            if (!empty($poll['updated_at'])) {
                $ts = strtotime((string) $poll['updated_at']);
                if ($ts !== false && ($pollsLastmod === null || $ts > strtotime((string) $pollsLastmod))) {
                    $pollsLastmod = date('Y-m-d', $ts);
                }
            }
        }

        // Pages statiques publiques.
        $static = [
            '/'                 => ['priority' => '1.0', 'changefreq' => 'daily'],
            '/events'           => ['priority' => '0.9', 'changefreq' => 'daily', 'lastmod' => $eventsLastmod],
            '/presentation'     => ['priority' => '0.8', 'changefreq' => 'monthly'],
            '/sondages'         => ['priority' => '0.8', 'changefreq' => 'daily', 'lastmod' => $pollsLastmod],
            '/team'             => ['priority' => '0.7', 'changefreq' => 'monthly'],
            '/jeux'             => ['priority' => '0.7', 'changefreq' => 'weekly'],
            '/galerie'          => ['priority' => '0.6', 'changefreq' => 'weekly', 'images' => true],
            '/jeux/wordle'      => ['priority' => '0.6', 'changefreq' => 'daily'],
            '/jeux/enigme'      => ['priority' => '0.6', 'changefreq' => 'daily'],
            '/jeux/memory'      => ['priority' => '0.6', 'changefreq' => 'weekly'],
            '/jeux/snake'       => ['priority' => '0.6', 'changefreq' => 'weekly'],
            '/jeux/tetris'      => ['priority' => '0.6', 'changefreq' => 'weekly'],
            '/legal'            => ['priority' => '0.4', 'changefreq' => 'yearly'],
            '/privacy'          => ['priority' => '0.4', 'changefreq' => 'yearly'],
            '/cgu'              => ['priority' => '0.4', 'changefreq' => 'yearly'],
            '/jeux/leaderboard' => ['priority' => '0.4', 'changefreq' => 'daily'],
        ];

        // Image Open Graph de l'accueil.
        $ogImage = $abs(Setting::get('og_image', ''));
        if ($ogImage !== '') {
            $static['/']['images'] = [['loc' => $ogImage]];
        }

        foreach ($static as $p => $meta) {
            $urls[] = [
                'loc'       => $base . $p,
                'priority'  => $meta['priority'],
                'changefreq' => $meta['changefreq'],
                'lastmod'   => $meta['lastmod'] ?? null,
                'images'    => $meta['images'] ?? [],
            ];
        }
        $staticPaths = array_map(static fn (string $p): string => ltrim($p, '/'), array_keys($static));

        // Événements publiés (+ visuel de l'événement en image sitemap).
        foreach (Event::published() as $ev) {
            if (!empty($ev['slug'])) {
                $images = [];
                if (!empty($ev['image'])) {
                    $img = $abs((string) $ev['image']);
                    if ($img !== '') {
                        $images[] = ['loc' => $img, 'title' => (string) ($ev['title'] ?? '')];
                    }
                }
                $urls[] = [
                    'loc'      => $base . '/events/' . rawurlencode((string) $ev['slug']),
                    'priority' => '0.7',
                    'changefreq' => 'weekly',
                    'lastmod'  => !empty($ev['date']) ? date('Y-m-d', strtotime((string) $ev['date'])) : null,
                    'images'   => $images,
                ];
            }
        }

        // Sondages publiés.
        foreach (Poll::published() as $poll) {
            if (!empty($poll['slug'])) {
                $urls[] = [
                    'loc'      => $base . '/sondages/' . rawurlencode((string) $poll['slug']),
                    'priority' => '0.6',
                    'changefreq' => 'daily',
                    'lastmod'  => !empty($poll['updated_at']) ? date('Y-m-d', strtotime((string) $poll['updated_at'])) : null,
                ];
            }
        }

        // Photos de la galerie : médias uploadés + photos d'événements passés
        // (plafonnées pour garder un XML léger ; la spec autorise 1000 images/URL).
        $galerieImages = [];
        try {
            $stmt = db()->query(
                'SELECT url, alt, name
                 FROM media
                 ORDER BY created_at DESC
                 LIMIT 50'
            );
            foreach ($stmt->fetchAll() as $m) {
                $img = $abs((string) ($m['url'] ?? ''));
                if ($img !== '') {
                    $galerieImages[] = ['loc' => $img, 'caption' => (string) ($m['alt'] ?? $m['name'] ?? '')];
                }
            }
            foreach (Event::pastPhotos() as $row) {
                if (count($galerieImages) >= 100) {
                    break;
                }
                $img = $abs((string) ($row['url'] ?? ''));
                if ($img !== '') {
                    $galerieImages[] = ['loc' => $img, 'caption' => (string) ($row['caption'] ?? '')];
                }
            }
        } catch (\Throwable) {
            // Base indisponible : galerie sans images.
        }
        foreach ($urls as &$u) {
            if (($u['images'] ?? null) === true) {
                $u['images'] = $galerieImages;
            }
        }
        unset($u);

        // Pages CMS publiées.
        try {
            $pages = Page::all();
            foreach ($pages as $pg) {
                if (!empty($pg['is_published']) && !empty($pg['slug'])) {
                    $slug = (string) $pg['slug'];
                    // Les pages CMS qui doublonnent une page statique
                    // (/p/presentation vs /presentation) sont exclues du
                    // sitemap pour éviter le contenu dupliqué.
                    if (in_array($slug, $staticPaths, true)) {
                        continue;
                    }
                    $urls[] = [
                        'loc'      => $base . '/p/' . rawurlencode($slug),
                        'priority' => '0.5',
                        'changefreq' => 'monthly',
                        'lastmod'  => !empty($pg['updated_at']) ? date('Y-m-d', strtotime((string) $pg['updated_at'])) : null,
                    ];
                }
            }
        } catch (\Throwable) {
            // Base indisponible : on ignore les pages CMS.
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
            . ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
        foreach ($urls as $u) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . htmlspecialchars((string) $u['loc'], ENT_XML1) . "</loc>\n";
            if (!empty($u['lastmod'])) {
                $xml .= '    <lastmod>' . $u['lastmod'] . "</lastmod>\n";
            }
            if (!empty($u['changefreq'])) {
                $xml .= '    <changefreq>' . $u['changefreq'] . "</changefreq>\n";
            }
            if (!empty($u['priority'])) {
                $xml .= '    <priority>' . $u['priority'] . "</priority>\n";
            }
            foreach ((array) ($u['images'] ?? []) as $img) {
                if (empty($img['loc'])) {
                    continue;
                }
                $xml .= "    <image:image>\n";
                $xml .= '      <image:loc>' . htmlspecialchars((string) $img['loc'], ENT_XML1) . "</image:loc>\n";
                foreach (['title', 'caption'] as $k) {
                    $v = trim((string) ($img[$k] ?? ''));
                    if ($v !== '') {
                        $xml .= '      <image:' . $k . '>' . htmlspecialchars($v, ENT_XML1) . '</image:' . $k . ">\n";
                    }
                }
                $xml .= "    </image:image>\n";
            }
            $xml .= "  </url>\n";
        }
        $xml .= '</urlset>' . "\n";

        return $xml;
    }

    /**
     * Recherche globale (GET /search?q=...).
     *
     * Cherche dans les événements (titre, excerpt), les sondages (titre)
     * et les pages (titre), et renvoie du JSON (max 10 résultats).
     */
    public function search(): void
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $results = [];

        if ($q !== '' && mb_strlen($q) >= 2) {
            $like = '%' . str_replace(['%', '_', '\\'], ['\%', '\_', '\\\\'], $q) . '%';

            // Événements (titre + excerpt).
            foreach (Event::search($like, 5) as $row) {
                $results[] = [
                    'type'    => 'Événement',
                    'title'   => (string) ($row['title'] ?? ''),
                    'url'     => url('/events/' . rawurlencode((string) ($row['slug'] ?? ''))),
                    'excerpt' => trim(strip_tags((string) ($row['excerpt'] ?? ''))),
                ];
            }

            // Sondages (titre).
            foreach (Poll::search($like, 3) as $row) {
                $results[] = [
                    'type'    => 'Sondage',
                    'title'   => (string) ($row['title'] ?? ''),
                    'url'     => url('/sondages/' . rawurlencode((string) ($row['slug'] ?? ''))),
                    'excerpt' => trim(strip_tags((string) ($row['description'] ?? ''))),
                ];
            }

            // Pages (titre).
            foreach (Page::search($like, 3) as $row) {
                $results[] = [
                    'type'    => 'Page',
                    'title'   => (string) ($row['title'] ?? ''),
                    'url'     => url('/p/' . rawurlencode((string) ($row['slug'] ?? ''))),
                    'excerpt' => '',
                ];
            }

            $results = array_slice($results, 0, 10);
        }

        $this->json($results);
    }
}
