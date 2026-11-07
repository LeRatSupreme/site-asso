<?php

declare(strict_types=1);

namespace Tests\Unit\Reco;

use App\Core\Compta\Reco\GuardRails;
use App\Core\Compta\Reco\Hyper;
use App\Core\Compta\Reco\R8Gamme;
use App\Core\Compta\Reco\RecoContext;
use App\Core\Compta\Reco\RecoProduct;
use PHPUnit\Framework\TestCase;

/**
 * R8 — Fin de gamme : strict > sur l'immobilisé (30,00 € → non,
 * 30,01 € → oui), strict < sur la demande désaisonnalisée (3,0 u → non),
 * gains = hypothèse d'écoulement, garde-fous.
 */
final class R8GammeTest extends TestCase
{
    private const NOW = '2026-10-21 10:00:00';

    private function ctx(array $produits, float $facteur = 1.0, bool $creuse = false): RecoContext
    {
        return new RecoContext(
            RecoScenario::dataset($produits, [], facteurCreuse: $facteur, periodeCreuseActive: $creuse),
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

    public function test_immobilise_30_euros_exactement_ne_declenche_pas(): void
    {
        $p = RecoScenario::produit('P9', 'Sirop', qty30: 2.0, ca30: 10.0, prix: 5.0, cout: 1.0, stock: 30);
        $ctx = $this->ctx([$p]);
        self::assertSame([], R8Gamme::evaluer($ctx));
    }

    public function test_immobilise_30_01_euros_declenche(): void
    {
        $p = RecoScenario::produit('P9', 'Sirop', qty30: 2.0, ca30: 10.0, prix: 0.02, cout: 0.01, stock: 3001);
        $ctx = $this->ctx([$p]);
        $c = R8Gamme::evaluer($ctx);

        self::assertCount(1, $c);
        self::assertSame('R8', $c[0]['regle']);
        self::assertSame(['P9'], $c[0]['keys']);
        self::assertStringContainsString('30,01', $c[0]['pourquoi']);
        self::assertStringContainsString('2,0', $c[0]['pourquoi']);

        $marge = 0.02 - 0.01;
        self::assertEqualsWithDelta(3001.0 * $marge * R8Gamme::ECOULEMENT_PCT_MEDIAN, $c[0]['gain']['median'], 1.0e-9);
        self::assertEqualsWithDelta(0.0, $c[0]['gain']['bas'], 1.0e-12);
        self::assertEqualsWithDelta(3001.0 * $marge * R8Gamme::ECOULEMENT_PCT_HAUT, $c[0]['gain']['haut'], 1.0e-9);

        self::assertTrue(GuardRails::candidatValide($c[0], $this->parCle($ctx), Hyper::MARGE_MIN));
    }

    public function test_trois_unites_exactement_ne_declenche_pas(): void
    {
        $p = RecoScenario::produit('P10', 'Kombo', qty30: 3.0, ca30: 6.0, prix: 2.0, cout: 1.0, stock: 40);
        $ctx = $this->ctx([$p]);
        self::assertSame([], R8Gamme::evaluer($ctx));
    }

    public function test_declenche_avec_gain_et_action_conformes(): void
    {
        $p = RecoScenario::produit('P8', 'Kombucha', qty30: 2.5, ca30: 12.5, prix: 5.0, cout: 2.0, stock: 20);
        $ctx = $this->ctx([$p]);
        $c = R8Gamme::evaluer($ctx);

        self::assertCount(1, $c);
        self::assertSame(Hyper::EFFORTS['R8'], $c[0]['effort']);
        self::assertSame('moyenne', $c[0]['confiance']);
        self::assertStringContainsString('Réduire ou écouler Kombucha', $c[0]['action']);
        self::assertStringContainsString('2,5', $c[0]['pourquoi']);
        self::assertStringContainsString('40,00', $c[0]['pourquoi']);
        self::assertEqualsWithDelta(20.0 * 3.0 * R8Gamme::ECOULEMENT_PCT_MEDIAN, $c[0]['gain']['median'], 1.0e-9);
        self::assertEqualsWithDelta(0.0, $c[0]['gain']['bas'], 1.0e-12);
        self::assertEqualsWithDelta(20.0 * 3.0 * R8Gamme::ECOULEMENT_PCT_HAUT, $c[0]['gain']['haut'], 1.0e-9);
        self::assertArrayNotHasKey('prix_effectifs', $c[0]);

        self::assertTrue(GuardRails::candidatValide($c[0], $this->parCle($ctx), Hyper::MARGE_MIN));
    }

    public function test_la_desaisonnalisation_compte_pour_la_demande(): void
    {
        $p = RecoScenario::produit('P8', 'Kombucha', qty30: 4.0, ca30: 12.0, prix: 3.0, cout: 1.0, stock: 40);

        $ctxCreuse = $this->ctx([$p], facteur: 2.0, creuse: true);
        $c = R8Gamme::evaluer($ctxCreuse);
        self::assertCount(1, $c);
        self::assertTrue(GuardRails::candidatValide($c[0], $this->parCle($ctxCreuse), Hyper::MARGE_MIN));

        $ctxNeutre = $this->ctx([$p]);
        self::assertSame([], R8Gamme::evaluer($ctxNeutre));
    }

    public function test_produits_non_qualifies_ignores(): void
    {
        $produits = [
            RecoScenario::produit('PA', 'Non suivi', qty30: 2.0, prix: 5.0, cout: 1.0, stock: null),
            RecoScenario::produit('PB', 'Rupture', qty30: 2.0, prix: 5.0, cout: 1.0, stock: 0),
            RecoScenario::produit('PC', 'Sans coût', qty30: 2.0, prix: 5.0, cout: null, coutRaison: 'cout_inconnu', stock: 40),
            RecoScenario::produit('PD', 'Marge nulle', qty30: 2.0, prix: 1.0, cout: 1.0, stock: 40),
        ];
        $ctx = $this->ctx($produits);
        self::assertSame([], R8Gamme::evaluer($ctx));
    }
}
