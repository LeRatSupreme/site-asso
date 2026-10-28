<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Tests d'intégration du CAPTCHA auto-hébergé : image PNG (challenge en
 * session), page de démonstration (succès/échec/honeypot) et usage unique
 * du challenge.
 */
final class CaptchaDemoTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->reset(['captcha_settings']);
    }

    /**
     * Corps de réponse décodé (le runner transporte le binaire en base64).
     */
    private function bodyOf(array $response): string
    {
        $body = (string) ($response['body'] ?? '');

        return !empty($response['bodyB64']) ? (string) base64_decode($body, true) : $body;
    }

    public function test_image_rend_un_png_et_pose_le_challenge_en_session(): void
    {
        $response = $this->request('GET', '/captcha/image');

        self::assertSame(200, $response['code']);
        self::assertStringStartsWith("\x89PNG", $this->bodyOf($response));

        $captcha = $response['session']['captcha'] ?? null;
        self::assertIsArray($captcha, 'Le challenge doit être posé en session');
        self::assertArrayHasKey('hash', $captcha);
        self::assertStringStartsWith('$2y$', (string) $captcha['hash']);
        // APP_TESTING : le code de test est exposé pour la suite du test.
        self::assertArrayHasKey('test_code', $captcha);
        // Le hash n'est pas le code en clair (bcrypt unidirectionnel).
        self::assertNotSame($captcha['test_code'], $captcha['hash']);
    }

    public function test_bon_code_valide_le_formulaire(): void
    {
        $image = $this->request('GET', '/captcha/image');
        $code = (string) ($image['session']['captcha']['test_code'] ?? '');

        $response = $this->request('POST', '/captcha-demo', ['captcha' => $code]);

        self::assertSame(200, $response['code']);
        self::assertStringContainsString('data-marker="captcha-success"', $this->bodyOf($response));
        self::assertStringNotContainsString('data-marker="captcha-error"', $this->bodyOf($response));
        // Usage unique : le challenge est consommé.
        self::assertArrayNotHasKey('captcha', $response['session']);
    }

    public function test_mauvais_code_affiche_l_erreur(): void
    {
        $this->request('GET', '/captcha/image');

        $response = $this->request('POST', '/captcha-demo', ['captcha' => 'ZZZZZZ']);

        self::assertSame(200, $response['code']);
        self::assertStringContainsString('data-marker="captcha-error"', $this->bodyOf($response));
        self::assertStringNotContainsString('data-marker="captcha-success"', $this->bodyOf($response));
        // Usage unique : même en échec, le challenge est consommé.
        self::assertArrayNotHasKey('captcha', $response['session']);
    }

    public function test_honeypot_rempli_fait_echouer_meme_avec_le_bon_code(): void
    {
        $image = $this->request('GET', '/captcha/image');
        $code = (string) ($image['session']['captcha']['test_code'] ?? '');

        $response = $this->request('POST', '/captcha-demo', [
            'captcha'          => $code,
            'entreprise_site' => 'http://spam.example',
        ]);

        self::assertSame(200, $response['code']);
        self::assertStringContainsString('data-marker="captcha-error"', $this->bodyOf($response));
        self::assertStringNotContainsString('data-marker="captcha-success"', $this->bodyOf($response));
    }

    public function test_la_page_demo_affiche_le_formulaire(): void
    {
        $response = $this->request('GET', '/captcha-demo');

        self::assertSame(200, $response['code']);
        $body = $this->bodyOf($response);
        self::assertStringContainsString('id="captcha-img"', $body);
        self::assertStringContainsString('id="captcha-input"', $body);
        self::assertStringContainsString(url('/captcha/image'), $body);
        // Honeypot présent mais hors écran.
        self::assertStringContainsString('name="entreprise_site"', $body);
    }
}
