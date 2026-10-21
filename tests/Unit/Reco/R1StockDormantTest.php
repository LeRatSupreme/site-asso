<?php

declare(strict_types=1);

namespace Tests\Unit\Reco;

use App\Core\Compta\Reco\GuardRails;
use App\Core\Compta\Reco\Hyper;
use App\Core\Compta\Reco\R1StockDormant;
use App\Core\Compta\Reco\RecoContext;
use App\Core\Compta\Reco\RecoProduct;
use PHPUnit\Framework\TestCase;

/**
 * R1 — Stock dormant : déclenchement d'après la PRÉVISION (pas du
 * passé), limites de seuils (8,0 / 8,05 semaines ; stock 10 / 11),
 * échantillon minimal, garde-fou 1 (remise plafonnée, lot 3 pour 2,
 * aucun candidat), consigne datée jeudi→vendredi (semaine courante ou
 * suivante selon le jour de l'horloge figée).
 */
final class R1StockDormantTest extends TestCase
{
    private const NOW = '2026-10-21 10:00:00';

    private function ctx(array $produits, array $parametres = []): RecoContext
    {
        return new RecoContext(
            RecoScenario::dataset($produits, [], [], 1.0, false, 30, null, [], $parametres),
            new \DateTimeImmutable(self::NOW)
        );
    }

    /** Produit dormant de référence : 200 u./30 j mais 1 u./sem prévue, stock 20, prix 2 €, coût 1 €. */
    private function dormant(float $up7 = 1.0, int $stock = 20, float $qty30 = 200.0): RecoProduct
    {
        return RecoScenario::produit('P1', 'Coca', qty30: $qty30, ca30: $qty30 * 2.0, prix: 2.0, cout: 1.0, stock: $stock, unitesPrevues7j: $up7, prevLo: $up7 / 2.0, prevHi: $up7 * 2.0);
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

    public function test_stock_dormant_declenche_promo_quinze_pour_cent(): void
    {
        $ctx = $this->ctx([$this->dormant()]);
        $c = R1StockDormant::evaluer($ctx);

        self::assertCount(1, $c);
        self::assertSame('R1', $c[0]['regle']);
        self::assertSame(['P1'], $c[0]['keys']);
        self::assertSame('Offre fin de semaine : Coca', $c[0]['titre']);
        // Mercredi 21/10 → offre jeudi 22/10 au vendredi 23/10 (semaine courante).
        self::assertSame('2026-10-22', $c[0]['debut']);
        self::assertSame('2026-10-23', $c[0]['fin']);
        $g = $c[0]['gain'];
        // Surplus 20 − 8×1 = 12 u. → 12/4 semaines × (1,70 − 1) = 2,1 €/sem.
        self::assertEqualsWithDelta(0.7, $g['bas'], 1.0e-9);
        self::assertEqualsWithDelta(2.1, $g['median'], 1.0e-9);
        self::assertEqualsWithDelta(2.8, $g['haut'], 1.0e-9);
        self::assertEqualsWithDelta(1.7, $c[0]['prix_effectifs'][0]['prix'], 1.0e-9);
        self::assertSame('P1', $c[0]['prix_effectifs'][0]['key']);
        // Consigne : produit, prix promo, ancien prix, remise, marge, emplacement.
        self::assertStringContainsString('-15,0 %', $c[0]['action']);
        self::assertStringContainsString('Du jeu. 22/10 au ven. 23/10', $c[0]['action']);
        self::assertStringContainsString('Coca à 1,70 € au lieu de 2,00 €', $c[0]['action']);
        self::assertStringContainsString('marge 0,70 €/unité', $c[0]['action']);
        self::assertStringContainsString('Affichez-le près de la caisse', $c[0]['action']);
        self::assertStringContainsString('20,0 sem de stock', $c[0]['pourquoi']);
        self::assertStringContainsString('1,0 u./sem prévues', $c[0]['pourquoi']);
        $hyp = implode(' | ', $c[0]['hypotheses']);
        self::assertStringContainsString('péremption', $hyp);
        self::assertStringContainsString('hypothèse', $hyp);
        self::assertSame('Marge hebdo du produit pendant la promo vs baseline 30 j désaisonnalisée', $c[0]['comment_mesurer']);
        self::assertSame('moyenne', $c[0]['confiance']);
        self::assertSame(Hyper::EFFORTS['R1'], $c[0]['effort']);
        self::assertTrue(GuardRails::candidatValide($c[0], $this->parCle($ctx), Hyper::MARGE_MIN));
    }

    public function test_mardi_offre_sur_la_semaine_courante(): void
    {
        // Mardi 20/10/2026 (dow 2 ≤ 4) → jeudi 22/10, vendredi 23/10.
        $ctx = new RecoContext(
            RecoScenario::dataset([$this->dormant()]),
            RecoScenario::horloge('2026-10-20 09:00:00')
        );
        $c = R1StockDormant::evaluer($ctx);

        self::assertCount(1, $c);
        self::assertSame('2026-10-22', $c[0]['debut']);
        self::assertSame('2026-10-23', $c[0]['fin']);
        self::assertStringContainsString('Du jeu. 22/10 au ven. 23/10', $c[0]['action']);
    }

    public function test_samedi_offre_sur_la_semaine_suivante(): void
    {
        // Samedi 24/10/2026 (dow 6 > 4) → jeudi 29/10, vendredi 30/10.
        $ctx = new RecoContext(
            RecoScenario::dataset([$this->dormant()]),
            RecoScenario::horloge('2026-10-24 09:00:00')
        );
        $c = R1StockDormant::evaluer($ctx);

        self::assertCount(1, $c);
        self::assertSame('2026-10-29', $c[0]['debut']);
        self::assertSame('2026-10-30', $c[0]['fin']);
        self::assertStringContainsString('Du jeu. 29/10 au ven. 30/10', $c[0]['action']);
    }

    public function test_semaines_exactement_8_ne_declenchent_pas(): void
    {
        // 20 u. ÷ 2,5 u./sem = 8,0 semaines exactement (strictement au-dessus requis).
        $ctx = $this->ctx([$this->dormant(up7: 2.5, stock: 20)]);
        self::assertSame([], R1StockDormant::evaluer($ctx));
    }

    public function test_semaines_8_05_declenchent(): void
    {
        // 161 u. ÷ 20 u./sem = 8,05 semaines → déclenché.
        $ctx = $this->ctx([$this->dormant(up7: 20.0, stock: 161)]);
        self::assertCount(1, R1StockDormant::evaluer($ctx));
    }

    public function test_stock_10_ne_declenche_pas(): void
    {
        // Stock > 10 requis strictement.
        $ctx = $this->ctx([$this->dormant(stock: 10)]);
        self::assertSame([], R1StockDormant::evaluer($ctx));
    }

    public function test_stock_11_avec_prevision_faible_declenche(): void
    {
        // 11 u. ÷ 1 u./sem = 11 semaines > 8.
        $ctx = $this->ctx([$this->dormant(up7: 1.0, stock: 11)]);
        self::assertCount(1, R1StockDormant::evaluer($ctx));
    }

    public function test_la_prevision_domine_le_passe_gros_stock_peu_prevu(): void
    {
        // 200 u. vendues sur 30 j mais 1 seule prévue par semaine : 20 semaines de stock → dormant.
        $ctx = $this->ctx([$this->dormant(up7: 1.0, stock: 20, qty30: 200.0)]);
        self::assertCount(1, R1StockDormant::evaluer($ctx));
    }

    public function test_la_prevision_domine_le_passe_forte_prevision_ne_declenche_pas(): void
    {
        // 60 u. en stock mais 40 prévues par semaine : 1,5 semaine → pas dormant.
        $ctx = $this->ctx([$this->dormant(up7: 40.0, stock: 60, qty30: 2.0)]);
        self::assertSame([], R1StockDormant::evaluer($ctx));
    }

    public function test_sans_prevision_avec_ventes_le_stock_est_dormant(): void
    {
        $p = RecoScenario::produit('P1', 'Coca', qty30: 200.0, ca30: 400.0, prix: 2.0, cout: 1.0, stock: 20, unitesPrevues7j: 0.0, prevLo: 0.0, prevHi: 0.0);
        $ctx = $this->ctx([$p]);
        $c = R1StockDormant::evaluer($ctx);

        self::assertCount(1, $c);
        self::assertStringContainsString('sans demande prévue', $c[0]['pourquoi']);
        self::assertTrue(GuardRails::candidatValide($c[0], $this->parCle($ctx), Hyper::MARGE_MIN));
    }

    public function test_sans_prevision_sans_ventes_rien(): void
    {
        $p = RecoScenario::produit('P1', 'Coca', qty30: 0.0, ca30: 0.0, prix: 2.0, cout: 1.0, stock: 20, unitesPrevues7j: 0.0, prevLo: 0.0, prevHi: 0.0);
        $ctx = $this->ctx([$p]);
        self::assertSame([], R1StockDormant::evaluer($ctx));
    }

    public function test_quantite_sous_echantillon_ne_declenche_pas(): void
    {
        // 19 u./30 j < 20 (échantillon minimal).
        $ctx = $this->ctx([$this->dormant(qty30: 19.0)]);
        self::assertSame([], R1StockDormant::evaluer($ctx));
    }

    public function test_cout_suspect_ne_declenche_pas(): void
    {
        $p = RecoScenario::produit('P1', 'Coca', qty30: 200.0, ca30: 400.0, prix: 2.0, cout: 0.0, coutRaison: 'cout_zero', stock: 20, unitesPrevues7j: 1.0, prevLo: 0.5, prevHi: 2.0);
        $ctx = $this->ctx([$p]);
        self::assertSame([], R1StockDormant::evaluer($ctx));
    }

    public function test_remise_plafonnee_par_la_marge_minimale(): void
    {
        // Prix 2 €, coût 1,50 € : remiseMax = 1 − 1,15×1,5/2 = 0,1375 < cible 0,15.
        $p = RecoScenario::produit('P1', 'Yaourt', qty30: 200.0, ca30: 400.0, prix: 2.0, cout: 1.5, stock: 20, unitesPrevues7j: 1.0, prevLo: 0.5, prevHi: 2.0);
        $ctx = $this->ctx([$p]);
        $c = R1StockDormant::evaluer($ctx);

        self::assertCount(1, $c);
        // Prix effectif exactement au plancher : 2 × (1 − 0,1375) = 1,725 = 1,5 × 1,15.
        self::assertEqualsWithDelta(1.725, $c[0]['prix_effectifs'][0]['prix'], 1.0e-9);
        self::assertEqualsWithDelta(12.0 / 4.0 * 0.225, $c[0]['gain']['median'], 1.0e-9);
        self::assertTrue(GuardRails::candidatValide($c[0], $this->parCle($ctx), Hyper::MARGE_MIN));
    }

    public function test_lot_3_pour_2_quand_la_remise_est_refusee(): void
    {
        // Remise bas relevée à 0,35 : remiseMax (0,3445) la refuse, mais 2/3 ≥ 1,15×1,14/2.
        $p = RecoScenario::produit('P1', 'Eau', qty30: 200.0, ca30: 400.0, prix: 2.0, cout: 1.14, stock: 20, unitesPrevues7j: 1.0, prevLo: 0.5, prevHi: 2.0);
        $ctx = $this->ctx([$p], ['reco_remise_dormant_bas' => 0.35]);
        $c = R1StockDormant::evaluer($ctx);

        self::assertCount(1, $c);
        self::assertEqualsWithDelta(2.0 * 2.0 / 3.0, $c[0]['prix_effectifs'][0]['prix'], 1.0e-9);
        self::assertStringContainsString('3 pour 2', $c[0]['action']);
        $median = 12.0 / R1StockDormant::DUREE_ECOULEMENT_SEMAINES * (2.0 * 2.0 / 3.0 - 1.14);
        self::assertEqualsWithDelta($median, $c[0]['gain']['median'], 1.0e-9);
        self::assertTrue(GuardRails::candidatValide($c[0], $this->parCle($ctx), Hyper::MARGE_MIN));
    }

    public function test_aucun_candidat_quand_ni_remise_ni_lot(): void
    {
        // Prix 2,20 €, coût 2 € : remiseMax = −0,045 < 0,10 et (1,15×2)/2,2 > 2/3 → rien.
        $p = RecoScenario::produit('P1', 'Brioche', qty30: 200.0, ca30: 440.0, prix: 2.2, cout: 2.0, stock: 20, unitesPrevues7j: 1.0, prevLo: 0.5, prevHi: 2.0);
        $ctx = $this->ctx([$p]);
        self::assertSame([], R1StockDormant::evaluer($ctx));
    }

    public function test_gain_toujours_ordonne_et_positif(): void
    {
        $ctx = $this->ctx([$this->dormant()]);
        $c = R1StockDormant::evaluer($ctx);
        $g = $c[0]['gain'];

        self::assertGreaterThanOrEqual(0.0, $g['bas']);
        self::assertTrue($g['bas'] <= $g['median'] + 1.0e-12);
        self::assertTrue($g['median'] <= $g['haut'] + 1.0e-12);
    }

    public function test_fourchette_non_plate_avec_prev_lo_hi(): void
    {
        // 100 u. en stock, 5 u./sem prévues (20 sem), intervalle [3 ; 8] :
        // surplus 76/60/36 u. ÷ 4 sem × 0,70 € (prix remisé 1,70 − coût 1).
        $p = RecoScenario::produit('P1', 'Coca', qty30: 200.0, ca30: 400.0, prix: 2.0, cout: 1.0, stock: 100, unitesPrevues7j: 5.0, prevLo: 3.0, prevHi: 8.0);
        $ctx = $this->ctx([$p]);
        $c = R1StockDormant::evaluer($ctx);

        self::assertCount(1, $c);
        $g = $c[0]['gain'];
        self::assertLessThan($g['median'] - 1.0e-9, $g['bas'], 'bas = surplus(prevHi) < surplus(prev).');
        self::assertLessThan($g['haut'] - 1.0e-9, $g['median'], 'median < haut = surplus(prevLo).');
        self::assertEqualsWithDelta(6.3, $g['bas'], 1.0e-9);
        self::assertEqualsWithDelta(10.5, $g['median'], 1.0e-9);
        self::assertEqualsWithDelta(13.3, $g['haut'], 1.0e-9);
        self::assertStringNotContainsString(
            'Fourchette non différenciée',
            implode(' | ', $c[0]['hypotheses']),
            'Intervalle disponible : pas de note de dégradation.'
        );
        self::assertTrue(GuardRails::candidatValide($c[0], $this->parCle($ctx), Hyper::MARGE_MIN));
    }

    public function test_prev_lo_egale_prev_hi_fourchette_plate_et_hypothese(): void
    {
        // Intervalle dégénéré [5 ; 5] : fourchette plate assumée + hypothèse explicite.
        $p = RecoScenario::produit('P1', 'Coca', qty30: 200.0, ca30: 400.0, prix: 2.0, cout: 1.0, stock: 100, unitesPrevues7j: 5.0, prevLo: 5.0, prevHi: 5.0);
        $ctx = $this->ctx([$p]);
        $c = R1StockDormant::evaluer($ctx);

        self::assertCount(1, $c);
        $g = $c[0]['gain'];
        self::assertEqualsWithDelta(10.5, $g['median'], 1.0e-9);
        self::assertEqualsWithDelta($g['median'], $g['bas'], 1.0e-9);
        self::assertEqualsWithDelta($g['median'], $g['haut'], 1.0e-9);
        self::assertStringContainsString(
            'Fourchette non différenciée : intervalle de prévision indisponible',
            implode(' | ', $c[0]['hypotheses'])
        );
        self::assertTrue(GuardRails::candidatValide($c[0], $this->parCle($ctx), Hyper::MARGE_MIN));
    }
}
