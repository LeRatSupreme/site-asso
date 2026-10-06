<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Pont PHPUnit vers le harnais Node.js des helpers dynamiques de la
 * grille de saisie d'achats (public/assets/js/compta-saisie.js).
 *
 * La logique « côté dynamique » (normalisation des clés produit,
 * collage « Nom ; Qté ; Montant », décomposition HT/TTC, doublons de
 * lignes) vit en JavaScript dans le navigateur : ce test l'exécute
 * réellement avec Node pour qu'une régression soit attrapée par la
 * suite PHPUnit standard. Saute si Node est indisponible.
 */
final class PurchaseGridJsTest extends TestCase
{
    public function test_harnais_js_des_helpers_de_saisie(): void
    {
        $node = self::nodeBinary();
        if ($node === null) {
            self::markTestSkipped('Node.js indisponible : tests JS de la grille de saisie ignorés.');
        }

        $harness = dirname(__DIR__) . '/js/compta-saisie.test.js';
        self::assertFileExists($harness);

        $output = [];
        $code = 1;
        exec(escapeshellcmd($node) . ' ' . escapeshellarg($harness) . ' 2>&1', $output, $code);

        self::assertSame(
            0,
            $code,
            "Le harnais JS compta-saisie a échoué (code $code) :\n" . implode("\n", $output)
        );
        self::assertStringContainsString('JS OK', implode("\n", $output));
    }

    /**
     * La vue Achats doit toujours charger le fichier partagé : si
     * quelqu'un recolle la logique en dur dans la vue, les tests JS
     * ne couvriraient plus le code réellement exécuté.
     */
    public function test_la_vue_achats_charge_le_fichier_partage(): void
    {
        $view = (string) file_get_contents(AEIC_VIEWS . '/admin/compta/purchases.php');
        self::assertStringContainsString('assets/js/compta-saisie.js', $view);
        self::assertStringNotContainsString(
            'function normKey(',
            $view,
            'La normalisation doit venir du fichier partagé testé, pas d’une copie inline.'
        );
        self::assertStringNotContainsString(
            'function parsePasteLine(',
            $view,
            'L’analyse du collage doit venir du fichier partagé testé, pas d’une copie inline.'
        );
    }

    /**
     * Parité PHP/JS de la normalisation : les mêmes cas de test que le
     * harnais JS, exécutés contre StockPublic::normalizeKey — les deux
     * implémentations doivent converger vers la même clé (sinon
     * l'anti-doublon de la grille diverge du serveur).
     */
    public function test_norm_key_js_en_parity_avec_php(): void
    {
        if (!class_exists(\App\Core\Compta\StockPublic::class)) {
            self::markTestSkipped('StockPublic indisponible (autoloader ?).');
        }

        // Cas stabilisés côté JS (tests/js/compta-saisie.test.js).
        $fixtures = [
            'Red Bull'                        => 'redbull',
            'Crème Fraîche'                   => 'cremefraiche',
            'Bueno_white'                     => 'buenowhite',
            'Coca-Cherry'                     => 'cocacherry',
            'Perrier (33cl)'                  => 'perrier33cl',
            'Redbull Peach'                   => 'redbullpeach',
            'M&Ms'                            => 'm&ms',
            'Oasis Pomme Cassis Framboise'    => 'oasispommecassisframboise',
        ];

        foreach ($fixtures as $input => $expected) {
            self::assertSame(
                $expected,
                \App\Core\Compta\StockPublic::normalizeKey($input),
                "PHP et JS doivent normaliser « $input » de la même façon."
            );
        }
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
