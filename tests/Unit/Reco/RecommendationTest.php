<?php

declare(strict_types=1);

namespace Tests\Unit\Reco;

use App\Core\Compta\Reco\Recommendation;
use PHPUnit\Framework\TestCase;

/**
 * Sérialisation canonique : toArray expose EXACTEMENT les 13 clés
 * imposées, dans l'ordre.
 */
final class RecommendationTest extends TestCase
{
    public function test_toarray_expose_exactement_13_cles(): void
    {
        $r = new Recommendation(
            id: 'R2_P1_20261021',
            regle: 'R2',
            titre: 'Déstocker le sirop menthe',
            pourquoi: '12 unités en stock, aucune vente depuis 9 semaines',
            action: 'Remise de 10 % pendant 2 semaines',
            gainHebdo: ['bas' => 5.0, 'median' => 10.0, 'haut' => 15.0],
            confiance: 'moyenne',
            effort: 1,
            hypotheses: ['Ventes stables par ailleurs'],
            produits: [['key' => 'P1', 'label' => 'Sirop menthe']],
            gardeFousOk: true,
            commentMesurer: 'CA hebdomadaire 14 jours avant / après',
            statut: 'proposée'
        );

        $a = $r->toArray();
        self::assertCount(13, $a);
        self::assertSame([
            'id',
            'regle',
            'titre',
            'pourquoi',
            'action',
            'gain_hebdo',
            'confiance',
            'effort',
            'hypotheses',
            'produits',
            'garde_fous_ok',
            'comment_mesurer',
            'statut',
        ], array_keys($a));
        self::assertSame('R2', $a['regle']);
        self::assertSame(['bas' => 5.0, 'median' => 10.0, 'haut' => 15.0], $a['gain_hebdo']);
        self::assertSame('moyenne', $a['confiance']);
        self::assertSame(1, $a['effort']);
        self::assertTrue($a['garde_fous_ok']);
        self::assertSame('proposée', $a['statut']);
    }
}
