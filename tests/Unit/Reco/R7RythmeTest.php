<?php

declare(strict_types=1);

namespace Tests\Unit\Reco;

use App\Core\Compta\Reco\GuardRails;
use App\Core\Compta\Reco\Hyper;
use App\Core\Compta\Reco\R7Rythme;
use App\Core\Compta\Reco\RecoContext;
use App\Core\Compta\Reco\RecoProduct;
use PHPUnit\Framework\TestCase;

/**
 * R7 — Rythme horaire : détection stricte des creux (< 0,4 × moyenne)
 * et des pics (> 1,5 × moyenne), candidat unique combiné, gain exact,
 * cas plat / absent, secours du taux de marge.
 */
final class R7RythmeTest extends TestCase
{
    private const NOW = '2026-10-21 10:00:00';

    /** Produits : 3 à coût fiable (Café 0,5 / Thé 0,667 / Eau 0,2 de taux) + 1 à coût suspect. */
    private function produits(): array
    {
        return [
            RecoScenario::produit('P1', 'Café', qty30: 100.0, ca30: 200.0, prix: 2.0, cout: 1.0, unitesPrevues7j: 50.0),
            RecoScenario::produit('P2', 'Thé', qty30: 60.0, ca30: 180.0, prix: 3.0, cout: 1.0, unitesPrevues7j: 10.0),
            RecoScenario::produit('P4', 'Eau', qty30: 80.0, ca30: 160.0, prix: 2.0, cout: 1.6, unitesPrevues7j: 20.0),
            RecoScenario::produit('PS', 'Mystère', qty30: 10.0, prix: null, cout: null, coutRaison: 'cout_inconnu'),
        ];
    }

    /** Profil réaliste : 4 h creuses (10), 16 h normales (40), 4 h de pic (100) — moyenne 45. */
    private function partsRealistes(): array
    {
        $parts = [];
        for ($h = 0; $h < 24; $h++) {
            $parts[] = $h < 4 ? 10.0 : ($h >= 20 ? 100.0 : 40.0);
        }

        return $parts;
    }

    private function forecastDepuisParts(array $parts): array
    {
        $s = [];
        $t = 0.0;
        foreach ($parts as $v) {
            $t += $v;
            $s[] = $t;
        }
        $f = RecoScenario::forecastMinimal();
        $f['horaire']['s'] = $s;

        return $f;
    }

    private function ctx(array $produits, array $parts, ?array $forecast = null): RecoContext
    {
        return new RecoContext(
            RecoScenario::dataset($produits, [], forecast: $forecast ?? $this->forecastDepuisParts($parts)),
            new \DateTimeImmutable(self::NOW)
        );
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

    public function test_profil_realiste_emet_un_seul_candidat_combine(): void
    {
        $ctx = $this->ctx($this->produits(), $this->partsRealistes());
        $c = R7Rythme::evaluer($ctx);

        self::assertCount(1, $c);
        $cand = $c[0];
        self::assertSame('R7', $cand['regle']);
        self::assertSame(Hyper::EFFORTS['R7'], $cand['effort']);
        self::assertSame('moyenne', $cand['confiance']);
        self::assertStringContainsString('Happy hour 0–3', $cand['action']);
        self::assertStringContainsString('Thé, Café, Eau', $cand['action']);
        self::assertStringContainsString('aux pics (20, 21, 22, 23)', $cand['action']);
        self::assertStringContainsString('3,7', $cand['pourquoi']);
        self::assertStringContainsString('170,00', $cand['pourquoi']);
        self::assertArrayNotHasKey('prix_effectifs', $cand);

        $median = 170.0 * 40.0 * R7Rythme::UPLIFT_CREUX * 0.5;
        self::assertEqualsWithDelta($median, $cand['gain']['median'], 1.0e-9);
        self::assertEqualsWithDelta($median * 0.5, $cand['gain']['bas'], 1.0e-9);
        self::assertEqualsWithDelta($median * 1.5, $cand['gain']['haut'], 1.0e-9);

        self::assertTrue(GuardRails::candidatValide($cand, $this->parCle($ctx), Hyper::MARGE_MIN));
    }

    public function test_part_exactement_au_seuil_de_creux_n_est_pas_un_creux(): void
    {
        $parts = array_fill(0, 24, 59.0);
        $parts[0] = 23.0; // exactement 0,4 × 57,5 (moyenne)

        $ctx = $this->ctx($this->produits(), $parts);
        self::assertSame([], R7Rythme::evaluer($ctx));
    }

    public function test_part_juste_en_dessous_du_seuil_declenche_avec_pic(): void
    {
        $parts = array_fill(0, 24, 59.0);
        $parts[0] = 22.9;   // creux (22,9 < 23,0)
        $parts[23] = 90.0;  // pic (90 > 86,25)

        $ctx = $this->ctx($this->produits(), $parts);
        $c = R7Rythme::evaluer($ctx);

        self::assertCount(1, $c);
        self::assertStringContainsString('Happy hour 0', $c[0]['action']);
        self::assertStringContainsString('aux pics (23)', $c[0]['action']);
    }

    public function test_profil_plat_aucun_candidat(): void
    {
        $ctx = new RecoContext(RecoScenario::dataset($this->produits()), new \DateTimeImmutable(self::NOW));
        self::assertSame([], R7Rythme::evaluer($ctx));
    }

    public function test_horaire_absent_aucun_candidat(): void
    {
        $f = RecoScenario::forecastMinimal();
        unset($f['horaire']);

        $ctx = $this->ctx($this->produits(), [], $f);
        self::assertSame([], R7Rythme::evaluer($ctx));
    }

    public function test_secours_du_taux_de_marge_documente(): void
    {
        $produits = [
            RecoScenario::produit('P1', 'Café', prix: 2.0, cout: 1.0, unitesPrevues7j: 50.0),
            RecoScenario::produit('P2', 'Thé', prix: 3.0, cout: 1.0, unitesPrevues7j: 10.0),
        ];
        $ctx = $this->ctx($produits, $this->partsRealistes());
        $c = R7Rythme::evaluer($ctx);

        self::assertCount(1, $c);
        $median = 130.0 * 40.0 * R7Rythme::UPLIFT_CREUX * R7Rythme::TAUX_MARGE_SECOURS;
        self::assertEqualsWithDelta($median, $c[0]['gain']['median'], 1.0e-9);
        $hyp = implode(' | ', $c[0]['hypotheses']);
        self::assertStringContainsString('0,30', $hyp);
        self::assertStringContainsString('hypothèse', $hyp);
    }

    public function test_aucun_produit_a_cout_fiable_aucun_candidat(): void
    {
        $produits = [
            RecoScenario::produit('PS', 'Mystère', prix: null, cout: null, coutRaison: 'cout_inconnu'),
        ];
        $ctx = $this->ctx($produits, $this->partsRealistes());
        self::assertSame([], R7Rythme::evaluer($ctx));
    }
}
