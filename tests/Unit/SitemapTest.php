<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\SeoController;
use PHPUnit\Framework\TestCase;

/**
 * Tests du sitemap.xml dynamique.
 *
 * Vérifie que le XML généré est valide et contient les pages statiques.
 * Fonctionne sans base de données (les méthodes DB échouent silencieusement).
 */
final class SitemapTest extends TestCase
{
    public function test_sitemap_est_du_xml_valide_avec_urlset(): void
    {
        $xml = SeoController::buildSitemap();

        self::assertStringStartsWith('<?xml', $xml);
        self::assertStringContainsString('<urlset', $xml);
        self::assertStringContainsString('</urlset>', $xml);
    }

    public function test_sitemap_contient_les_pages_statiques(): void
    {
        $xml = SeoController::buildSitemap();

        self::assertStringContainsString('<loc>https://example.test/</loc>', $xml);
        self::assertStringContainsString('<loc>https://example.test/events</loc>', $xml);
        self::assertStringContainsString('<loc>https://example.test/presentation</loc>', $xml);
        self::assertStringContainsString('<loc>https://example.test/team</loc>', $xml);
    }

    public function test_sitemap_definit_une_priorite_pour_l_accueil(): void
    {
        $xml = SeoController::buildSitemap();

        self::assertStringContainsString('<priority>1.0</priority>', $xml);
    }

    public function test_sitemap_est_parseable_par_simplexml(): void
    {
        $xml = SeoController::buildSitemap();

        $doc = @simplexml_load_string($xml);

        self::assertNotFalse($doc, 'Le sitemap doit être du XML bien formé.');
        self::assertNotEmpty($doc->url);
        self::assertSame('https://example.test/', (string) $doc->url[0]->loc);
    }

    public function test_sitemap_contient_la_zone_jeux(): void
    {
        $xml = SeoController::buildSitemap();

        self::assertStringContainsString('<loc>https://example.test/jeux</loc>', $xml);
        self::assertStringContainsString('<loc>https://example.test/jeux/wordle</loc>', $xml);
        self::assertStringContainsString('<loc>https://example.test/jeux/enigme</loc>', $xml);
        self::assertStringContainsString('<loc>https://example.test/jeux/memory</loc>', $xml);
        self::assertStringContainsString('<loc>https://example.test/jeux/snake</loc>', $xml);
        self::assertStringContainsString('<loc>https://example.test/jeux/tetris</loc>', $xml);
        self::assertStringContainsString('<loc>https://example.test/jeux/leaderboard</loc>', $xml);
    }

    public function test_sitemap_renseigne_changefreq_et_priority(): void
    {
        $xml = SeoController::buildSitemap();
        $doc = @simplexml_load_string($xml);

        self::assertNotFalse($doc);

        $found = ['daily' => false, 'weekly' => false, 'monthly' => false, 'yearly' => false];
        foreach ($doc->url as $url) {
            $cf = (string) $url->changefreq;
            if (isset($found[$cf])) { $found[$cf] = true; }
            // Chaque URL a une priorité et un loc non vides.
            self::assertNotSame('', (string) $url->loc);
            self::assertNotSame('', (string) $url->priority);
        }
        foreach ($found as $cf => $seen) {
            self::assertTrue($seen, "changefreq '$cf' attendu dans le sitemap.");
        }
    }

    public function test_sitemap_lastmod_a_le_format_date(): void
    {
        $xml = SeoController::buildSitemap();

        // lastmod optionnel mais toujours au format YYYY-MM-DD quand présent.
        if (preg_match_all('/<lastmod>([^<]+)<\/lastmod>/', $xml, $m)) {
            foreach ($m[1] as $date) {
                self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $date);
            }
        }
    }

    public function test_sitemap_declare_le_namespace_image(): void
    {
        $xml = SeoController::buildSitemap();

        self::assertStringContainsString(
            'xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"',
            $xml
        );
    }

    public function test_sitemap_reference_la_feuille_xsl(): void
    {
        $xml = SeoController::buildSitemap();

        self::assertStringContainsString('<?xml-stylesheet type="text/xsl"', $xml);
        self::assertStringContainsString('/sitemap.xsl', $xml);
    }

    public function test_sitemap_avec_images_reste_parseable_et_valide(): void
    {
        $xml = SeoController::buildSitemap();
        $doc = @simplexml_load_string($xml);

        self::assertNotFalse($doc, 'Le sitemap avec extension image doit rester du XML bien formé.');

        // Toute balise image:loc porte une URL non vide, dans un bloc image:image.
        if (preg_match_all('/<image:loc>([^<]+)<\/image:loc>/', $xml, $m)) {
            foreach ($m[1] as $u) {
                self::assertNotSame('', $u);
                self::assertStringStartsWith('http', $u);
            }
            self::assertStringContainsString('<image:image>', $xml);
        }
    }
}
