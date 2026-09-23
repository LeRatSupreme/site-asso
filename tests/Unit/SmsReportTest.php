<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\SmsReport;
use PHPUnit\Framework\TestCase;

/**
 * Tests des fonctions pures du rapport SMS (sans base de données).
 */
final class SmsReportTest extends TestCase
{
    public function test_parse_recipients_accepte_un_json_valide(): void
    {
        $json = '[{"label":"Adrien","user":"12345678","pass":"abc"},{"label":"Bob","user":"87654321","pass":"xyz"}]';

        $r = SmsReport::parseRecipients($json);

        self::assertCount(2, $r);
        self::assertSame('Adrien', $r[0]['label']);
        self::assertSame('12345678', $r[0]['user']);
        self::assertSame('abc', $r[0]['pass']);
    }

    public function test_parse_recipients_rejette_les_entrees_invalides(): void
    {
        self::assertSame([], SmsReport::parseRecipients(''));
        self::assertSame([], SmsReport::parseRecipients('not json'));
        // Destinataire sans clé : ignoré.
        self::assertSame([], SmsReport::parseRecipients('[{"label":"X","user":"12345678","pass":""}]'));
        // Les chiffres non numériques de l'identifiant sont retirés.
        $r = SmsReport::parseRecipients('[{"user":"12a34","pass":"k"}]');
        self::assertSame('1234', $r[0]['user']);
    }

    public function test_normalize_days_trie_et_filtre(): void
    {
        self::assertSame('1,2,3,4,5', SmsReport::normalizeDays(['5', '1', '3', '2', '4']));
        self::assertSame('6,7', SmsReport::normalizeDays([7, 6]));
        self::assertSame('1,2,3,4,5', SmsReport::normalizeDays([]));            // défaut
        self::assertSame('1,7', SmsReport::normalizeDays(['9', '0', '1', 7]));  // hors bornes ignorés
    }

    public function test_day_matches_le_csv_iso(): void
    {
        self::assertTrue(SmsReport::dayMatches('1,2,3,4,5', 1));
        self::assertFalse(SmsReport::dayMatches('1,2,3,4,5', 6));
        self::assertTrue(SmsReport::dayMatches('6,7', 7));
    }

    public function test_render_template_remplace_les_variables(): void
    {
        $out = SmsReport::renderTemplate('CA {ca} / benef {benefice} / {top}', [
            '{ca}'       => '12,00 €',
            '{benefice}' => '5,00 €',
            '{top}'      => '1. Croissant x3',
        ]);

        self::assertSame('CA 12,00 € / benef 5,00 € / 1. Croissant x3', $out);
    }

    public function test_time_window_accepte_le_retard_de_cron(): void
    {
        self::assertTrue(SmsReport::timeWindowMatches('20:00', '20:00'));
        self::assertTrue(SmsReport::timeWindowMatches('20:00', '20:25'));
        self::assertFalse(SmsReport::timeWindowMatches('20:00', '20:31'));
        self::assertFalse(SmsReport::timeWindowMatches('20:00', '19:59'));
        self::assertFalse(SmsReport::timeWindowMatches('pas une heure', '20:00'));
    }

    public function test_evolution_label_compare_au_jour_precedent(): void
    {
        self::assertSame("\u{2796}", SmsReport::evolutionLabel(10.0, 0.0));      // hier = 0 : pas de comparaison
        self::assertSame("\u{1F4C8} +50 % vs hier", SmsReport::evolutionLabel(15.0, 10.0));
        self::assertSame("\u{1F4C9} -25 % vs hier", SmsReport::evolutionLabel(7.5, 10.0));
        self::assertSame("\u{2796} 0 % vs hier", SmsReport::evolutionLabel(10.0, 10.0));
    }

    public function test_top_line_formate_une_ligne_de_classement(): void
    {
        $line = SmsReport::topLine(2, ['label' => 'Croissant', 'qty' => 12, 'ca' => 10.8]);

        self::assertSame('2. Croissant x12 (' . number_format(10.8, 2, ',', ' ') . ' €)', $line);
    }

    public function test_variable_groups_couvre_toutes_les_variables(): void
    {
        $vars = [];
        foreach (SmsReport::variableGroups() as $group) {
            foreach ($group as $v) {
                $vars[] = '{' . $v['var'] . '}';
            }
        }

        // Toutes les variables documentées existent comme clés de varsFor.
        self::assertContains('{ca}', $vars);
        self::assertContains('{top3}', $vars);
        self::assertContains('{evolution}', $vars);
        self::assertContains('{panier}', $vars);
        self::assertContains('{semaine_ca}', $vars);
        self::assertContains('{categories}', $vars);
    }

    public function test_category_block_dessine_des_barres_proportionnelles(): void
    {
        $rows = [
            ['category' => 'Boissons', 'ca' => 20.0],
            ['category' => 'Snacks',   'ca' => 10.0],
        ];

        $block = SmsReport::categoryBlock($rows, 4);
        $lines = explode("\n", $block);

        self::assertCount(2, $lines);
        self::assertStringContainsString('Boissons', $lines[0]);
        self::assertStringContainsString('67 %', $lines[0]);
        self::assertStringContainsString('33 %', $lines[1]);
        // La barre du n°1 est plus longue que celle du n°2.
        $bars1 = substr_count($lines[0], '▇');
        $bars2 = substr_count($lines[1], '▇');
        self::assertGreaterThan($bars2, $bars1);
    }

    public function test_category_block_vide_renvoie_un_message(): void
    {
        self::assertSame('(aucune vente)', SmsReport::categoryBlock([]));
    }

    public function test_schedule_due_verifie_activation_jour_heure_et_doublon(): void
    {
        $now = new \DateTimeImmutable('2026-09-25 17:35:00', new \DateTimeZone('Europe/Paris')); // vendredi
        $today = '2026-09-25';

        $base = [
            'is_enabled'    => 1,
            'days'          => '5',
            'send_time'     => '17:30:00',
            'last_sent_day' => null,
        ];

        self::assertTrue(SmsReport::scheduleDue($base, $now, $today));
        self::assertFalse(SmsReport::scheduleDue(['is_enabled' => 0] + $base, $now, $today), 'désactivé');
        self::assertFalse(SmsReport::scheduleDue(['days' => '1'] + $base, $now, $today), 'mauvais jour');
        self::assertFalse(SmsReport::scheduleDue(['send_time' => '08:00:00'] + $base, $now, $today), 'hors fenêtre');
        self::assertFalse(SmsReport::scheduleDue(['last_sent_day' => $today] + $base, $now, $today), 'déjà envoyé');
        self::assertTrue(SmsReport::scheduleDue($base, $now, '2026-09-24'), 'envoyé hier mais dû aujourd\'hui');
    }

    public function test_build_svg_genere_un_svg_sain(): void
    {
        $svg = \App\Controllers\SmsChartController::buildSvg(
            [['category' => 'Boi <x>', 'ca' => 20.0]],
            '26/09/2026'
        );

        self::assertStringContainsString('<svg', $svg);
        self::assertStringContainsString('Boi &lt;x&gt;', $svg);
        self::assertStringContainsString('26/09/2026', $svg);
        self::assertStringContainsString('Total', $svg);
        self::assertStringContainsString('</svg>', $svg);
    }

    public function test_build_svg_sans_vente_affiche_un_message(): void
    {
        $svg = \App\Controllers\SmsChartController::buildSvg([], '26/09/2026');

        self::assertStringContainsString('Aucune vente', $svg);
    }
}
