<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Verrouilles du design system côté CSS (lot 1 de la refonte UI).
 *
 * Ces tests garantissent que :
 *  - components.css ne contient AUCUNE couleur littérale (aucun hex) :
 *    toute couleur passe par une var(--...) de tokens.css ;
 *  - aucun dégradé (linear/radial) n'est introduit, dans aucun des deux
 *    fichiers : décoration interdite ;
 *  - tokens.css définit bien les variables imposées par le contrat de
 *    design (accent, états, tailles, espacements, largeur) ;
 *  - les tokens sont bien portés par le sélecteur html.ds (portée des
 *    pages migrées uniquement).
 */
final class DesignSystemCssTest extends TestCase
{
    private function lire(string $fichier): string
    {
        $chemin = __DIR__ . '/../../public/assets/css/' . $fichier;
        self::assertFileExists($chemin, 'Fichier CSS du design system absent : ' . $fichier);

        $contenu = file_get_contents($chemin);
        self::assertNotFalse($contenu);

        return $contenu;
    }

    public function test_components_css_ne_contient_aucune_couleur_hex(): void
    {
        $css = $this->lire('components.css');
        preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $css, $m);

        self::assertSame(
            [],
            $m[0],
            'components.css ne doit contenir aucune couleur hex : utiliser var(--...) de tokens.css.'
        );
    }

    public function test_aucun_degrade_dans_les_deux_feuilles(): void
    {
        foreach (['tokens.css', 'components.css'] as $fichier) {
            $css = $this->lire($fichier);
            self::assertStringNotContainsString('linear-gradient', $css, "$fichier : les dégradés sont interdits.");
            self::assertStringNotContainsString('radial-gradient', $css, "$fichier : les dégradés sont interdits.");
        }
    }

    public function test_tokens_css_definit_les_variables_imposees(): void
    {
        $css = $this->lire('tokens.css');

        $variables = [
            '--accent', '--accent-strong', '--accent-soft',
            '--ok', '--warn', '--ko',
            '--fs-1', '--fs-2', '--fs-3', '--fs-4',
            '--sp-1', '--sp-2', '--sp-3', '--sp-4', '--sp-6', '--sp-8',
            '--radius-s', '--radius-m', '--maxw', '--font', '--lh',
        ];

        foreach ($variables as $variable) {
            self::assertStringContainsString(
                $variable . ':',
                $css,
                "tokens.css doit définir la variable $variable."
            );
        }
    }

    public function test_les_tokens_sont_portes_par_le_selecteur_html_ds(): void
    {
        $css = $this->lire('tokens.css');
        self::assertStringContainsString('html.ds', $css, 'Les tokens doivent être portés par html.ds.');
        self::assertStringContainsString('data-theme="dark"', $css, 'Le thème sombre doit être prévu (html.ds[data-theme="dark"]).');
    }
}
