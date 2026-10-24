<?php

declare(strict_types=1);

namespace App\Core\Compta;

/**
 * Logique de calcul comptable pure (sans accès DB).
 *
 * Ces méthodes sont volontairement isolées du reste de l'application afin
 * d'être testées unitairement sans base de données. Toute la logique métier
 * de calcul de bénéfice / marge / sélection de lot de coût s'y concentre.
 */
final class ComptaCalc
{
    /** Fuseau de référence des fenêtres : l'asso opère à Paris. */
    public const TZ = 'Europe/Paris';

    /**
     * Fenêtre « 7 derniers jours » (J-6 → J, bornes incluses) en heure
     * de Paris. $today injectable pour les tests.
     *
     * @return array{from:string,to:string}
     */
    public static function last7DaysWindow(?\DateTimeImmutable $today = null): array
    {
        $tz = new \DateTimeZone(self::TZ);
        $today = ($today ?? new \DateTimeImmutable('today', $tz))->setTimezone($tz);

        return [
            'from' => $today->modify('-6 days')->format('Y-m-d'),
            'to'   => $today->format('Y-m-d'),
        ];
    }

    /**
     * Fenêtre « semaine actuelle » (lundi → J, bornes incluses) en heure
     * de Paris. $today injectable pour les tests.
     *
     * @return array{from:string,to:string}
     */
    public static function currentWeekWindow(?\DateTimeImmutable $today = null): array
    {
        $tz = new \DateTimeZone(self::TZ);
        $today = ($today ?? new \DateTimeImmutable('today', $tz))->setTimezone($tz);

        // N : 1 = lundi … 7 = dimanche → recul jusqu'au lundi de la semaine.
        $monday = $today->modify('-' . ((int) $today->format('N') - 1) . ' days');

        return [
            'from' => $monday->format('Y-m-d'),
            'to'   => $today->format('Y-m-d'),
        ];
    }

    /**
     * Fenêtre « mois en cours » (1er du mois → J, bornes incluses) en
     * heure de Paris. $today injectable pour les tests.
     *
     * @return array{from:string,to:string}
     */
    public static function monthToDateWindow(?\DateTimeImmutable $today = null): array
    {
        $tz = new \DateTimeZone(self::TZ);
        $today = ($today ?? new \DateTimeImmutable('today', $tz))->setTimezone($tz);

        return [
            'from' => $today->modify('first day of this month')->setTime(0, 0)->format('Y-m-d'),
            'to'   => $today->format('Y-m-d'),
        ];
    }

    /**
     * Options du sélecteur « 📅 Période » (pages Catégories, Dépenses,
     * Budgets, Achats) — mêmes bornes que la page Réappro.
     *
     * @var array<string,string>
     */
    public const PERIOD_OPTIONS = [
        '1d'     => '1 jour',
        'week'   => 'Semaine en cours',
        '30d'    => '30 derniers jours',
        'all'    => 'Tout',
        'custom' => 'Personnalisé',
    ];

    /**
     * Résout une période d'analyse depuis les paramètres GET (period/from/to),
     * avec la même sémantique que la page Réappro.
     *
     * Règles :
     *  - preset inconnu/null → $defaut (« week » par défaut : semaine en
     *    cours, lundi → J ; le livre comptable passe « 30d », contrat du
     *    test OperationsLedgerPagesTest « période par défaut 30 jours ») ;
     *  - « Nd » → from = aujourd'hui −(N−1) jours ;
     *  - « week » → from = lundi de la semaine en cours ;
     *  - « all » → bornes null (toute la période disponible) ;
     *  - « custom » → from/to validés au format YYYY-MM-DD (from <= to,
     *    sinon échange) ; bornes invalides → repli sur « 30d ».
     *
     * @param string|null $preset Clé de PERIOD_OPTIONS (« period » en GET).
     * @param string|null $from   Jour de début saisi (« from » en GET).
     * @param string|null $to     Jour de fin saisi (« to » en GET).
     * @param string      $defaut Preset appliqué quand $preset est inconnu
     *                            ou null (clé de PERIOD_OPTIONS).
     *
     * @return array{preset:string, from:?string, to:?string} Dates « YYYY-MM-DD »
     *                                                          (null pour « all »).
     */
    public static function resolvePeriod(?string $preset, ?string $from, ?string $to, string $defaut = 'week'): array
    {
        $preset = (string) $preset;
        if (!array_key_exists($preset, self::PERIOD_OPTIONS)) {
            $preset = array_key_exists($defaut, self::PERIOD_OPTIONS) ? $defaut : 'week';
        }

        $fromDay = null;
        $toDay = null;

        switch ($preset) {
            case '1d':
                $fromDay = date('Y-m-d');
                $toDay = date('Y-m-d');
                break;
            case 'week':
                // Semaine en cours : du lundi (inclus) à aujourd'hui.
                $fromDay = date('Y-m-d', strtotime('-' . ((int) date('N') - 1) . ' days'));
                $toDay = date('Y-m-d');
                break;
            case 'all':
                $fromDay = null;
                $toDay = null;
                break;
            case 'custom':
                $from = trim((string) $from);
                $to = trim((string) $to);
                $fromOk = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1;
                $toOk = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1;
                if ($fromOk && $toOk) {
                    if ($from > $to) {
                        [$from, $to] = [$to, $from];
                    }
                    $fromDay = $from;
                    $toDay = $to;
                } else {
                    // Bornes invalides : repli sur la semaine en cours.
                    $preset = 'week';
                    $fromDay = date('Y-m-d', strtotime('-' . ((int) date('N') - 1) . ' days'));
                    $toDay = date('Y-m-d');
                }
                break;
            default: // '30d'
                $fromDay = date('Y-m-d', strtotime('-29 days'));
                $toDay = date('Y-m-d');
                break;
        }

        return ['preset' => $preset, 'from' => $fromDay, 'to' => $toDay];
    }

    /**
     * Mois couverts par une plage de jours (bornes incluses), du premier
     * jour du mois de départ à celui du mois d'arrivée.
     *
     * Utilisé par les budgets : une période « du 30/09 au 30/09 » couvre
     * un seul mois (septembre), les budgets se comparant par mois calendaire.
     *
     * @param int $maxMonths Plafond de sécurité (les mois les plus anciens
     *                       sont retirés en cas de période trop large).
     *
     * @return list<array{0:int,1:int}> Couples [année, mois] chronologiques.
     */
    public static function monthsCoveredBy(string $fromDay, string $toDay, int $maxMonths = 24): array
    {
        $start = (new \DateTimeImmutable($fromDay))->modify('first day of this month');
        $end = (new \DateTimeImmutable($toDay))->modify('first day of this month');

        $months = [];
        $cur = $start;
        while ($cur <= $end) {
            $months[] = [(int) $cur->format('Y'), (int) $cur->format('n')];
            $cur = $cur->modify('+1 month');
        }

        if (count($months) > $maxMonths) {
            $months = array_slice($months, -$maxMonths);
        }

        return $months;
    }

    /**
     * Part du budget mensuel que couvre une période de jours : rapport
     * entre le chevauchement [période ∩ mois] et la durée du mois.
     *
     * Utilisé par les budgets pour comparer un « réalisé » sur n'importe
     * quelle durée à un budget mensuel : une période d'un jour en
     * septembre compare à 1/30 du budget de septembre.
     *
     * @param string $monthStartDay Premier jour du mois (« YYYY-MM-01 »).
     * @param string $periodStartDay Début de période (« YYYY-MM-DD »).
     * @param string $periodEndDay   Fin de période (« YYYY-MM-DD »).
     *
     * @return float Facteur dans [0.0 ; 1.0].
     */
    public static function monthBudgetFactor(string $monthStartDay, string $periodStartDay, string $periodEndDay): float
    {
        $monthStart = new \DateTimeImmutable($monthStartDay);
        $monthEnd = $monthStart->modify('last day of this month');
        $pStart = new \DateTimeImmutable($periodStartDay);
        $pEnd = new \DateTimeImmutable($periodEndDay);

        $ovStart = $monthStart > $pStart ? $monthStart : $pStart;
        $ovEnd = $monthEnd < $pEnd ? $monthEnd : $pEnd;
        if ($ovEnd < $ovStart) {
            return 0.0;
        }

        $days = $ovStart->diff($ovEnd)->days + 1;

        return min(1.0, ((float) $days) / (float) $monthStart->format('t'));
    }

    /**
     * Bénéfice d'une ligne de vente.
     *
     * Formule : price_ttc − (cost_price × quantity).
     *
     * Règles :
     *  - un « montant personnalisé » (is_custom_amount) est EXCLU du bénéfice
     *    (compté dans le CA mais sans coût de revient) → renvoie 0,00 ;
     *  - si aucun coût n'est connu (cost_price = null), on retient 0
     *    (bénéfice = prix TTC) — la marge sera alors surévaluée tant qu'aucun
     *    lot n'est saisi, ce qui attire l'attention de l'admin.
     */
    public static function lineProfit(float $priceTtc, ?float $costPrice, int $quantity, bool $isCustomAmount): float
    {
        if ($isCustomAmount) {
            return 0.0;
        }

        $qty = $quantity > 0 ? $quantity : 1;
        $cost = $costPrice ?? 0.0;

        return round($priceTtc - ($cost * $qty), 4);
    }

    /**
     * Sélectionne le lot de coût applicable à une date donnée — règle
     * « as-of » : le coût d'une vente est celui du lot valable À LA DATE de
     * la vente, jamais un lot créé après coup (pas de rétroactivité).
     *
     * Ordre de sélection :
     *  1. lot couvrant la date : valid_from <= date ET (valid_to est NULL
     *     OU date <= valid_to) → le plus récent (valid_from max) ;
     *  2. sinon — vente dans un « trou » entre deux lots, ou après la fin
     *     du dernier lot — le lot précédent : le plus récent dont
     *     valid_from <= date ;
     *  3. sinon — vente antérieure au premier lot — null : coût inconnu
     *     avant le premier lot = 0 (pas de rétroactivité).
     *
     * @param list<array<string,mixed>> $lots Chaque lot doit contenir
     *                                        'valid_from', 'valid_to' (nullable),
     *                                        'cost_price', etc.
     * @return array<string,mixed>|null Le lot retenu, ou null si aucun lot
     *                                  couvrant ni antérieur.
     */
    public static function selectCostLot(string $date, array $lots): ?array
    {
        $day = self::datePart($date);

        $covered = null;    // lot couvrant la date, valid_from max
        $coveredFrom = '';
        $coveredTie = '';
        $prior = null;      // lot le plus récent avec valid_from <= date
        $priorFrom = '';
        $priorTie = '';

        foreach ($lots as $lot) {
            $from = self::datePart((string) ($lot['valid_from'] ?? ''));
            if ($from === '') {
                continue;
            }

            // Jamais un lot dont valid_from est postérieur à la date.
            if ($from > $day) {
                continue;
            }

            // À valid_from ÉGAL (deux lots ouverts le même jour, ex. une
            // même course saisie deux fois sous deux orthographes puis
            // fusionnées) : la saisie la plus récente gagne, puis l'id le
            // plus grand — jamais un choix dépendant de l'ordre des lignes.
            $tie = self::lotTieKey($lot);

            if ($prior === null || $from > $priorFrom
                || ($from === $priorFrom && $tie > $priorTie)) {
                $prior = $lot;
                $priorFrom = $from;
                $priorTie = $tie;
            }

            $toRaw = $lot['valid_to'] ?? null;
            $to = $toRaw === null ? null : self::datePart((string) $toRaw);
            if ($to === null || $day <= $to) {
                if ($covered === null || $from > $coveredFrom
                    || ($from === $coveredFrom && $tie > $coveredTie)) {
                    $covered = $lot;
                    $coveredFrom = $from;
                    $coveredTie = $tie;
                }
            }
        }

        return $covered ?? $prior;
    }

    /**
     * Clé de départage entre deux lots de même valid_from : created_at
     * puis id (mêmes règles que ProductCost::normalizePeriods). Colonnes
     * absentes (lots hors base) → chaîne vide : l'ordre d'entrée décide.
     */
    private static function lotTieKey(array $lot): string
    {
        return (string) ($lot['created_at'] ?? '') . '|' . (string) ($lot['id'] ?? '');
    }

    /**
     * Coût unitaire applicable à une date donnée (raccourci sur selectCostLot).
     */
    public static function costAt(string $date, array $lots): ?float
    {
        $lot = self::selectCostLot($date, $lots);

        return $lot === null ? null : (float) $lot['cost_price'];
    }

    /**
     * Marge en pourcentage : bénéfice / CA × 100.
     * Renvoie 0 si le CA est nul ou négatif.
     */
    public static function marginPercent(float $profit, float $ca): float
    {
        if ($ca <= 0.0) {
            return 0.0;
        }

        return round($profit / $ca * 100.0, 2);
    }

    /**
     * Moyenne mobile sur N mois à partir d'une série de quantités mensuelles.
     *
     * @param list<float|int|string> $monthly Quantités par mois (chronologique,
     *                                        le dernier élément = mois le plus récent).
     */
    public static function movingAverage(array $monthly, int $window = 3): float
    {
        $window = $window > 0 ? $window : 3;
        if ($monthly === []) {
            return 0.0;
        }

        $tail = array_slice($monthly, -$window);
        $sum = 0.0;
        foreach ($tail as $v) {
            $sum += (float) $v;
        }

        return round($sum / count($tail), 4);
    }

    /**
     * Quantité conseillée à racheter pour un horizon donné (en mois).
     *
     * suggestion = max(0, conso_moyenne_mois × horizon − stock_restant).
     */
    public static function suggestedReorder(float $avgMonthlyConsumption, int $horizonMonths, int $stock): int
    {
        $need = (int) ceil($avgMonthlyConsumption * max(1, $horizonMonths)) - $stock;

        return $need > 0 ? $need : 0;
    }

    /**
     * Nombre de jours CALENDaires entre deux dates, bornes incluses
     * (week-end compris). Sert au réappro : la consommation est ramenée
     * à un rythme 7 j/7 pour que l'autonomie compte le week-end.
     *
     * @param string $fromDay « YYYY-MM-DD » (inclus).
     * @param string $toDay   « YYYY-MM-DD » (inclus).
     */
    public static function calendarDaysBetween(string $fromDay, string $toDay): int
    {
        try {
            $cur = new \DateTimeImmutable($fromDay);
            $end = new \DateTimeImmutable($toDay);
        } catch (\Exception) {
            return 0;
        }

        if ($cur > $end) {
            return 0;
        }

        // Garde-fou : plage absurde (> ~10 ans) plafonnée.
        if ($end->getTimestamp() - $cur->getTimestamp() > 3660 * 86400) {
            $end = $cur->modify('+3660 days');
        }

        return (int) $cur->diff($end)->days + 1;
    }

    /**
     * Nombre de jours d'ouverture (lundi→vendredi) entre deux dates, bornes
     * incluses. Sert à rapporter une consommation à des journées réelles
     * d'activité de la cafétéria.
     *
     * @param string $fromDay « YYYY-MM-DD » (inclus).
     * @param string $toDay   « YYYY-MM-DD » (inclus).
     */
    public static function openDaysBetween(string $fromDay, string $toDay): int
    {
        try {
            $cur = new \DateTimeImmutable($fromDay);
            $end = new \DateTimeImmutable($toDay);
        } catch (\Exception) {
            return 0;
        }

        if ($cur > $end) {
            return 0;
        }

        // Garde-fou : plage absurde (> ~10 ans) plafonnée.
        if ($end->getTimestamp() - $cur->getTimestamp() > 3660 * 86400) {
            $end = $cur->modify('+3660 days');
        }

        $count = 0;
        while ($cur <= $end) {
            if ((int) $cur->format('N') <= 5) {
                $count++;
            }
            $cur = $cur->modify('+1 day');
        }

        return $count;
    }

    /**
     * Nombre de jours d'autonomie restants (stock / conso journalière moyenne).
     * Renvoie null si la consommation est inconnue (aucune vente).
     */
    public static function autonomyDays(int $stock, float $avgMonthlyConsumption): ?int
    {
        if ($avgMonthlyConsumption <= 0.0) {
            return null;
        }

        $perDay = $avgMonthlyConsumption / 30.0;

        return (int) floor($stock / $perDay);
    }

    /**
     * Extrait la partie date (YYYY-MM-DD) d'une valeur DATETIME/DATE.
     */
    private static function datePart(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return substr($value, 0, 10);
    }
}
