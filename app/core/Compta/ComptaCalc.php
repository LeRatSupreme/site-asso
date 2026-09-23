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
    /**
     * Options du sélecteur « 📅 Période » (pages Catégories, Dépenses,
     * Budgets, Achats) — mêmes bornes que la page Réappro.
     *
     * @var array<string,string>
     */
    public const PERIOD_OPTIONS = [
        '1d'     => '1 jour',
        '7d'     => '7 derniers jours',
        '30d'    => '30 derniers jours',
        '3m'     => '3 derniers mois',
        '6m'     => '6 derniers mois',
        '12m'    => '12 derniers mois',
        'ytd'    => 'Année civile',
        'all'    => 'Tout',
        'custom' => 'Personnalisé',
    ];

    /**
     * Résout une période d'analyse depuis les paramètres GET (period/from/to),
     * avec la même sémantique que la page Réappro.
     *
     * Règles :
     *  - preset inconnu/null → « 30d » ;
     *  - « Nd » → from = aujourd'hui −(N−1) jours ;
     *  - « Nm » → from = aujourd'hui −N mois (calendaire, comme Réappro) ;
     *  - « ytd » → 1er janvier de l'année courante ;
     *  - « all » → bornes null (toute la période disponible) ;
     *  - « custom » → from/to validés au format YYYY-MM-DD (from <= to,
     *    sinon échange) ; bornes invalides → repli sur « 30d ».
     *
     * @param string|null $preset Clé de PERIOD_OPTIONS (« period » en GET).
     * @param string|null $from   Jour de début saisi (« from » en GET).
     * @param string|null $to     Jour de fin saisi (« to » en GET).
     *
     * @return array{preset:string, from:?string, to:?string} Dates « YYYY-MM-DD »
     *                                                          (null pour « all »).
     */
    public static function resolvePeriod(?string $preset, ?string $from, ?string $to): array
    {
        $preset = (string) $preset;
        if (!array_key_exists($preset, self::PERIOD_OPTIONS)) {
            $preset = '30d';
        }

        $fromDay = null;
        $toDay = null;

        switch ($preset) {
            case '1d':
                $fromDay = date('Y-m-d');
                $toDay = date('Y-m-d');
                break;
            case '7d':
                $fromDay = date('Y-m-d', strtotime('-6 days'));
                $toDay = date('Y-m-d');
                break;
            case '3m':
                $fromDay = date('Y-m-d', strtotime('-3 months'));
                $toDay = date('Y-m-d');
                break;
            case '6m':
                $fromDay = date('Y-m-d', strtotime('-6 months'));
                $toDay = date('Y-m-d');
                break;
            case '12m':
                $fromDay = date('Y-m-d', strtotime('-12 months'));
                $toDay = date('Y-m-d');
                break;
            case 'ytd':
                $fromDay = date('Y-01-01');
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
                    // Bornes invalides : bascule sur les 30 derniers jours.
                    $preset = '30d';
                    $fromDay = date('Y-m-d', strtotime('-29 days'));
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
        $prior = null;      // lot le plus récent avec valid_from <= date
        $priorFrom = '';

        foreach ($lots as $lot) {
            $from = self::datePart((string) ($lot['valid_from'] ?? ''));
            if ($from === '') {
                continue;
            }

            // Jamais un lot dont valid_from est postérieur à la date.
            if ($from > $day) {
                continue;
            }

            if ($prior === null || $from > $priorFrom) {
                $prior = $lot;
                $priorFrom = $from;
            }

            $toRaw = $lot['valid_to'] ?? null;
            $to = $toRaw === null ? null : self::datePart((string) $toRaw);
            if ($to === null || $day <= $to) {
                if ($covered === null || $from > $coveredFrom) {
                    $covered = $lot;
                    $coveredFrom = $from;
                }
            }
        }

        return $covered ?? $prior;
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
