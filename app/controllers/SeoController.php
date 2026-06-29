<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Models\Event;
use App\Models\Page;
use App\Models\Setting;

/**
 * SEO : sitemap.xml dynamique.
 *
 * Liste les pages statiques, les événements publiés et les pages CMS publiées.
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

        // Pages statiques.
        foreach (['/', '/events', '/presentation', '/team'] as $p) {
            $urls[] = ['loc' => $base . $p, 'priority' => $p === '/' ? '1.0' : '0.8'];
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
}
