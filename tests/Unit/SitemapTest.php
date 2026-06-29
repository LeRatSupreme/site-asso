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
}
