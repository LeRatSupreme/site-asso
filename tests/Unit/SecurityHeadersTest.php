<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Security\SecurityHeaders;
use PHPUnit\Framework\TestCase;

/**
 * Tests des en-têtes de sécurité HTTP.
 */
final class SecurityHeadersTest extends TestCase
{
    public function test_build_inclut_les_en_tetes_obligatoires(): void
    {
        $headers = SecurityHeaders::build(false);

        self::assertSame('nosniff', $headers['X-Content-Type-Options']);
        self::assertSame('SAMEORIGIN', $headers['X-Frame-Options']);
        self::assertSame('strict-origin-when-cross-origin', $headers['Referrer-Policy']);
        self::assertArrayHasKey('Content-Security-Policy', $headers);
    }

    public function test_csp_par_defaut_est_restrictive(): void
    {
        $csp = SecurityHeaders::build(false)['Content-Security-Policy'];

        self::assertStringContainsString("default-src 'self'", $csp);
        self::assertStringContainsString("object-src 'none'", $csp);
        self::assertStringContainsString("frame-ancestors 'self'", $csp);
    }

    public function test_csp_personnalisee_est_utilisee(): void
    {
        $custom = "default-src 'none'";
        $headers = SecurityHeaders::build(false, $custom);

        self::assertSame($custom, $headers['Content-Security-Policy']);
    }

    public function test_hsts_uniquement_en_https(): void
    {
        $http = SecurityHeaders::build(false);
        $https = SecurityHeaders::build(true);

        self::assertArrayNotHasKey('Strict-Transport-Security', $http);
        self::assertArrayHasKey('Strict-Transport-Security', $https);
        self::assertStringContainsString('max-age=31536000', $https['Strict-Transport-Security']);
    }

    public function test_permissions_policy_presente(): void
    {
        $headers = SecurityHeaders::build(false);

        self::assertStringContainsString('geolocation=()', $headers['Permissions-Policy']);
    }

    public function test_send_stocke_les_en_tetes_pour_inspection(): void
    {
        SecurityHeaders::send(true);
        $last = SecurityHeaders::lastSent();

        self::assertArrayHasKey('Content-Security-Policy', $last);
        self::assertArrayHasKey('Strict-Transport-Security', $last);
    }
}
