<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Sale;
use App\Models\Setting;

/**
 * Rapport quotidien par SMS (API Free Mobile) : CA du jour, bénéfice et
 * top produits, envoyé aux destinataires enregistrés les jours choisis
 * à l'heure choisie (par défaut lundi-vendredi à 20h00).
 *
 * Les destinataires sont stockés dans le setting `sms_recipients` sous
 * forme de JSON : [{"label":"Adrien","user":"12345678","pass":"abcd"}].
 * La planification est pilotée par le cron scripts/send_sms_report.php.
 */
final class SmsReport
{
    /** Modèle de message par défaut (variables entre accolades). */
    public const DEFAULT_TEMPLATE = "\u{1F4CA} Rapport {date}\n"
        . "\u{1F4B0} CA : {ca}\n"
        . "\u{1F4C8} Bénéfice : {benefice}\n"
        . "\u{1F6D2} Articles vendus : {qty}\n"
        . "\u{1F3C6} Top produits :\n{top}";

    /** Jours par défaut : lundi → vendredi (ISO-8601, 1 = lundi). */
    public const DEFAULT_DAYS = '1,2,3,4,5';

    /** Heure par défaut d'envoi. */
    public const DEFAULT_TIME = '20:00';

    /** Tolérance autour de l'heure prévue (retard de cron accepté). */
    public const WINDOW_MINUTES = 30;

    /** Clé du setting contenant le JSON des destinataires. */
    private const RECIPIENTS_KEY = 'sms_recipients';

    /**
     * Liste des destinataires enregistrés.
     *
     * @return list<array{label:string, user:string, pass:string}>
     */
    public static function recipients(): array
    {
        return self::parseRecipients(Setting::get(self::RECIPIENTS_KEY, ''));
    }

    /**
     * Enregistre la liste des destinataires.
     *
     * @param list<array{label:string, user:string, pass:string}> $recipients
     */
    public static function saveRecipients(array $recipients): void
    {
        $clean = array_values(array_map(static fn (array $r): array => [
            'label' => mb_substr(trim((string) ($r['label'] ?? '')), 0, 40),
            'user'  => preg_replace('/\D/', '', (string) ($r['user'] ?? '')) ?? '',
            'pass'  => trim((string) ($r['pass'] ?? '')),
        ], $recipients));

        Setting::set(self::RECIPIENTS_KEY, json_encode($clean, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Décode le JSON des destinataires (fonction pure, testable).
     *
     * @return list<array{label:string, user:string, pass:string}>
     */
    public static function parseRecipients(?string $json): array
    {
        $json = trim((string) $json);
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $user = preg_replace('/\D/', '', (string) ($row['user'] ?? '')) ?? '';
            $pass = trim((string) ($row['pass'] ?? ''));
            if ($user === '' || $pass === '') {
                continue;
            }
            $out[] = [
                'label' => mb_substr(trim((string) ($row['label'] ?? '')), 0, 40),
                'user'  => $user,
                'pass'  => $pass,
            ];
        }

        return $out;
    }

    /**
     * Normalise les jours cochés (noms de champs POST `days[]`) en CSV
     * ISO-8601 trié « 1,2,3,4,5 » (vide = lundi-vendredi).
     *
     * @param list<string|int> $input
     */
    public static function normalizeDays(array $input): string
    {
        $days = [];
        foreach ($input as $d) {
            $n = (int) $d;
            if ($n >= 1 && $n <= 7) {
                $days[$n] = $n;
            }
        }
        ksort($days);

        return $days === [] ? self::DEFAULT_DAYS : implode(',', array_keys($days));
    }

    /**
     * Indique si le jour ISO-8601 (1 = lundi … 7 = dimanche) figure dans
     * le CSV de jours planifiés (fonction pure, testable).
     */
    public static function dayMatches(string $daysCsv, int $isoDay): bool
    {
        foreach (explode(',', $daysCsv) as $d) {
            if ((int) trim($d) === $isoDay) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rend le message depuis le modèle et les variables {placeholder}
     * (fonction pure, testable).
     *
     * @param array<string,string> $vars
     */
    public static function renderTemplate(string $template, array $vars): string
    {
        $message = str_replace(array_keys($vars), array_values($vars), $template);

        return trim($message);
    }

    /**
     * L'heure $nowHm (HH:MM) est-elle dans la fenêtre [$time, $time+30min] ?
     * (fonction pure, testable — tolère le passage de minuit si l'heure
     * planifiée est 23h45+).
     */
    public static function timeWindowMatches(string $time, string $nowHm, int $windowMinutes = self::WINDOW_MINUTES): bool
    {
        $t = self::minutesOfDay($time);
        $n = self::minutesOfDay($nowHm);
        if ($t === null || $n === null) {
            return false;
        }

        $diff = $n - $t;
        if ($diff < -($windowMinutes / 2)) {
            $diff += 24 * 60;   // passage de minuit
        }

        return $diff >= 0 && $diff <= $windowMinutes;
    }

    private static function minutesOfDay(string $hm): ?int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($hm), $m)) {
            return null;
        }
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h > 23 || $min > 59) {
            return null;
        }

        return $h * 60 + $min;
    }

    /**
     * Collecte les données du jour et construit le message complet.
     *
     * @return array{message:string, ca:float, profit:float, qty:int, top:list<array<string,mixed>>}
     */
    public static function buildMessage(?string $day = null): array
    {
        $day = $day ?: date('Y-m-d');

        $agg = Sale::aggregatesBetween($day, $day);
        $top = Sale::topProductsBetween($day, $day, 3);

        $topLines = '';
        foreach ($top as $i => $p) {
            $n = $i + 1;
            $label = (string) ($p['label'] ?? '?');
            $qty = (int) ($p['qty'] ?? 0);
            $ca = formatPrice((float) ($p['ca'] ?? 0));
            $topLines .= $n . '. ' . $label . ' x' . $qty . ' (' . $ca . ")\n";
        }

        $vars = [
            '{date}'     => date('d/m/Y', strtotime($day)),
            '{ca}'       => formatPrice($agg['ca']),
            '{benefice}' => formatPrice($agg['profit']),
            '{qty}'      => (string) $agg['qty'],
            '{top}'      => $topLines !== '' ? trim($topLines) : '(aucune vente)',
        ];

        $template = Setting::get('sms_report_template', '');
        if (trim($template) === '') {
            $template = self::DEFAULT_TEMPLATE;
        }

        return [
            'message' => self::renderTemplate($template, $vars),
            'ca'      => $agg['ca'],
            'profit'  => $agg['profit'],
            'qty'     => $agg['qty'],
            'top'     => $top,
        ];
    }

    /**
     * Le rapport doit-il partir maintenant ? (activé + bon jour + fenêtre
     * horaire + pas déjà envoyé aujourd'hui).
     */
    public static function shouldRun(\DateTimeImmutable $now): bool
    {
        if (!Setting::getBool('sms_report_enabled', false)) {
            return false;
        }

        if (self::recipients() === []) {
            return false;
        }

        $days = Setting::get('sms_report_days', self::DEFAULT_DAYS);
        if (!self::dayMatches($days, (int) $now->format('N'))) {
            return false;
        }

        $time = Setting::get('sms_report_time', self::DEFAULT_TIME);
        if (!self::timeWindowMatches($time, $now->format('H:i'))) {
            return false;
        }

        return !self::hasSent($now->format('Y-m-d'));
    }

    /**
     * Le rapport du jour a-t-il déjà été envoyé ?
     */
    public static function hasSent(string $day): bool
    {
        return Setting::get('sms_report_last_sent', '') === $day;
    }

    /**
     * Marque le rapport du jour comme envoyé (garde anti-doublon).
     */
    public static function markSent(string $day): void
    {
        Setting::set('sms_report_last_sent', $day);
    }

    /**
     * Envoie un message à tous les destinataires.
     *
     * @return list<array{label:string, ok:bool, status:int, error:?string}>
     */
    public static function sendToAll(string $message): array
    {
        $results = [];
        foreach (self::recipients() as $r) {
            $res = FreeMobileSms::send($r['user'], $r['pass'], $message);
            $results[] = [
                'label'  => $r['label'] !== '' ? $r['label'] : $r['user'],
                'ok'     => $res['ok'],
                'status' => $res['status'],
                'error'  => $res['error'],
            ];
        }

        return $results;
    }
}
