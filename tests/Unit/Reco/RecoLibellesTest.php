<?php

declare(strict_types=1);

namespace Tests\Unit\Reco;

use App\Core\Compta\Reco\RecoLibelles;
use PHPUnit\Framework\TestCase;

/**
 * RecoLibelles : libellés HUMAINS des règles du moteur (jamais de code
 * R# dans l'interface). Chaque règle existante R1..R11 porte un intitulé
 * DISTINCT ; tout code inconnu retombe sur un libellé générique neutre
 * SANS « R » ni chiffre (jamais le code brut).
 */
final class RecoLibellesTest extends TestCase
{
    /** Chaque règle R1..R11 → le libellé humain attendu (mapping complet). */
    public function test_chaque_regle_a_son_libelle_humain(): void
    {
        $attendus = [
            'R1'  => 'Stock à écouler',
            'R2'  => 'Rupture imminente',
            'R3'  => 'Marge à améliorer',
            'R4'  => 'Forte marge peu vendue',
            'R5'  => 'Duo de produits',
            'R6'  => 'Paniers à compléter',
            'R7'  => 'Heure creuse',
            'R8'  => 'Fin de gamme',
            'R9'  => 'Demande en forte variation',
            'R10' => 'Objectif de la semaine',
            'R11' => 'Données à compléter',
        ];
        foreach ($attendus as $code => $libelle) {
            self::assertSame($libelle, RecoLibelles::regle($code), "Libellé de {$code}.");
            self::assertStringNotContainsString($code, RecoLibelles::regle($code), "{$code} : le code ne doit jamais apparaître dans le libellé.");
        }
    }

    /** Les 11 libellés sont deux à deux DISTINCTS (pas de règle interchangeable). */
    public function test_les_libelles_sont_tous_distincts(): void
    {
        $codes = ['R1', 'R2', 'R3', 'R4', 'R5', 'R6', 'R7', 'R8', 'R9', 'R10', 'R11'];
        $libelles = array_map(static fn (string $c): string => RecoLibelles::regle($c), $codes);

        self::assertCount(11, $libelles);
        self::assertCount(11, array_unique($libelles), 'Chaque règle doit avoir un libellé distinct.');
    }

    /**
     * Code inconnu (ou vide) → libellé générique neutre : ni le code brut,
     * ni un motif « R » + chiffres, jamais une chaîne vide.
     */
    public function test_code_inconnu_libelle_generique_sans_code(): void
    {
        foreach (['R99', 'R12', '', 'X1', 'r1'] as $code) {
            $libelle = RecoLibelles::regle($code);
            self::assertNotSame('', $libelle, 'Un code inconnu ne doit jamais donner un libellé vide.');
            self::assertDoesNotMatchRegularExpression('/\bR\d{1,2}\b/', $libelle, "Le libellé générique ne doit contenir aucun code de règle (entrée : « {$code} »).");
            if ($code !== '') {
                self::assertStringNotContainsString($code, $libelle, 'Le code brut ne doit jamais fuiter dans le libellé.');
            }
        }

        // Générique stable et identique quel que soit le code inconnu.
        self::assertSame(RecoLibelles::regle('R99'), RecoLibelles::regle('X1'));
    }
}
