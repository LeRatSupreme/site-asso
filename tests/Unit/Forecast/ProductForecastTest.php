<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Core\Compta\Forecast\DayCurrent;
use App\Core\Compta\Forecast\DemandModel;
use App\Core\Compta\Forecast\Hyper;
use App\Core\Compta\Forecast\ProductStat;
use App\Core\Compta\Forecast\ProductForecast;
use PHPUnit\Framework\TestCase;

/**
 * Prévision d'unités par produit : parts de Dirichlet (produits rares
 * non nuls), incertitude Poisson (lo ≤ unites ≤ hi), safety_demand au
 * quantile 0.90, suggestion = max(0, safety − stock), filtre
 * qty > 0 OU suggestion > 0, tri suggestion desc puis label,
 * déterminisme par graine.
 */
final class ProductForecastTest extends TestCase
{
    public function test_unites_avec_incertitude_poisson(): void
    {
        $m = DemandModel::fit(self::serie(), [], Hyper::K_POOL);
        $produits = [
            new ProductStat('a', 'Eau', 'Boissons', 60, 120.0, 2.0, 1.0, false, 10),
            new ProductStat('b', 'Chips', 'Snacks', 30, 60.0, 2.0, 1.0, false, 100),
            new ProductStat('c', 'Pain', 'Boulangerie', 10, 20.0, 2.0, 1.0, false, 5),
            new ProductStat('d', 'Nouveauté', 'Divers', 0, 0.0, 2.0, 1.0, false, 0),
        ];

        $r1 = ProductForecast::units($produits, $m, 0.2, 7, new \Random\Randomizer(new \Random\Engine\Mt19937(7)), 1000);
        $r2 = ProductForecast::units($produits, $m, 0.2, 7, new \Random\Randomizer(new \Random\Engine\Mt19937(7)), 1000);

        self::assertSame($r1, $r2, 'Même graine → sortie identique.');
        self::assertCount(4, $r1, 'Le produit rare (qty 0, stock 0) reste inclus via sa suggestion.');

        $parCle = [];
        foreach ($r1 as $row) {
            $parCle[$row['key']] = $row;
        }
        self::assertEqualsWithDelta(61 / 104, $parCle['a']['part'], 1.0e-9, 'Dirichlet : (60+1)/(100+4).');
        self::assertEqualsWithDelta(1 / 104, $parCle['d']['part'], 1.0e-9, 'Produit jamais vendu : part (0+1)/(100+4) > 0.');

        foreach ($parCle as $row) {
            self::assertLessThanOrEqual($row['hi'] + 1.0e-9, $row['unites'], 'Moyenne ≤ quantile 0.90.');
            self::assertGreaterThanOrEqual($row['lo'] - 1.0e-9, $row['unites'], 'Moyenne ≥ quantile 0.10.');
            self::assertGreaterThanOrEqual(0, $row['safety_demand']);
            $attendu = $row['stock'] !== null ? max(0, $row['safety_demand'] - $row['stock']) : null;
            self::assertSame($attendu, $row['suggestion']);
        }

        self::assertGreaterThan(0, $parCle['a']['suggestion'], 'Eau : demande > stock 10.');
        self::assertSame(0, $parCle['b']['suggestion'], 'Chips : stock 100 >> demande.');

        // Tri : suggestion desc, nulls en fin, puis label.
        $suggestions = array_map(static fn (array $r): int => $r['suggestion'] ?? -1, $r1);
        $trie = $suggestions;
        rsort($trie);
        self::assertSame($trie, $suggestions);
    }

    public function test_sans_rng_sortie_deterministe_et_filtre(): void
    {
        $m = DemandModel::fit(self::serie(), [], Hyper::K_POOL);
        $produits = [
            new ProductStat('a', 'Eau', 'Boissons', 60, 120.0, 2.0, 1.0, false, null),
            new ProductStat('z', 'Invisible', 'Divers', 0, 0.0, 2.0, 1.0, false, 50),
        ];

        $r = ProductForecast::units($produits, $m, 0.2, 7, null, 100);

        self::assertCount(1, $r, 'qty 0 + suggestion 0 (stock élevé) → exclu.');
        $row = $r[0];
        self::assertSame('a', $row['key']);
        self::assertEqualsWithDelta($row['unites'], $row['lo'], 0.0, 'Sans rng : lo = unites.');
        self::assertEqualsWithDelta($row['unites'], $row['hi'], 0.0, 'Sans rng : hi = unites.');
        self::assertNull($row['suggestion'], 'Stock inconnu → suggestion null.');
        self::assertSame((int) ceil($row['unites']), $row['safety_demand'], 'Sans rng : safety = ceil(unites).');
    }

    public function test_vide(): void
    {
        $m = DemandModel::fit([], [], Hyper::K_POOL);
        self::assertSame([], ProductForecast::units([], $m, 1.0, 7, new \Random\Randomizer(new \Random\Engine\Mt19937(1)), 100));
    }

    /**
     * 10 jours ouverts (lun–ven × 2), 50 transactions/jour, panier 2.0
     * → globalN ≈ 50, λ = 50 × 0.2 × 7 = 70 unités sur 7 jours.
     *
     * @return array<string,DayCurrent>
     */
    private static function serie(): array
    {
        $days = [];
        $d = new \DateTimeImmutable('2026-09-07');
        while (count($days) < 10) {
            $dow = (int) $d->format('N') - 1;
            if ($dow <= 4) {
                $key = $d->format('Y-m-d');
                $days[$key] = new DayCurrent($key, 100.0, 50, self::horaire(50), array_fill(0, 50, 2.0));
            }
            $d = $d->modify('+1 day');
        }

        return $days;
    }

    /**
     * @return array<int,int>
     */
    private static function horaire(int $n): array
    {
        $h = array_fill(0, 24, 0);
        $base = intdiv($n, 10);
        $reste = $n - $base * 10;
        for ($i = 0; $i < 10; $i++) {
            $h[8 + $i] = $base + ($i < $reste ? 1 : 0);
        }

        return $h;
    }
}
