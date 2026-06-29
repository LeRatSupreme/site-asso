<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Csrf;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de la vérification CSRF (temps constant, présence/absence).
 *
 * PHPUnit CLI gère les sessions via un stockage temporaire : on force un
 * démarrage explicite afin de pouvoir manipuler $_SESSION.
 */
final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function test_token_valide_est_accepte(): void
    {
        $_SESSION['_csrf_token'] = 'le-bon-token';

        self::assertTrue(Csrf::verify('le-bon-token'));
    }

    public function test_token_faux_est_rejete(): void
    {
        $_SESSION['_csrf_token'] = 'le-bon-token';

        self::assertFalse(Csrf::verify('un-mauvais-token'));
    }

    public function test_token_absant_est_rejete(): void
    {
        $_SESSION['_csrf_token'] = 'le-bon-token';

        self::assertFalse(Csrf::verify(null));
        self::assertFalse(Csrf::verify(''));
    }

    public function test_aucun_token_en_session_rejete_tout(): void
    {
        self::assertFalse(Csrf::verify('nimportequoi'));
    }

    public function test_csrf_field_contient_le_token(): void
    {
        $html = csrf_field();

        self::assertStringContainsString('type="hidden"', $html);
        self::assertStringContainsString('name="_csrf"', $html);
        self::assertStringContainsString('value="', $html);
    }
}
