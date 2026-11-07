<?php

declare(strict_types=1);

namespace App\Core\Compta\Dashboard;

/**
 * Les 4 cartes « Niveau 1 » du dashboard /admin/compta (statique, pur,
 * zéro DB), dans l'ordre : Argent encaissé (ca), Ce qu'on gagne sur les
 * ventes (profit), Ce qui reste après les dépenses (net = profit −
 * expenses), Articles vendus (qty).
 *
 * Règles des repères :
 *  - JAMAIS de % tant que la comparaison n'est pas fiable : repère null si
 *    le précédent n'est pas fiable ($precedentComplet) OU si la période
 *    sélectionnée n'est pas terminée ($periodeComplete — règle de mission
 *    « aucun % sur période incomplète »), ni si le précédent est nul (pas
 *    de base de comparaison) ;
 *  - variation % = (actuel − precedent) / |precedent| × 100, arrondie à
 *    1 décimale, signe explicite (« +11,1 % » / « −10,0 % ») ;
 *  - repere_code des cartes ca/articles : ok si hausse, attention si
 *    baisse, neutre si nulle ou incomparable ;
 *  - repere_code de la carte net : par le SIGNE du net (≥ 0 → ok,
 *    < 0 → alerte), indépendant de la tendance.
 *
 * Ligne « sur 1 € vendu » (carte profit) : marge = profit ÷ CA, affichée
 * seulement si la période est complète et le CA strictement positif.
 *
 * $parametres est accepté pour homogénéité des signatures (surcharges
 * « dashboard_<nom> » via Hyper::valeur) : aucun seuil n'y est consommé
 * aujourd'hui, les repères sont qualitatifs (hausse/baisse).
 */
final class Cartes
{
    /**
     * Construit les 4 cartes, dans l'ordre fixe.
     *
     * @param array{ca?:float|int,profit?:float|int,qty?:float|int,expenses?:float|int} $actuel
     *     Agrégats de la période (Sale::aggregatesBetween + Expense::aggregatesBetween).
     * @param array{ca?:float|int,profit?:float|int,qty?:float|int,expenses?:float|int}|null $precedent
     *     Agrégats de la période précédente (même longueur, finissant J-1), ou null.
     * @param bool $precedentComplet Le précédent est-il comparable (fourni et fiable) ?
     * @param bool $periodeComplete La période sélectionnée est-elle terminée ?
     * @param array<string,mixed> $parametres Surcharges « dashboard_<nom> » (réservé).
     *
     * @return list<array{cle:string, label:string, valeur:string, valeur_num:float, repere:?string, repere_code:'ok'|'attention'|'alerte'|'neutre', aide:string, ligne2:?string}>
     */
    public static function construire(array $actuel, ?array $precedent, bool $precedentComplet, bool $periodeComplete, array $parametres): array
    {
        $ca = (float) ($actuel['ca'] ?? 0.0);
        $profit = (float) ($actuel['profit'] ?? 0.0);
        $qty = (float) ($actuel['qty'] ?? 0.0);
        $expenses = (float) ($actuel['expenses'] ?? 0.0);
        $net = $profit - $expenses;

        // Le précédent n'est consulté QUE si le contrôleur le déclare fiable
        // ET que la période est terminée (jamais de % sur une période en cours).
        $comparable = $precedentComplet && $periodeComplete;
        $prevCa = 0.0;
        $prevNet = 0.0;
        $prevQty = 0.0;
        if ($comparable && is_array($precedent)) {
            $prevCa = (float) ($precedent['ca'] ?? 0.0);
            $prevNet = (float) ($precedent['profit'] ?? 0.0) - (float) ($precedent['expenses'] ?? 0.0);
            $prevQty = (float) ($precedent['qty'] ?? 0.0);
        }

        $varCa = $comparable ? self::variationPct($ca, $prevCa) : null;
        $varNet = $comparable ? self::variationPct($net, $prevNet) : null;
        $varQty = $comparable ? self::variationPct($qty, $prevQty) : null;

        return [
            [
                'cle'         => 'ca',
                'label'       => 'Argent encaissé',
                'valeur'      => self::formatEuro($ca),
                'valeur_num'  => $ca,
                'repere'      => self::reperePeriode($varCa),
                'repere_code' => self::codeTendance($varCa),
                'aide'        => 'Tout ce qui a été encaissé, liquide et carte confondus.',
                'ligne2'      => null,
            ],
            [
                'cle'         => 'profit',
                'label'       => "Ce qu'on gagne sur les ventes",
                'valeur'      => self::formatEuro($profit),
                'valeur_num'  => $profit,
                'repere'      => null,
                'repere_code' => 'neutre',
                'aide'        => "Prix de vente moins le coût d'achat des produits. Ne compte pas vos autres dépenses.",
                'ligne2'      => ($periodeComplete && $ca > 0.0)
                    ? 'Sur 1 € vendu, on garde ' . self::formatEuro(round($profit / $ca, 2))
                    : null,
            ],
            [
                'cle'         => 'net',
                'label'       => 'Ce qui reste après les dépenses',
                'valeur'      => self::formatEuro($net),
                'valeur_num'  => $net,
                'repere'      => self::reperePeriode($varNet),
                // Code par le SIGNE du net, jamais par la tendance.
                'repere_code' => $net >= 0.0 ? 'ok' : 'alerte',
                'aide'        => "La marge des ventes moins les dépenses saisies (achats de stock exclus : ils sont déjà comptés dans la marge).",
                'ligne2'      => null,
            ],
            [
                'cle'         => 'articles',
                'label'       => 'Articles vendus',
                'valeur'      => self::formatEntier($qty),
                'valeur_num'  => $qty,
                'repere'      => self::repereSimple($varQty),
                'repere_code' => self::codeTendance($varQty),
                'aide'        => "Nombre total d'articles vendus sur la période.",
                'ligne2'      => null,
            ],
        ];
    }

    /**
     * Variation en % entre deux valeurs : null si pas de base de
     * comparaison (précédent nul), sinon (actuel − precedent)/|precedent| × 100.
     */
    private static function variationPct(float $actuel, float $precedent): ?float
    {
        if ($precedent == 0.0) {
            return null;
        }

        return ($actuel - $precedent) / abs($precedent) * 100.0;
    }

    /**
     * Repère des cartes ca/net : « +11,1 % vs période précédente ».
     */
    private static function reperePeriode(?float $variation): ?string
    {
        return $variation === null ? null : self::signePct($variation) . ' vs période précédente';
    }

    /**
     * Repère simple de la carte articles : « +5,0 % » / « −2,0 % ».
     */
    private static function repereSimple(?float $variation): ?string
    {
        return $variation === null ? null : self::signePct($variation);
    }

    /**
     * Variation signée formatée FR : « +11,1 % » / « −10,0 % » / « 0,0 % ».
     */
    private static function signePct(float $variation): string
    {
        if ($variation > 0.0) {
            return '+' . number_format($variation, 1, ',', ' ') . ' %';
        }
        if ($variation < 0.0) {
            return '−' . number_format(abs($variation), 1, ',', ' ') . ' %';
        }

        return '0,0 %';
    }

    /**
     * Code de tendance (cartes ca/articles) : hausse → ok, baisse →
     * attention, nulle ou incomparable → neutre.
     */
    private static function codeTendance(?float $variation): string
    {
        if ($variation === null || $variation == 0.0) {
            return 'neutre';
        }

        return $variation > 0.0 ? 'ok' : 'attention';
    }

    /**
     * Montant en euros formaté FR : « 1 234,56 € », « −50,00 € »
     * (espace milliers, virgule décimale, signe moins typographique).
     */
    private static function formatEuro(float $montant): string
    {
        return ($montant < 0.0 ? '−' : '') . number_format(abs($montant), 2, ',', ' ') . ' €';
    }

    /**
     * Nombre entier formaté FR : « 1 234 » (espace milliers, sans décimale).
     */
    private static function formatEntier(float $nombre): string
    {
        return number_format($nombre, 0, ',', ' ');
    }
}
