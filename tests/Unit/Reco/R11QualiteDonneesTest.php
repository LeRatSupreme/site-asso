<?php

declare(strict_types=1);

namespace Tests\Unit\Reco;

use App\Core\Compta\Reco\GuardRails;
use App\Core\Compta\Reco\Hyper;
use App\Core\Compta\Reco\R11QualiteDonnees;
use App\Core\Compta\Reco\RecoContext;
use App\Core\Compta\Reco\RecoProduct;
use PHPUnit\Framework\TestCase;

/**
 * R11 — Qualité des données : coûts suspects (ventes ou prévision),
 * doublons de noms normalisés, produits sans stock ; ordre coûts <
 * doublons < stocks, cap reco_r11_max, jamais de prix_effectifs.
 */
final class R11QualiteDonneesTest extends TestCase
{
    private const NOW = '2026-10-21 10:00:00';

    private function ctx(array $produits, ?array $forecast = null, array $parametres = []): RecoContext
    {
        return new RecoContext(
            RecoScenario::dataset($produits, [], forecast: $forecast, parametres: $parametres),
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

    private function forecastAvecAnomalies(array $labelsSansStock, array $prevus = []): array
    {
        $f = RecoScenario::forecastMinimal();
        $f['anomalies']['produits_sans_stock'] = $labelsSansStock;
        $f['produits'] = $prevus;

        return $f;
    }

    public function test_cout_inconnu_actif_produit_une_reco(): void
    {
        $produits = [
            RecoScenario::produit('P1', 'Café', qty30: 100.0, prix: 2.0, cout: 1.0),
            RecoScenario::produit('P2', 'Mystère', qty30: 30.0, ca30: 60.0, prix: 2.0, cout: null, coutRaison: 'cout_inconnu'),
        ];
        $ctx = $this->ctx($produits);
        $c = R11QualiteDonnees::evaluer($ctx);

        self::assertCount(1, $c);
        self::assertSame('R11', $c[0]['regle']);
        self::assertSame(['P2'], $c[0]['keys']);
        self::assertSame('haute', $c[0]['confiance']);
        self::assertSame(Hyper::EFFORTS['R11'], $c[0]['effort']);
        self::assertSame(['bas' => 0.0, 'median' => 0.0, 'haut' => 0.0], $c[0]['gain']);
        self::assertStringContainsString('Saisir le coût d\'achat de Mystère', $c[0]['action']);
        self::assertStringContainsString('aucune reco de prix ou promo', $c[0]['action']);
        self::assertStringContainsString('30', $c[0]['pourquoi']);
        self::assertStringContainsString('2,00 €', $c[0]['pourquoi']);
        self::assertArrayNotHasKey('prix_effectifs', $c[0]);
        self::assertTrue(GuardRails::candidatValide($c[0], $this->parCle($ctx), Hyper::MARGE_MIN));
    }

    public function test_produit_cout_inconnu_sans_ventes_nous_renvoie_rien(): void
    {
        $produits = [
            RecoScenario::produit('P1', 'Café', qty30: 100.0, prix: 2.0, cout: 1.0),
            RecoScenario::produit('P2', 'Mystère', qty30: 0.0, prix: 2.0, cout: null, coutRaison: 'cout_inconnu'),
        ];
        $ctx = $this->ctx($produits);
        self::assertSame([], R11QualiteDonnees::evaluer($ctx));
    }

    public function test_produit_sans_ventes_mais_prevu_est_signale(): void
    {
        $produits = [
            RecoScenario::produit('P1', 'Café', qty30: 100.0, prix: 2.0, cout: 1.0),
            RecoScenario::produit('P2', 'Mystère', qty30: 0.0, prix: 2.0, cout: null, coutRaison: 'cout_inconnu'),
        ];
        $f = $this->forecastAvecAnomalies([], [['key' => 'P2', 'label' => 'Mystère']]);
        $ctx = $this->ctx($produits, $f);
        $c = R11QualiteDonnees::evaluer($ctx);

        self::assertCount(1, $c);
        self::assertSame(['P2'], $c[0]['keys']);
        self::assertTrue(GuardRails::candidatValide($c[0], $this->parCle($ctx), Hyper::MARGE_MIN));
    }

    public function test_les_trois_raisons_de_cout_suspect_sont_traitees(): void
    {
        $produits = [
            RecoScenario::produit('P1', 'Café', qty30: 100.0, prix: 2.0, cout: 1.0),
            RecoScenario::produit('P2', 'Inconnu', qty30: 30.0, prix: 2.0, cout: null, coutRaison: 'cout_inconnu'),
            RecoScenario::produit('P3', 'Zero', qty30: 10.0, prix: 1.0, cout: 0.0, coutRaison: 'cout_zero'),
            RecoScenario::produit('P4', 'SousPrix', qty30: 5.0, prix: 1.0, cout: 2.0, coutRaison: 'prix_inferieur_au_cout'),
        ];
        $ctx = $this->ctx($produits);
        $c = R11QualiteDonnees::evaluer($ctx);

        self::assertCount(3, $c);
        self::assertSame(['P2'], $c[0]['keys']);
        self::assertSame(['P3'], $c[1]['keys']);
        self::assertSame(['P4'], $c[2]['keys']);
        foreach ($c as $cand) {
            self::assertArrayNotHasKey('prix_effectifs', $cand);
            self::assertTrue(GuardRails::candidatValide($cand, $this->parCle($ctx), Hyper::MARGE_MIN));
        }
    }

    public function test_ordre_couts_puis_doublons_puis_stocks_avec_cap_3(): void
    {
        $produits = [
            RecoScenario::produit('P1', 'Produit 1', qty30: 100.0, prix: 2.0, cout: 1.0),
            RecoScenario::produit('P2', 'Mystère', qty30: 30.0, prix: 2.0, cout: null, coutRaison: 'cout_inconnu'),
            RecoScenario::produit('P3', 'Coca  33cl', qty30: 10.0, prix: 2.0, cout: 1.0),
            RecoScenario::produit('P4', 'coca 33cl ', qty30: 10.0, prix: 2.0, cout: 1.0),
        ];
        $f = $this->forecastAvecAnomalies(['Produit 1']);
        $ctx = $this->ctx($produits, $f);
        $c = R11QualiteDonnees::evaluer($ctx);

        self::assertCount(Hyper::R11_MAX, $c);
        self::assertSame(['P2'], $c[0]['keys']);
        self::assertSame(['P3', 'P4'], $c[1]['keys']);
        self::assertSame(['P1'], $c[2]['keys']);
        self::assertStringContainsString('coca 33cl', $c[1]['titre']);
        self::assertStringContainsString('2 fiches', $c[1]['pourquoi']);
        foreach ($c as $cand) {
            self::assertArrayNotHasKey('prix_effectifs', $cand);
            self::assertTrue(GuardRails::candidatValide($cand, $this->parCle($ctx), Hyper::MARGE_MIN));
        }
    }

    public function test_cap_parametrable_reco_r11_max(): void
    {
        $produits = [
            RecoScenario::produit('P1', 'Produit 1', qty30: 100.0, prix: 2.0, cout: 1.0),
            RecoScenario::produit('P2', 'Mystère', qty30: 30.0, prix: 2.0, cout: null, coutRaison: 'cout_inconnu'),
            RecoScenario::produit('P3', 'Coca  33cl', qty30: 10.0, prix: 2.0, cout: 1.0),
            RecoScenario::produit('P4', 'coca 33cl ', qty30: 10.0, prix: 2.0, cout: 1.0),
        ];
        $f = $this->forecastAvecAnomalies(['Produit 1']);
        $ctx = $this->ctx($produits, $f, ['reco_r11_max' => 1]);
        $c = R11QualiteDonnees::evaluer($ctx);

        self::assertCount(1, $c);
        self::assertSame(['P2'], $c[0]['keys']);
    }

    public function test_cle_sans_stock_hors_catalogue_utilise_le_prefixe(): void
    {
        $produits = [
            RecoScenario::produit('P1', 'Café', qty30: 100.0, prix: 2.0, cout: 1.0),
        ];
        $f = $this->forecastAvecAnomalies(['Limonade']);
        $ctx = $this->ctx($produits, $f);
        $c = R11QualiteDonnees::evaluer($ctx);

        self::assertCount(1, $c);
        self::assertSame(['sans_stock:Limonade'], $c[0]['keys']);
        self::assertSame([['key' => 'sans_stock:Limonade', 'label' => 'Limonade']], $c[0]['produits']);

        $parCle = $this->parCle($ctx);
        $parCle['sans_stock:Limonade'] = RecoScenario::produit('sans_stock:Limonade', 'Limonade');
        self::assertTrue(GuardRails::candidatValide($c[0], $parCle, Hyper::MARGE_MIN));
    }

    public function test_aucun_probleme_aucun_candidat(): void
    {
        $produits = [
            RecoScenario::produit('P1', 'Café', qty30: 100.0, prix: 2.0, cout: 1.0),
            RecoScenario::produit('P2', 'Thé', qty30: 50.0, prix: 3.0, cout: 1.0),
        ];
        $ctx = $this->ctx($produits);
        self::assertSame([], R11QualiteDonnees::evaluer($ctx));
    }
}
