<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Pont PHPUnit vers le harnais Node.js de la logique pure du collage
 * « Inventaire tablette SumUp » (public/assets/js/inventaire-tablette.js).
 *
 * La saisie d'un relevé tablette se fait par copier-coller : le découpage
 * des lignes (« Nom 12 », « Nom : 12 », nombre seul rattaché au libellé
 * du dessus…) et l'appariement aux clés produits (égalité lisible, clé
 * normalisée façon AliasSuggester::normalizeKey, inclusion unique) vivent
 * en JavaScript dans le navigateur. Ce test les exécute réellement avec
 * Node pour qu'une régression soit attrapée par la suite PHPUnit standard.
 * Saute si Node est indisponible.
 */
final class InventaireTabletJsTest extends TestCase
{
    public function test_harnais_js_du_collage_tablette(): void
    {
        $node = self::nodeBinary();
        if ($node === null) {
            self::markTestSkipped('Node.js indisponible : tests JS du collage tablette ignorés.');
        }

        $harness = dirname(__DIR__) . '/js/inventaire-tablette.test.js';
        self::assertFileExists($harness);

        $output = [];
        $code = 1;
        exec(escapeshellcmd($node) . ' ' . escapeshellarg($harness) . ' 2>&1', $output, $code);

        self::assertSame(
            0,
            $code,
            "Le harnais JS inventaire-tablette a échoué (code $code) :\n" . implode("\n", $output)
        );
        self::assertStringContainsString('JS OK', implode("\n", $output));
    }

    /**
     * La vue « Inventaire tablette » doit toujours charger le fichier
     * partagé : si quelqu'un recolle le parseur en dur dans la vue, les
     * tests JS ne couvriraient plus le code réellement exécuté. La grille
     * de saisie par ligne (filter + qtys[] par produit) ne doit pas
     * réapparaître : le collage est la seule méthode de saisie voulue.
     */
    public function test_la_vue_inventaire_tablette_charge_le_fichier_partage(): void
    {
        $view = (string) file_get_contents(AEIC_VIEWS . '/admin/compta/inventaire_tablette.php');
        self::assertStringContainsString('assets/js/inventaire-tablette.js', $view);
        self::assertStringContainsString('id="tablet-paste-form"', $view);
        self::assertStringContainsString('Analyser le collage', $view);
        self::assertStringNotContainsString(
            'function InventaireTabletParse',
            $view,
            'Le parseur doit venir du fichier partagé testé, pas d’une copie inline.'
        );
        self::assertStringNotContainsString(
            'id="inv-table"',
            $view,
            'L’ancienne grille de saisie par ligne (filtre + qtys[] par produit) a été retirée.'
        );
        self::assertStringNotContainsString(
            'id="inv-filter"',
            $view,
            'L’ancien filtre produit a été retiré avec la grille.'
        );
    }

    /** Binaire Node disponible sur la machine, sinon null. */
    private static function nodeBinary(): ?string
    {
        $devNull = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        foreach (['node', 'node.exe'] as $bin) {
            $out = [];
            $code = 1;
            exec(escapeshellcmd($bin) . ' -v 2>' . $devNull, $out, $code);
            if ($code === 0) {
                return $bin;
            }
        }

        return null;
    }
}
