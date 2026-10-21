<?php

declare(strict_types=1);

namespace Tests\Unit\Reco;

use App\Core\Compta\Reco\GuardRails;
use App\Core\Compta\Reco\Hyper;
use App\Core\Compta\Reco\R2Rupture;
use App\Core\Compta\Reco\RecoContext;
use App\Core\Compta\Reco\RecoProduct;
use PHPUnit\Framework\TestCase;

/**
 * R2 — Rupture/critique : stock nul, seuil de sécurité
 * (ceil(prévision × 3 ÷ 7)) exact ou franchi, confiance haute/moyenne,
 * coût inconnu → gain nul, gains issus de la prévision.
 */
final class R2RuptureTest extends TestCase
{
    private const NOW = '2026-10-21 10:00:00';

    private function ctx(array $produits): RecoContext
    {
        return new RecoContext(RecoScenario::dataset($produits), new \DateTimeImmutable(self::NOW));
    }

    /** Produit de référence : 40 u./sem prévues, prix 2 €, coût 1 € (marge 1 €). */
    private function produit(?int $stock, float $up7 = 40.0, float $qty30 = 200.0): RecoProduct
    {
        return RecoScenario::produit('P1', 'Soda', qty30: $qty30, ca30: $qty30 * 2.0, prix: 2.0, cout: 1.0, stock: $stock, unitesPrevues7j: $up7, prevLo: 30.0, prevHi: 50.0);
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

    public function test_stock_zero_declenche_commande(): void
    {
        $ctx = $this->ctx([$this->produit(0)]);
        $c = R2Rupture::evaluer($ctx);

        self::assertCount(1, $c);
        self::assertSame('R2', $c[0]['regle']);
        self::assertSame(['P1'], $c[0]['keys']);
        // Seuil = ceil(40 × 3 ÷ 7) = 18 → commander 18 − 0 = 18 u.
        self::assertStringContainsString('Commander 18', $c[0]['action']);
        self::assertStringContainsString('dès maintenant', $c[0]['action']);
        self::assertSame('0 u. en stock · 40,0 u./sem prévues', $c[0]['pourquoi']);
        $g = $c[0]['gain'];
        self::assertEqualsWithDelta(30.0, $g['bas'], 1.0e-9);
        self::assertEqualsWithDelta(40.0, $g['median'], 1.0e-9);
        self::assertEqualsWithDelta(50.0, $g['haut'], 1.0e-9);
        self::assertSame('haute', $c[0]['confiance']);
        self::assertSame(Hyper::EFFORTS['R2'], $c[0]['effort']);
        self::assertTrue(GuardRails::candidatValide($c[0], $this->parCle($ctx), Hyper::MARGE_MIN));
    }

    public function test_stock_sous_le_seuil_de_securite_declenche(): void
    {
        // Stock 16 < seuil 18 → commander 2 u.
        $ctx = $this->ctx([$this->produit(16)]);
        $c = R2Rupture::evaluer($ctx);

        self::assertCount(1, $c);
        self::assertStringContainsString('Commander 2 ', $c[0]['action']);
    }

    public function test_stock_exactement_au_seuil_ne_declenche_pas(): void
    {
        // Stock 18 = ceil(40 × 3 ÷ 7) = 18 (strictement en dessous requis).
        $ctx = $this->ctx([$this->produit(18)]);
        self::assertSame([], R2Rupture::evaluer($ctx));
    }

    public function test_stock_au_seuil_moins_un_declenche(): void
    {
        $ctx = $this->ctx([$this->produit(17)]);
        self::assertCount(1, R2Rupture::evaluer($ctx));
    }

    public function test_sans_prevision_pas_de_recommandation(): void
    {
        $ctx = $this->ctx([$this->produit(0, up7: 0.0, qty30: 0.0)]);
        self::assertSame([], R2Rupture::evaluer($ctx));
    }

    public function test_stock_non_gere_ne_declenche_pas(): void
    {
        $ctx = $this->ctx([$this->produit(null)]);
        self::assertSame([], R2Rupture::evaluer($ctx));
    }

    public function test_faible_historique_confiance_moyenne_et_hypothese(): void
    {
        $ctx = $this->ctx([$this->produit(0, qty30: 10.0)]);
        $c = R2Rupture::evaluer($ctx);

        self::assertCount(1, $c);
        self::assertSame('moyenne', $c[0]['confiance']);
        $hyp = implode(' | ', $c[0]['hypotheses']);
        self::assertStringContainsString('Demande estimée = hypothèse', $hyp);
    }

    public function test_cout_inconnu_gain_nul_candidat_emis(): void
    {
        $p = RecoScenario::produit('P2', 'Mystère', qty30: 200.0, ca30: 400.0, prix: null, cout: null, coutRaison: 'cout_inconnu', stock: 0, unitesPrevues7j: 40.0, prevLo: 30.0, prevHi: 50.0);
        $ctx = $this->ctx([$p]);
        $c = R2Rupture::evaluer($ctx);

        self::assertCount(1, $c);
        self::assertSame(['bas' => 0.0, 'median' => 0.0, 'haut' => 0.0], $c[0]['gain']);
        $hyp = implode(' | ', $c[0]['hypotheses']);
        self::assertStringContainsString('Coût inconnu', $hyp);
        self::assertTrue(GuardRails::candidatValide($c[0], $this->parCle($ctx), Hyper::MARGE_MIN));
    }

    public function test_gain_toujours_ordonne_et_positif(): void
    {
        $ctx = $this->ctx([$this->produit(0)]);
        $c = R2Rupture::evaluer($ctx);
        $g = $c[0]['gain'];

        self::assertGreaterThanOrEqual(0.0, $g['bas']);
        self::assertTrue($g['bas'] <= $g['median'] + 1.0e-12);
        self::assertTrue($g['median'] <= $g['haut'] + 1.0e-12);
    }
}
