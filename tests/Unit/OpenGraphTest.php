<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests du rendu des balises SEO / Open Graph dans le layout public.
 *
 * On rend directement le layout avec des données factices et on vérifie la
 * présence des méta attendues (OG, Twitter, canonical, JSON-LD).
 */
final class OpenGraphTest extends TestCase
{
    private string $html;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $content     = '<div id="contenu-test"></div>';
        $title       = 'Soirée d\'intégration — AEIC';
        $description = 'Le rendez-vous de rentrée de tous les étudiants en info.';
        $ogType      = 'article';
        $ogImage     = 'https://example.test/assets/img/soiree.jpg';
        $jsonLd      = '{"@context":"https://schema.org","@type":"Event","name":"Soirée"}';

        ob_start();
        require AEIC_VIEWS . '/layouts/public.php';
        $this->html = (string) ob_get_clean();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function test_balise_og_title_presente(): void
    {
        self::assertStringContainsString('property="og:title"', $this->html);
        self::assertStringContainsString('Soirée d', $this->html);
    }

    public function test_balise_og_type_article(): void
    {
        self::assertStringContainsString('property="og:type" content="article"', $this->html);
    }

    public function test_balise_og_image_presente(): void
    {
        self::assertStringContainsString('property="og:image"', $this->html);
        self::assertStringContainsString('https://example.test/assets/img/soiree.jpg', $this->html);
    }

    public function test_balises_twitter_card_presentes(): void
    {
        self::assertStringContainsString('name="twitter:card" content="summary_large_image"', $this->html);
        self::assertStringContainsString('name="twitter:title"', $this->html);
        self::assertStringContainsString('name="twitter:image"', $this->html);
    }

    public function test_canonical_et_description_presentes(): void
    {
        self::assertStringContainsString('<link rel="canonical"', $this->html);
        self::assertStringContainsString('name="description"', $this->html);
        self::assertStringContainsString('Le rendez-vous de rentrée', $this->html);
    }

    public function test_theme_color_et_locale_presentes(): void
    {
        self::assertStringContainsString('name="theme-color"', $this->html);
        self::assertStringContainsString('og:locale" content="fr_FR"', $this->html);
    }

    public function test_json_ld_present_quand_fourni(): void
    {
        self::assertStringContainsString('application/ld+json', $this->html);
        self::assertStringContainsString('"@type":"Event"', $this->html);
    }

    public function test_html_a_lang_fr_et_skip_link(): void
    {
        self::assertStringContainsString('<html lang="fr">', $this->html);
        self::assertStringContainsString('class="skip-link"', $this->html);
    }
}
