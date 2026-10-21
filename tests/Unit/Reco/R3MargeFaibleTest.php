<?php

declare(strict_types=1);

namespace Tests\Unit\Reco;

use App\Core\Compta\Reco\GuardRails;
use App\Core\Compta\Reco\Hyper;
use App\Core\Compta\Reco\R3MargeFaible;
use App\Core\Compta\Reco\RecoContext;
use App\Core\Compta\Reco\RecoProduct;
use PHPUnit\Framework\TestCase;

/**
 * R3 — Marge faible sur fort volume : limites de seuils (taux de marge
 * exactement 0,25 ; volume exactement 20), top CA, paliers de hausse
 * (Δ = 0,20 / 0,10), perteVolumeMax exacte à 1e-9, seuil présent dans
 * l'action, garde-fous.
 */
final class R3MargeFaibleTest extends TestCase
{
    private const NOW = '2026-10-21 10:00:00';

    private function ctx(array $produits): RecoContext
    {
        return new RecoContext(RecoScenario::dataset($produits), new \DateTimeImmutable(self::NOW));
    }

    /** Produit de référence du top CA : prix 2 €, coût 1,51 € (marge 24,5 %), 60 u./30 j. */
    private function produit(float $qty30 = 60.0): RecoProduct
    {
        return RecoScenario::produit('P1', 'Baguette', qty30: $qty30, ca30: $qty30 * 2.0, prix: 2.0, cout: 1.51, stock: 50, unitesPrevues7j: 10.0, prevLo: 8.0, prevHi: 12.0);
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

    public function test_marge_faible_fort_volume_declenche_test_prix(): void
    {
        $ctx = $this->ctx([$this->produit()]);
        $c = R3MargeFaible::evaluer($ctx);

        self::assertCount(1, $c);
        self::assertSame('R3', $c[0]['regle']);
        self::assertSame(['P1'], $c[0]['keys']);
        // Δ = 0,20 (60 ≥ 40) ; médian = 0,20×10×0,75 = 1,5 ; haut = 2 + 10×1,51×0,075 = 3,1325.
        $g = $c[0]['gain'];
        self::assertEqualsWithDelta(0.0, $g['bas'], 1.0e-9);
        self::assertEqualsWithDelta(1.5, $g['median'], 1.0e-9);
        self::assertEqualsWithDelta(3.1325, $g['haut'], 1.0e-9);
        self::assertEqualsWithDelta(2.2, $c[0]['prix_effectifs'][0]['prix'], 1.0e-9);
        self::assertStringContainsString('+0,20 €', $c[0]['action']);
        self::assertStringContainsString('21,9 %', $c[0]['action']);
        self::assertStringContainsString('14 jours', $c[0]['action']);
        $hyp = implode(' | ', $c[0]['hypotheses']);
        self::assertStringContainsString('Élasticité-prix inconnue : hypothèse', $hyp);
        self::assertStringContainsString('renégocier le prix d\'achat', $hyp);
        self::assertSame('moyenne', $c[0]['confiance']);
        self::assertSame(Hyper::EFFORTS['R3'], $c[0]['effort']);
        self::assertTrue(GuardRails::candidatValide($c[0], $this->parCle($ctx), Hyper::MARGE_MIN));
    }

    public function test_marge_exactement_au_seuil_ne_declenche_pas(): void
    {
        // (2,00 − 1,50)/2,00 = 0,25 exactement → strictement en dessous requis.
        $p = RecoScenario::produit('P1', 'Baguette', qty30: 60.0, ca30: 120.0, prix: 2.0, cout: 1.5, stock: 50, unitesPrevues7j: 10.0);
        $ctx = $this->ctx([$p]);
        self::assertSame([], R3MargeFaible::evaluer($ctx));
    }

    public function test_volume_exactement_20_declenche_petit_delta(): void
    {
        // 20 u./30 j : échantillon atteint, Δ = 0,10 (20 < 40).
        $ctx = $this->ctx([$this->produit(qty30: 20.0)]);
        $c = R3MargeFaible::evaluer($ctx);

        self::assertCount(1, $c);
        self::assertStringContainsString('+0,10 €', $c[0]['action']);
        self::assertEqualsWithDelta(2.1, $c[0]['prix_effectifs'][0]['prix'], 1.0e-9);
    }

    public function test_volume_19_ne_declenche_pas(): void
    {
        $ctx = $this->ctx([$this->produit(qty30: 19.0)]);
        self::assertSame([], R3MargeFaible::evaluer($ctx));
    }

    public function test_hors_top_ca_ne_declenche_pas(): void
    {
        // 5 produits → top CA = 1 seul produit (le plus gros CA) ; P1 n'y est pas.
        $top = RecoScenario::produit('TOP', 'Café', qty30: 300.0, ca30: 1000.0, prix: 3.0, cout: 1.0, stock: 50, unitesPrevues7j: 50.0);
        $cible = RecoScenario::produit('P1', 'Baguette', qty30: 60.0, ca30: 120.0, prix: 2.0, cout: 1.51, stock: 50, unitesPrevues7j: 10.0);
        $ctx = $this->ctx([$cible, $top, $this->remplissage('F1'), $this->remplissage('F2'), $this->remplissage('F3')]);
        self::assertSame([], R3MargeFaible::evaluer($ctx));
    }

    private function remplissage(string $key): RecoProduct
    {
        return RecoScenario::produit($key, 'Remplissage ' . $key, qty30: 5.0, ca30: 10.0, prix: null, cout: null);
    }

    public function test_perte_volume_max_valeur_exacte_a_1e_moins_9(): void
    {
        // Marges : 0,60/2,00 = 0,30 et 0,80/2,20 = 0,363636… → perte = 1 − 0,30/0,363636… = 0,175.
        $attendu = 1.0 - ((2.0 - 1.4) / 2.0) / ((2.2 - 1.4) / 2.2);
        self::assertEqualsWithDelta($attendu, R3MargeFaible::perteVolumeMax(2.0, 1.4, 0.2), 1.0e-9);
        self::assertEqualsWithDelta(0.175, R3MargeFaible::perteVolumeMax(2.0, 1.4, 0.2), 1.0e-9);
        self::assertEqualsWithDelta(0.0, R3MargeFaible::perteVolumeMax(2.0, 1.4, 0.0), 1.0e-9);
    }

    public function test_cout_suspect_ne_declenche_pas(): void
    {
        $p = RecoScenario::produit('P1', 'Baguette', qty30: 60.0, ca30: 120.0, prix: 2.0, cout: 0.0, coutRaison: 'cout_zero', stock: 50, unitesPrevues7j: 10.0);
        $ctx = $this->ctx([$p]);
        self::assertSame([], R3MargeFaible::evaluer($ctx));
    }

    public function test_gain_toujours_ordonne_et_positif(): void
    {
        $ctx = $this->ctx([$this->produit()]);
        $c = R3MargeFaible::evaluer($ctx);
        $g = $c[0]['gain'];

        self::assertGreaterThanOrEqual(0.0, $g['bas']);
        self::assertTrue($g['bas'] <= $g['median'] + 1.0e-12);
        self::assertTrue($g['median'] <= $g['haut'] + 1.0e-12);
    }
}
