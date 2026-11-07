<?php

declare(strict_types=1);

namespace Tests\Unit\Reco;

use App\Core\Compta\Reco\GuardRails;
use App\Core\Compta\Reco\Hyper;
use App\Core\Compta\Reco\R9Tendance;
use App\Core\Compta\Reco\RecoContext;
use App\Core\Compta\Reco\RecoProduct;
use PHPUnit\Framework\TestCase;

/**
 * R9 — Tendance : garde-fou période creuse (bloque tout), seuil de
 * volume (15), seuil de variation (±50 % exactement → rien, ±51 % →
 * candidat), gains croissance/déclin, coût inconnu → gain nul.
 */
final class R9TendanceTest extends TestCase
{
    private const NOW = '2026-10-21 10:00:00';

    private function ctx(array $produits, array $qtyPrec, bool $creuse = false, float $facteur = 1.0): RecoContext
    {
        return new RecoContext(
            RecoScenario::dataset($produits, [], $qtyPrec, $facteur, $creuse),
            new \DateTimeImmutable(self::NOW)
        );
    }

    /** Produit de référence : Limonade, marge 1 € (prix 2, coût 1). */
    private function produit(float $qty30 = 40.0): RecoProduct
    {
        return RecoScenario::produit('P1', 'Limonade', qty30: $qty30, ca30: $qty30 * 2.0, prix: 2.0, cout: 1.0, stock: 50);
    }

    /** @return array<string,RecoProduct> */
    private function parCle(RecoContext $ctx): array
    {
        $map = [];
        foreach ($ctx->dataset()->produits as $p) {
            $map[$p->key] = $p;
        }

        return $map;
    }

    public function test_garde_fou_periode_creuse_bloque_tout(): void
    {
        // qty30 = 0,4 × qtyPrec (déclin −60 %) : déclencherait sans la période creuse.
        $ctx = $this->ctx([$this->produit(40.0)], ['P1' => 100.0], creuse: true, facteur: 1.0);
        self::assertSame([], R9Tendance::evaluer($ctx));
    }

    public function test_meme_donnees_sans_creuse_declenche_le_declin(): void
    {
        $ctx = $this->ctx([$this->produit(40.0)], ['P1' => 100.0]);
        $c = R9Tendance::evaluer($ctx);

        self::assertCount(1, $c);
        self::assertSame('R9', $c[0]['regle']);
        self::assertSame('Investiguer la baisse de Limonade', $c[0]['titre']);
        self::assertSame(['bas' => 0.0, 'median' => 0.0, 'haut' => 0.0], $c[0]['gain']);
        self::assertStringContainsString('-60,0', $c[0]['pourquoi']);
        self::assertStringContainsString('100,0', $c[0]['pourquoi']);
        self::assertStringContainsString('40,0', $c[0]['pourquoi']);
        $hyp = implode(' | ', $c[0]['hypotheses']);
        self::assertStringContainsString('investigation', $hyp);
        self::assertSame(Hyper::EFFORTS['R9'], $c[0]['effort']);
        self::assertTrue(GuardRails::candidatValide($c[0], $this->parCle($ctx), Hyper::MARGE_MIN));
    }

    public function test_volumes_15_exactement_ne_declenchent_pas(): void
    {
        $ctx = $this->ctx([$this->produit(15.0)], ['P1' => 15.0]);
        self::assertSame([], R9Tendance::evaluer($ctx));
    }

    public function test_volume_sous_le_min_ne_declenche_pas_meme_en_forte_hausse(): void
    {
        $ctx = $this->ctx([$this->produit(100.0)], ['P1' => 14.0]);
        self::assertSame([], R9Tendance::evaluer($ctx));
    }

    public function test_ratio_plus_50_pour_cent_exactement_ne_declenche_pas(): void
    {
        $ctx = $this->ctx([$this->produit(30.0)], ['P1' => 20.0]);
        self::assertSame([], R9Tendance::evaluer($ctx));
    }

    public function test_ratio_moins_50_pour_cent_exactement_ne_declenche_pas(): void
    {
        $ctx = $this->ctx([$this->produit(10.0)], ['P1' => 20.0]);
        self::assertSame([], R9Tendance::evaluer($ctx));
    }

    public function test_croissance_51_pour_cent_est_signalee_avec_gain(): void
    {
        $ctx = $this->ctx([$this->produit(30.4)], ['P1' => 20.0]);
        $c = R9Tendance::evaluer($ctx);

        self::assertCount(1, $c);
        self::assertSame('Suivre le stock de Limonade (demande en forte hausse)', $c[0]['titre']);
        self::assertStringContainsString('+52,0', $c[0]['pourquoi']);
        $median = (30.4 - 20.0) / R9Tendance::SEMAINES_PAR_MOIS * 1.0;
        self::assertEqualsWithDelta(0.0, $c[0]['gain']['bas'], 1.0e-12);
        self::assertEqualsWithDelta($median, $c[0]['gain']['median'], 1.0e-9);
        self::assertEqualsWithDelta($median * R9Tendance::FACTEUR_HAUT, $c[0]['gain']['haut'], 1.0e-9);
        self::assertTrue(GuardRails::candidatValide($c[0], $this->parCle($ctx), Hyper::MARGE_MIN));
    }

    public function test_declin_51_pour_cent_est_signale_sans_gain(): void
    {
        $ctx = $this->ctx([$this->produit(19.6)], ['P1' => 40.0]);
        $c = R9Tendance::evaluer($ctx);

        self::assertCount(1, $c);
        self::assertSame('Investiguer la baisse de Limonade', $c[0]['titre']);
        self::assertStringContainsString('-51,0', $c[0]['pourquoi']);
        self::assertSame(['bas' => 0.0, 'median' => 0.0, 'haut' => 0.0], $c[0]['gain']);
        self::assertTrue(GuardRails::candidatValide($c[0], $this->parCle($ctx), Hyper::MARGE_MIN));
    }

    public function test_cout_inconnu_gain_nul_mais_candidat_emis(): void
    {
        $p = RecoScenario::produit('P2', 'Mystère', qty30: 30.4, ca30: 60.0, prix: null, cout: null, coutRaison: 'cout_inconnu');
        $ctx = $this->ctx([$p], ['P2' => 20.0]);
        $c = R9Tendance::evaluer($ctx);

        self::assertCount(1, $c);
        self::assertSame(['bas' => 0.0, 'median' => 0.0, 'haut' => 0.0], $c[0]['gain']);
        $hyp = implode(' | ', $c[0]['hypotheses']);
        self::assertStringContainsString('Coût inconnu', $hyp);
        self::assertTrue(GuardRails::candidatValide($c[0], $this->parCle($ctx), Hyper::MARGE_MIN));
    }

    public function test_sans_historique_aucun_candidat(): void
    {
        $ctx = $this->ctx([$this->produit(100.0)], []);
        self::assertSame([], R9Tendance::evaluer($ctx));
    }
}
