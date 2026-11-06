<?php

declare(strict_types=1);

namespace App\Core\Compta\Reappro;

use App\Models\ProductPack;

/**
 * Fusion d'une saisie d'inventaire « packs + unités » en unités comptées.
 *
 * `inventory_counts` reste TOUJOURS en unités (le stock théorique, les
 * achats et les pertes le sont) : la conversion « 2 cartons de 24 + 3
 * unités = 51 » est faite côté serveur, ici, en un seul point — jamais
 * en JS ni dans les vues.
 *
 * La taille du pack est résolue par PackResolver (exacte → normalisée →
 * alias) via ProductPack::resolver() : un pack saisi sous une graphie
 * différente de la clé de comptage (casse, accents, ponctuation, alias
 * de saisie) est quand même appliqué — pas de map[strtolower] ad hoc
 * (règle AGENTS.md, régression « 5 cannettes » du 22/10/2026).
 *
 * Produit sans pack (résolveur = 0, ou taille 1 qui ne change rien) :
 * le champ packs est ignoré silencieusement — comportement strictement
 * identique au comptage en unités seules d'avant les packs.
 */
final class CountPacks
{
    /**
     * Fusionne les deux champs d'UNE ligne de comptage (packs + unités).
     *
     * @param string       $productKey Clé produit comptée (index des champs count[clé] / count_packs[clé]).
     * @param string       $unitsRaw   Valeur brute du champ unités ($_POST['count'][clé], '' si vide).
     * @param string       $packsRaw   Valeur brute du champ packs ($_POST['count_packs'][clé], '' si vide).
     * @param ?PackResolver $resolver  Résolveur de packs (défaut : ProductPack::resolver()).
     *
     * @return array{counted:int, note:?string}
     *         counted : unités comptées = packs × taille + unités ;
     *         note    : trace de la saisie d'origine « 2×24+3 » quand des
     *                   packs ont été pris en compte, null sinon (colonne
     *                   inventory_counts.note existante, aucune migration).
     */
    public static function merge(string $productKey, string $unitsRaw, string $packsRaw, ?PackResolver $resolver = null): array
    {
        $units = self::positiveInt($unitsRaw);
        $packs = self::positiveInt($packsRaw);

        // Pas de pack saisi : ligne d'unités classique, comportement
        // strictement identique à avant les packs.
        if ($packs === 0) {
            return ['counted' => $units, 'note' => null];
        }

        $size = ($resolver ?? ProductPack::resolver())->resolve($productKey);

        // Produit sans pack utile (< 2) : champ packs ignoré
        // silencieusement, la saisie d'unités reste seule valable.
        if ($size < 2) {
            return ['counted' => $units, 'note' => null];
        }

        return [
            'counted' => $packs * $size + $units,
            'note'    => $packs . '×' . $size . ($units > 0 ? '+' . $units : ''),
        ];
    }

    /**
     * Entier positif depuis une valeur de formulaire : vide, texte,
     * négatif ou nul → 0 (jamais d'erreur sur une saisie parasite).
     */
    private static function positiveInt(string $raw): int
    {
        $value = (int) trim($raw);

        return $value > 0 ? $value : 0;
    }
}
