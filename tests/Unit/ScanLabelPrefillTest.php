<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ProductKeyMerge;
use App\Models\ScanLabel;
use PHPUnit\Framework\TestCase;

/**
 * Tests PURS (sans base) du pré-remplissage « Nouvelles appellations » :
 *
 *  - ScanLabel::mergePendingSources() : union dédupliquée de la file
 *    des libellés scannés (hint 'scan') et des fiches carte au nom de
 *    facture (hint 'fiche') — déduplication par clé normalisée
 *    (StockPublic::normalizeKey), scans d'abord (occurrences
 *    décroissantes), fiches ensuite (alphabétique) ;
 *  - ProductKeyMerge::canMerge() : pré-condition PURE de la fusion
 *    automatique (clés non vides et distinctes une fois normalisées).
 */
final class ScanLabelPrefillTest extends TestCase
{
    // ————————————————————————————————————————————————————————————
    // mergePendingSources : union, dédup, ordre
    // ————————————————————————————————————————————————————————————

    public function testScansDabordPuisFichesAlphabetiques(): void
    {
        $rows = ScanLabel::mergePendingSources(
            [
                ['label' => 'EVIEN 50CL', 'occurrences' => 3],
                ['label' => 'SAUMON FUME 200G', 'occurrences' => 7],
                ['label' => 'KIT KAT BOITE 24', 'occurrences' => 3],
            ],
            [
                ['label' => 'RED BULL BLUE BOITE 25CL'],
                ['label' => 'MARS 51G UVC'],
            ]
        );

        self::assertSame(
            ['SAUMON FUME 200G', 'EVIEN 50CL', 'KIT KAT BOITE 24', 'MARS 51G UVC', 'RED BULL BLUE BOITE 25CL'],
            array_map(static fn (array $r): string => $r['label'], $rows)
        );
        // Occurrences décroissantes côté scans ; à égalité (3/3),
        // ordre d'entrée conservé.
        self::assertSame([7, 3, 3, 0, 0], array_map(static fn (array $r): int => $r['occurrences'], $rows));
        self::assertSame(['scan', 'scan', 'scan', 'fiche', 'fiche'], array_map(static fn (array $r): string => $r['hint'], $rows));
    }

    public function testDedupParCleNormaliseeScanGagneSurFiche(): void
    {
        // Même clé normalisée (casse/espaces ignorés) : la fiche n'est
        // PAS reprise — le libellé scanné (avec ses occurrences) suffit.
        $rows = ScanLabel::mergePendingSources(
            [['label' => 'OASIS TROPICAL SLIM BTE 33CL', 'occurrences' => 2]],
            [['label' => 'oasis tropical  SLIM BTE 33cl']]
        );

        self::assertCount(1, $rows);
        self::assertSame('scan', $rows[0]['hint']);
        self::assertSame(2, $rows[0]['occurrences']);
    }

    public function testSuggestedEtCategoryTransmis(): void
    {
        $rows = ScanLabel::mergePendingSources(
            [['label' => 'EVIEN 50CL', 'occurrences' => 1]],
            [['label' => 'MARS 51G UVC', 'suggested' => 'Mars', 'category' => 'Snacks']]
        );

        self::assertSame('', $rows[0]['suggested']);
        self::assertSame('Mars', $rows[1]['suggested']);
        self::assertSame('Snacks', $rows[1]['category']);
    }

    public function testLignesVidesOuSansCleIgnorees(): void
    {
        $rows = ScanLabel::mergePendingSources(
            [
                ['label' => '   ', 'occurrences' => 5],
                ['label' => 'EVIEN 50CL'],
                ['occurrences' => 4],
            ],
            [['label' => '']]
        );

        self::assertSame(['EVIEN 50CL'], array_map(static fn (array $r): string => $r['label'], $rows));
        // Occurrences inconnues -> 0.
        self::assertSame(0, $rows[0]['occurrences']);
    }

    public function testSourcesVidesRien(): void
    {
        self::assertSame([], ScanLabel::mergePendingSources([], []));
    }

    // ————————————————————————————————————————————————————————————
    // canMerge : pré-condition de l'auto-fusion
    // ————————————————————————————————————————————————————————————

    public function testFusionNomLongVersNomCourtAutorisee(): void
    {
        self::assertTrue(ProductKeyMerge::canMerge('OASIS TROPICAL SLIM BTE 33CL', 'Oasis Tropical'));
        // Casse/accents/espaces ignorés : « eau » vs « Eau » = MÊME clé,
        // donc PAS fusible (l'alias vise la fiche elle-même).
        self::assertFalse(ProductKeyMerge::canMerge('eau', 'Eau'));
        self::assertFalse(ProductKeyMerge::canMerge('Oasis Tropical', 'oasis tropical'));
    }

    public function testFusionRefuseeSiCleVideOuIdentique(): void
    {
        self::assertFalse(ProductKeyMerge::canMerge('', 'Eau'));
        self::assertFalse(ProductKeyMerge::canMerge('EVIEN 50CL', ''));
        self::assertFalse(ProductKeyMerge::canMerge('Eau', 'Eau'));
        self::assertFalse(ProductKeyMerge::canMerge('   ', '   '));
    }
}
