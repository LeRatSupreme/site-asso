<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Security\Totp;
use PHPUnit\Framework\TestCase;

/**
 * Tests de l'implémentation TOTP (RFC 6238 / HOTP RFC 4226).
 */
final class TotpTest extends TestCase
{
    /**
     * Vecteur de test RFC 6238 : secret "12345678901234567890" (Base32),
     * au pas 30 s, à T=59 (compteur 1) → code 6 chiffres « 287082 ».
     */
    public function test_code_conforme_au_vecteur_rfc6238(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');

        self::assertSame('287082', Totp::code($secret, 59));
    }

    public function test_second_vecteur_rfc6238(): void
    {
        // T=1111111109 → 8 chiffres 07081804 → 6 chiffres 081804.
        $secret = Totp::base32Encode('12345678901234567890');

        self::assertSame('081804', Totp::code($secret, 1111111109));
    }

    public function test_code_est_toujours_6_chiffres(): void
    {
        $secret = Totp::generateSecret();

        self::assertSame(6, strlen(Totp::code($secret)));
        self::assertTrue(ctype_digit(Totp::code($secret)));
    }

    public function test_verify_accepte_le_code_courant(): void
    {
        $secret = Totp::generateSecret();
        $code = Totp::code($secret);

        self::assertTrue(Totp::verify($secret, $code));
    }

    public function test_verify_rejette_un_mauvais_code(): void
    {
        $secret = Totp::generateSecret();

        self::assertFalse(Totp::verify($secret, '000000'));
    }

    public function test_fenetre_permet_une_tolerance(): void
    {
        $secret = Totp::generateSecret();
        // Code du pas précédent (T - 30 s).
        $prev = Totp::code($secret, time() - 30);

        self::assertTrue(Totp::verify($secret, $prev, 1));
        self::assertFalse(Totp::verify($secret, $prev, 0));
    }

    public function test_fenetre_rejette_un_code_trop_ancien(): void
    {
        $secret = Totp::generateSecret();
        $old = Totp::code($secret, time() - 600); // 20 pas en arrière.

        self::assertFalse(Totp::verify($secret, $old, 1));
    }

    public function test_base32_round_trip(): void
    {
        $data = random_bytes(20);
        $encoded = Totp::base32Encode($data);

        self::assertSame($data, Totp::base32Decode($encoded));
    }

    public function test_secret_genere_est_valide(): void
    {
        $secret = Totp::generateSecret();

        self::assertNotEmpty($secret);
        // Un secret décodé puis réutilisé doit produire un code cohérent.
        self::assertSame(Totp::code($secret), Totp::code($secret));
    }

    public function test_uri_otpauth_contient_secret_et_issuer(): void
    {
        $uri = Totp::uri('JBSWY3DPEHPK3PXP', 'alice@example.com', 'AEIC');

        self::assertStringStartsWith('otpauth://totp/', $uri);
        self::assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        self::assertStringContainsString('issuer=AEIC', $uri);
    }
}
