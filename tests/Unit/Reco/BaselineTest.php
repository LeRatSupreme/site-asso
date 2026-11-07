<?php

declare(strict_types=1);

namespace Tests\Unit\Reco;

use App\Core\Compta\Reco\RecommendationEngine;
use App\Core\Compta\Reco\RecoProduct;
use PHPUnit\Framework\TestCase;

/**
 * RecommendationEngine::baseline() : dowRatio appliqué depuis
 * forecast['jours_semaine'], somme ≈ Σ unités×prix×jours/7, bornes
 * lo/hi autour de l'attendu, facteur creuse propagé, jours fermés
 * exclus, produits sans prix fiable ou hors reco exclus.
 */
final class BaselineTest extends TestCase
{
    public function test_dow_ratio_applique_et_somme_invariante(): void
    {
        $ds = RecoScenario::dataset(self::produits(), [], [], 0.8, true, 30, self::forecast(n: [100.0, 200.0, 100.0, 100.0, 100.0]));
        $lignes = RecommendationEngine::baseline(['P1', 'P2'], $ds, new \DateTimeImmutable('2026-11-02'), 5); // lun 02/11 → ven 06/11

        self::assertCount(5, $lignes);
        self::assertSame(
            ['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06'],
            array_column($lignes, 'date'),
            '5 jours ouverts lun→ven, week-end exclu.'
        );

        // Σ dowRatio = (100+200+100+100+100)/120 = 5 → Σ attendu = 210×5/7 = 150 exact.
        $somme = array_sum(array_column($lignes, 'attendu'));
        self::assertEqualsWithDelta(210.0 * 5 / 7.0, $somme, $somme * 0.05, 'Σ attendu ≈ Σ unités×prix×jours/7 (5 %).');

        // dowRatio par jour : 30 × n/120 (Σ unités×prix = 140 + 70 = 210).
        self::assertEqualsWithDelta(25.0, $lignes[0]['attendu'], 1.0e-9, 'Lundi : 210/7 × 100/120.');
        self::assertEqualsWithDelta(50.0, $lignes[1]['attendu'], 1.0e-9, 'Mardi : 210/7 × 200/120.');

        foreach ($lignes as $l) {
            self::assertLessThanOrEqual($l['attendu'] + 1.0e-9, $l['lo'], 'lo ≤ attendu.');
            self::assertGreaterThanOrEqual($l['attendu'], $l['hi'], 'attendu ≤ hi.');
            self::assertSame(0.8, $l['facteur'], 'facteur = facteurCreuse du dataset.');
        }

        // bornes lo/hi : ratios produits agrégés = 0,8 / 1,2 (prevLo/prevHi = ±20 %).
        self::assertEqualsWithDelta(20.0, $lignes[0]['lo'], 1.0e-9);
        self::assertEqualsWithDelta(30.0, $lignes[0]['hi'], 1.0e-9);
        self::assertEqualsWithDelta(40.0, $lignes[1]['lo'], 1.0e-9);
        self::assertEqualsWithDelta(60.0, $lignes[1]['hi'], 1.0e-9);
    }

    public function test_jours_fermes_sautes_week_end_exclu(): void
    {
        $ds = RecoScenario::dataset(self::produits(), [], [], 1.0, false, 30, self::forecast());
        $lignes = RecommendationEngine::baseline(['P1', 'P2'], $ds, new \DateTimeImmutable('2026-11-02'), 7);

        self::assertCount(7, $lignes);
        $dates = array_column($lignes, 'date');
        self::assertNotContains('2026-11-07', $dates, 'Samedi fermé.');
        self::assertNotContains('2026-11-08', $dates, 'Dimanche fermé.');
        self::assertContains('2026-11-09', $dates, 'Le lundi suivant est le 6e jour ouvert.');
        self::assertSame('2026-11-09', $dates[5], 'Après ven 06/11, le prochain jour ouvert est lun 09/11.');
    }

    public function test_produits_sans_prix_fiable_ou_hors_reco_exclus(): void
    {
        $sansPrix = new RecoProduct('P3', 'Produit 3', 'Boissons', 500.0, 0.0, null, null, 'cout_inconnu', null, 1000.0, 800.0, 1200.0);
        $produits = array_merge(self::produits(), [$sansPrix]);
        $plat = [100.0, 100.0, 100.0, 100.0, 100.0];
        $ds = RecoScenario::dataset($produits, [], [], 1.0, false, 30, self::forecast($plat));

        // P3 (prix null) exclu même s'il est dans la reco.
        $avec = RecommendationEngine::baseline(['P1', 'P2', 'P3'], $ds, new \DateTimeImmutable('2026-11-02'), 1);
        self::assertEqualsWithDelta(30.0, $avec[0]['attendu'], 1.0e-9, 'Σ unités×prix = 210 → 30 €/jour plat.');

        // P2 exclu car absent de la liste de la reco.
        $seul = RecommendationEngine::baseline(['P1'], $ds, new \DateTimeImmutable('2026-11-02'), 1);
        self::assertEqualsWithDelta(20.0, $seul[0]['attendu'], 1.0e-9, 'P1 seul : 140/7 = 20 €/jour plat.');
        self::assertEqualsWithDelta(16.0, $seul[0]['lo'], 1.0e-9, 'Ratio lo P1 : 112/140 = 0,8.');
        self::assertEqualsWithDelta(24.0, $seul[0]['hi'], 1.0e-9, 'Ratio hi P1 : 168/140 = 1,2.');
    }

    public function test_profil_plat_dow_ratio_fallback_1(): void
    {
        $ds = RecoScenario::dataset(self::produits(), [], [], 1.0, false, 30, self::forecast(n: [100.0, 100.0, 100.0, 100.0, 100.0]));
        $lignes = RecommendationEngine::baseline(['P1', 'P2'], $ds, new \DateTimeImmutable('2026-11-02'), 5);

        foreach ($lignes as $l) {
            self::assertEqualsWithDelta(30.0, $l['attendu'], 1.0e-9, 'Profil plat : dowRatio = 1.0 → 210/7.');
            self::assertEqualsWithDelta(24.0, $l['lo'], 1.0e-9);
            self::assertEqualsWithDelta(36.0, $l['hi'], 1.0e-9);
        }
    }

    public function test_jours_semaine_absent_baseline_vide(): void
    {
        $ds = RecoScenario::dataset(self::produits(), [], [], 1.0, false, 30, ['horaire' => ['s' => array_fill(0, 24, 1.0)]]);
        self::assertSame([], RecommendationEngine::baseline(['P1'], $ds, new \DateTimeImmutable('2026-11-02'), 5));

        $zero = RecommendationEngine::baseline(['P1'], $ds, new \DateTimeImmutable('2026-11-02'), 0);
        self::assertSame([], $zero);
    }

    /**
     * Deux produits de la reco : P1 (70 u. × 2 € = 140, lo 112, hi 168) et
     * P2 (35 u. × 2 € = 70, lo 56, hi 84) → Σ attendu 210, ratios 0,8/1,2.
     *
     * @return list<RecoProduct>
     */
    private static function produits(): array
    {
        return [
            new RecoProduct('P1', 'Produit 1', 'Boissons', 100.0, 200.0, 2.0, 1.0, null, null, 70.0, 56.0, 84.0),
            new RecoProduct('P2', 'Produit 2', 'Snacks', 50.0, 100.0, 2.0, 1.0, null, null, 35.0, 28.0, 42.0),
        ];
    }

    /**
     * forecast minimal : jours_semaine lun–ven ouverts avec n irréguliers,
     * sam/dim fermés.
     *
     * @param list<float> $n Transactions attendues par dow ouvert.
     *
     * @return array<string,mixed>
     */
    private static function forecast(array $n = [100.0, 200.0, 100.0, 100.0, 100.0]): array
    {
        $labels = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
        $js = [];
        for ($dow = 0; $dow < 7; $dow++) {
            $js[] = [
                'dow' => $dow,
                'label' => $labels[$dow],
                'n' => $dow <= 4 ? $n[$dow] : 0.0,
                'ca' => 0.0,
                'occ' => 0,
                'is_open' => $dow <= 4,
                'obs_ca' => null,
                'obs_ca_creuse' => null,
                'obs_n_creuse' => null,
                'se' => null,
                'next_date' => null,
            ];
        }

        return ['jours_semaine' => $js];
    }
}
