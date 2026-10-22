<?php

declare(strict_types=1);

namespace App\Core\Compta\Dashboard;

/**
 * Verdict du haut de page /admin/compta (statique, pur, zéro DB) :
 * UNE phrase en français courant pour un non-spécialiste, un code
 * couleur ('ok' | 'attention' | 'alerte' | 'neutre') et, le cas échéant,
 * la phrase d'objectif de CA.
 *
 * Règles DÉTERMINISTES, dans cet ordre :
 *  1. période incomplète (ou résultat manquant) → « pas encore de verdict »,
 *     neutre, JAMAIS de % (le contrôleur décide si la période est complète) ;
 *  2. précédent non fiable → « pas de comparaison fiable », neutre ;
 *  3. aucune vente → alerte si le résultat net est négatif, sinon neutre ;
 *  4. tendance du résultat net : variation = (net − netPrev) / |netPrev| × 100.
 *     netPrev nul → « pas de base de comparaison » (ok / alerte / stable sans %).
 *     variation > 0 → ok ; variation < 0 et ≥ seuil d'alerte (borne INCLUSIVE,
 *     surchargeable, défaut Hyper::BAISSE_ALERTE_PCT = −25 %) → attention ;
 *     en dessous → alerte ; variation nulle → stable, neutre.
 *     (BAISSE_ATTENTION_PCT = −10 % marque l'entrée dans l'orange : réservé
 *     à l'affichage/légende, la règle ci-dessus couvre toute baisse ≥ seuil.)
 *  5. objectif de CA, APRÈS la tendance, seulement si période complète,
 *     objectif renseigné (> 0) et CA > 0 : « atteint » à partir de
 *     Hyper::OBJECTIF_SEUIL_OK_PCT ; sinon « P % atteint » et si le code
 *     était 'ok' il passe à 'attention' (objectif manquant = signal orange,
 *     jamais masqué par une bonne tendance). JAMAIS de % si !periodeComplete.
 */
final class Verdict
{
    /**
     * Construit le verdict de la période.
     *
     * @param array<string,mixed> $parametres Surcharges « dashboard_<nom> » (seuil d'alerte…).
     * @param string|null         $periodeLibelle Libellé optionnel glissé dans la phrase « période en cours ».
     *
     * @return array{phrase:string, code:'ok'|'attention'|'alerte'|'neutre', objectif_phrase:?string}
     */
    public static function construire(
        ?float $net,
        ?float $netPrecedent,
        bool $precedentComplet,
        bool $periodeComplete,
        float $ca,
        ?float $objectif,
        array $parametres,
        ?string $periodeLibelle = null
    ): array {
        $seuilAlerte = (float) Hyper::valeur($parametres, 'baisse_alerte_pct', Hyper::BAISSE_ALERTE_PCT);

        // Règle 1 — période en cours (ou résultat manquant) : pas de verdict.
        if (!$periodeComplete || $net === null) {
            $phrase = $periodeLibelle === null
                ? 'Période en cours : pas encore de verdict.'
                : 'Période en cours (' . $periodeLibelle . ') : pas encore de verdict.';

            return self::avecObjectif($phrase, 'neutre', $periodeComplete, $ca, $objectif);
        }

        // Règle 2 — comparaison impossible avec la période précédente.
        if (!$precedentComplet || $netPrecedent === null) {
            return self::avecObjectif(
                'Pas de comparaison fiable avec la période précédente (données incomplètes).',
                'neutre',
                $periodeComplete,
                $ca,
                $objectif
            );
        }

        // Règle 3 — aucune vente sur la période.
        if ($ca == 0.0) {
            if ($net < 0.0) {
                return self::avecObjectif(
                    'Aucune vente sur la période, et des dépenses : le résultat net est négatif (' . self::formatEuro($net) . ').',
                    'alerte',
                    $periodeComplete,
                    $ca,
                    $objectif
                );
            }

            return self::avecObjectif('Aucune vente sur la période.', 'neutre', $periodeComplete, $ca, $objectif);
        }

        // Règle 4a — précédent nul : pas de base de comparaison (jamais de %).
        if ($netPrecedent == 0.0) {
            if ($net > 0.0) {
                $phrase = 'Résultat net de ' . self::formatEuro($net) . ' (pas de base de comparaison).';
                $code = 'ok';
            } elseif ($net < 0.0) {
                $phrase = 'Dépenses supérieures à la marge : résultat net négatif (' . self::formatEuro($net) . ').';
                $code = 'alerte';
            } else {
                $phrase = 'Résultat net stable (' . self::formatEuro($net) . ').';
                $code = 'neutre';
            }

            return self::avecObjectif($phrase, $code, $periodeComplete, $ca, $objectif);
        }

        // Règle 4b — tendance du résultat net (variation en %).
        $variation = ($net - $netPrecedent) / abs($netPrecedent) * 100.0;
        if ($variation > 0.0) {
            $phrase = 'Bonne nouvelle : le résultat net progresse ('
                . self::formatEuro($net) . ' contre ' . self::formatEuro($netPrecedent)
                . ', +' . self::formatPct($variation) . ' %).';
            $code = 'ok';
        } elseif ($variation == 0.0) {
            $phrase = 'Résultat net stable (' . self::formatEuro($net) . ').';
            $code = 'neutre';
        } elseif ($variation >= $seuilAlerte) {
            $phrase = 'Le résultat net baisse (' . self::formatPct($variation) . ' %) : '
                . self::formatEuro($net) . ' contre ' . self::formatEuro($netPrecedent)
                . ' sur la période précédente.';
            $code = 'attention';
        } else {
            $phrase = 'Forte baisse du résultat net (' . self::formatPct($variation) . ' %) : '
                . self::formatEuro($net) . ' contre ' . self::formatEuro($netPrecedent) . '.';
            $code = 'alerte';
        }

        return self::avecObjectif($phrase, $code, $periodeComplete, $ca, $objectif);
    }

    /**
     * Règle 5 — phrase d'objectif de CA (null si non applicable) et
     * dégradation ok → attention quand l'objectif est manqué. Le % n'est
     * JAMAIS calculé si la période est incomplète.
     *
     * @param array<string,mixed> $parametres
     *
     * @return array{phrase:string, code:'ok'|'attention'|'alerte'|'neutre', objectif_phrase:?string}
     */
    private static function avecObjectif(
        string $phrase,
        string $code,
        bool $periodeComplete,
        float $ca,
        ?float $objectif
    ): array {
        $objectifPhrase = null;
        if ($periodeComplete && $objectif !== null && $objectif > 0.0 && $ca > 0.0) {
            $pct = $ca / $objectif * 100.0;
            if ($pct >= Hyper::OBJECTIF_SEUIL_OK_PCT) {
                $objectifPhrase = 'Objectif de CA atteint (' . self::formatEuro($ca) . ' sur ' . self::formatEuro($objectif) . ').';
            } else {
                $objectifPhrase = 'Objectif de CA : ' . self::formatPct($pct) . ' % atteint ('
                    . self::formatEuro($ca) . ' sur ' . self::formatEuro($objectif) . ').';
                if ($code === 'ok') {
                    $code = 'attention';
                }
            }
        }

        return ['phrase' => $phrase, 'code' => $code, 'objectif_phrase' => $objectifPhrase];
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
     * Pourcentage à 1 décimale formaté FR : « 25,0 », « −12,3 »
     * (signe négatif seul ; le « + » est posé par les phrases de hausse).
     */
    private static function formatPct(float $pct): string
    {
        return ($pct < 0.0 ? '−' : '') . number_format(abs($pct), 1, ',', ' ');
    }
}
