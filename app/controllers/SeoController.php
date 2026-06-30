<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Article;
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

        // Pages statiques publiques.
        $static = [
            '/'             => '1.0',
            '/blog'         => '0.9',
            '/events'       => '0.9',
            '/presentation' => '0.8',
            '/team'         => '0.7',
            '/sondages'     => '0.8',
            '/legal'        => '0.4',
            '/privacy'      => '0.4',
            '/cgu'          => '0.4',
        ];
        foreach ($static as $p => $priority) {
            $urls[] = ['loc' => $base . $p, 'priority' => $priority];
        }

        // Événements publiés.
        foreach (Event::published() as $ev) {
            if (!empty($ev['slug'])) {
                $urls[] = [
                    'loc'      => $base . '/events/' . rawurlencode((string) $ev['slug']),
                    'priority' => '0.7',
                    'lastmod'  => !empty($ev['date']) ? date('Y-m-d', strtotime((string) $ev['date'])) : null,
                ];
            }
        }

        // Sondages publiés.
        foreach (Poll::published() as $poll) {
            if (!empty($poll['slug'])) {
                $urls[] = [
                    'loc'      => $base . '/sondages/' . rawurlencode((string) $poll['slug']),
                    'priority' => '0.6',
                ];
            }
        }

        // Articles de blog publiés.
        try {
            foreach (Article::published() as $art) {
                if (!empty($art['slug'])) {
                    $urls[] = [
                        'loc'      => $base . '/blog/' . rawurlencode((string) $art['slug']),
                        'priority' => '0.7',
                        'lastmod'  => !empty($art['published_at'])
                            ? date('Y-m-d', strtotime((string) $art['published_at']))
                            : null,
                    ];
                }
            }
        } catch (\Throwable) {
            // Base indisponible : on ignore les articles.
        }

        // Pages CMS publiées.
        try {
            $pages = Page::all();
            foreach ($pages as $pg) {
                if (!empty($pg['is_published']) && !empty($pg['slug'])) {
                    $urls[] = ['loc' => $base . '/p/' . rawurlencode((string) $pg['slug']), 'priority' => '0.5'];
                }
            }
        } catch (\Throwable) {
            // Base indisponible : on ignore les pages CMS.
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $u) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . htmlspecialchars((string) $u['loc'], ENT_XML1) . "</loc>\n";
            if (!empty($u['lastmod'])) {
                $xml .= '    <lastmod>' . $u['lastmod'] . "</lastmod>\n";
            }
            if (!empty($u['priority'])) {
                $xml .= '    <priority>' . $u['priority'] . "</priority>\n";
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

            // Articles de blog (titre + extrait).
            foreach (Article::search($like, 4) as $row) {
                $results[] = [
                    'type'    => 'Article',
                    'title'   => (string) ($row['title'] ?? ''),
                    'url'     => url('/blog/' . rawurlencode((string) ($row['slug'] ?? ''))),
                    'excerpt' => trim(strip_tags((string) ($row['excerpt'] ?? ''))),
                ];
            }

            $results = array_slice($results, 0, 10);
        }

        $this->json($results);
    }
}
