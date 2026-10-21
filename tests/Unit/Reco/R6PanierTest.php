<?php

declare(strict_types=1);

namespace Tests\Unit\Reco;

use App\Core\Compta\Reco\GuardRails;
use App\Core\Compta\Reco\Hyper;
use App\Core\Compta\Reco\R6Panier;
use App\Core\Compta\Reco\RecoContext;
use App\Core\Compta\Reco\RecoProduct;
use PHPUnit\Framework\TestCase;

/**
 * R6 — Seuil cadeau : déclenchement sur la part de paniers à 1 ligne,
 * choix du cadeau (moins cher, coût borné, stock suffisant), seuil =
 * quantile des montants, gain exact, garde-fous.
 */
final class R6PanierTest extends TestCase
{
    private const NOW = '2026-10-21 10:00:00';

    /** Catalogue avec candidats cadeaux (CAD retenu : coût 0,10 € et stock non géré). */
    private function produitsCadeaux(): array
    {
        return [
            RecoScenario::produit('P1', 'Café', qty30: 400.0, ca30: 800.0, prix: 2.0, cout: 1.0),
            RecoScenario::produit('CAD2', 'Carambar', qty30: 20.0, ca30: 10.0, prix: 0.60, cout: 0.12),
            RecoScenario::produit('CAD3', 'Chewing-gum', qty30: 10.0, ca30: 5.0, prix: 0.50, cout: 0.10, stock: 5),
            RecoScenario::produit('CAD', 'Bonbon', qty30: 30.0, ca30: 15.0, prix: 0.50, cout: 0.10),
        ];
    }

    /** Paniers à 1 ligne (3 €, article P1) ou à 2 lignes (10 €). */
    private function paniers(int $nb1Ligne, int $nb2Lignes): array
    {
        $paniers = [];
        for ($i = 0; $i < $nb1Ligne; $i++) {
            $paniers[] = RecoScenario::panier('2026-10-19', [['key' => 'P1', 'montant' => 3.0, 'qty' => 1]]);
        }
        for ($i = 0; $i < $nb2Lignes; $i++) {
            $paniers[] = RecoScenario::panier('2026-10-19', [
                ['key' => 'P1', 'montant' => 5.0, 'qty' => 1],
                ['key' => 'P1', 'montant' => 5.0, 'qty' => 1],
            ]);
        }

        return $paniers;
    }

    private function ctx(array $produits, array $paniers, array $parametres = []): RecoContext
    {
        return new RecoContext(
            RecoScenario::dataset($produits, $paniers, parametres: $parametres),
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

    public function test_declenche_a_85_1_pour_cent_et_calcule_le_gain_exact(): void
    {
        $ctx = $this->ctx($this->produitsCadeaux(), $this->paniers(851, 149));
        $c = R6Panier::evaluer($ctx);

        self::assertCount(1, $c);
        $cand = $c[0];
        self::assertSame('R6', $cand['regle']);
        self::assertSame(['CAD'], $cand['keys']);
        self::assertSame('moyenne', $cand['confiance']);
        self::assertSame(Hyper::EFFORTS['R6'], $cand['effort']);
        self::assertArrayNotHasKey('prix_effectifs', $cand);
        self::assertStringContainsString('85,1', $cand['pourquoi']);
        self::assertStringContainsString('3,00', $cand['pourquoi']);
        self::assertStringContainsString('Offrir Bonbon au-delà de 3,00 € d\'achat', $cand['action']);

        $parSemaine = 851.0 / R6Panier::SEMAINES_PAR_MOIS;
        $median = $parSemaine * R6Panier::TAUX_FRANCHISSEMENT * 1.0 - $parSemaine * R6Panier::TAUX_FRANCHISSEMENT * 0.10;
        self::assertEqualsWithDelta($median, $cand['gain']['median'], 1.0e-9);
        self::assertEqualsWithDelta($median * 0.5, $cand['gain']['bas'], 1.0e-9);
        self::assertEqualsWithDelta($median * 1.5, $cand['gain']['haut'], 1.0e-9);
        self::assertGreaterThanOrEqual(0.0, $cand['gain']['bas']);

        self::assertTrue(GuardRails::candidatValide($cand, $this->parCle($ctx), Hyper::MARGE_MIN));
    }

    public function test_85_pour_cent_exactement_ne_declenche_pas(): void
    {
        $ctx = $this->ctx($this->produitsCadeaux(), $this->paniers(17, 3));
        self::assertSame([], R6Panier::evaluer($ctx));
    }

    public function test_85_1_pour_cent_avec_moins_de_30_paniers_confiance_faible(): void
    {
        $ctx = $this->ctx($this->produitsCadeaux(), $this->paniers(18, 2));
        $c = R6Panier::evaluer($ctx);

        self::assertCount(1, $c);
        self::assertSame('faible', $c[0]['confiance']);
        $hyp = implode(' | ', $c[0]['hypotheses']);
        self::assertStringContainsString('estimation peu fiable', $hyp);
    }

    public function test_aucun_cadeau_eligible_aucun_candidat(): void
    {
        $produits = [
            RecoScenario::produit('P1', 'Café', prix: 2.0, cout: 1.0),
            RecoScenario::produit('CX', 'Cher', prix: 1.0, cout: 0.20),
            RecoScenario::produit('CY', 'Presque', prix: 0.50, cout: 0.10, stock: 5),
        ];
        $ctx = $this->ctx($produits, $this->paniers(851, 149));
        self::assertSame([], R6Panier::evaluer($ctx));
    }

    public function test_seuil_percentile_et_cout_cadeau_surchargeables(): void
    {
        $ctx = $this->ctx(
            $this->produitsCadeaux(),
            $this->paniers(851, 149),
            ['reco_panier_percentile' => 0.9, 'reco_cout_cadeau_max' => 0.11]
        );
        $c = R6Panier::evaluer($ctx);

        self::assertCount(1, $c);
        self::assertSame(['CAD'], $c[0]['keys']);
        self::assertStringContainsString('10,00', $c[0]['pourquoi']);
        self::assertStringContainsString('Offrir Bonbon au-delà de 10,00 € d\'achat', $c[0]['action']);
        self::assertTrue(GuardRails::candidatValide($c[0], $this->parCle($ctx), Hyper::MARGE_MIN));
    }

    public function test_sans_paniers_aucun_candidat(): void
    {
        $ctx = $this->ctx($this->produitsCadeaux(), []);
        self::assertSame([], R6Panier::evaluer($ctx));
    }
}
