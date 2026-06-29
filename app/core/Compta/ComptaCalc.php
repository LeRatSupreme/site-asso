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
     * Sélectionne le lot de coût valide à une date donnée.
     *
     * Un lot est valide si : valid_from <= date ET (valid_to est NULL
     * OU date < valid_to). En cas de lots chevauchants, on retient celui dont
     * le valid_from est le plus récent (le plus précis pour la période).
     *
     * @param list<array<string,mixed>> $lots Chaque lot doit contenir
     *                                        'valid_from', 'valid_to' (nullable),
     *                                        'cost_price', etc.
     * @return array<string,mixed>|null Le lot retenu, ou null si aucun valide.
     */
    public static function selectCostLot(string $date, array $lots): ?array
    {
        $day = self::datePart($date);

        $best = null;
        $bestFrom = '';

        foreach ($lots as $lot) {
            $from = self::datePart((string) ($lot['valid_from'] ?? ''));
            if ($from === '' || $from > $day) {
                continue;
            }

            $toRaw = $lot['valid_to'] ?? null;
            $to = $toRaw === null ? null : self::datePart((string) $toRaw);
            if ($to !== null && $day >= $to) {
                continue;
            }

            if ($best === null || $from > $bestFrom) {
                $best = $lot;
                $bestFrom = $from;
            }
        }

        return $best;
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
