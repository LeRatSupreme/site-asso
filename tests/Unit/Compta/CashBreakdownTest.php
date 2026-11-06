<?php

declare(strict_types=1);

namespace Tests\Unit\Compta;

use App\Core\Compta\CashBreakdown;
use PHPUnit\Framework\TestCase;

/**
 * Tests du service de coupures CashBreakdown (PRISE « chez soi » et
 * dépôts banque) — classe PURE, aucune base.
 *
 * Valide : catalogue verrouillé aux 12 valeurs légales (refus 10000+),
 * parsing POST qty_<cents>, totaux en centimes ENTIERS exacts,
 * quantités absentes/négatives/fractionnaires → 0, refus total vide,
 * formatage « 3×20€ · 5×2€ · 12×50c » et contrôle de cohérence
 * Σ(unit×qty) = |amount|×100.
 */
final class CashBreakdownTest extends TestCase
{
    public function test_le_catalogue_est_exactement_les_12_valeurs_legales(): void
    {
        self::assertSame(
            [1, 2, 5, 10, 20, 50, 100, 200, 500, 1000, 2000, 5000],
            CashBreakdown::UNITS,
            'Catalogue verrouillé : 1c 2c 5c 10c 20c 50c 1€ 2€ 5€ 10€ 20€ 50€ — PAS de 100/200/500 €.'
        );
    }

    public function test_from_array_refuse_une_coupure_hors_catalogue(): void
    {
        // 10000 centimes = 100 € : hors catalogue (choix produit assumé).
        $this->expectException(\InvalidArgumentException::class);
        CashBreakdown::fromArray([10000 => 1]);
    }

    public function test_from_post_ignore_un_champ_coupure_hors_catalogue(): void
    {
        // Champ forgé qty_10000 : simplement ignoré (jamais compté), le
        // POST ne doit jamais pouvoir créer une coupure inconnue.
        $b = CashBreakdown::fromPost(['qty_10000' => '3', 'qty_2000' => '1']);

        self::assertSame(2000, $b->totalCents());
        self::assertSame([2000 => 1], $b->quantities());
    }

    public function test_total_en_centimes_entiers_exact(): void
    {
        // 3×20€ + 5×2€ + 12×50c = 6000 + 1000 + 600 = 7600 c = 76,00 €.
        $b = CashBreakdown::fromPost(['qty_2000' => '3', 'qty_200' => '5', 'qty_50' => '12']);

        self::assertSame(7600, $b->totalCents());
        self::assertSame(76.0, $b->amount());
    }

    public function test_quantites_absentes_negatives_ou_fractionnaires_sont_ignorees(): void
    {
        $b = CashBreakdown::fromPost([
            'qty_100' => '2',
            'qty_500' => '0',       // saisie remise à zéro : non stockée
            'qty_1000' => '-4',     // négative → ignorée (= 0)
            'qty_2000' => '1.5',    // fractionnaire → ignorée (pièces entières)
            'qty_5000' => 'abc',    // non numérique → ignorée
        ]);

        self::assertSame([100 => 2], $b->quantities());
        self::assertSame(200, $b->totalCents());
    }

    public function test_post_vide_ou_nul_est_vide(): void
    {
        self::assertTrue(CashBreakdown::fromPost([])->isEmpty());
        self::assertTrue(CashBreakdown::fromPost(['qty_100' => '0', 'qty_2000' => '0'])->isEmpty());
        self::assertTrue(CashBreakdown::fromArray([])->isEmpty());
        self::assertSame(0, CashBreakdown::fromPost([])->totalCents());
    }

    public function test_cohérence_avec_un_montant(): void
    {
        $b = CashBreakdown::fromPost(['qty_2000' => '3', 'qty_200' => '5', 'qty_50' => '12']);

        self::assertTrue($b->matchesAmount(76.0));
        self::assertTrue($b->matchesAmount(-76.0), 'Le contrôle porte sur |amount| (montant signé).');
        self::assertFalse($b->matchesAmount(76.5));
        self::assertFalse($b->matchesAmount(0.01));
    }

    public function test_formatage_grosses_coupures_d_abord(): void
    {
        $b = CashBreakdown::fromPost(['qty_50' => '12', 'qty_2000' => '3', 'qty_200' => '5']);

        self::assertSame('3×20€ · 5×2€ · 12×50c', $b->format());
        self::assertSame('', CashBreakdown::fromPost([])->format());

        // Libellés unitaires : euros et centimes.
        self::assertSame('20€', $b->unitLabel(2000));
        self::assertSame('50c', $b->unitLabel(50));
        self::assertSame('1€', $b->unitLabel(100));
        self::assertSame('1c', $b->unitLabel(1));
    }
}
