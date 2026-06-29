<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Security\RecoveryCodes;
use App\Core\Security\TwoFactorPolicy;
use App\Core\Auth;
use PHPUnit\Framework\TestCase;

/**
 * Tests des codes de récupération 2FA et de la politique d'obligation.
 */
final class TwoFactorTest extends TestCase
{
    public function test_generate_rend_le_nombre_attendu_de_codes(): void
    {
        $codes = RecoveryCodes::generate(8);

        self::assertCount(8, $codes);
        foreach ($codes as $c) {
            self::assertMatchesRegularExpression('/^[0-9A-F]{4}-[0-9A-F]{4}$/', $c);
        }
    }

    public function test_codes_sont_uniques(): void
    {
        $codes = RecoveryCodes::generate(20);

        self::assertSame(count($codes), count(array_unique($codes)));
    }

    public function test_verify_and_consume_reconnait_un_code_valide_et_le_retire(): void
    {
        $codes = RecoveryCodes::generate(4);
        $hashed = RecoveryCodes::hash($codes);

        [$ok, $remaining] = RecoveryCodes::verifyAndConsume($codes[0], $hashed);

        self::assertTrue($ok);
        self::assertCount(3, $remaining);
        // Le code consommé ne marche plus.
        [$ok2, $remaining2] = RecoveryCodes::verifyAndConsume($codes[0], $remaining);
        self::assertFalse($ok2);
    }

    public function test_verify_and_consume_est_insensible_a_la_casse(): void
    {
        $codes = RecoveryCodes::generate(1);
        $hashed = RecoveryCodes::hash($codes);

        [$ok] = RecoveryCodes::verifyAndConsume(strtolower($codes[0]), $hashed);

        self::assertTrue($ok);
    }

    public function test_verify_and_consume_rejette_un_code_inconnu(): void
    {
        $hashed = RecoveryCodes::hash(RecoveryCodes::generate(2));

        [$ok] = RecoveryCodes::verifyAndConsume('AAAA-AAAA', $hashed);

        self::assertFalse($ok);
    }

    public function test_hash_ne_stocke_jamais_le_clair(): void
    {
        $codes = RecoveryCodes::generate(1);
        $hashed = RecoveryCodes::hash($codes);

        self::assertNotContains($codes[0], $hashed);
    }

    // ---- Politique d'obligation ---------------------------------------

    public function test_admin_et_tresorerie_exigent_le_2fa(): void
    {
        self::assertTrue(TwoFactorPolicy::requires(Auth::ROLE_ADMIN));
        self::assertTrue(TwoFactorPolicy::requires(Auth::ROLE_TRESORERIE));
    }

    public function test_eleve_n_exige_pas_le_2fa(): void
    {
        self::assertFalse(TwoFactorPolicy::requires(Auth::ROLE_ELEVE));
    }

    public function test_satisfied_pour_admin_exige_l_activation(): void
    {
        self::assertFalse(TwoFactorPolicy::satisfied(Auth::ROLE_ADMIN, false));
        self::assertTrue(TwoFactorPolicy::satisfied(Auth::ROLE_ADMIN, true));
    }

    public function test_satisfied_pour_eleve_toujours_vrai(): void
    {
        self::assertTrue(TwoFactorPolicy::satisfied(Auth::ROLE_ELEVE, false));
        self::assertTrue(TwoFactorPolicy::satisfied(Auth::ROLE_ELEVE, true));
    }
}
