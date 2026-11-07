<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Core\Compta\Forecast\ProfitForecast;
use PHPUnit\Framework\TestCase;

/**
 * Projection de bénéfice à 7 jours DÉRIVÉE DES MÊMES UNITÉS que le
 * réapprovisionnement (calibrées sur le CA prévu du moteur) : ca_p =
 * unites × prix, marge_p = unites × (prix − coût) pour les produits
 * fiables, agrégats par catégorie avec marge_pct = marge/ca COMPARABLE
 * à la marge observée, diagnostics des produits sans coût fiable
 * (cout_inconnu / cout_zero / prix_inferieur_au_cout) et note UI.
 */
final class ProfitForecastTest extends TestCase
{
    /**
     * Ligne d'unités au contrat de ProductForecast::units (champs
     * consommés par ProfitForecast::compute).
     */
    private static function ligne(
        string $key,
        string $label,
        string $category,
        float $unites,
        ?float $prix,
        ?float $cout,
        bool $suspect
    ): array {
        return [
            'key'         => $key,
            'label'       => $label,
            'category'    => $category,
            'unites'      => $unites,
            'unitPrice'   => $prix,
            'unitCost'    => $cout,
            'costSuspect' => $suspect,
        ];
    }

    public function test_repartition_marge_et_suspects(): void
    {
        $unites = [
            self::ligne('cafe', 'Café', 'Boissons', 100.0, 2.0, 1.0, false),
            self::ligne('chips', 'Chips', 'Snacks', 150.0, 2.0, null, true),
            self::ligne('soda', 'Soda', 'Snacks', 50.0, 2.0, 0.0, true),
            self::ligne('croiss', 'Croissant', 'Divers', 50.0, 1.0, 2.0, true),
        ];

        $r = ProfitForecast::compute($unites, 650.0);

        // Seul le café est fiable : 100 × (2 − 1) = 100 € de marge.
        self::assertEqualsWithDelta(100.0, $r['total_marge_7j'], 0.0001);

        self::assertCount(3, $r['suspects']);
        self::assertSame('cout_inconnu', $r['suspects'][0]['raison']);
        self::assertSame('cout_zero', $r['suspects'][1]['raison']);
        self::assertSame('prix_inferieur_au_cout', $r['suspects'][2]['raison']);

        // CA prévu des suspects : 300 + 100 + 50 = 450 € sur fenêtre 650 €.
        self::assertEqualsWithDelta(450.0 / 650.0 * 100.0, $r['pct_ca_sans_cout_fiable'], 0.0001);
        // Régime ≥ 5 % : entier + produits suspects nommés (3 max).
        self::assertSame('69 % du CA récent sans coût fiable (Chips, Soda, Croissant) : bénéfice sous-estimé ou surestimé.', $r['note']);
        // La valeur du payload reste BRUTE (le rendu fait l'arrondi).
        self::assertEqualsWithDelta(69.230769, $r['pct_ca_sans_cout_fiable'], 0.0001);

        self::assertSame(['Boissons', 'Snacks', 'Divers'], array_keys($r['par_categorie']));
        self::assertEqualsWithDelta(200.0, $r['par_categorie']['Boissons']['ca'], 0.0001);
        self::assertEqualsWithDelta(100.0, $r['par_categorie']['Boissons']['marge'], 0.0001);
        self::assertEqualsWithDelta(50.0, $r['par_categorie']['Boissons']['marge_pct'], 0.0001, 'Marge/ca des MÊMES unités : comparable à l\'observé.');
        self::assertEqualsWithDelta(0.0, $r['par_categorie']['Snacks']['marge'], 0.0001, 'Suspects : aucune marge.');
        self::assertEqualsWithDelta(0.0, $r['par_categorie']['Snacks']['marge_pct'], 0.0001);
        self::assertEqualsWithDelta(400.0, $r['par_categorie']['Snacks']['ca'], 0.0001, 'Le CA des suspects compte dans les catégories.');
    }

    /**
     * Style « Redbull » : plusieurs variantes de prix/coût dans une même
     * catégorie — la marge de la catégorie est exactement la SOMME des
     * marges des variantes (±0,01), à l'échelle des unités calibrées.
     */
    public function test_marge_categorie_egale_somme_des_marges_variantes(): void
    {
        $unites = [
            self::ligne('rb-peach', 'Redbull Peach', 'Redbull', 50.0, 1.5, 1.3, false),
            self::ligne('rb-ice', 'Red bull ice', 'Redbull', 38.0, 1.5, 1.3, false),
            self::ligne('rb-classic', 'Redbull', 'Redbull', 10.0, 2.0, 1.7, false),
        ];

        $r = ProfitForecast::compute($unites, 300.0);

        $sommeVariantes = 50.0 * (1.5 - 1.3) + 38.0 * (1.5 - 1.3) + 10.0 * (2.0 - 1.7);
        self::assertEqualsWithDelta($sommeVariantes, $r['par_categorie']['Redbull']['marge'], 0.01);
        self::assertEqualsWithDelta($sommeVariantes, $r['total_marge_7j'], 0.01);
        // Invariant : Σ ca des catégories = Σ unites × prix.
        self::assertEqualsWithDelta(50.0 * 1.5 + 38.0 * 1.5 + 10.0 * 2.0, $r['par_categorie']['Redbull']['ca'], 0.0001);
        self::assertEqualsWithDelta(
            $r['par_categorie']['Redbull']['marge'] / $r['par_categorie']['Redbull']['ca'] * 100.0,
            $r['par_categorie']['Redbull']['marge_pct'],
            0.0001
        );
    }

    public function test_produits_tous_fiables_marge_par_categorie(): void
    {
        $unites = [
            self::ligne('a', 'A', 'Cat', 75.0, 2.0, 1.0, false),
            self::ligne('b', 'B', 'Cat', 25.0, 2.0, 1.0, false),
        ];

        $r = ProfitForecast::compute($unites, 200.0);

        // unites 75/25 → ca 150/50, marge 75/25, même catégorie 'Cat'.
        self::assertEqualsWithDelta(100.0, $r['total_marge_7j'], 0.0001);
        self::assertEqualsWithDelta(100.0, $r['par_categorie']['Cat']['marge'], 0.0001, '75 (A) + 25 (B).');
        self::assertEqualsWithDelta(200.0, $r['par_categorie']['Cat']['ca'], 0.0001);
        self::assertEqualsWithDelta(50.0, $r['par_categorie']['Cat']['marge_pct'], 0.0001, 'Marge 100 sur CA 200.');
        self::assertSame([], $r['suspects']);
        self::assertEqualsWithDelta(0.0, $r['pct_ca_sans_cout_fiable'], 0.0001);
        self::assertStringContainsString('Coûts fiables', $r['note']);
    }

    public function test_cas_limites(): void
    {
        // Aucun produit.
        $vide = ProfitForecast::compute([], 0.0);
        self::assertSame([], $vide['par_categorie']);
        self::assertEqualsWithDelta(0.0, $vide['total_marge_7j'], 0.0001);
        self::assertEqualsWithDelta(0.0, $vide['pct_ca_sans_cout_fiable'], 0.0001);

        // Prix null et coût null sur produit suspect : CA prévu inconnu → 0.
        $q = [self::ligne('y', 'Y', 'Cat', 5.0, null, null, true)];
        $r2 = ProfitForecast::compute($q, 10.0);
        self::assertSame('cout_inconnu', $r2['suspects'][0]['raison']);
        self::assertEqualsWithDelta(0.0, $r2['pct_ca_sans_cout_fiable'], 0.0001, 'Prix inconnu → CA prévu non chiffrable.');
        self::assertEqualsWithDelta(0.0, $r2['par_categorie']['Cat']['ca'], 0.0001);

        // Prix null mais coût connu (non suspect) : ni ca ni marge, pas d'erreur.
        $p = [self::ligne('x', 'X', 'Cat', 10.0, null, 1.0, false)];
        $r = ProfitForecast::compute($p, 20.0);
        self::assertSame([], $r['suspects']);
        self::assertEqualsWithDelta(0.0, $r['total_marge_7j'], 0.0001);
    }

    /**
     * Régime « négligeable » (pct < 0,5 %) : pas de pourcentage arrondi à
     * « 0 % » trompeur, produits nommés et marge déclarée peu biaisée.
     */
    public function test_note_regime_negligeable_sous_un_demi_pourcent(): void
    {
        $unites = [
            self::ligne('fiable', 'Fiable', 'Cat', 325.0, 2.0, 1.0, false),
            self::ligne('haribo', 'Haribo', 'Bonbons', 5.0, 0.4, null, true),
        ];

        $r = ProfitForecast::compute($unites, 652.0);

        // 2 € de CA suspect sur 652 € = 0,307 % < 0,5 %.
        self::assertEqualsWithDelta(2.0 / 652.0 * 100.0, $r['pct_ca_sans_cout_fiable'], 0.0001);
        self::assertSame(
            'Quelques produits sans coût fiable (Haribo) : impact < 0,5 % du CA, marge peu biaisée.',
            $r['note']
        );
    }

    /**
     * Régime intermédiaire (0,5 % ≤ pct < 5 %) : 1 décimale (virgule FR),
     * « possiblement biaisé » et produits nommés.
     */
    public function test_note_regime_intermediaire_une_decimale(): void
    {
        $unites = [
            self::ligne('fiable', 'Fiable', 'Cat', 320.0, 2.0, 1.0, false),
            self::ligne('haribo', 'Haribo', 'Bonbons', 5.0, 1.2, null, true),
            self::ligne('sucette', 'Sucette', 'Bonbons', 5.0, 0.6, null, true),
        ];

        $r = ProfitForecast::compute($unites, 649.0);

        // 9 € de CA suspect sur 649 € = 1,386… % → affiché « 1,4 ».
        self::assertEqualsWithDelta(9.0 / 649.0 * 100.0, $r['pct_ca_sans_cout_fiable'], 0.0001);
        self::assertSame(
            '1,4 % du CA récent sans coût fiable (Haribo, Sucette) : bénéfice possiblement biaisé.',
            $r['note']
        );
    }

    /** La note nomme au plus 3 produits suspects. */
    public function test_note_nomme_trois_suspects_maximum(): void
    {
        $unites = [
            self::ligne('a', 'Aaa', 'Cat', 10.0, 10.0, null, true),
            self::ligne('b', 'Bbb', 'Cat', 10.0, 10.0, null, true),
            self::ligne('c', 'Ccc', 'Cat', 10.0, 10.0, null, true),
            self::ligne('d', 'Ddd', 'Cat', 10.0, 10.0, null, true),
        ];

        $r = ProfitForecast::compute($unites, 400.0);

        self::assertStringContainsString('(Aaa, Bbb, Ccc)', $r['note']);
        self::assertStringNotContainsString('Ddd', $r['note']);
    }
}
