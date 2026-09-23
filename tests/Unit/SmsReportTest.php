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
}
