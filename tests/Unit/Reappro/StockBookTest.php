<?php

declare(strict_types=1);

namespace Tests\Unit\Reappro;

use App\Core\Compta\Reappro\StockBook;
use PHPUnit\Framework\TestCase;

/**
 * Livre de stock unique du réappro : résolution exacte → normalisée →
 * alias, dates de comptage, détection « sans donnée », stock négatif
 * conservé tel quel (signalé, jamais masqué).
 */
final class StockBookTest extends TestCase
{
    public function test_resolution_exacte_puis_normalisee(): void
    {
        $book = new StockBook(
            ['Redbull Peach' => 6, 'Eau 50cl' => 12],
            ['Redbull Peach' => ['at' => '2026-10-21 18:00:00', 'qty' => 6, 'gap' => 0]],
            []
        );

        // Casse ignorée : « RedbuLL peach » == « Redbull Peach » (passe exacte).
        $e = $book->resolve('RedbuLL peach');
        self::assertNotNull($e);
        self::assertSame(6, $e['stock']);
        self::assertSame('2026-10-21 18:00:00', $e['at']);
        self::assertSame('exact', $e['via'], 'trim+lower converge avant la normalisation d\'accents.');

        // Ponctuation/espacements : « Eau-50CL » == « Eau 50cl » (passe normalisée).
        $e2 = $book->resolve('Eau-50CL');
        self::assertNotNull($e2);
        self::assertSame(12, $e2['stock']);
        self::assertSame('normalise', $e2['via']);
    }

    public function test_alias_rapproche_les_noms_differents(): void
    {
        $book = new StockBook(
            ['Pepsi sans sucre' => 9],
            ['Pepsi sans sucre' => ['at' => '2026-10-20 10:00:00', 'qty' => 9, 'gap' => 0]],
            [
                ['raw_description' => 'pepsi Zero', 'product_key' => 'Pepsi sans sucre'],
                ['raw_description' => 'Pepsi zero (canette)', 'product_key' => ' Pepsi sans sucre '],
            ]
        );

        // Exact alias : « pepsi Zero » → « Pepsi sans sucre ».
        $e = $book->resolve('pepsi Zero');
        self::assertNotNull($e);
        self::assertSame(9, $e['stock']);
        self::assertSame('alias', $e['via']);
        self::assertSame('Pepsi sans sucre', $e['key'], 'La clé retournée est la cible canonique de l\'alias.');

        // Alias normalisé : « pepsi zero (CANETTE) » → même cible.
        $e2 = $book->resolve('Pepsi zero CANETTE');
        self::assertNotNull($e2);
        self::assertSame('alias', $e2['via']);
        self::assertSame(9, $e2['stock']);
    }

    public function test_sans_donnee_et_stock_negatif(): void
    {
        $book = new StockBook(
            ['Bueno white' => -5, 'Gauffre' => 0],
            [
                'Bueno white' => ['at' => '2026-10-19 09:30:00', 'qty' => 0, 'gap' => -5],
                'Gauffre'     => ['at' => '2026-10-21 20:00:00', 'qty' => 0, 'gap' => 0],
            ],
            []
        );

        // Jamais vendu/compté/acheté : null → « à compter » côté liste.
        self::assertNull($book->resolve('Mister Freeze'));

        // Survente : le négatif est retourné TEL QUEL (l'anomalie vit en amont).
        $e = $book->resolve('Bueno white');
        self::assertNotNull($e);
        self::assertSame(-5, $e['stock']);

        // Date la plus récente du livre.
        self::assertSame('2026-10-21 20:00:00', $book->derniereDate());
    }

    public function test_conflit_de_normalisation_comportement_conservateur(): void
    {
        $book = new StockBook(
            ['Red bull' => 3, 'red-bull' => 7],
            [
                'Red bull' => ['at' => '2026-10-18 08:00:00', 'qty' => 3, 'gap' => 0],
                'red-bull' => ['at' => '2026-10-21 12:00:00', 'qty' => 7, 'gap' => 0],
            ],
            []
        );

        // « red_bull » (séparateur non exact) converge vers la forme
        // normalisée commune de « Red bull » / « red-bull » : conflit → max.
        $e = $book->resolve('red_bull');
        self::assertNotNull($e);
        self::assertSame('normalise', $e['via']);
        self::assertSame(7, $e['stock'], 'Conflit normalisé : max (conservateur pour ne pas sous-commander).');
        self::assertSame('2026-10-21 12:00:00', $e['at'], 'Date la plus récente des deux formes.');
    }
}
