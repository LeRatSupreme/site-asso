<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Core\Compta\Forecast\ProductStat;
use App\Core\Compta\Forecast\ProfitForecast;
use PHPUnit\Framework\TestCase;

/**
 * Projection de bénéfice à 7 jours : marge unitaire (prix − coût),
 * répartition au prorata des quantités fiables, regroupement par
 * catégorie, diagnostics des produits sans coût fiable
 * (cout_inconnu / cout_zero / prix_inferieur_au_cout) et note UI.
 */
final class ProfitForecastTest extends TestCase
{
    public function test_repartition_marge_et_suspects(): void
    {
        $produits = [
            new ProductStat('cafe', 'Café', 'Boissons', 100, 200.0, 2.0, 1.0, false, null),
            new ProductStat('chips', 'Chips', 'Snacks', 100, 300.0, 3.0, null, true, null),
            new ProductStat('soda', 'Soda', 'Snacks', 50, 100.0, 2.0, 0.0, true, null),
            new ProductStat('croiss', 'Croissant', 'Divers', 50, 50.0, 1.0, 2.0, true, null),
        ];

        $r = ProfitForecast::compute($produits, 650.0, 1000.0);

        // Seul le café est fiable : part 100/100 = 1.0, marge/prix = 0.5.
        self::assertEqualsWithDelta(500.0, $r['total_marge_7j'], 0.0001);

        self::assertCount(3, $r['suspects']);
        self::assertSame('cout_inconnu', $r['suspects'][0]['raison']);
        self::assertSame('cout_zero', $r['suspects'][1]['raison']);
        self::assertSame('prix_inferieur_au_cout', $r['suspects'][2]['raison']);

        // 450 € de CA suspect sur 650 € de fenêtre.
        self::assertEqualsWithDelta(450.0 / 650.0 * 100.0, $r['pct_ca_sans_cout_fiable'], 0.0001);
        self::assertStringContainsString('69 % du CA récent sans coût fiable', $r['note']);

        self::assertSame(['Boissons', 'Snacks', 'Divers'], array_keys($r['par_categorie']));
        self::assertEqualsWithDelta(200.0, $r['par_categorie']['Boissons']['ca'], 0.0001);
        self::assertEqualsWithDelta(500.0, $r['par_categorie']['Boissons']['marge'], 0.0001);
        self::assertEqualsWithDelta(250.0, $r['par_categorie']['Boissons']['marge_pct'], 0.0001);
        self::assertEqualsWithDelta(0.0, $r['par_categorie']['Snacks']['marge'], 0.0001, 'Suspects : aucune marge.');
        self::assertEqualsWithDelta(0.0, $r['par_categorie']['Snacks']['marge_pct'], 0.0001);
    }

    public function test_produits_tous_fiables_repartition_pro_rata(): void
    {
        $produits = [
            new ProductStat('a', 'A', 'Cat', 75, 150.0, 2.0, 1.0, false, null),
            new ProductStat('b', 'B', 'Cat', 25, 50.0, 2.0, 1.0, false, null),
        ];

        $r = ProfitForecast::compute($produits, 200.0, 400.0);

        // parts 0.75 / 0.25, marge/prix = 0.5 → 150 + 50, même catégorie 'Cat'.
        self::assertEqualsWithDelta(200.0, $r['total_marge_7j'], 0.0001);
        self::assertEqualsWithDelta(200.0, $r['par_categorie']['Cat']['marge'], 0.0001, '150 (A) + 50 (B).');
        self::assertEqualsWithDelta(100.0, $r['par_categorie']['Cat']['marge_pct'], 0.0001, 'Marge 200 sur CA 200.');
        self::assertSame([], $r['suspects']);
        self::assertEqualsWithDelta(0.0, $r['pct_ca_sans_cout_fiable'], 0.0001);
        self::assertStringContainsString('Coûts fiables', $r['note']);
    }

    public function test_cas_limites(): void
    {
        // Aucun produit.
        $vide = ProfitForecast::compute([], 0.0, 1000.0);
        self::assertSame([], $vide['par_categorie']);
        self::assertEqualsWithDelta(0.0, $vide['total_marge_7j'], 0.0001);
        self::assertEqualsWithDelta(0.0, $vide['pct_ca_sans_cout_fiable'], 0.0001);

        // Fenêtre à 0 → pas de pourcentage, pas d'erreur.
        $p = new ProductStat('x', 'X', 'Cat', 10, 20.0, 2.0, null, true, null);
        $r = ProfitForecast::compute([$p], 0.0, 500.0);
        self::assertEqualsWithDelta(0.0, $r['pct_ca_sans_cout_fiable'], 0.0001);
        self::assertSame('cout_inconnu', $r['suspects'][0]['raison']);

        // Prix null et coût null sur produit suspect.
        $q = new ProductStat('y', 'Y', 'Cat', 5, 10.0, null, null, true, null);
        $r2 = ProfitForecast::compute([$q], 10.0, 100.0);
        self::assertSame('cout_inconnu', $r2['suspects'][0]['raison']);
        self::assertEqualsWithDelta(100.0, $r2['pct_ca_sans_cout_fiable'], 0.0001);
    }
}
