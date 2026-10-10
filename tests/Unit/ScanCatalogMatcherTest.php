<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Compta\ScanCatalogMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Tests du reconnaisseur catalogue ScanCatalogMatcher : libellés OCR
 * bruts des factures METRO (ex. « RED BULL WHITE BOITE 25CL ») vers
 * les clés d'achat/stock réellement utilisées (achats et ventes :
 * « Red Bull Blanche », « oasis pomme poire »…).
 *
 * Le fixture catalogue MIRE LE VPS (extrait du 10/10/2026) : fiches
 * produits, alias de ventes (product_aliases), clés SumUp — dont les
 * subtilités qui font la difficulté :
 *  - doublons de fiches (« Surfizz Fruit Graine » / « Surfizz Fruits
 *    gaine », deux crunch) : l'égalité parfaite doit être REFUSÉE ;
 *  - cible d'alias sans fiche (« oasis pomme poire », clé SumUp) ;
 *  - orthographe fiche prioritaire (« Bounty » pour la cible « bounty »,
 *    « Fuze Tea » pour la vente « fuze tea ») ;
 *  - alias enseignant le savoir marque (« MONSTER ULTRA ZERO » ->
 *    « Monster Blanche » : la canette blanche, aucun mot commun).
 *
 * Libellés de test : ceux de la facture scan.pdf (14 lignes, 14/14
 * reconnues en bout en bout sur le VPS).
 */
final class ScanCatalogMatcherTest extends TestCase
{
    /**
     * Extrait fidèle du catalogue du VPS (10/10/2026).
     *
     * @return array<array{key:string, name:string, tokens:list<string>}>
     */
    private static function catalog(): array
    {
        $fiches = [
            'Bounty', 'Fuze Tea', 'KitKat', 'Lion', 'Minute Maid',
            'Minute Maid Orange', 'Minute Maid Pomme', 'Monster',
            'Monster Blanche', 'Monster Bleu', 'Nutella B Ready', 'Oasis',
            'Oasis Pomme Cassis Framboise', 'Oasis Tropical', 'Red Bull',
            'Red Bull Blanche', 'Red Bull Ice', 'Snickers',
            'Surfizz Fruit Graine', 'Surfizz Fruits gaine', 'Tete de mort',
            'crunch Snak Barres', 'Crunch snaks barres',
        ];

        $aliases = [
            ['raw_description' => 'Bounty', 'product_key' => 'bounty'],
            ['raw_description' => 'KitKat', 'product_key' => 'KitKat'],
            ['raw_description' => 'Monster Blanche', 'product_key' => 'Monster Blanche'],
            ['raw_description' => 'nutella b ready', 'product_key' => 'Nutella B Ready'],
            ['raw_description' => 'Oasis pomme poire', 'product_key' => 'oasis pomme poire'],
            ['raw_description' => 'Minute Maid Multi Vitamines', 'product_key' => 'Minute Maid Multi Vitamines'],
            ['raw_description' => 'Redbull Blanche', 'product_key' => 'Red Bull Blanche'],
            // Alias « savoir marque » : Ultra Zero est la canette blanche.
            ['raw_description' => 'MONSTER ULTRA ZERO', 'product_key' => 'Monster Blanche'],
        ];

        $sales = [
            'KitKat', 'Monster Blanche', 'Red Bull Blanche', 'Red Bull ice',
            'oasis pomme poire', 'fuze tea', 'Minute Maid Multi Vitamines',
            'Snickers', 'Montant personnalisé',
        ];

        return ScanCatalogMatcher::catalogFrom($fiches, $aliases, $sales);
    }

    /** @param array{key:string, name:string, tokens:list<string>} $catalog */
    private static function m(string $label, ?array $catalog = null): ?string
    {
        return ScanCatalogMatcher::match($label, $catalog ?? self::catalog());
    }

    // ————————————————————————————————————————————————————————————
    // Palier 1 : égalité exacte normalisée
    // ————————————————————————————————————————————————————————————

    public function testEgaliteExacteIgnoreCasseEtEspaces(): void
    {
        // « KIT KAT » (facture) = « KitKat » (fiche) une fois normalisé.
        self::assertSame('KitKat', self::m('KIT KAT'));
        self::assertSame('KitKat', self::m('kitkat'));
        self::assertSame('Oasis Tropical', self::m('OASIS TROPICAL'));
    }

    // ————————————————————————————————————————————————————————————
    // Palier 2 : mots-clés (dictionnaire EN/FR, faute de frappe)
    // ————————————————————————————————————————————————————————————

    public function testDictionnaireEnFrRedBullWhiteVersBlanche(): void
    {
        // WHITE ~ BLANCHE par le dictionnaire : 3 mots matchés battent
        // le « Red Bull » générique (2 mots).
        self::assertSame('Red Bull Blanche', self::m('RED BULL WHITE BOITE 25CL'));
    }

    public function testMotsClesBattentInclusionPlusCourte(): void
    {
        // « Minute Maid » (10 caractères, inclusion) serait trouvé, mais
        // l'alias « Minute Maid Multi Vitamines » matche 3 mots — c'est
        // lui, la vraie clé d'achat.
        self::assertSame('Minute Maid Multi Vitamines', self::m('MINUTE MAID NECT MULTI BTE33CL'));
    }

    public function testToleranceUneFauteDeFrappe(): void
    {
        // SNACK ~ SNAK (Levenshtein 1) : « crunch Snak Barres » (2 mots)
        // bat « Crunch snaks barres » (1 mot, SNACK~SNAKS = 2 fautes).
        self::assertSame('crunch Snak Barres', self::m('CRUNCH SNACK BARRES 30 GRS'));
    }

    public function testEgaliteParfaiteDepartageeParLaDistanceDesCles(): void
    {
        // Deux fiches doublons matchent 3 mots exacts chacune
        // (SURFIZZ/FRUITS/GAINE vs SURFIZZ/FRUIT~/GRAINE~) : égalité
        // parfaite — la distance des clés normalisées tranche, la clé
        // la plus proche du libellé gagne (celle de l'historique
        // d'achats).
        self::assertSame('Surfizz Fruits gaine', self::m('SURFIZZ FRUITS GAINE 2KG'));
    }

    public function testConfusionOcrChiffreUnPourLettreI(): void
    {
        // Cas réel du scan.pdf : Tesseract lit « SURF1ZZ » (1 au lieu
        // du I). Le token est écarté (chiffre), mais FRUITS+GAINE
        // mettent les deux fiches doublons à égalité 2-2 — et le
        // départage par distance absorbe la confusion : « surfizz… »
        // reste plus proche du libellé que « surfizzfruitgraine ».
        self::assertSame('Surfizz Fruits gaine', self::m('SURF1ZZ FRUITS GAINE 2KG'));
    }

    public function testMotsVidesEtUnitesIgnores(): void
    {
        // DE (mot vide), 400 (chiffre) et GRS (unité) ne comptent pas :
        // TETE+MORT = 2 mots signifiants suffisent.
        self::assertSame('Tete de mort', self::m('TETE DE MORT 400 GRS'));
        // B (1 lettre) et T10 (chiffre) ignorés côté libellé ET fiche.
        self::assertSame('Nutella B Ready', self::m('NUTELLA B READY T10'));
        self::assertSame('Red Bull Ice', self::m('RED BULL ICE BOITE 25CL'));
    }

    // ————————————————————————————————————————————————————————————
    // Palier 3 : inclusion (et alias « savoir marque »)
    // ————————————————————————————————————————————————————————————

    public function testInclusionAiguilleLaPlusLongue(): void
    {
        self::assertSame('Snickers', self::m('SNICKERS 50G'));
        self::assertSame('Bounty', self::m('BOUNTY BARRE 57G'));
        self::assertSame('Lion', self::m('LION BARRE 42G NESTLE'));
        // « fuze tea » (vente) et « Fuze Tea » (fiche) partagent la clé
        // normalisée : l'orthographe de la FICHE est retenue.
        self::assertSame('Fuze Tea', self::m('FUZETEA PECHE BOITE 33CL SLIM'));
    }

    public function testAliasRetablitLorthographeDeLaFiche(): void
    {
        // Cible d'alias « bounty » (sans majuscule) : la fiche « Bounty »
        // existe -> c'est son orthographe qui est proposée.
        self::assertSame('Bounty', self::m('BOUNTY'));
    }

    public function testAliasSansFicheGardeLaCleSumup(): void
    {
        // « oasis pomme poire » n'a PAS de fiche : la clé vendue (1
        // vente, 1 achat historiques) est proposée telle quelle.
        self::assertSame('oasis pomme poire', self::m('OASIS POMME POIRE SLIM33CL'));
    }

    public function testAliasSavoirMarqueMonsterUltraZero(): void
    {
        // Sans l'alias, « MONSTER ULTRA ZERO » ne matche que le générique
        // « Monster » (aucun mot commun avec « Monster Blanche ») ; l'alias
        // « MONSTER ULTRA ZERO » (15 caractères, inclusion) tranche.
        self::assertSame('Monster Blanche', self::m('MONSTER ULTRA ZERO BTE 50CL'));
    }

    // ————————————————————————————————————————————————————————————
    // Conservatisme : sans confiance suffisante, aucun match
    // ————————————————————————————————————————————————————————————

    public function testLibelleInconnuNeMatcheRien(): void
    {
        self::assertNull(self::m('SAUMON FUME 200G'));
        self::assertNull(self::m(''));
        self::assertNull(self::m('   '));
    }

    public function testUnSeulMotSignifiantNeMatchePasParMotsCles(): void
    {
        // « MONSTER » seul est ambigu entre 5 variantes : le palier
        // mots-clés exige 2 mots ; seul « Monster » générique répond
        // en inclusion — jamais une variante au hasard.
        self::assertSame('Monster', self::m('MONSTER BTE 50CL'));
    }

    public function testMatchSansCatalogRetourneNull(): void
    {
        // Panne/base vide : jamais d'exception, aucun match.
        self::assertNull(ScanCatalogMatcher::match('KIT KAT', []));
    }

    // ————————————————————————————————————————————————————————————
    // enrichLines : ligne de facture -> champ « product »
    // ————————————————————————————————————————————————————————————

    public function testEnrichLinesAjouteProductSansToucherLeLabel(): void
    {
        $lines = [
            ['label' => 'KIT KAT', 'units' => 36, 'total' => 19.99],
            ['label' => 'SAUMON FUME 200G', 'units' => 1, 'total' => 8.5],
            ['label' => '', 'units' => 2],
        ];

        $out = ScanCatalogMatcher::enrichLines($lines, self::catalog());

        self::assertSame('KitKat', $out[0]['product']);
        // Inconnu : null, pas de chaîne vide ambiguë.
        self::assertNull($out[1]['product']);
        self::assertNull($out[2]['product']);
        // Le libellé OCR brut reste la référence.
        self::assertSame('KIT KAT', $out[0]['label']);
    }

    public function testEnrichLinesSansCatalogLaisseLesLignesIntactes(): void
    {
        $lines = [['label' => 'KIT KAT', 'units' => 36]];

        $out = ScanCatalogMatcher::enrichLines($lines, []);

        self::assertSame($lines, $out);
        self::assertArrayNotHasKey('product', $out[0]);
    }
}
