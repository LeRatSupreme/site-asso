<?php

declare(strict_types=1);

namespace Tests\Unit\Reco;

use App\Core\Compta\Reco\Basket;
use App\Core\Compta\Reco\GuardRails;
use App\Core\Compta\Reco\Hyper;
use App\Core\Compta\Reco\R5Duos;
use App\Core\Compta\Reco\RecoContext;
use App\Core\Compta\Reco\RecoProduct;
use PHPUnit\Framework\TestCase;

/**
 * R5 — Duos : bascule heuristique (29 paniers multi) / analyse (30),
 * lift exactement 1,2 exclu vs 1,21 retenu, orientation accroche
 * (fort volume) → cible (forte marge, stock, catégorie), remise duo
 * plafonnée par le plancher, confiance moyenne/faible, garde-fous.
 */
final class R5DuosTest extends TestCase
{
    private const NOW = '2026-10-21 10:00:00';

    private function ctx(array $produits, array $paniers, array $parametres = []): RecoContext
    {
        return new RecoContext(
            RecoScenario::dataset($produits, $paniers, [], 1.0, false, 30, null, [], $parametres),
            new \DateTimeImmutable(self::NOW)
        );
    }

    /** Accroche par défaut : Ananas, plus fort volume (100 u./30 j), prix 2 €, coût 1 €. */
    private function accroche(): RecoProduct
    {
        return RecoScenario::produit('PA', 'Ananas', 'Boissons', qty30: 100.0, ca30: 200.0, prix: 2.0, cout: 1.0, stock: null, unitesPrevues7j: 20.0);
    }

    /** Cible par défaut : Biscuits, prix 3 €, coût 1 €. */
    private function cible(): RecoProduct
    {
        return RecoScenario::produit('PB', 'Biscuits', 'Snacks', qty30: 50.0, ca30: 150.0, prix: 3.0, cout: 1.0, stock: null, unitesPrevues7j: 10.0);
    }

    private function remplissageC(): RecoProduct
    {
        return RecoScenario::produit('PC', 'Chocolat', 'Snacks', qty30: 20.0, ca30: 30.0, prix: 1.5, cout: 1.0, stock: null, unitesPrevues7j: 5.0);
    }

    private function remplissageD(): RecoProduct
    {
        return RecoScenario::produit('PD', 'Dattes', 'Snacks', qty30: 20.0, ca30: 30.0, prix: 2.0, cout: 1.5, stock: null, unitesPrevues7j: 5.0);
    }

    /**
     * $n paniers multi-articles identiques contenant les clés données.
     *
     * @return list<Basket>
     */
    private function paniers(int $n, string ...$keys): array
    {
        $lignes = array_map(static fn (string $k): array => ['key' => $k, 'montant' => 1.0, 'qty' => 1], $keys);
        $out = [];
        for ($i = 0; $i < $n; ++$i) {
            $out[] = RecoScenario::panier('2026-10-01', $lignes);
        }

        return $out;
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

    public function test_analyse_association_declenche_meilleure_paire(): void
    {
        // 40 paniers multi : 16×[PA,PB,PD], 4×[PA,PC,PD], 20×[PC,PD] → seule PA|PB dépasse lift 1,2.
        $paniers = array_merge(
            $this->paniers(16, 'PA', 'PB', 'PD'),
            $this->paniers(4, 'PA', 'PC', 'PD'),
            $this->paniers(20, 'PC', 'PD')
        );
        $ctx = $this->ctx([$this->accroche(), $this->cible(), $this->remplissageC(), $this->remplissageD()], $paniers);
        $c = R5Duos::evaluer($ctx);

        self::assertCount(1, $c);
        self::assertSame('R5', $c[0]['regle']);
        self::assertSame(['PA', 'PB'], $c[0]['keys']);
        self::assertSame('moyenne', $c[0]['confiance']);
        self::assertStringContainsString('lift', $c[0]['pourquoi']);
        // Remise duo 10 % : 1,80 € + 2,70 € (au lieu de 5 €).
        self::assertEqualsWithDelta(1.8, $c[0]['prix_effectifs'][0]['prix'], 1.0e-9);
        self::assertEqualsWithDelta(2.7, $c[0]['prix_effectifs'][1]['prix'], 1.0e-9);
        // Médian = 0,25 × (20−16 paniers sans cible ÷ 4,33) × marge duo 2,50 €.
        $median = R5Duos::CONVERSION_ANALYSE * (4.0 / R5Duos::SEMAINES_PAR_MOIS) * 2.5;
        self::assertEqualsWithDelta($median * R5Duos::FACTEUR_BAS, $c[0]['gain']['bas'], 1.0e-9);
        self::assertEqualsWithDelta($median, $c[0]['gain']['median'], 1.0e-9);
        self::assertEqualsWithDelta($median * R5Duos::FACTEUR_HAUT, $c[0]['gain']['haut'], 1.0e-9);
        self::assertSame(Hyper::EFFORTS['R5'], $c[0]['effort']);
        self::assertTrue(GuardRails::candidatValide($c[0], $this->parCle($ctx), Hyper::MARGE_MIN));
    }

    public function test_30_paniers_multi_passent_en_analyse(): void
    {
        $paniers = array_merge(
            $this->paniers(12, 'PA', 'PB', 'PD'),
            $this->paniers(3, 'PA', 'PC', 'PD'),
            $this->paniers(15, 'PC', 'PD')
        );
        $ctx = $this->ctx([$this->accroche(), $this->cible(), $this->remplissageC(), $this->remplissageD()], $paniers);
        $c = R5Duos::evaluer($ctx);

        self::assertCount(1, $c);
        self::assertSame(['PA', 'PB'], $c[0]['keys']);
        self::assertSame('moyenne', $c[0]['confiance']);
        self::assertStringContainsString('lift', $c[0]['pourquoi']);
        // Sans cible : 15 − 12 = 3 paniers → 0,25 × (3/4,33) × 2,50.
        $median = R5Duos::CONVERSION_ANALYSE * (3.0 / R5Duos::SEMAINES_PAR_MOIS) * 2.5;
        self::assertEqualsWithDelta($median, $c[0]['gain']['median'], 1.0e-9);
    }

    public function test_29_paniers_multi_restant_en_heuristique(): void
    {
        $paniers = array_merge(
            $this->paniers(12, 'PA', 'PB', 'PD'),
            $this->paniers(3, 'PA', 'PC', 'PD'),
            $this->paniers(14, 'PC', 'PD')
        );
        $ctx = $this->ctx([$this->accroche(), $this->cible(), $this->remplissageC(), $this->remplissageD()], $paniers);
        $c = R5Duos::evaluer($ctx);

        self::assertCount(1, $c);
        self::assertSame(['PA', 'PB'], $c[0]['keys']);
        self::assertSame('faible', $c[0]['confiance']);
        $hyp = implode(' | ', $c[0]['hypotheses']);
        self::assertStringContainsString('hypothèse', $hyp);
        // Médian = 0,15 × 20 u. prévues (accroche) × marge duo 2,50.
        $median = R5Duos::CONVERSION_HEURISTIQUE * 20.0 * 2.5;
        self::assertEqualsWithDelta($median, $c[0]['gain']['median'], 1.0e-9);
        self::assertTrue(GuardRails::candidatValide($c[0], $this->parCle($ctx), Hyper::MARGE_MIN));
    }

    public function test_lift_exactement_1_2_est_exclu(): void
    {
        // Chaque produit dans 20/40 paniers, paires A|B et C|D à 12 co-occurrences : lift = 0,6/0,5 = 1,2 exactement.
        $paniers = array_merge(
            $this->paniers(12, 'PA', 'PB'),
            $this->paniers(8, 'PA', 'PC'),
            $this->paniers(8, 'PB', 'PD'),
            $this->paniers(12, 'PC', 'PD')
        );
        $ctx = $this->ctx([$this->accroche(), $this->cible(), $this->remplissageC(), $this->remplissageD()], $paniers);
        self::assertSame([], R5Duos::evaluer($ctx));
    }

    public function test_lift_1_21_est_retenu(): void
    {
        // conf 0,55 (11/20), P(B) = 20/44 → lift ≈ 1,21 > 1,2 : seule PA|PB est retenue.
        $paniers = array_merge(
            $this->paniers(11, 'PA', 'PB'),
            $this->paniers(9, 'PA', 'PC'),
            $this->paniers(9, 'PB', 'PD'),
            $this->paniers(15, 'PC', 'PD')
        );
        $ctx = $this->ctx([$this->accroche(), $this->cible(), $this->remplissageC(), $this->remplissageD()], $paniers);
        $c = R5Duos::evaluer($ctx);

        self::assertCount(1, $c);
        self::assertSame(['PA', 'PB'], $c[0]['keys']);
        self::assertTrue(GuardRails::candidatValide($c[0], $this->parCle($ctx), Hyper::MARGE_MIN));
    }

    public function test_heuristique_choisit_accroche_fort_volume_et_cible_forte_marge(): void
    {
        $pa = $this->accroche();                                        // marge 30 %, Boissons, stock 20
        $pb = $this->cible();                                           // marge 60 %, Snacks, stock null
        $pc = RecoScenario::produit('PC', 'Chocolat', 'Boissons', qty30: 20.0, ca30: 40.0, prix: 2.0, cout: 0.4, stock: null, unitesPrevues7j: 5.0); // même catégorie → exclue
        $pd = RecoScenario::produit('PD', 'Dattes', 'Snacks', qty30: 30.0, ca30: 60.0, prix: 2.0, cout: 0.6, stock: 5, unitesPrevues7j: 5.0);       // stock ≤ 10 → exclue
        $pe = RecoScenario::produit('PE', 'Eclairs', 'Snacks', qty30: 30.0, ca30: 60.0, prix: 2.0, cout: 0.9, stock: null, unitesPrevues7j: 5.0);   // marge 55 % < 60 %
        $ctx = $this->ctx([$pa, $pb, $pc, $pd, $pe], []);
        $c = R5Duos::evaluer($ctx);

        self::assertCount(1, $c);
        self::assertSame(['PA', 'PB'], $c[0]['keys']);
        // Remise 10 % : 1,80 € + 2,70 € (marge duo 5×0,9 − 2 = 2,50 €).
        self::assertEqualsWithDelta(1.8, $c[0]['prix_effectifs'][0]['prix'], 1.0e-9);
        self::assertEqualsWithDelta(2.7, $c[0]['prix_effectifs'][1]['prix'], 1.0e-9);
        $median = R5Duos::CONVERSION_HEURISTIQUE * 20.0 * 2.5;
        self::assertEqualsWithDelta($median * R5Duos::FACTEUR_BAS, $c[0]['gain']['bas'], 1.0e-9);
        self::assertEqualsWithDelta($median, $c[0]['gain']['median'], 1.0e-9);
        self::assertEqualsWithDelta($median * R5Duos::FACTEUR_HAUT, $c[0]['gain']['haut'], 1.0e-9);
        self::assertTrue($c[0]['gain']['bas'] >= 0.0 && $c[0]['gain']['bas'] <= $c[0]['gain']['median'] && $c[0]['gain']['median'] <= $c[0]['gain']['haut']);
        self::assertTrue(GuardRails::candidatValide($c[0], $this->parCle($ctx), Hyper::MARGE_MIN));
    }

    public function test_heuristique_cible_introuvable_ne_declenche_pas(): void
    {
        $ctx = $this->ctx([$this->accroche()], []);
        self::assertSame([], R5Duos::evaluer($ctx));
    }

    public function test_cout_suspect_ne_declenche_pas(): void
    {
        $pa = RecoScenario::produit('PA', 'Ananas', 'Boissons', qty30: 100.0, ca30: 200.0, prix: null, cout: null, coutRaison: 'cout_inconnu', stock: null, unitesPrevues7j: 20.0);
        $ctx = $this->ctx([$pa, $this->cible()], []);
        self::assertSame([], R5Duos::evaluer($ctx));
    }

    public function test_marge_duo_impossible_ne_declenche_pas(): void
    {
        // Marge de l'accroche (5 %) < marge minimale : aucune remise ne respecte le plancher.
        $pa = RecoScenario::produit('PA', 'Ananas', 'Boissons', qty30: 100.0, ca30: 200.0, prix: 2.0, cout: 1.9, stock: null, unitesPrevues7j: 20.0);
        $ctx = $this->ctx([$pa, $this->cible()], []);
        self::assertSame([], R5Duos::evaluer($ctx));
    }
}
