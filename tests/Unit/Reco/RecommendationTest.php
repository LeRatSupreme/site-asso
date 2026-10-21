<?php

declare(strict_types=1);

namespace Tests\Unit\Reco;

use App\Core\Compta\Reco\Recommendation;
use PHPUnit\Framework\TestCase;

/**
 * Sérialisation canonique : toArray expose EXACTEMENT les 16 clés
 * imposées, dans l'ordre — dont bloc ('idee'|'correction') et la
 * fenêtre de validité debut/fin ('Y-m-d', null si non datée).
 */
final class RecommendationTest extends TestCase
{
    public function test_toarray_expose_exactement_16_cles(): void
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
            statut: 'proposée',
            bloc: 'correction',
            debut: '2026-10-22',
            fin: '2026-10-23'
        );

        $a = $r->toArray();
        self::assertCount(16, $a);
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
            'bloc',
            'debut',
            'fin',
        ], array_keys($a));
        self::assertSame('R2', $a['regle']);
        self::assertSame(['bas' => 5.0, 'median' => 10.0, 'haut' => 15.0], $a['gain_hebdo']);
        self::assertSame('moyenne', $a['confiance']);
        self::assertSame(1, $a['effort']);
        self::assertTrue($a['garde_fous_ok']);
        self::assertSame('proposée', $a['statut']);
        self::assertSame('correction', $a['bloc']);
        self::assertSame('2026-10-22', $a['debut']);
        self::assertSame('2026-10-23', $a['fin']);
    }

    public function test_bloc_idee_et_dates_nulles_par_defaut(): void
    {
        $r = new Recommendation(
            id: 'R5_PA_PB',
            regle: 'R5',
            titre: 'Duo promo : Ananas + Biscuits',
            pourquoi: 'lift 4,0',
            action: 'Proposez le duo',
            gainHebdo: ['bas' => 0.0, 'median' => 1.0, 'haut' => 2.0],
            confiance: 'faible',
            effort: 2,
            hypotheses: [],
            produits: [],
            gardeFousOk: true,
            commentMesurer: 'Duos vendus',
            statut: 'proposée'
        );

        $a = $r->toArray();
        self::assertSame('idee', $a['bloc']);
        self::assertNull($a['debut']);
        self::assertNull($a['fin']);
        self::assertSame(16, count($a));
    }
}
