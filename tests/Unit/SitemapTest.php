<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\SeoController;
use PHPUnit\Framework\TestCase;

/**
 * Tests du sitemap.xml dynamique.
 *
 * Vérifie que le XML généré est valide et contient les pages statiques.
 * Fonctionne sans base de données : la sonde de base est branchée sur un
 * stub (setDbProbeForTests) — aucune requête réelle n'est émise, le XML se
 * réduit alors aux pages statiques (comportement des anciens tests).
 *
 * Résilience (S) : le cache « dernier bon » (cache/seo/sitemap.xml) doit
 * être écrit sur génération complète et SERVI à la place d'une génération
 * dégradée (base en panne / modèles silencieux vides), sans jamais de 500.
 */
final class SitemapTest extends TestCase
{
    private string $dir;
    private string $cacheFile;

    protected function setUp(): void
    {
        // Cache redirigé vers un répertoire temporaire : les tests n'écrivent
        // jamais dans le vrai cache/seo du serveur.
        $this->dir = sys_get_temp_dir() . '/aeic_sitemap_test_' . uniqid('', true);
        mkdir($this->dir, 0o775, true);
        $this->cacheFile = $this->dir . '/sitemap.xml';
        SeoController::setCachePathForTests($this->cacheFile);
    }

    protected function tearDown(): void
    {
        SeoController::resetTestHooks();
        @unlink($this->cacheFile);
        @rmdir($this->dir);
    }

    // ------------------------------------------------------------------
    //  Tests historiques (contenu du XML), sonde en échec => dégradé sans
    //  cache : les pages statiques restent servies.
    // ------------------------------------------------------------------

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

        // Assertion inconditionnelle : sans base (aucun lastmod produit),
        // le test resterait sinon « risky » et échouerait en CI (failOnRisky).
        self::assertIsString($xml);

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

    // ------------------------------------------------------------------
    //  Résilience (S) : cache dernier-bon, dégradation, garde-fou.
    // ------------------------------------------------------------------

    public function test_generation_reussie_ecrit_le_cache_et_le_sert(): void
    {
        // Sonde saine : génération complète (pages statiques seules, le
        // contenu dynamique est simulé vide en mode test).
        SeoController::setDbProbeForTests(static fn (): bool => true);
        self::assertFileDoesNotExist($this->cacheFile);

        $servi = self::servirSitemap();

        self::assertFileExists($this->cacheFile, 'Une génération complète doit écrire le cache.');
        self::assertSame($servi, (string) file_get_contents($this->cacheFile));
        self::assertStringContainsString('<loc>https://example.test/</loc>', $servi);
    }

    public function test_generation_degradee_sert_le_dernier_bon_cache(): void
    {
        $dernierBon = self::dernierBonXml();
        file_put_contents($this->cacheFile, $dernierBon);
        // Base en panne : la sonde lève, comme un vrai PDOException.
        SeoController::setDbProbeForTests(static function (): bool {
            throw new \RuntimeException('SQLSTATE[HY000] [2002] simulation panne');
        });

        $octets = self::offsetLog();

        $servi = self::servirSitemap();

        // Le cache (et non la génération amputée) est servi, sans 500.
        self::assertSame($dernierBon, $servi);
        // Le cache n'a pas été écrasé par le XML dégradé.
        self::assertSame($dernierBon, (string) file_get_contents($this->cacheFile));
        // L'échec est journalisé, plus silencieux.
        self::assertStringContainsString(
            'sitemap: génération dégradée, cache servi',
            self::logDepuis($octets)
        );
    }

    public function test_generation_degradee_sans_cache_repond_quand_meme(): void
    {
        // Premier déploiement : base en panne, aucun cache de secours.
        SeoController::setDbProbeForTests(static function (): bool {
            throw new \RuntimeException('SQLSTATE[HY000] [2002] simulation panne');
        });

        $octets = self::offsetLog();

        $servi = self::servirSitemap();

        // Jamais de 500 : le XML générable (pages statiques) est servi.
        self::assertStringStartsWith('<?xml', $servi);
        self::assertStringContainsString('<loc>https://example.test/</loc>', $servi);
        self::assertStringEndsWith('</urlset>' . "\n", $servi);
        // Une génération dégradée ne pollue pas le cache.
        self::assertFileDoesNotExist($this->cacheFile);
        self::assertStringContainsString(
            'sitemap: génération dégradée, aucun cache de secours',
            self::logDepuis($octets)
        );
    }

    public function test_sections_dynamiques_vides_serve_le_cache(): void
    {
        // Reproduit la cause racine du 29/10 : la sonde passe (connexion OK)
        // mais les modèles « silencieux » renvoient [] (base saturée).
        $dernierBon = self::dernierBonXml();
        file_put_contents($this->cacheFile, $dernierBon);
        SeoController::setDbProbeForTests(static fn (): bool => true);

        $octets = self::offsetLog();

        $servi = self::servirSitemap();

        self::assertSame($dernierBon, $servi, 'Sections dynamiques évacuées => cache servi.');
        self::assertStringContainsString(
            'sections dynamiques vides',
            self::logDepuis($octets)
        );
    }

    public function test_cache_perime_n_est_pas_servi(): void
    {
        // Au-delà de 7 jours, le cache dernier-bon est ignoré : mieux vaut
        // un XML générable exact qu'un cache périmé listant des URLs mortes.
        file_put_contents($this->cacheFile, self::dernierBonXml());
        touch($this->cacheFile, time() - 8 * 86400);
        SeoController::setDbProbeForTests(static fn (): bool => true);

        $servi = self::servirSitemap();

        self::assertStringContainsString('<loc>https://example.test/</loc>', $servi);
        self::assertStringNotContainsString('/events/soiree-integrale', $servi);
    }

    public function test_garde_fou_rejette_xml_trop_court_ou_tronque(): void
    {
        $racine = 'https://example.test/';
        $seuil = 16; // = nombre de pages statiques du site

        // XML complet (généré en mode test : pages statiques seules).
        SeoController::setDbProbeForTests(static fn (): bool => true);
        $complet = SeoController::buildSitemap();
        self::assertTrue(SeoController::looksComplete($complet, $racine, $seuil));

        // Amputé : racine seule, loin du seuil de taille et d'URLs
        // (465 o observés le 29/10).
        $ampute = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
            . "  <url>\n    <loc>{$racine}</loc>\n  </url>\n"
            . "</urlset>\n";
        self::assertFalse(SeoController::looksComplete($ampute, $racine, $seuil));

        // Tronqué : pas de fermeture </urlset>.
        self::assertFalse(SeoController::looksComplete(substr($complet, 0, 500), $racine, $seuil));

        // Racine absente : génération incomplète.
        self::assertFalse(SeoController::looksComplete(str_replace($racine, 'https://autre.test/', $complet), $racine, $seuil));
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    /**
     * Invoque SeoController::sitemap() en capturant le corps de la réponse
     * (les header() sont sans effet en CLI SAPI).
     */
    private static function servirSitemap(): string
    {
        ob_start();
        try {
            (new SeoController())->sitemap();
        } finally {
            $sortie = (string) ob_get_clean();
        }

        return $sortie;
    }

    /**
     * Un « dernier bon » sitemap plausible : racine + URLs dynamiques.
     */
    private static function dernierBonXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
            . "  <url>\n    <loc>https://example.test/</loc>\n  </url>\n"
            . "  <url>\n    <loc>https://example.test/events/soiree-integrale</loc>\n  </url>\n"
            . "  <url>\n    <loc>https://example.test/sondages/bureau-2026</loc>\n  </url>\n"
            . "</urlset>\n";
    }

    /**
     * Position courante du journal applicatif (pour ne lire ensuite que les
     * lignes ajoutées par le test).
     */
    private static function offsetLog(): int
    {
        $log = AEIC_ROOT . '/logs/app.log';

        return (int) (@filesize($log) ?: 0);
    }

    private static function logDepuis(int $offset): string
    {
        $log = AEIC_ROOT . '/logs/app.log';
        if (!is_file($log)) {
            return '';
        }
        $fp = @fopen($log, 'rb');
        if ($fp === false) {
            return '';
        }
        fseek($fp, $offset);
        $contenu = (string) stream_get_contents($fp);
        fclose($fp);

        return $contenu;
    }
}
