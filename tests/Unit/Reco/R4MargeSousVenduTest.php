<?php

declare(strict_types=1);

namespace Tests\Unit\Reco;

use App\Core\Compta\Reco\GuardRails;
use App\Core\Compta\Reco\Hyper;
use App\Core\Compta\Reco\R4MargeSousVendue;
use App\Core\Compta\Reco\RecoContext;
use App\Core\Compta\Reco\RecoProduct;
use PHPUnit\Framework\TestCase;

/**
 * R4 — Forte marge sous-vendue : médianes (taux de marge strictement
 * au-dessus, volume strictement en dessous), échantillon minimal 8,
 * réserve de stock ≥ 5, médiane indisponible → rien, gains ×0,5 / ×1,5.
 */
final class R4MargeSousVenduTest extends TestCase
{
    private const NOW = '2026-10-21 10:00:00';

    private function ctx(array $produits): RecoContext
    {
        return new RecoContext(RecoScenario::dataset($produits), new \DateTimeImmutable(self::NOW));
    }

    /**
     * Scénario à 3 produits : cible P1 (marge 60 %, volume 8), P2
     * (marge 30 %, volume 100), P3 (marge 50 %, volume 50). Médianes :
     * taux 40 %, volume 50.
     *
     * @return list<RecoProduct>
     */
    private function trio(?int $stockCible = null, float $qtyCible = 8.0): array
    {
        $p1 = RecoScenario::produit('P1', 'Truffes', qty30: $qtyCible, ca30: $qtyCible * 2.5, prix: 2.5, cout: 1.0, stock: $stockCible, unitesPrevues7j: 2.0);
        $p2 = RecoScenario::produit('P2', 'Eau', qty30: 100.0, ca30: 200.0, prix: 2.0, cout: 1.4, stock: 50, unitesPrevues7j: 20.0);
        $p3 = RecoScenario::produit('P3', 'Pains', qty30: 50.0, ca30: 100.0, prix: 2.0, cout: 1.0, stock: 50, unitesPrevues7j: 10.0);

        return [$p1, $p2, $p3];
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

    public function test_forte_marge_sous_vendue_declenche_mise_en_avant(): void
    {
        $ctx = $this->ctx($this->trio());
        $c = R4MargeSousVendue::evaluer($ctx);

        self::assertCount(1, $c);
        self::assertSame('R4', $c[0]['regle']);
        self::assertSame(['P1'], $c[0]['keys']);
        // Médian = 2 × 0,20 × 1,50 = 0,6 ; bas 0,3 ; haut 0,9.
        $g = $c[0]['gain'];
        self::assertEqualsWithDelta(0.3, $g['bas'], 1.0e-9);
        self::assertEqualsWithDelta(0.6, $g['median'], 1.0e-9);
        self::assertEqualsWithDelta(0.9, $g['haut'], 1.0e-9);
        // Consigne datée : affichage caisse une semaine, marge vs médiane du catalogue.
        self::assertSame('2026-10-21', $c[0]['debut']);
        self::assertSame('2026-10-28', $c[0]['fin']);
        self::assertStringContainsString(
            'Cette semaine : affichez Truffes près de la caisse (affiche + suggestion du jour) — marge 1,50 €/unité contre 1,00 € en médiane du catalogue.',
            $c[0]['action']
        );
        self::assertStringContainsString('60,0 %', $c[0]['pourquoi']);
        $hyp = implode(' | ', $c[0]['hypotheses']);
        self::assertStringContainsString('+20 % de volume = hypothèse', $hyp);
        self::assertSame('moyenne', $c[0]['confiance']);
        self::assertSame(Hyper::EFFORTS['R4'], $c[0]['effort']);
        self::assertTrue(GuardRails::candidatValide($c[0], $this->parCle($ctx), Hyper::MARGE_MIN));
    }

    public function test_marge_exactement_mediane_ne_declenche_pas(): void
    {
        // P1 à marge 40 % = médiane exactement (strictement au-dessus requis).
        $p1 = RecoScenario::produit('P1', 'Truffes', qty30: 8.0, ca30: 16.0, prix: 2.0, cout: 1.2, stock: 50, unitesPrevues7j: 2.0);
        $p2 = RecoScenario::produit('P2', 'Eau', qty30: 100.0, ca30: 200.0, prix: 2.0, cout: 1.4, stock: 50, unitesPrevues7j: 20.0);
        $p3 = RecoScenario::produit('P3', 'Pains', qty30: 50.0, ca30: 100.0, prix: 2.0, cout: 1.0, stock: 50, unitesPrevues7j: 10.0);
        $ctx = $this->ctx([$p1, $p2, $p3]);
        self::assertSame([], R4MargeSousVendue::evaluer($ctx));
    }

    public function test_volume_7_ne_declenche_pas(): void
    {
        // 7 < 8 : échantillon minimal non atteint.
        $ctx = $this->ctx($this->trio(qtyCible: 7.0));
        self::assertSame([], R4MargeSousVendue::evaluer($ctx));
    }

    public function test_volume_8_declenche(): void
    {
        $ctx = $this->ctx($this->trio(qtyCible: 8.0));
        self::assertCount(1, R4MargeSousVendue::evaluer($ctx));
    }

    public function test_stock_4_ne_declenche_pas(): void
    {
        $ctx = $this->ctx($this->trio(stockCible: 4));
        self::assertSame([], R4MargeSousVendue::evaluer($ctx));
    }

    public function test_stock_5_declenche(): void
    {
        $ctx = $this->ctx($this->trio(stockCible: 5));
        self::assertCount(1, R4MargeSousVendue::evaluer($ctx));
    }

    public function test_moins_de_trois_produits_exploitables_ne_declenche_pas(): void
    {
        // Médiane des taux de marge indisponible (2 produits à coût utilisable).
        $p1 = RecoScenario::produit('P1', 'Truffes', qty30: 8.0, ca30: 20.0, prix: 2.5, cout: 1.0, stock: 50, unitesPrevues7j: 2.0);
        $p2 = RecoScenario::produit('P2', 'Eau', qty30: 100.0, ca30: 200.0, prix: 2.0, cout: 1.4, stock: 50, unitesPrevues7j: 20.0);
        $ctx = $this->ctx([$p1, $p2]);
        self::assertSame([], R4MargeSousVendue::evaluer($ctx));
    }

    public function test_gain_toujours_ordonne_et_positif(): void
    {
        $ctx = $this->ctx($this->trio());
        $c = R4MargeSousVendue::evaluer($ctx);
        $g = $c[0]['gain'];

        self::assertGreaterThanOrEqual(0.0, $g['bas']);
        self::assertTrue($g['bas'] <= $g['median'] + 1.0e-12);
        self::assertTrue($g['median'] <= $g['haut'] + 1.0e-12);
    }
}
