<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Compta\Dashboard\Afaire;
use PHPUnit\Framework\TestCase;

/**
 * Tests de Afaire::itemBudget — décision PURE de l'entrée « À faire »
 * budget du mois courant (chantier P1-3 : rendre le budget visible) :
 *  - aucune ligne de budget pour le mois → rappel « Aucun budget pour le
 *    mois courant » ;
 *  - enveloppe de dépenses consommée à ≥ 85 % → « CATÉGORIE à X % de
 *    l'enveloppe » (la pire catégorie est signalée) ;
 *  - l'objectif de CA n'est jamais signalé (le dépasser est une bonne
 *    nouvelle) ;
 *  - tout va bien → null (aucune entrée).
 */
final class DashboardAfaireBudgetTest extends TestCase
{
    public function test_aucun_budget_pour_le_mois(): void
    {
        $item = Afaire::itemBudget([], ['MATIERE' => 120.0]);

        self::assertNotNull($item, 'Sans aucune ligne de budget, le rappel doit apparaître.');
        self::assertSame('budget', $item['cle']);
        self::assertSame(2, $item['urgence'], 'Même urgence que les autres entrées de suivi (badge orange).');
        self::assertSame('Aucun budget pour le mois courant', $item['texte']);
    }

    public function test_enveloppe_sous_le_seuil_aucune_entree(): void
    {
        self::assertNull(
            Afaire::itemBudget(['MATIERE' => 100.0], ['MATIERE' => 50.0]),
            'Une enveloppe à 50 % ne mérite pas d\'entrée « À faire ».'
        );
    }

    public function test_seuil_85_inclusif(): void
    {
        $item = Afaire::itemBudget(['MATIERE' => 100.0], ['MATIERE' => 85.0]);

        self::assertNotNull($item, '85 % exactement doit être signalé (seuil inclusif).');
        self::assertSame("MATIERE à 85 % de l'enveloppe", $item['texte']);
    }

    public function test_la_pire_categorie_est_signalée(): void
    {
        $item = Afaire::itemBudget(
            ['MATIERE' => 200.0, 'FRAIS' => 100.0, 'MATERIEL' => 400.0],
            ['MATIERE' => 20.0, 'FRAIS' => 180.0, 'MATERIEL' => 40.0]
        );

        self::assertNotNull($item);
        self::assertSame("FRAIS à 180 % de l'enveloppe", $item['texte'], 'FRAIS (180 %) passe avant MATERIEL (10 %).');
    }

    public function test_objectif_de_ca_jamais_signale(): void
    {
        // Le CA est un objectif, pas une enveloppe de dépenses : aucun % ne
        // le signale, même « dépassé ».
        self::assertNull(Afaire::itemBudget(['CA' => 1000.0], []));
        self::assertNull(Afaire::itemBudget(['CA' => 1000.0], ['CA' => 99999.0]));
    }

    public function test_pourcentage_arrondi_au_point_le_plus_proche(): void
    {
        $item = Afaire::itemBudget(['MATIERE' => 300.0], ['MATIERE' => 280.0]);

        self::assertNotNull($item);
        self::assertSame("MATIERE à 93 % de l'enveloppe", $item['texte'], '280/300 = 93,33 % → affiché 93 %.');
    }

    public function test_budget_sans_categorie_realisee_compte_a_zero(): void
    {
        // Enveloppe saisie mais rien dépensé (ni dépense ni achat) : pas
        // d'entrée — la catégorie absente du réalisé vaut 0 %.
        self::assertNull(Afaire::itemBudget(['MATIERE' => 100.0, 'FRAIS' => 100.0], []));
    }
}
