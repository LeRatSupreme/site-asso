<?php

declare(strict_types=1);

namespace Tests\Unit\Reappro;

use App\Core\Compta\Reappro\PackResolver;
use PHPUnit\Framework\TestCase;

/**
 * Résolution du pack d'achat : les packs sont saisis sous les
 * product_key du Réappro, les lignes moteur portent clé/libellé de
 * vente parfois différents. Exact → normalisé → alias, sur chaque
 * candidat (clé puis libellé) — sinon la liste commande à l'unité
 * (régression « 5 cannettes » du 22/10/2026).
 */
final class PackResolverTest extends TestCase
{
    public function test_exacte_puis_normalisee(): void
    {
        $r = new PackResolver(['Pepsi Zero' => 6, 'Eau 50cl' => 24], []);

        self::assertSame(6, $r->resolve('pepsi zero'), 'Casse/espacements ignorés (passe exacte).');
        self::assertSame(24, $r->resolve('eau-50CL'), 'Ponctuation/accents repliés (passe normalisée).');
        self::assertSame(0, $r->resolve('Inconnu'), 'Aucun pack → commande à l\'unité.');
    }

    public function test_alias_rapproche_le_libelle_de_vente(): void
    {
        // Pack saisi sur la clé canonique, libellé de vente différent.
        $r = new PackResolver(
            ['Pepsi sans sucre' => 6],
            [['raw_description' => 'pepsi Zero', 'product_key' => 'Pepsi sans sucre']]
        );

        self::assertSame(6, $r->resolve('pepsi Zero'), 'Alias exact → pack de la clé canonique.');
        self::assertSame(6, $r->resolve('PEPSI  zéro'), 'Alias normalisé (casse/accents/espacements) → pack de la clé canonique.');
        self::assertSame(6, $r->resolve('Pepsi SANS SUCRE'), 'Clé canonique elle-même (passe exacte).');
    }

    public function test_cle_puis_libelle_premier_gagnant(): void
    {
        // Clé moteur ≠ libellé : la clé trouve d'abord son pack.
        $r = new PackResolver(['cafe' => 12, 'Café expresso bio' => 24], []);

        self::assertSame(
            12,
            $r->resolve('cafe', 'Café expresso bio'),
            'Le premier candidat qui trouve un pack l\'impose (la clé moteur prime).'
        );
        self::assertSame(
            24,
            $r->resolve('autre clé', 'Café expresso bio'),
            'Sans pack sur la clé, le libellé prend le relais.'
        );
    }

    public function test_conflit_de_normalisation_max(): void
    {
        $r = new PackResolver(['Red bull' => 12, 'red-bull' => 24], []);

        self::assertSame(24, $r->resolve('red_bull'), 'Conflit normalisé : max (conservateur).');
    }

    public function test_tailles_invalidees_ignorees(): void
    {
        $r = new PackResolver(['X' => 0, 'Y' => -3, ' ' => 12], []);

        self::assertSame(0, $r->resolve('x'));
        self::assertSame(0, $r->resolve('y'));
        self::assertSame(0, $r->resolve(' '), 'Clé vide ignorée.');
    }
}
