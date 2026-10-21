<?php

declare(strict_types=1);

namespace Tests\Unit\Reco;

use App\Core\Compta\Reco\GuardRails;
use App\Core\Compta\Reco\Hyper;
use PHPUnit\Framework\TestCase;

/**
 * Garde-fous : plancher de prix exact (±1e-9), normalisation des
 * fourchettes, et validation des candidats (catégories exclues, coûts
 * suspects, gains désordonnés, confiance/effort).
 */
final class GuardRailsTest extends TestCase
{
    private const MARGE = Hyper::MARGE_MIN; // 0.15

    /** Catalogue de test : P1 normal, PEV (Événement), PS (coût inconnu). */
    private function produitsParCle(): array
    {
        return [
            'P1'  => RecoScenario::produit('P1', prix: 2.0, cout: 1.0),
            'PEV' => RecoScenario::produit('PEV', label: 'Borne arcade', category: 'Événement', prix: 2.0, cout: 1.0),
            'PS'  => RecoScenario::produit('PS', label: 'Sans coût', prix: 2.0, cout: null, coutRaison: 'cout_inconnu'),
        ];
    }

    /**
     * Candidat de référence valide sur P1 (sans prix_effectifs).
     *
     * @param array<string,mixed> $overrides
     *
     * @return array<string,mixed>
     */
    private static function candidat(array $overrides = []): array
    {
        return array_merge([
            'regle'           => 'R2',
            'keys'            => ['P1'],
            'titre'           => 'Titre',
            'pourquoi'        => 'Pourquoi',
            'action'          => 'Action',
            'gain'            => ['bas' => 10.0, 'median' => 20.0, 'haut' => 30.0],
            'confiance'       => 'haute',
            'hypotheses'      => ['h1'],
            'comment_mesurer' => 'CA hebdo',
            'effort'          => 1,
            'produits'        => [['key' => 'P1', 'label' => 'Produit 1']],
        ], $overrides);
    }

    public function test_prix_plancher_exact(): void
    {
        self::assertEqualsWithDelta(1.15, GuardRails::prixPlancher(1.0, 0.15), 1.0e-9);
        self::assertEqualsWithDelta(1.725, GuardRails::prixPlancher(1.5, 0.15), 1.0e-9);
        self::assertEqualsWithDelta(0.0, GuardRails::prixPlancher(0.0, 0.15), 1.0e-9);
    }

    public function test_fourchette_clamp_et_tri(): void
    {
        self::assertSame(['bas' => 0.0, 'median' => 10.0, 'haut' => 20.0], GuardRails::fourchette(-5.0, 10.0, 20.0));
        self::assertSame(['bas' => 0.0, 'median' => 5.0, 'haut' => 20.0], GuardRails::fourchette(20.0, 5.0, 0.0));
        self::assertSame(['bas' => 30.0, 'median' => 40.0, 'haut' => 50.0], GuardRails::fourchette(30.0, 50.0, 40.0));
        self::assertSame(['bas' => 7.0, 'median' => 7.0, 'haut' => 7.0], GuardRails::fourchette(7.0, 7.0, 7.0));
        self::assertSame(['bas' => 1.0, 'median' => 2.0, 'haut' => 3.0], GuardRails::fourchette(1.0, 2.0, 3.0));
    }

    public function test_candidat_valide_accepte_le_candidat_de_reference(): void
    {
        self::assertTrue(GuardRails::candidatValide(self::candidat(), $this->produitsParCle(), self::MARGE));
    }

    public function test_refuse_prix_sous_plancher_meme_moins_un_centime(): void
    {
        $c = self::candidat(['prix_effectifs' => [['key' => 'P1', 'prix' => 1.14]]]);
        self::assertFalse(GuardRails::candidatValide($c, $this->produitsParCle(), self::MARGE));
    }

    public function test_accepte_prix_exactement_au_plancher_tolerance_1e9(): void
    {
        $plancher = GuardRails::prixPlancher(1.0, self::MARGE);
        $c = self::candidat(['prix_effectifs' => [['key' => 'P1', 'prix' => $plancher - 1.0e-9]]]);
        self::assertTrue(GuardRails::candidatValide($c, $this->produitsParCle(), self::MARGE));
    }

    public function test_refuse_categorie_evenement(): void
    {
        $c = self::candidat(['keys' => ['PEV'], 'produits' => [['key' => 'PEV', 'label' => 'Borne arcade']]]);
        self::assertFalse(GuardRails::candidatValide($c, $this->produitsParCle(), self::MARGE));
    }

    public function test_refuse_key_inconnue(): void
    {
        $c = self::candidat(['keys' => ['ZZ']]);
        self::assertFalse(GuardRails::candidatValide($c, $this->produitsParCle(), self::MARGE));
    }

    public function test_refuse_cout_suspect_avec_prix_effectifs(): void
    {
        $c = self::candidat([
            'keys'          => ['P1', 'PS'],
            'produits'      => [['key' => 'P1', 'label' => 'Produit 1'], ['key' => 'PS', 'label' => 'Sans coût']],
            'prix_effectifs' => [['key' => 'P1', 'prix' => 2.0], ['key' => 'PS', 'prix' => 2.0]],
        ]);
        self::assertFalse(GuardRails::candidatValide($c, $this->produitsParCle(), self::MARGE));
    }

    public function test_refuse_cout_raison_sur_r3_sans_prix(): void
    {
        $c = self::candidat(['regle' => 'R3', 'keys' => ['PS'], 'produits' => [['key' => 'PS', 'label' => 'Sans coût']]]);
        self::assertFalse(GuardRails::candidatValide($c, $this->produitsParCle(), self::MARGE));
    }

    public function test_accepte_cout_raison_sur_r2_sans_prix(): void
    {
        $c = self::candidat(['regle' => 'R2', 'keys' => ['PS'], 'produits' => [['key' => 'PS', 'label' => 'Sans coût']]]);
        self::assertTrue(GuardRails::candidatValide($c, $this->produitsParCle(), self::MARGE));
    }

    public function test_refuse_gain_desordonne(): void
    {
        self::assertFalse(GuardRails::candidatValide(
            self::candidat(['gain' => ['bas' => 30.0, 'median' => 20.0, 'haut' => 10.0]]),
            $this->produitsParCle(),
            self::MARGE
        ));
        self::assertFalse(GuardRails::candidatValide(
            self::candidat(['gain' => ['bas' => 5.0, 'median' => 50.0, 'haut' => 10.0]]),
            $this->produitsParCle(),
            self::MARGE
        ));
        self::assertFalse(GuardRails::candidatValide(
            self::candidat(['gain' => ['bas' => -10.0, 'median' => 20.0, 'haut' => 30.0]]),
            $this->produitsParCle(),
            self::MARGE
        ));
    }

    public function test_refuse_confiance_inconnue_et_effort_hors_bornes(): void
    {
        self::assertFalse(GuardRails::candidatValide(
            self::candidat(['confiance' => 'certain']),
            $this->produitsParCle(),
            self::MARGE
        ));
        self::assertFalse(GuardRails::candidatValide(self::candidat(['effort' => 4]), $this->produitsParCle(), self::MARGE));
        self::assertFalse(GuardRails::candidatValide(self::candidat(['effort' => 0]), $this->produitsParCle(), self::MARGE));
    }
}
