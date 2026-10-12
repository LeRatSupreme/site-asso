<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ScanLabel;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la file « libellés factures non rattachés » : la partie
 * pure ScanLabel::unmatchedLabels() — sélection des libellés de scan
 * qu'aucun produit du catalogue n'a reconnus (ex. « EVIEN 50CL »
 * pour « Eau »), dédupliquée, ordre stable. Ces libellés alimentent
 * l'encart « Libellés factures non rattachés » de la page
 * « Ajouter une appellation ».
 */
final class ScanLabelTest extends TestCase
{
    public function testLibelleNonReconnuEstCollecte(): void
    {
        $lines = [
            ['label' => 'MONSTER PIPELINE PUNCH 24X0,5L', 'product' => 'Monster rose'],
            ['label' => 'EVIEN 50CL', 'product' => null],
        ];

        self::assertSame(['EVIEN 50CL'], ScanLabel::unmatchedLabels($lines));
    }

    public function testLigneSansCleProductEstCollectee(): void
    {
        // Enrichissement sauté (catalogue vide) : la ligne n'a pas de
        // clé « product » — traitée comme non reconnue.
        self::assertSame(
            ['EVIEN 50CL'],
            ScanLabel::unmatchedLabels([['label' => 'EVIEN 50CL']])
        );
    }

    public function testLibelleVideIgnore(): void
    {
        self::assertSame([], ScanLabel::unmatchedLabels([
            ['label' => '   ', 'product' => null],
            ['product' => 'Eau'],
        ]));
    }

    public function testDoublonsDedupliquesEtOrdreStable(): void
    {
        $lines = [
            ['label' => 'EVIEN 50CL', 'product' => null],
            ['label' => 'CRISTALINE 6X1,5L', 'product' => null],
            ['label' => 'EVIEN 50CL', 'product' => null],
        ];

        self::assertSame(
            ['EVIEN 50CL', 'CRISTALINE 6X1,5L'],
            ScanLabel::unmatchedLabels($lines)
        );
    }

    public function testLibelleEstTrimme(): void
    {
        self::assertSame(
            ['EVIEN 50CL'],
            ScanLabel::unmatchedLabels([['label' => '  EVIEN 50CL  ', 'product' => null]])
        );
    }

    public function testToutesReconnuesFileVide(): void
    {
        self::assertSame([], ScanLabel::unmatchedLabels([
            ['label' => 'MONSTER PIPELINE PUNCH 24X0,5L', 'product' => 'Monster rose'],
            ['label' => 'CRISTALINE 6X1,5L', 'product' => 'Eau'],
        ]));
    }
}
