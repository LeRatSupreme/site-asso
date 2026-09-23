<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Compta\CashLedger;
use App\Models\Sale;
use App\Models\SmsSchedule;
use App\Models\Setting;

/**
 * Rapports SMS (API Free Mobile) : plusieurs messages programmés, chacun
 * avec ses jours, son heure et son modèle (CA du jour, bénéfice, top
 * produits, répartition par catégorie, comparaisons…), envoyés aux
 * destinataires enregistrés.
 *
 * Les destinataires sont stockés dans le setting `sms_recipients` sous
 * forme de JSON : [{"label":"Adrien","user":"12345678","pass":"abcd"}].
 * Les messages vivent dans la table `sms_reports` et l'envoi est piloté
 * par le cron scripts/send_sms_report.php.
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
     * Liste des variables disponibles dans le modèle de message,
     * groupées par famille (pour l'aide de la page admin).
     *
     * @return array<string, list<array{var:string, desc:string}>>
     */
    public static function variableGroups(): array
    {
        return [
            'Jour' => [
                ['var' => 'date',     'desc' => 'Date du rapport (23/09/2026)'],
                ['var' => 'jour',     'desc' => 'Jour de la semaine (Mardi)'],
                ['var' => 'ca',       'desc' => 'Chiffre d\'affaires du jour'],
                ['var' => 'benefice', 'desc' => 'Bénéfice du jour'],
                ['var' => 'marge',    'desc' => 'Marge du jour en %'],
                ['var' => 'qty',      'desc' => 'Articles vendus dans la journée'],
                ['var' => 'ventes',   'desc' => 'Nombre de transactions'],
                ['var' => 'panier',   'desc' => 'Panier moyen'],
                ['var' => 'carte',    'desc' => 'CA encaissé par carte'],
                ['var' => 'espece',   'desc' => 'CA encaissé en espèces'],
                ['var' => 'caisse',   'desc' => 'Montant actuel dans la caisse (liquide)'],
            ],
            'Top produits' => [
                ['var' => 'top',   'desc' => 'Top 3 produits (bloc multi-lignes)'],
                ['var' => 'top1',  'desc' => '1er produit du jour'],
                ['var' => 'top2',  'desc' => '2e produit du jour'],
                ['var' => 'top3',  'desc' => '3e produit du jour'],
            ],
            'Semaine & mois' => [
                ['var' => 'semaine_ca',       'desc' => 'CA depuis lundi'],
                ['var' => 'semaine_benefice', 'desc' => 'Bénéfice depuis lundi'],
                ['var' => 'semaine_qty',      'desc' => 'Articles vendus depuis lundi'],
                ['var' => 'mois',             'desc' => 'Mois en cours (Septembre)'],
                ['var' => 'mois_ca',          'desc' => 'CA du mois en cours'],
                ['var' => 'mois_benefice',    'desc' => 'Bénéfice du mois en cours'],
                ['var' => 'mois_qty',         'desc' => 'Articles vendus ce mois'],
            ],
            'Comparaison hier' => [
                ['var' => 'hier_ca',       'desc' => 'CA d\'hier'],
                ['var' => 'hier_benefice', 'desc' => 'Bénéfice d\'hier'],
                ['var' => 'evolution',     'desc' => 'Évolution du CA vs hier (📈 📉 ➖)'],
            ],
            'Catégories' => [
                ['var' => 'categories', 'desc' => 'Répartition du CA par catégorie (barres)'],
                ['var' => 'categories_semaine', 'desc' => 'Répartition par catégorie de la semaine (barres)'],
                ['var' => 'cat_top',    'desc' => 'Première catégorie du jour'],
                ['var' => 'graphique',  'desc' => 'Lien vers le graphique image du jour'],
                ['var' => 'graphique_semaine', 'desc' => 'Lien vers le graphique image de la semaine'],
            ],
        ];
    }

    /**
     * Étiquette d'évolution du CA vs hier (fonction pure, testable).
     */
    public static function evolutionLabel(float $today, float $yesterday): string
    {
        if ($yesterday <= 0.0) {
            return "\u{2796}";   // pas de comparaison possible
        }
        $pct = round(($today - $yesterday) / $yesterday * 100);
        $sign = $pct > 0 ? '+' : '';
        $arrow = $pct > 0 ? "\u{1F4C8}" : ($pct < 0 ? "\u{1F4C9}" : "\u{2796}");

        return $arrow . ' ' . $sign . number_format($pct, 0, ',', ' ') . ' % vs hier';
    }

    /**
     * Construit la ligne d'un produit du top (fonction pure, testable).
     */
    public static function topLine(int $rank, array $product): string
    {
        $label = (string) ($product['label'] ?? '?');
        $qty = (int) ($product['qty'] ?? 0);
        $ca = formatPrice((float) ($product['ca'] ?? 0));

        return $rank . '. ' . $label . ' x' . $qty . ' (' . $ca . ')';
    }

    /**
     * Collecte les données du jour et construit la carte des variables
     * {placeholder} => valeur.
     *
     * @return array<string,string>
     */
    public static function varsFor(?string $day = null): array
    {
        $day = $day ?: date('Y-m-d');
        $ts = strtotime($day);
        $yesterday = date('Y-m-d', $ts - 86400);
        $monday = date('Y-m-d', strtotime('monday this week', $ts));

        $agg = Sale::aggregatesBetween($day, $day);
        $top = Sale::topProductsBetween($day, $day, 3);
        $split = Sale::paymentSplitBetween($day, $day);
        $tx = Sale::transactionsBetween($day, $day);

        $weekAgg = Sale::aggregatesBetween($monday, $day);
        $monthAgg = Sale::monthAggregates((int) date('Y', $ts), (int) date('n', $ts));
        $yAgg = Sale::aggregatesBetween($yesterday, $yesterday);

        $topLines = [];
        foreach ($top as $i => $p) {
            $topLines[] = self::topLine($i + 1, $p);
        }
        while (count($topLines) < 3) {
            $topLines[] = ($n = count($topLines) + 1) . '. —';
        }

        $catRows = Sale::byCategoryBetween($day, $day);
        $catTop = $catRows !== [] ? (string) ($catRows[0]['category'] ?? '') : '—';

        $marge = $agg['ca'] > 0 ? round($agg['profit'] / $agg['ca'] * 100) : 0;
        $panier = $tx > 0 ? $agg['ca'] / $tx : 0.0;

        $dayNames = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];
        $monthNames = [1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'];

        return [
            '{date}'     => date('d/m/Y', $ts),
            '{jour}'     => $dayNames[(int) date('N', $ts)] ?? '',
            '{ca}'       => formatPrice($agg['ca']),
            '{benefice}' => formatPrice($agg['profit']),
            '{marge}'    => $marge . ' %',
            '{qty}'      => (string) $agg['qty'],
            '{ventes}'   => (string) $tx,
            '{panier}'   => formatPrice($panier),
            '{carte}'    => formatPrice($split['CARTE'] ?? 0.0),
            '{espece}'   => formatPrice($split['LIQUIDE'] ?? 0.0),
            '{top}'      => $topLines === [] ? '(aucune vente)' : implode("\n", $topLines),
            '{top1}'     => $topLines[0],
            '{top2}'     => $topLines[1],
            '{top3}'     => $topLines[2],
            '{semaine_ca}'       => formatPrice($weekAgg['ca']),
            '{semaine_benefice}' => formatPrice($weekAgg['profit']),
            '{semaine_qty}'      => (string) $weekAgg['qty'],
            '{mois}'             => $monthNames[(int) date('n', $ts)] ?? '',
            '{mois_ca}'          => formatPrice($monthAgg['ca']),
            '{mois_benefice}'    => formatPrice($monthAgg['profit']),
            '{mois_qty}'         => (string) $monthAgg['qty'],
            '{hier_ca}'          => formatPrice($yAgg['ca']),
            '{hier_benefice}'    => formatPrice($yAgg['profit']),
            '{evolution}'        => self::evolutionLabel($agg['ca'], $yAgg['ca']),
            '{categories}'       => self::categoryBlock($catRows),
            '{categories_semaine}' => self::categoryBlock(Sale::byCategoryBetween($monday, $day)),
            '{cat_top}'          => $catTop,
            '{caisse}'           => formatPrice(CashLedger::balance()),
            '{graphique}'        => APP_URL . '/sms-chart/' . self::chartToken() . '.png',
            '{graphique_semaine}' => APP_URL . '/sms-chart/' . self::chartToken() . '.png?p=week',
        ];
    }

    /**
     * Jeton secret protégeant l'URL du graphique (généré au premier
     * appel, stocké dans les settings).
     */
    public static function chartToken(): string
    {
        $token = Setting::get('sms_chart_token', '');
        if (trim($token) === '') {
            $token = bin2hex(random_bytes(16));
            Setting::set('sms_chart_token', $token);
        }

        return $token;
    }

    /**
     * Bloc texte « graphique » : répartition du CA par catégorie avec
     * barres, trié par CA décroissant (fonction pure, testable).
     *
     * @param list<array<string,mixed>> $rows Lignes de Sale::byCategoryBetween()
     */
    public static function categoryBlock(array $rows, int $topN = 4): string
    {
        $rows = array_slice($rows, 0, max(1, $topN));
        if ($rows === []) {
            return '(aucune vente)';
        }

        $max = 0.0;
        $total = 0.0;
        foreach ($rows as $r) {
            $ca = (float) ($r['ca'] ?? 0);
            $max = max($max, $ca);
            $total += $ca;
        }

        $lines = [];
        foreach ($rows as $r) {
            $ca = (float) ($r['ca'] ?? 0);
            $bars = $max > 0 ? max(1, (int) round($ca / $max * 10)) : 1;
            $pct = $total > 0 ? (int) round($ca / $total * 100) : 0;
            $lines[] = sprintf(
                '%s %s %s (%d %%)',
                (string) ($r['category'] ?? '?'),
                str_repeat('▇', $bars),
                formatPrice($ca),
                $pct
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Un message programmé doit-il partir maintenant ? (fonction pure,
     * testable : activation + jour + fenêtre horaire + pas déjà envoyé).
     *
     * @param array<string,mixed> $schedule Ligne de sms_reports
     */
    public static function scheduleDue(array $schedule, \DateTimeImmutable $now, string $today): bool
    {
        if ((int) ($schedule['is_enabled'] ?? 0) !== 1) {
            return false;
        }

        if (!self::dayMatches((string) ($schedule['days'] ?? ''), (int) $now->format('N'))) {
            return false;
        }

        $time = substr((string) ($schedule['send_time'] ?? ''), 0, 5);
        if (!self::timeWindowMatches($time, $now->format('H:i'))) {
            return false;
        }

        return (string) ($schedule['last_sent_day'] ?? '') !== $today;
    }

    /**
     * Messages programmés qui doivent partir maintenant.
     *
     * @return list<array<string,mixed>>
     */
    public static function dueSchedules(\DateTimeImmutable $now): array
    {
        $today = $now->format('Y-m-d');

        return array_values(array_filter(
            SmsSchedule::enabled(),
            static fn (array $s): bool => self::scheduleDue($s, $now, $today)
        ));
    }

    /**
     * Message rendu pour un message programmé donné.
     */
    public static function buildFor(array $schedule, ?string $day = null): string
    {
        $template = trim((string) ($schedule['template'] ?? ''));
        if ($template === '') {
            $template = self::DEFAULT_TEMPLATE;
        }

        return self::renderTemplate($template, self::varsFor($day));
    }

    /**
     * Marque un message comme envoyé pour le jour donné.
     */
    public static function markScheduleSent(string $id, string $day): void
    {
        SmsSchedule::setLastSent($id, $day);
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
