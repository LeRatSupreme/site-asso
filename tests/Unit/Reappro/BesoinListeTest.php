<?php

declare(strict_types=1);

namespace Tests\Unit\Reappro;

use App\Core\Compta\Forecast\DayCurrent;
use App\Core\Compta\Forecast\DemandModel;
use App\Core\Compta\Forecast\Hyper;
use App\Core\Compta\Forecast\ProductForecast;
use App\Core\Compta\Forecast\ProductStat;
use App\Core\Compta\Reappro\BesoinListe;
use PHPUnit\Framework\TestCase;

/**
 * Besoin de la liste de courses : besoin(N) = ceil(hi × N/7), stock
 * déduit via max(0, stock) — un stock NÉGATIF n'augmente JAMAIS la
 * commande — arrondi au pack, « à compter » (stock null) à part.
 *
 * EXIGENCE DE COHÉRENCE (21/10/2026) : à couverture 1 semaine
 * (facteur 1, sans pack), BesoinListe::ligne() redonne EXACTEMENT la
 * suggestion de la carte Réappro Analytics (ProductForecast::units),
 * car les deux pages partagent la même demande et le même stock.
 */
final class BesoinListeTest extends TestCase
{
    /**
     * @dataProvider providerStocks
     */
    public function test_coherence_liste_reappro_a_1_semaine(?int $stock): void
    {
        $m = DemandModel::fit(self::serie(), [], Hyper::K_POOL);
        $stat = new ProductStat('a', 'Eau', 'Boissons', 60, 120.0, 2.0, 1.0, false, $stock);

        $row = ProductForecast::units([$stat], $m, 2.0, 7, null, 100)[0];
        $ligne = BesoinListe::ligne($row, 1.0, 0);

        self::assertSame($row['safety_demand'], $ligne['besoin'], 'À 1 semaine : besoin = safety_demand du moteur.');

        if ($stock === null) {
            self::assertNull($row['suggestion']);
            self::assertNull($ligne['to_order'], 'Sans donnée d\'inventaire : pas de commande chiffrée.');
        } else {
            self::assertSame($row['suggestion'], $ligne['to_order'], 'À 1 semaine sans pack : la liste ordonne la suggestion du Réappro.');
        }
    }

    /**
     * @return iterable<string,array{?int}>
     */
    public static function providerStocks(): iterable
    {
        yield 'stock couvrant' => [100];
        yield 'stock partiel' => [10];
        yield 'stock nul' => [0];
        yield 'stock négatif (survente)' => [-5];
        yield 'jamais compté' => [null];
    }

    public function test_stock_negatif_anomalie_sans_majoration(): void
    {
        // Bueno white du 21/10/2026 : hi 12, stock théorique −5.
        $ligne = BesoinListe::ligne(['hi' => 12.0, 'unites' => 9.5, 'stock' => -5], 1.0, 0);

        self::assertTrue($ligne['negatif'], 'Flag anomalie posé pour affichage à part.');
        self::assertSame(-5, $ligne['stock'], 'Le stock négatif reste visible (à recompter).');
        self::assertSame(12, $ligne['to_order'], 'Besoin 12 − max(0, −5)=0 → 12 : la survente ne majore PAS la commande (ancien bug : 17).');
    }

    public function test_arrondi_au_pack(): void
    {
        $base = ['hi' => 9.0, 'unites' => 7.0, 'stock' => 0];

        self::assertSame(12, BesoinListe::ligne($base, 1.0, 12)['to_order'], 'Besoin 9 + pack 12 → 12.');
        self::assertSame(24, BesoinListe::ligne($base, 1.0, 24)['to_order'], 'Besoin 9 + pack 24 → 24.');
        self::assertSame(9, BesoinListe::ligne($base, 1.0, 0)['to_order'], 'Pack 0 = à l\'unité.');
        self::assertSame(9, BesoinListe::ligne($base, 1.0, 1)['to_order'], 'Pack 1 = à l\'unité.');

        // Le pack n'arrondit pas une commande nulle (stock suffisant).
        self::assertSame(0, BesoinListe::ligne(['hi' => 3.0, 'unites' => 2.0, 'stock' => 30], 1.0, 12)['to_order']);
    }

    public function test_facteur_de_couverture(): void
    {
        $p = ['hi' => 7.0, 'unites' => 5.0, 'stock' => 4];

        // 1 semaine : ceil(7 × 1) − 4 = 3.
        self::assertSame(3, BesoinListe::ligne($p, 1.0)['to_order']);
        // 2 semaines : ceil(7 × 2) − 4 = 10.
        self::assertSame(10, BesoinListe::ligne($p, 14 / 7)['to_order']);
        // 1 mois (30 j) : ceil(7 × 30/7) = 30 − 4 = 26.
        self::assertSame(26, BesoinListe::ligne($p, 30 / 7)['to_order']);
    }

    public function test_sans_stock_besoin_indicatif(): void
    {
        $ligne = BesoinListe::ligne(['hi' => 8.4, 'unites' => 6.0, 'stock' => null], 1.0, 12);

        self::assertNull($ligne['to_order'], '« À compter » : jamais de commande chiffrée.');
        self::assertSame(9, $ligne['besoin'], 'Besoin indicatif = ceil(hi) pour aider le comptage.');
        self::assertFalse($ligne['negatif']);
        self::assertSame(6.0, $ligne['prev_semaine']);
    }

    /**
     * 10 jours ouverts (lun–ven × 2), 50 transactions/jour, panier 2.0.
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
