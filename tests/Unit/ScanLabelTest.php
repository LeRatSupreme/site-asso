<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Model;
use App\Models\ScanLabel;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la file « libellés factures non rattachés ».
 *
 * Partie PURE :
 *  - unmatchedLabels() : sélection historique des libellés de scan
 *    qu'aucun produit du catalogue n'a reconnus (ex. « EVIEN 50CL »
 *    pour « Eau »), dédupliquée, ordre stable ;
 *  - allLabels() : TOUS les libellés d'un scan (reconnus ou non) —
 *    le formulaire « Nouvelles appellations » les montre pré-remplis
 *    tant que l'utilisateur ne les a pas nommés.
 *
 * Partie BASE (auto-skip sans `aeic_test`) : la nouvelle règle de
 * purge de pending()/isResolved() — un libellé ne sort de la file que
 * si un ALIAS utilisateur existe pour lui exactement, ou s'il est
 * EXACTEMENT (clé normalisée) une entrée du catalogue. Les
 * correspondances FLOUES (troncatures, « SLIM BTE 33CL ») le laissent
 * en file : l'heuristique ne nomme plus à la place de l'utilisateur.
 */
final class ScanLabelTest extends TestCase
{
    use TestDatabaseTrait;

    private ?\PDO $pdo = null;

    protected function setUp(): void
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped(
                'Base aeic_test indisponible : tests file scan_labels ignorés.'
            );
        }

        $this->reset($pdo, ['scan_labels', 'product_aliases', 'products', 'sales']);
        $this->pdo = $pdo;
        Model::setTestPdo($pdo);
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
        $this->pdo = null;
    }

    private function insertLabel(string $label): void
    {
        $this->pdo->prepare('INSERT INTO scan_labels (id, label) VALUES (?, ?)')
            ->execute(['scan_test_' . bin2hex(random_bytes(6)), $label]);
    }

    private function insertFiche(string $name): void
    {
        $this->pdo->prepare('INSERT INTO products (id, name) VALUES (?, ?)')
            ->execute(['prod_test_' . bin2hex(random_bytes(6)), $name]);
    }

    private function insertAlias(string $raw, string $key): void
    {
        $this->pdo->prepare(
            'INSERT INTO product_aliases (id, raw_description, product_key, created_at) VALUES (?, ?, ?, NOW())'
        )->execute(['alias_test_' . bin2hex(random_bytes(6)), $raw, $key]);
    }

    /** Libellés restant en file après purge. */
    private function pendingLabels(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['label'],
            ScanLabel::pending()
        );
    }

    // ————————————————————————————————————————————————————————————
    // unmatchedLabels (pure) : comportement historique conservé
    // ————————————————————————————————————————————————————————————

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

    // ————————————————————————————————————————————————————————————
    // allLabels (pure) : TOUS les libellés, reconnus ou non
    // ————————————————————————————————————————————————————————————

    public function testTousLesLibellesReconnusOuNon(): void
    {
        $lines = [
            ['label' => 'MONSTER PIPELINE PUNCH 24X0,5L', 'product' => 'Monster rose'],
            ['label' => 'EVIEN 50CL', 'product' => null],
            ['label' => '  EVIEN 50CL  ', 'product' => 'Eau'],
            ['product' => 'Coca'],
        ];

        self::assertSame(
            ['MONSTER PIPELINE PUNCH 24X0,5L', 'EVIEN 50CL'],
            ScanLabel::allLabels($lines)
        );
    }

    public function testAllLabelsSansLibelleRien(): void
    {
        self::assertSame([], ScanLabel::allLabels([
            ['label' => '   '],
            ['units' => 2],
        ]));
    }

    public function testAllLabelsDoublonsDedupliques(): void
    {
        self::assertSame(
            ['EVIEN 50CL', 'KitKat'],
            ScanLabel::allLabels([
                ['label' => 'EVIEN 50CL', 'product' => null],
                ['label' => 'KitKat', 'product' => 'KitKat'],
                ['label' => 'EVIEN 50CL', 'product' => null],
            ])
        );
    }

    // ————————————————————————————————————————————————————————————
    // Nouvelle règle de purge (base aeic_test) : EXACT purge, FLOU garde
    // ————————————————————————————————————————————————————————————

    public function testLibelleFlouResteEnFile(): void
    {
        // La fiche « Oasis Tropical » existe, mais le libellé scan n'est
        // PAS exactement cette clé (conditionnements « SLIM BTE 33CL ») :
        // l'ancienne règle le purgeait (palier inclusion) — plus maintenant.
        $this->insertFiche('Oasis Tropical');
        $this->insertLabel('OASIS TROPICAL SLIM BTE 33CL');

        self::assertSame(['OASIS TROPICAL SLIM BTE 33CL'], $this->pendingLabels());
    }

    public function testLibelleExactDuCatalogueEstPurge(): void
    {
        // « eau » = fiche « Eau » une fois normalisé (casse/accents) :
        // purge.
        $this->insertFiche('Eau');
        $this->insertLabel('eau');
        $this->insertLabel('EVIEN 50CL');

        self::assertSame(['EVIEN 50CL'], $this->pendingLabels());
    }

    public function testAliasUtilisateurPurgeLeLibelle(): void
    {
        $this->insertAlias('MONSTER PIPELINE PUNCH 24X0,5L', 'Monster rose');
        $this->insertLabel('MONSTER PIPELINE PUNCH 24X0,5L');
        // Variante d'écriture (casse) : même clé normalisée -> purge aussi.
        $this->insertLabel('monster pipeline punch 24x0,5l');
        $this->insertLabel('SAUMON FUME 200G');

        self::assertSame(['SAUMON FUME 200G'], $this->pendingLabels());
    }

    public function testRecordAllMemoriseTousLesLibelles(): void
    {
        ScanLabel::recordAll([
            ['label' => 'EVIEN 50CL', 'product' => null],
            ['label' => 'KitKat', 'product' => 'KitKat'],
            ['label' => 'EVIEN 50CL', 'product' => null],
        ]);

        $rows = ScanLabel::all();
        $labels = array_map(static fn (array $r): string => (string) $r['label'], $rows);
        sort($labels);

        self::assertSame(['EVIEN 50CL', 'KitKat'], $labels);
    }
}
