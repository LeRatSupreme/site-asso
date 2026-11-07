<?php

declare(strict_types=1);

namespace Tests\Unit\Reco;

use App\Core\Compta\Reco\GuardRails;
use App\Core\Compta\Reco\Hyper;
use App\Core\Compta\Reco\R10Objectif;
use App\Core\Compta\Reco\RecoProduct;
use PHPUnit\Framework\TestCase;

/**
 * R10 — Objectif hebdo en risque : seuil de probabilité (0,50 → null,
 * 0,49 → candidat), 2 candidats sources requis, tri par gain médian,
 * fusion exacte des gains / prix / hypothèses, empreinte déléguée.
 */
final class R10ObjectifTest extends TestCase
{
    /** @param list<array{key:string,prix:float}> $prix */
    private static function source(
        string $regle,
        string $key,
        string $titre,
        float $bas,
        float $median,
        float $haut,
        array $prix = [],
        array $hypotheses = []
    ): array {
        return [
            'regle'           => $regle,
            'keys'            => [$key],
            'produits'        => [['key' => $key, 'label' => 'Produit ' . $key]],
            'titre'           => $titre,
            'pourquoi'        => 'pourquoi ' . $key,
            'action'          => 'action ' . $key,
            'gain'            => ['bas' => $bas, 'median' => $median, 'haut' => $haut],
            'confiance'       => 'moyenne',
            'effort'          => 1,
            'hypotheses'      => $hypotheses,
            'comment_mesurer' => 'mesure ' . $key,
            'prix_effectifs'  => $prix,
        ];
    }

    /** Semaine de forecastMinimal avec la probabilité d'atteinte imposée. */
    private static function semaine(float $prob): array
    {
        $s = RecoScenario::forecastMinimal()['semaine'];
        $s['prob_atteinte'] = $prob;

        return $s;
    }

    /** Catalogue propre pour les garde-fous (P1, P2, P3, P4 à marge saine). */
    private function parCle(): array
    {
        $map = [];
        foreach (['P1', 'P2', 'P3', 'P4'] as $k) {
            $map[$k] = RecoScenario::produit($k, 'Produit ' . $k, prix: 2.0, cout: 1.0);
        }

        return $map;
    }

    public function test_prob_0_50_exactement_renvoie_null(): void
    {
        $sources = [
            self::source('R1', 'P1', 'Hausse café', 10.0, 20.0, 30.0),
            self::source('R4', 'P2', 'Duo thé', 5.0, 15.0, 25.0),
        ];
        self::assertNull(R10Objectif::construire(self::semaine(0.50), $sources, fn (string $r, array $k): string => ''));
    }

    public function test_prob_0_49_produit_le_candidat_fusionne(): void
    {
        $s1 = self::source('R1', 'P1', 'Hausse café', 10.0, 20.0, 30.0, [['key' => 'P1', 'prix' => 2.3]], ['h1', 'h2', 'h3']);
        $s2 = self::source('R4', 'P2', 'Duo thé', 5.0, 15.0, 25.0, [['key' => 'P2', 'prix' => 2.2]], ['h2', 'h4', 'h5']);
        $recu = null;
        $fp = function (string $r, array $k) use (&$recu): string {
            $recu = [$r, $k];

            return $r . '|' . implode(',', $k);
        };

        $c = R10Objectif::construire(self::semaine(0.49), [$s1, $s2], $fp);

        self::assertNotNull($c);
        self::assertSame('R10', $c['regle']);
        self::assertSame(['P1', 'P2'], $c['keys']);
        self::assertSame('Objectif hebdo en risque', $c['titre']);
        self::assertStringContainsString('49,0', $c['pourquoi']);
        self::assertStringContainsString('14,30', $c['pourquoi']);
        self::assertStringContainsString('Hausse café', $c['action']);
        self::assertStringContainsString('puis Duo thé', $c['action']);
        self::assertStringContainsString('7 jours restants', $c['action']);
        self::assertEqualsWithDelta(15.0, $c['gain']['bas'], 1.0e-12);
        self::assertEqualsWithDelta(35.0, $c['gain']['median'], 1.0e-12);
        self::assertEqualsWithDelta(55.0, $c['gain']['haut'], 1.0e-12);
        self::assertSame('moyenne', $c['confiance']);
        self::assertSame(Hyper::EFFORTS['R10'], $c['effort']);
        self::assertSame(['h1', 'h2', 'h3', 'h4'], $c['hypotheses']);
        self::assertSame([['key' => 'P1', 'prix' => 2.3], ['key' => 'P2', 'prix' => 2.2]], $c['prix_effectifs']);
        self::assertStringContainsString('objectif', $c['comment_mesurer']);
        self::assertSame(['R10', ['P1', 'P2']], $recu);
        self::assertSame('R10|P1,P2', $c['fingerprint']);

        self::assertTrue(GuardRails::candidatValide($c, $this->parCle(), Hyper::MARGE_MIN));
    }

    public function test_prob_min_personnalisable(): void
    {
        $sources = [
            self::source('R1', 'P1', 'A', 1.0, 2.0, 3.0),
            self::source('R4', 'P2', 'B', 1.0, 2.0, 3.0),
        ];
        self::assertNull(R10Objectif::construire(self::semaine(0.35), $sources, fn (string $r, array $k): string => '', 0.30));
        self::assertNotNull(R10Objectif::construire(self::semaine(0.35), $sources, fn (string $r, array $k): string => '', 0.40));
    }

    public function test_moins_de_deux_candidats_renvoie_null(): void
    {
        $s1 = self::source('R1', 'P1', 'A', 1.0, 2.0, 3.0);
        self::assertNull(R10Objectif::construire(self::semaine(0.49), [$s1], fn (string $r, array $k): string => ''));
        self::assertNull(R10Objectif::construire(self::semaine(0.49), [], fn (string $r, array $k): string => ''));
        self::assertNull(R10Objectif::construire(null, [$s1, $s1], fn (string $r, array $k): string => ''));
    }

    public function test_seules_les_regles_r1_r4_r5_r6_sont_eligibles(): void
    {
        $sources = [
            self::source('R2', 'P3', 'Dormant', 900.0, 999.0, 1000.0),
            self::source('R1', 'P1', 'A', 1.0, 20.0, 3.0),
            self::source('R4', 'P2', 'B', 1.0, 10.0, 3.0),
        ];
        $c = R10Objectif::construire(self::semaine(0.49), $sources, fn (string $r, array $k): string => '');

        self::assertNotNull($c);
        self::assertSame(['P1', 'P2'], $c['keys']);
        self::assertEqualsWithDelta(30.0, $c['gain']['median'], 1.0e-12);
    }

    public function test_tri_par_gain_median_descendant(): void
    {
        $sources = [
            self::source('R1', 'P1', 'Petit', 1.0, 10.0, 20.0),
            self::source('R4', 'P2', 'Gros', 1.0, 30.0, 60.0),
            self::source('R5', 'P3', 'Moyen', 1.0, 20.0, 40.0),
        ];
        $c = R10Objectif::construire(self::semaine(0.49), $sources, fn (string $r, array $k): string => '');

        self::assertNotNull($c);
        self::assertSame(['P2', 'P3'], $c['keys']);
        self::assertStringContainsString('Gros', $c['action']);
        self::assertTrue(strpos($c['action'], 'Gros') < strpos($c['action'], 'Moyen'));
        self::assertEqualsWithDelta(50.0, $c['gain']['median'], 1.0e-12);
    }

    public function test_hypotheses_fusionnees_plafonnees_a_quatre(): void
    {
        $s1 = self::source('R1', 'P1', 'A', 1.0, 20.0, 3.0, [], ['a', 'b', 'c']);
        $s2 = self::source('R4', 'P2', 'B', 1.0, 15.0, 3.0, [], ['b', 'd', 'e', 'f']);
        $c = R10Objectif::construire(self::semaine(0.49), [$s1, $s2], fn (string $r, array $k): string => '');

        self::assertNotNull($c);
        self::assertSame(['a', 'b', 'c', 'd'], $c['hypotheses']);
    }

    public function test_semaine_illisible_renvoie_null(): void
    {
        $sources = [
            self::source('R1', 'P1', 'A', 1.0, 2.0, 3.0),
            self::source('R4', 'P2', 'B', 1.0, 2.0, 3.0),
        ];
        self::assertNull(R10Objectif::construire(['prob_atteinte' => '0,49'], $sources, fn (string $r, array $k): string => ''));
    }
}
