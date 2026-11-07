<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\RecoData;
use PHPUnit\Framework\TestCase;

/**
 * Extraction RecoData SANS base : le mappage « produits » du ForecastEngine
 * (LISTE de lignes indexée 0..n) vers la map clé => ligne {unites, lo, hi}
 * consommée par les RecoProduct. Cœur du bug prod « 9,91–9,91–9,91 €/sem » :
 * une liste indexée numériquement n'était pas ré-indexée par clé produit,
 * donc unitesPrevues7j/prevLo/prevHi tombaient tous à 0 (fourchettes plates
 * R1, R2 muette). Testé via le helper statique pur prevParCle() — aucune DB.
 */
final class RecoDataMappingTest extends TestCase
{
    public function test_indexe_la_liste_produits_du_forecast_par_cle(): void
    {
        $map = RecoData::prevParCle([
            ['key' => 'nutella', 'label' => 'Nutella B-ready', 'unites' => 5.0, 'lo' => 3.0, 'hi' => 8.0],
            ['key' => 'lipton', 'label' => 'Lipton Ice Tea', 'unites' => 1.0, 'lo' => 1.0, 'hi' => 1.0],
            'pas une ligne',
            ['sans_cle' => true],
            ['key' => '', 'unites' => 9.0],
        ]);

        self::assertSame(['nutella', 'lipton'], array_keys($map), 'Lignes mal formées ignorées, clés textuelles conservées.');
        self::assertSame(5.0, $map['nutella']['unites']);
        self::assertSame(3.0, $map['nutella']['lo']);
        self::assertSame(8.0, $map['nutella']['hi']);
        self::assertSame(1.0, $map['lipton']['lo']);
    }

    public function test_liste_absente_ou_vide_donne_map_vide(): void
    {
        self::assertSame([], RecoData::prevParCle([]), 'Aucun produit prévu : map vide (unités/lo/hi à 0 côté RecoProduct).');
    }

    public function test_cle_numerique_restable_par_sa_cle_produit(): void
    {
        $map = RecoData::prevParCle([
            ['key' => '7', 'unites' => 2.0, 'lo' => 1.0, 'hi' => 3.0],
        ]);

        // PHP coerce '7' en clé entière, mais l'accès par la clé produit
        // '7' du RecoProduct résout la même entrée (coercition symétrique).
        self::assertSame(2.0, $map['7']['unites']);
        self::assertSame(3.0, $map['7']['hi']);
    }
}
