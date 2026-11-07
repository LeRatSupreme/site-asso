<?php

declare(strict_types=1);

namespace Tests\Unit\Reco;

use App\Core\Compta\Reco\Hyper;
use PHPUnit\Framework\TestCase;

/**
 * Hyper::valeur : surcharge « reco_<nom> » numérique, retombée au
 * défaut, ignorances des valeurs non numériques.
 */
final class HyperTest extends TestCase
{
    public function test_lit_la_surcharge_numerique(): void
    {
        self::assertSame(0.20, Hyper::valeur(['reco_marge_min' => 0.20], 'marge_min', Hyper::MARGE_MIN));
        self::assertSame(5, Hyper::valeur(['reco_r11_max' => 5], 'r11_max', Hyper::R11_MAX));
        self::assertSame(0.2, Hyper::valeur(['reco_marge_min' => '0.2'], 'marge_min', 0.15));
    }

    public function test_retombe_au_defaut_si_absent(): void
    {
        self::assertSame(0.15, Hyper::valeur([], 'marge_min', Hyper::MARGE_MIN));
        self::assertSame(Hyper::MAX_ACTIVES, Hyper::valeur(['autre_cle' => 9], 'max_actives', Hyper::MAX_ACTIVES));
    }

    public function test_ignore_les_valeurs_non_numeriques(): void
    {
        self::assertSame(0.15, Hyper::valeur(['reco_marge_min' => 'abc'], 'marge_min', 0.15));
        self::assertSame(0.15, Hyper::valeur(['reco_marge_min' => null], 'marge_min', 0.15));
        self::assertSame(0.15, Hyper::valeur(['reco_marge_min' => ['x']], 'marge_min', 0.15));
        self::assertSame(0.15, Hyper::valeur(['reco_marge_min' => true], 'marge_min', 0.15));
    }
}
