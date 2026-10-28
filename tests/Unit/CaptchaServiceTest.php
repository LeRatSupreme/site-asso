<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Captcha\CaptchaService;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires du service CAPTCHA : génération (alphabet sans
 * ambiguïtés, hashage en session, jamais de code en clair), vérification
 * (usage unique, casse, expiration) et rendu PNG.
 *
 * PHPUnit CLI gère les sessions via un stockage temporaire : on force un
 * démarrage explicite afin de pouvoir manipuler $_SESSION (cf. CsrfTest).
 */
final class CaptchaServiceTest extends TestCase
{
    private CaptchaService $service;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];

        $this->service = new CaptchaService();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function test_alphabet_sans_ambiguites(): void
    {
        self::assertSame(32, strlen(CaptchaService::ALPHABET));

        // Aucun caractère ambigu (0/O/1/l/I).
        foreach (str_split('0O1lI') as $ambiguous) {
            self::assertStringNotContainsString($ambiguous, CaptchaService::ALPHABET);
        }
    }

    public function test_generate_produit_200_codes_valides(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $code = $this->service->generate();

            self::assertSame(6, strlen($code), "Longueur inattendue pour « {$code} »");
            foreach (str_split($code) as $char) {
                self::assertStringContainsString(
                    $char,
                    CaptchaService::ALPHABET,
                    "Caractère hors alphabet dans « {$code} »"
                );
            }
        }
    }

    public function test_generate_ne_stocke_jamais_le_code_en_clair(): void
    {
        $code = $this->service->generate();

        $challenge = $_SESSION[CaptchaService::SESSION_KEY];
        self::assertIsArray($challenge);
        self::assertArrayHasKey('hash', $challenge);
        self::assertIsString($challenge['hash']);
        self::assertStringStartsWith('$2y$', $challenge['hash']);

        // Le code en clair ne doit JAMAIS être stocké comme valeur — hormis
        // la clé test_code, qui n'existe qu'en APP_TESTING (jamais en prod).
        $horsTestCode = $challenge;
        unset($horsTestCode['test_code']);
        self::assertNotContains($code, $horsTestCode, 'Le code en clair ne doit pas être stocké');
        self::assertNotSame($code, $challenge['hash']);

        // En APP_TESTING, le code de test est exposé (promesse double branche).
        if (defined('APP_TESTING') && APP_TESTING) {
            self::assertSame($code, $challenge['test_code'] ?? null);
        } else {
            self::assertArrayNotHasKey('test_code', $challenge);
        }
    }

    public function test_generate_ecrase_le_challenge_precedent(): void
    {
        $this->service->generate();
        $hash1 = $_SESSION[CaptchaService::SESSION_KEY]['hash'];

        $this->service->generate();
        $hash2 = $_SESSION[CaptchaService::SESSION_KEY]['hash'];

        self::assertIsString($hash1);
        self::assertIsString($hash2);
        self::assertNotSame($hash1, $hash2, 'Chaque generate() doit écraser le hash précédent');
    }

    public function test_verify_accepte_le_bon_code(): void
    {
        $code = $this->service->generate();

        self::assertTrue($this->service->verify($code));
        self::assertArrayNotHasKey(CaptchaService::SESSION_KEY, $_SESSION);
    }

    public function test_verify_est_insensible_a_la_casse_et_aux_espaces(): void
    {
        $code = $this->service->generate();

        $saisie = ' ' . strtolower($code[0]) . ' ' . substr(strtolower($code), 1) . ' ';
        self::assertTrue($this->service->verify($saisie));
    }

    public function test_verify_rejette_le_mauvais_code(): void
    {
        $this->service->generate();

        self::assertFalse($this->service->verify('ZZZZZZ'));
        self::assertArrayNotHasKey(CaptchaService::SESSION_KEY, $_SESSION);
    }

    public function test_verify_est_a_usage_unique_meme_en_cas_de_succes(): void
    {
        $code = $this->service->generate();

        self::assertTrue($this->service->verify($code));
        self::assertFalse($this->service->verify($code), 'Le challenge consommé ne doit pas être rejouable');
    }

    public function test_verify_rejette_l_absence_de_challenge(): void
    {
        self::assertFalse($this->service->verify('ABCDEF'));
    }

    public function test_verify_rejette_un_challenge_expire(): void
    {
        $code = $this->service->generate();
        $_SESSION[CaptchaService::SESSION_KEY]['expires'] = time() - 1;

        self::assertFalse($this->service->verify($code));
        self::assertArrayNotHasKey(CaptchaService::SESSION_KEY, $_SESSION);
    }

    public function test_honeypot(): void
    {
        self::assertFalse(CaptchaService::honeypotFilled([]));
        self::assertTrue(CaptchaService::honeypotFilled(['entreprise_site' => 'x']));
        self::assertFalse(CaptchaService::honeypotFilled(['entreprise_site' => '']));
        self::assertFalse(CaptchaService::honeypotFilled(['entreprise_site' => '   ']));
    }

    public function test_render_produit_un_png(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('Extension GD non disponible.');
        }

        $png = $this->service->render('ABC123');

        self::assertStringStartsWith("\x89PNG", $png);
        self::assertGreaterThan(1024, strlen($png));
    }

    public function test_render_est_aleatoire_entre_deux_rendus(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('Extension GD non disponible.');
        }

        $png1 = $this->service->render('ABC123');
        $png2 = $this->service->render('ABC123');

        self::assertNotSame($png1, $png2, 'Le bruit doit rendre deux rendus différents');
    }
}
