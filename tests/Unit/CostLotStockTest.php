<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Compta\CostLotStock;
use PHPUnit\Framework\TestCase;

/**
 * Allocation « quantité par lot de coût de revient » — cœur PUR de
 * CostLotStock::allocate() (aucune DB, aucun modèle).
 *
 * Couvre : lot suivi épuisé « moins 1 jusqu'à 0 », report d'une vente
 * débordante sur un lot FUTUR (même saisi rétroactivement après la vente),
 * vente antérieure à tout lot reportée sur le premier lot suivi, lot non
 * suivi (qty_purchased NULL) absorbant sans jamais s'épuiser, traversée
 * des lots non suivis pendant un report, épuisement général (non_couvert),
 * déterminisme sur entrées non triées, lot qty_purchased = 0 jamais servi.
 *
 * La fenêtre temporelle de `vendu`/`perdu` (mouvements >= valid_from du
 * premier lot suivi) vit dans la couche DB (CostLotStock::forProduct /
 * ecarts, requêtes ventes par jour + InventoryCount::theoreticalStocksMap)
 * : elle sera couverte par les tests d'intégration.
 */
final class CostLotStockTest extends TestCase
{
    public function test_nominal_lot_suivi_epuise(): void
    {
        $r = CostLotStock::allocate(
            [['id' => 'A', 'valid_from' => '2025-10-01', 'qty_purchased' => 10]],
            [
                ['date' => '2025-10-03', 'qty' => 6],
                ['date' => '2025-10-05', 'qty' => 4],
            ]
        );

        self::assertSame(
            ['alloue' => 10, 'restant' => 0, 'epuise' => true],
            $r['lots']['A'],
            'Moins 1 jusqu\'à 0 : le lot est épuisé après 10 unités.'
        );
        self::assertSame(0, $r['non_couvert']);
    }

    public function test_debordement_reporte_sur_lot_futur_retroactif(): void
    {
        // 8 ventes le 05/10 : le lot A (01/10, qty 5) sert 5 unités, les 3
        // autres sont reportées sur le lot B FUTUR (20/10) saisi
        // rétroactivement.
        $r = CostLotStock::allocate(
            [
                ['id' => 'A', 'valid_from' => '2025-10-01', 'qty_purchased' => 5],
                ['id' => 'B', 'valid_from' => '2025-10-20', 'qty_purchased' => 10],
            ],
            [['date' => '2025-10-05', 'qty' => 8]]
        );

        self::assertSame(['alloue' => 5, 'restant' => 0, 'epuise' => true], $r['lots']['A']);
        self::assertSame(['alloue' => 3, 'restant' => 7, 'epuise' => false], $r['lots']['B']);
        self::assertSame(0, $r['non_couvert']);
    }

    public function test_vente_anterieure_reportee_sur_premier_lot_suivi(): void
    {
        // « Je saisis le coût après » : la vente du 01/09 précède tout lot ;
        // elle est reportée sur le premier lot suivi (01/10).
        $r = CostLotStock::allocate(
            [['id' => 'A', 'valid_from' => '2025-10-01', 'qty_purchased' => 10]],
            [['date' => '2025-09-01', 'qty' => 3]]
        );

        self::assertSame(['alloue' => 3, 'restant' => 7, 'epuise' => false], $r['lots']['A']);
        self::assertSame(0, $r['non_couvert']);
    }

    public function test_lot_non_suivi_absorbe_sans_epuisement(): void
    {
        $r = CostLotStock::allocate(
            [['id' => 'A', 'valid_from' => '2025-10-01', 'qty_purchased' => null]],
            [
                ['date' => '2025-10-02', 'qty' => 30],
                ['date' => '2025-10-09', 'qty' => 20],
            ]
        );

        self::assertSame(
            ['alloue' => 50, 'restant' => null, 'epuise' => false],
            $r['lots']['A'],
            'Lot ancien non suivi : capacité infinie, restant null, jamais épuisé.'
        );
        self::assertSame(0, $r['non_couvert']);
    }

    public function test_report_traverse_lot_non_suivi_puis_consomme_suivant(): void
    {
        // Le 03/10, cible = A (suivi, qty 2) : 2 unités servies, la 3e
        // déborde → traverse B (non suivi, absorbée) → consomme C (suivi).
        $r = CostLotStock::allocate(
            [
                ['id' => 'A', 'valid_from' => '2025-10-01', 'qty_purchased' => 2],
                ['id' => 'B', 'valid_from' => '2025-10-05', 'qty_purchased' => null],
                ['id' => 'C', 'valid_from' => '2025-10-10', 'qty_purchased' => 5],
            ],
            [['date' => '2025-10-03', 'qty' => 3]]
        );

        self::assertSame(['alloue' => 2, 'restant' => 0, 'epuise' => true], $r['lots']['A']);
        self::assertSame(
            ['alloue' => 1, 'restant' => null, 'epuise' => false],
            $r['lots']['B'],
            'Le lot non suivi traversé pendant le report est absorbé et compté.'
        );
        self::assertSame(['alloue' => 1, 'restant' => 4, 'epuise' => false], $r['lots']['C']);
        self::assertSame(0, $r['non_couvert']);
    }

    public function test_tout_epuise_non_couvert_compte_les_unites_restantes(): void
    {
        $r = CostLotStock::allocate(
            [['id' => 'A', 'valid_from' => '2025-10-01', 'qty_purchased' => 1]],
            [['date' => '2025-10-02', 'qty' => 4]]
        );

        self::assertSame(['alloue' => 1, 'restant' => 0, 'epuise' => true], $r['lots']['A']);
        self::assertSame(3, $r['non_couvert'], 'Les unités sans lot attendent un lot futur.');

        // Aucun lot du tout : tout est non couvert.
        $r2 = CostLotStock::allocate([], [['date' => '2025-10-02', 'qty' => 5]]);
        self::assertSame([], $r2['lots']);
        self::assertSame(5, $r2['non_couvert']);
    }

    public function test_entrees_non_triees_meme_resultat_que_triees(): void
    {
        $inputLots = [
            ['id' => 'A', 'valid_from' => '2025-10-01', 'qty_purchased' => 5],
            ['id' => 'B', 'valid_from' => '2025-10-20', 'qty_purchased' => 10],
        ];
        $inputMv = [['date' => '2025-10-05', 'qty' => 8]];

        // Entrées mélangées + mouvement même jour découpé dans le désordre.
        $rMelange = CostLotStock::allocate(
            [$inputLots[1], $inputLots[0]],
            [
                ['date' => '2025-10-05', 'qty' => 5],
                ['date' => '2025-10-05', 'qty' => 3],
            ]
        );
        $rTrie = CostLotStock::allocate($inputLots, $inputMv);

        self::assertSame($rTrie, $rMelange, 'Le résultat est déterministe : indépendant de l\'ordre d\'entrée.');
        self::assertSame(['alloue' => 5, 'restant' => 0, 'epuise' => true], $rMelange['lots']['A']);
        self::assertSame(['alloue' => 3, 'restant' => 7, 'epuise' => false], $rMelange['lots']['B']);
        self::assertSame(0, $rMelange['non_couvert']);
    }

    public function test_lot_qty_zero_jamais_consomme_report_direct(): void
    {
        // Le 01/10, cible = A (suivi, qty 0) : jamais servie, report direct
        // sur B.
        $r = CostLotStock::allocate(
            [
                ['id' => 'A', 'valid_from' => '2025-10-01', 'qty_purchased' => 0],
                ['id' => 'B', 'valid_from' => '2025-10-02', 'qty_purchased' => 3],
            ],
            [['date' => '2025-10-01', 'qty' => 3]]
        );

        self::assertSame(['alloue' => 0, 'restant' => 0, 'epuise' => true], $r['lots']['A']);
        self::assertSame(['alloue' => 3, 'restant' => 0, 'epuise' => true], $r['lots']['B']);
        self::assertSame(0, $r['non_couvert']);

        // Lot qty 0 seul : rien ne peut être servi.
        $r2 = CostLotStock::allocate(
            [['id' => 'A', 'valid_from' => '2025-10-01', 'qty_purchased' => 0]],
            [['date' => '2025-10-01', 'qty' => 2]]
        );
        self::assertSame(['alloue' => 0, 'restant' => 0, 'epuise' => true], $r2['lots']['A']);
        self::assertSame(2, $r2['non_couvert']);
    }

    public function test_cas_reel_bug_utilisateur_cafe(): void
    {
        // Cas signalé : 12 ventes le 20/09, lot A saisi le 19/09 (qty 10) et
        // lot B le 08/10 (qty 24). A sert 10 unités, les 2 restantes sont
        // reportées sur B : aucune vente « perdue », restant B = 22.
        $r = CostLotStock::allocate(
            [
                ['id' => 'A', 'valid_from' => '2025-09-19', 'qty_purchased' => 10],
                ['id' => 'B', 'valid_from' => '2025-10-08', 'qty_purchased' => 24],
            ],
            [['date' => '2025-09-20', 'qty' => 12]]
        );

        self::assertSame(['alloue' => 10, 'restant' => 0, 'epuise' => true], $r['lots']['A']);
        self::assertSame(['alloue' => 2, 'restant' => 22, 'epuise' => false], $r['lots']['B']);
        self::assertSame(0, $r['non_couvert']);
    }

    public function test_mouvements_ventes_et_pertes_agregees_meme_jour(): void
    {
        // Ventes et pertes arrivent agrégées par jour (couches DB) : la
        // consommation suit l'ordre des lignes à date égale.
        $r = CostLotStock::allocate(
            [['id' => 'A', 'valid_from' => '2025-10-01', 'qty_purchased' => 4]],
            [
                ['date' => '2025-10-02', 'qty' => 3], // ventes du jour
                ['date' => '2025-10-02', 'qty' => 1], // perte du jour
            ]
        );

        self::assertSame(['alloue' => 4, 'restant' => 0, 'epuise' => true], $r['lots']['A']);
        self::assertSame(0, $r['non_couvert']);
    }
}
