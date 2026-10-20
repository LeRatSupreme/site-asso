<?php

declare(strict_types=1);

namespace App\Core\Compta\Forecast;

/**
 * Étape 7 : projection de bénéfice à 7 jours.
 *
 * La marge unitaire d'un produit fiable vaut (unitPrice − unitCost).
 * Le CA prévu sur 7 jours est réparti au prorata des quantités vendues
 * sur la fenêtre (produits NON suspects uniquement) :
 *
 *     part_i   = qty_i / Σ qty(non suspects)
 *     benef_i  = caPrevu7j × part_i × (marge_i / unitPrice_i)
 *
 * Les produits sans coût fiable (coût inconnu, coût <= 0 ou prix <
 * coût) sont listés à part et ne contribuent à aucune marge : le
 * bénéfice est donc une borne basse, ce que rappelle la note UI.
 * Toutes les méthodes sont statiques et pures.
 */
final class ProfitForecast
{
    /**
     * Calcule la répartition du bénéfice par catégorie.
     *
     * @param list<ProductStat> $products         Statistiques produits de la fenêtre.
     * @param float             $caCourantFenetre CA total de la fenêtre analysée (dénominateur du %).
     * @param float             $caPrevu7j        CA total prévu sur les 7 prochains jours (événements inclus).
     *
     * @return array{par_categorie:array<string,array{ca:float,marge:float,marge_pct:float}>,total_marge_7j:float,pct_ca_sans_cout_fiable:float,suspects:list<array{key:string,label:string,raison:string}>,note:string}
     */
    public static function compute(array $products, float $caCourantFenetre, float $caPrevu7j): array
    {
        $parCategorie = [];
        $suspects     = [];
        $qtyFiables   = 0;
        $caSuspects   = 0.0;

        // Passe 1 : classification, CA par catégorie, total des quantités fiables.
        foreach ($products as $p) {
            if (!$p instanceof ProductStat) {
                continue;
            }
            if (!isset($parCategorie[$p->category])) {
                $parCategorie[$p->category] = ['ca' => 0.0, 'marge' => 0.0, 'marge_pct' => 0.0];
            }
            $parCategorie[$p->category]['ca'] += $p->ca;

            if ($p->costSuspect) {
                $caSuspects += $p->ca;
                $suspects[] = [
                    'key'    => $p->key,
                    'label'  => $p->label,
                    'raison' => self::raison($p),
                ];
                continue;
            }
            $qtyFiables += $p->qty;
        }

        // Passe 2 : répartition du CA prévu et marge par produit fiable.
        $totalMarge = 0.0;
        foreach ($products as $p) {
            if (!$p instanceof ProductStat || $p->costSuspect) {
                continue;
            }
            $part = $qtyFiables > 0 ? $p->qty / $qtyFiables : 0.0;
            if ($part <= 0.0 || $p->unitPrice === null || $p->unitPrice <= 0.0 || $p->unitCost === null) {
                continue;
            }
            $margeUnitaire = $p->unitPrice - $p->unitCost;
            $benef         = $caPrevu7j * $part * ($margeUnitaire / $p->unitPrice);
            $totalMarge   += $benef;
            if (isset($parCategorie[$p->category])) {
                $parCategorie[$p->category]['marge'] += $benef;
            }
        }

        // Pourcentages de marge par catégorie (CA de la fenêtre).
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
    private static function raison(ProductStat $p): string
    {
        if ($p->unitCost === null) {
            return 'cout_inconnu';
        }
        if ($p->unitCost <= 0.0) {
            return 'cout_zero';
        }
        if ($p->unitPrice !== null && $p->unitPrice < $p->unitCost) {
            return 'prix_inferieur_au_cout';
        }

        return 'cout_inconnu';
    }
}
