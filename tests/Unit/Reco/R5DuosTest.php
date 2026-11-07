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
 * deux niveaux de portes — FORTE (lift > 1,2, support ≥ 2 %, conf ≥ 5 %)
 * et VARIANTE (lift > 1,0, support ≥ 0,5 %, conf ≥ 3 %, confiance
 * « faible ») calibrées prod (lift 3,13 / 0,9 % ; 1,54 / 1,1 % ; 0,89
 * exclu) —, FORTE toujours préférée, anti-association lift ≤ 1,
 * orientation accroche (fort volume) → cible (forte marge, stock,
 * catégorie différente), remise duo plafonnée par le plancher, garde-fous.
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

    /**
     * $n paniers multi-articles contenant les clés données PLUS deux
     * lignes inconnues du catalogue (« ZZ », « QQ ») : compte comme
     * multi sans polluer les associations (ZZ|QQ à lift 1,0 exactement,
     * exclue par l'anti-association). Sans clé : panier de 2 lignes
     * inconnues (ni A ni B).
     *
     * @return list<Basket>
     */
    private function paniersProd(int $n, string ...$keys): array
    {
        return $this->paniers($n, ...array_merge($keys, ['ZZ', 'QQ']));
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
        // Consigne : duo, prix promo, ancien total, marge duo vs cible seule, durée, emplacement.
        self::assertStringContainsString(
            'Proposez le duo Ananas + Biscuits à 4,50 € au lieu de 5,00 € (-10,0 %) — marge duo 2,50 € contre 2,00 € pour Biscuits seul.',
            $c[0]['action']
        );
        self::assertStringContainsString('Affichez-le en caisse pendant 2 semaines', $c[0]['action']);
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

    /**
     * CALIBRAGE PROD — paire Bueno_white+Nutella (lift 3,13, support
     * 0,9 %, confiance 6,2 %) : refusée en FORTE (support < 2 %), retenue
     * en VARIANTE avec confiance « faible ».
     */
    public function test_variante_paire_calibree_prod_lift_3_13_support_0_9(): void
    {
        // 1111 paniers : A dans 161, B dans 22, ensemble 10 → support 0,9 %, conf 6,2 %, lift 3,14.
        $paniers = array_merge(
            $this->paniersProd(10, 'PA', 'PB'),
            $this->paniersProd(151, 'PA'),
            $this->paniersProd(12, 'PB'),
            $this->paniersProd(938)
        );
        $ctx = $this->ctx([$this->accroche(), $this->cible()], $paniers);
        $c = R5Duos::evaluer($ctx);

        self::assertCount(1, $c, 'Support 0,9 % ≥ 0,5 % et lift 3,1 > 1,0 : la VARIANTE déclenche.');
        self::assertSame(['PA', 'PB'], $c[0]['keys']);
        self::assertSame('faible', $c[0]['confiance']);
        $hyp = implode(' | ', $c[0]['hypotheses']);
        self::assertStringContainsString('Association rare mais cohérente : hypothèse', $hyp);
        self::assertStringContainsString('lift 3,14', $c[0]['pourquoi']);
        self::assertTrue(GuardRails::candidatValide($c[0], $this->parCle($ctx), Hyper::MARGE_MIN));
    }

    /**
     * CALIBRAGE PROD — paire eau+café (lift 1,54, support 1,1 %,
     * confiance 7 %) : refusée en FORTE, retenue en VARIANTE.
     */
    public function test_variante_paire_calibree_prod_lift_1_54_support_1_1(): void
    {
        // 1091 paniers : A dans 171, B dans 50, ensemble 12 → support 1,1 %, conf 7,0 %, lift 1,53.
        $paniers = array_merge(
            $this->paniersProd(12, 'PA', 'PB'),
            $this->paniersProd(159, 'PA'),
            $this->paniersProd(38, 'PB'),
            $this->paniersProd(882)
        );
        $ctx = $this->ctx([$this->accroche(), $this->cible()], $paniers);
        $c = R5Duos::evaluer($ctx);

        self::assertCount(1, $c);
        self::assertSame(['PA', 'PB'], $c[0]['keys']);
        self::assertSame('faible', $c[0]['confiance']);
        self::assertStringContainsString('lift 1,53', $c[0]['pourquoi']);
    }

    /** CALIBRAGE PROD — Bueno_white+eau (lift 0,89) : association plus faible que le hasard → EXCLUE. */
    public function test_lift_0_89_exclu_anti_association(): void
    {
        // 1111 paniers : A dans 161, B dans 78, ensemble 10 → conf 6,2 %, P(B) 7,0 %, lift 0,88.
        $paniers = array_merge(
            $this->paniersProd(10, 'PA', 'PB'),
            $this->paniersProd(151, 'PA'),
            $this->paniersProd(68, 'PB'),
            $this->paniersProd(882)
        );
        $ctx = $this->ctx([$this->accroche(), $this->cible()], $paniers);
        self::assertSame([], R5Duos::evaluer($ctx), 'Lift < 1 : jamais de duo (anti-association).');
    }

    public function test_lift_exactement_1_2_passe_en_variante(): void
    {
        // Chaque produit dans 20/40 paniers, paires A|B et C|D à 12 co-occurrences : lift = 0,6/0,5 = 1,2 exactement.
        $paniers = array_merge(
            $this->paniers(12, 'PA', 'PB'),
            $this->paniers(8, 'PA', 'PC'),
            $this->paniers(8, 'PB', 'PD'),
            $this->paniers(12, 'PC', 'PD')
        );
        $ctx = $this->ctx([$this->accroche(), $this->cible(), $this->remplissageC(), $this->remplissageD()], $paniers);
        $c = R5Duos::evaluer($ctx);

        self::assertCount(1, $c, 'Lift 1,2 : pas FORTE (> 1,2 requis) mais VARIANTE (> 1,0) — C|D exclue (même catégorie).');
        self::assertSame(['PA', 'PB'], $c[0]['keys']);
        self::assertSame('faible', $c[0]['confiance']);
    }

    public function test_lift_exactement_1_0_exclu(): void
    {
        // 40 paniers multi : conf 0,6 (12/20), P(B) = 24/40 = 0,6 → lift 1,0 exactement : indépendance pure.
        $paniers = array_merge(
            $this->paniersProd(12, 'PA', 'PB'),
            $this->paniersProd(8, 'PA'),
            $this->paniersProd(12, 'PB'),
            $this->paniersProd(8)
        );
        $ctx = $this->ctx([$this->accroche(), $this->cible()], $paniers);
        self::assertSame([], R5Duos::evaluer($ctx));
    }

    public function test_forte_toujours_preferree_a_variante(): void
    {
        // FORTE A|B (lift 1,5, support 3 %) vs VARIANTE C|D (lift 5,0, support 0,6 %) : la FORTE gagne.
        $paniers = array_merge(
            $this->paniersProd(30, 'PA', 'PB'),
            $this->paniersProd(170, 'PA'),
            $this->paniersProd(70, 'PB'),
            $this->paniersProd(6, 'PC', 'PD2'),
            $this->paniersProd(54, 'PC'),
            $this->paniersProd(14, 'PD2'),
            $this->paniersProd(656)
        );
        $pc = RecoScenario::produit('PC', 'Chocolat', 'Snacks', qty30: 20.0, ca30: 30.0, prix: 1.5, cout: 1.0, stock: null, unitesPrevues7j: 5.0);
        $pd = RecoScenario::produit('PD2', 'Dattes', 'Boissons', qty30: 20.0, ca30: 30.0, prix: 2.0, cout: 1.5, stock: null, unitesPrevues7j: 5.0);
        $ctx = $this->ctx([$this->accroche(), $this->cible(), $pc, $pd], $paniers);
        $c = R5Duos::evaluer($ctx);

        self::assertCount(1, $c);
        self::assertSame(['PA', 'PB'], $c[0]['keys'], 'La paire FORTE est préférée même quand la VARIANTE a un lift supérieur.');
        self::assertSame('moyenne', $c[0]['confiance']);
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
