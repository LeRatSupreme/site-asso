<?php

declare(strict_types=1);

namespace App\Core\Compta\Forecast;

/**
 * Étape 7 : projection de bénéfice à 7 jours — DÉRIVÉE DES MÊMES UNITÉS
 * que le réapprovisionnement (une seule source d'unités, calibrée sur le
 * CA prévu par le moteur, cf. ProductForecast::units).
 *
 * Pour chaque produit NON suspect à prix et coût connus :
 *
 *     ca_p    = unites_p × prix_p
 *     marge_p = unites_p × (prix_p − coût_p)
 *
 * Les agrégats par catégorie (ca, marge, marge_pct = marge/ca) sont donc
 * COMPARABLES à la marge observée du tableau « Détail par produit » : les
 * deux colonnes vivent à la même échelle (unités calibrées, plus de mélange
 * CA observé fenêtre / marge prévue 7 j). INVARIANT : Σ_p ca_p ≈ CA prévu
 * 7 j du moteur (±0,5 % pour les prix connus).
 *
 * Les produits sans coût fiable (coût inconnu, coût <= 0 ou prix < coût)
 * sont listés à part et ne contribuent à aucune marge : le bénéfice est
 * donc une borne basse, ce que rappelle la note UI.
 * pct_ca_sans_cout_fiable = Σ ca des suspects / CA de la fenêtre observée.
 * Toutes les méthodes sont statiques et pures.
 */
final class ProfitForecast
{
    /**
     * Calcule la répartition du bénéfice par catégorie depuis les unités
     * calibrées du réapprovisionnement.
     *
     * @param list<array{category:string,unites:float,unitPrice:?float,unitCost:?float,costSuspect:bool,label?:string,key?:string}> $unites           Unités 7 j calibrées (sortie ProductForecast::units).
     * @param float             $caCourantFenetre CA total de la fenêtre analysée (dénominateur du %).
     *
     * @return array{par_categorie:array<string,array{ca:float,marge:float,marge_pct:float}>,total_marge_7j:float,pct_ca_sans_cout_fiable:float,suspects:list<array{key:string,label:string,raison:string}>,note:string}
     */
    public static function compute(array $unites, float $caCourantFenetre): array
    {
        $parCategorie = [];
        $suspects     = [];
        $caSuspects   = 0.0;

        // Passe 1 : classification, CA prévu par catégorie (unites × prix).
        foreach ($unites as $p) {
            if (!is_array($p) || !isset($p['category'])) {
                continue;
            }
            $cat = (string) $p['category'];
            if (!isset($parCategorie[$cat])) {
                $parCategorie[$cat] = ['ca' => 0.0, 'marge' => 0.0, 'marge_pct' => 0.0];
            }
            $prix  = isset($p['unitPrice']) && is_numeric($p['unitPrice']) ? (float) $p['unitPrice'] : null;
            $caP   = ($prix !== null && $prix > 0.0) ? (float) ($p['unites'] ?? 0.0) * $prix : 0.0;
            $parCategorie[$cat]['ca'] += $caP;

            if (!empty($p['costSuspect'])) {
                $caSuspects += $caP;
                $suspects[] = [
                    'key'    => (string) ($p['key'] ?? ''),
                    'label'  => (string) ($p['label'] ?? ($p['key'] ?? '')),
                    'raison' => self::raison($prix, isset($p['unitCost']) && is_numeric($p['unitCost']) ? (float) $p['unitCost'] : null),
                ];
            }
        }

        // Passe 2 : marge prévue par produit fiable (unites × (prix − coût)).
        $totalMarge = 0.0;
        foreach ($unites as $p) {
            if (!is_array($p) || !empty($p['costSuspect']) || !isset($p['category'])) {
                continue;
            }
            $prix = isset($p['unitPrice']) && is_numeric($p['unitPrice']) ? (float) $p['unitPrice'] : null;
            $cout = isset($p['unitCost']) && is_numeric($p['unitCost']) ? (float) $p['unitCost'] : null;
            if ($prix === null || $prix <= 0.0 || $cout === null) {
                continue;
            }
            $margeP = (float) ($p['unites'] ?? 0.0) * ($prix - $cout);
            $totalMarge += $margeP;
            $parCategorie[(string) $p['category']]['marge'] += $margeP;
        }

        // Pourcentages de marge par catégorie (CA prévu des MÊMES unités).
        foreach ($parCategorie as $cat => $row) {
            $parCategorie[$cat]['marge_pct'] = $row['ca'] > 0.0 ? $row['marge'] / $row['ca'] * 100.0 : 0.0;
        }

        $pctSansCout = $caCourantFenetre > 0.0 ? $caSuspects / $caCourantFenetre * 100.0 : 0.0;

        return [
            'par_categorie'          => $parCategorie,
            'total_marge_7j'         => $totalMarge,
            'pct_ca_sans_cout_fiable' => $pctSansCout,
            'suspects'               => $suspects,
            'note'                   => self::note($pctSansCout, $suspects),
        ];
    }

    /**
     * Note UI en TROIS régimes selon la part du CA sans coût fiable
     * (valeur brute conservée dans le payload ; le rendu ci-dessous formule
     * le % avec 1 décimale si < 5 %, sinon en entier — plus de « 0 % »
     * trompeur pour une part réelle de 0,4 %). Jusqu'à 3 produits suspects
     * sont nommés. En dessous de 0,5 % l'impact est négligeable et le
     * bénéfice n'est pas considéré comme biaisé.
     *
     * @param list<array{key:string,label:string,raison:string}> $suspects
     */
    private static function note(float $pct, array $suspects): string
    {
        if ($pct <= 0.0) {
            return 'Coûts fiables sur tout le CA récent : l\'estimation du bénéfice est complète.';
        }

        $noms = array_slice(array_map(static fn (array $s): string => (string) $s['label'], $suspects), 0, 3);
        $avecNoms = $noms === [] ? '' : ' (' . implode(', ', $noms) . ')';

        if ($pct < 0.5) {
            return 'Quelques produits sans coût fiable' . $avecNoms
                . ' : impact < 0,5 % du CA, marge peu biaisée.';
        }
        if ($pct < 5.0) {
            return number_format($pct, 1, ',', ' ') . ' % du CA récent sans coût fiable' . $avecNoms
                . ' : bénéfice possiblement biaisé.';
        }

        return sprintf('%d %% du CA récent sans coût fiable%s : bénéfice sous-estimé ou surestimé.', (int) round($pct), $avecNoms);
    }

    /**
     * Raison du coût suspect, dans l'ordre de diagnostic :
     * coût inconnu (null), coût <= 0, prix inférieur au coût.
     */
    private static function raison(?float $prix, ?float $cout): string
    {
        if ($cout === null) {
            return 'cout_inconnu';
        }
        if ($cout <= 0.0) {
            return 'cout_zero';
        }
        if ($prix !== null && $prix < $cout) {
            return 'prix_inferieur_au_cout';
        }

        return 'cout_inconnu';
    }
}
