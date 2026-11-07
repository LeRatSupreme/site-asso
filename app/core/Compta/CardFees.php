<?php

declare(strict_types=1);

namespace App\Core\Compta;

use App\Models\Setting;
use App\Models\Sale;

/**
 * Estimation des commissions SumUp sur les ventes par carte.
 *
 * Les imports SumUp (CSV comme API) ne contiennent jamais le frais :
 * chaque paiement porte pourtant son propre taux (1,75 % de base,
 * jusqu'à 2,5 % pour les cartes étrangères / Amex / paiement à
 * distance). L'estimation applique donc un taux moyen paramétrable
 * (Réglages → sumup_fee_rate, défaut 1,75 %) transaction par
 * transaction, arrondi au centime comme SumUp.
 *
 * Ce sont des ESTIMATIONS : le réel dépend du mix exact de cartes de
 * chaque versement groupé SumUp.
 */
final class CardFees
{
    /** Taux par défaut (%) : tarif standard SumUp carte présente France. */
    public const DEFAULT_RATE = 1.75;

    /**
     * Taux de commission courant (%) depuis les réglages.
     */
    public static function rate(): float
    {
        return self::parseRate(Setting::get('sumup_fee_rate', (string) self::DEFAULT_RATE));
    }

    /**
     * Analyse une saisie de taux (« 1,75 », « 1.75 », « 2 ») en float
     * borné [0 ; 100]. Toute valeur illisible ou hors bornes retombe
     * sur le taux par défaut.
     */
    public static function parseRate(string $raw): float
    {
        $raw = str_replace([' ', "\u{00a0}", "\u{202f}"], '', trim($raw));
        if ($raw === '' || preg_match('/^-?\d+([.,]\d+)?$/', $raw) !== 1) {
            return self::DEFAULT_RATE;
        }

        $rate = parseFrenchFloat($raw);
        if ($rate < 0 || $rate > 100) {
            return self::DEFAULT_RATE;
        }

        return $rate;
    }

    /**
     * Frais estimés sur les ventes CARTE (toutes, ou bornées à une
     * période de jours incluse).
     */
    public static function estimatedTotal(?string $fromDay = null, ?string $toDay = null): float
    {
        return round(Sale::sumCardFeeEstimate(self::rate(), $fromDay, $toDay), 2);
    }

    /**
     * Net estimé des ventes CARTE après frais (brut − frais).
     */
    public static function estimatedNet(?string $fromDay = null, ?string $toDay = null): float
    {
        return round(Sale::sumByPaymentMethod('CARTE') - self::estimatedTotal($fromDay, $toDay), 2);
    }

    /**
     * Taux formaté pour affichage (« 1,75 », sans zéros inutiles).
     */
    public static function formattedRate(): string
    {
        $rate = self::rate();

        return rtrim(rtrim(number_format($rate, 2, ',', ' '), '0'), ',');
    }
}
