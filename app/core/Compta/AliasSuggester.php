<?php

declare(strict_types=1);

namespace App\Core\Compta;

/**
 * Suggère une clé canonique (product_key) à partir d'un libellé brut de vente.
 *
 * But : consolider les libellés incohérents des rapports SumUp (Bueno_white /
 * Bueno, Coca_cherry / Coca cherry, Monster Blanche / Monster_Bleue…) vers des
 * clés canoniques stables, afin d'agréger correctement CA / coûts / marges.
 *
 * Heuristique **déterministe** (sans accès base) :
 *  - minuscules + suppression des accents ;
 *  - normalisation des séparateurs (_ - . () [] , ; :) en espaces ;
 *  - repliement des espaces multiples ;
 *  - retrait des suffixes de couleur/gout quand ils apparaissent en fin de
 *    libellé (« white », « blanche », « bleue », « verte », « rose »…) afin de
 *    regrouper les variantes autour de leur radical.
 *
 * Le résultat n'est pas censé fusionner parfaitement tous les cas : il doit
 * être déterministe et raisonnable, et reste éditable avant application.
 */
final class AliasSuggester
{
    /** @var array<string,string> Correspondances accents → ASCII. */
    private const ACCENTS = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
        'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'œ' => 'oe', 'æ' => 'ae',
    ];

    /**
     * Suffixes de couleur/gout retirés lorsqu'ils terminent le libellé.
     *
     * @var list<string>
     */
    private const SUFFIX_STOPWORDS = [
        'white', 'noir', 'black',
        'blanche', 'blanches', 'blanc', 'blancs',
        'bleue', 'bleues', 'bleu', 'bleus',
        'verte', 'vertes', 'vert', 'verts',
        'rose', 'rouge', 'jaune', 'orange', 'violet',
    ];

    /**
     * Taille minimale des formes normalisées pour la passe « faute de
     * frappe » (distance d'une édition) de groupDuplicates : en dessous,
     * une seule lettre modifiée porte trop peu de sens pour suggérer
     * une fusion.
     */
    private const NEAR_MISS_MIN_LENGTH = 5;

    public static function suggest(string $description): string
    {
        $s = self::normalizeKey($description);

        if ($s === '') {
            return '';
        }

        // Retrait itératif des suffixes de couleur/gout.
        $tokens = explode(' ', $s);
        while ($tokens !== [] && in_array(end($tokens), self::SUFFIX_STOPWORDS, true)) {
            array_pop($tokens);
        }

        return implode(' ', $tokens);
    }

    /**
     * Normalisation légère (sans retrait de suffixe) : minuscules, sans
     * accents, séparateurs repliés en espaces, espaces multiples repliées.
     *
     * Sert à détecter les doublons entre produits canoniques (« redbull
     * Peach » vs « Redbull Peach ») : deux libellés différents mais de
     * même clé normalisée désignent le même produit.
     */
    public static function normalizeKey(string $value): string
    {
        // 1) Minuscules.
        $s = mb_strtolower(trim($value));

        // 2) Suppression des accents (table manuelle — aucune dépendance intl).
        $s = strtr($s, self::ACCENTS);

        // 3) Normalisation des séparateurs en espaces.
        $s = (string) preg_replace('/[_\-\.()\[\]\{\},;:\/]+/u', ' ', $s);

        // 4) Repliement des espaces multiples + bornes.
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /**
     * Regroupe les noms de produits désignant probablement le MÊME produit.
     *
     * Deux passes déterministes :
     *  1. formes normalisées identiques (« redbull Peach » vs « Redbull
     *     Peach », « Coca_cherry » vs « Coca cherry ») ;
     *  2. fautes de frappe : formes normalisées distinctes mais distantes
     *     d'UNE seule édition — transposition (« Redbull Bleu » vs
     *     « redbull blue »), insertion, suppression ou substitution d'un
     *     caractère (« Cristaline » vs « Cristalline »).
     *
     * La passe 2 ne s'applique qu'aux formes ASCII d'au moins
     * NEAR_MISS_MIN_LENGTH caractères : en dessous, une édition porte trop
     * peu de sens (« Eau » vs « Riz »). Deux variantes légitimes restent
     * distinctes (« Monster Blanche » vs « Monster Bleue » : 3 éditions),
     * et le résultat n'est qu'une SUGGESTION de fusion, jamais une fusion
     * automatique.
     *
     * Ne retourne que les groupes comportant au moins 2 libellés distincts
     * (c'est-à-dire les vrais doublons), triés par nom.
     *
     * @param list<string> $names
     *
     * @return list<list<string>> Groupes de doublons (liste de libellés originaux).
     */
    public static function groupDuplicates(array $names): array
    {
        $byNorm = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $byNorm[self::normalizeKey($name)][] = $name;
        }

        // Passe 2 — union des groupes distants d'une édition (union-find,
        // racine = plus petite forme : la chaîne A~B, B~C ne forme qu'un
        // groupe, quel que soit l'ordre d'entrée).
        $norms = array_keys($byNorm);
        sort($norms, SORT_STRING);
        $parent = array_combine($norms, $norms);

        $root = static function (string $n) use (&$parent): string {
            while ($parent[$n] !== $n) {
                $parent[$n] = $parent[$parent[$n]];
                $n = $parent[$n];
            }

            return $n;
        };

        $count = count($norms);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (!self::isNearMiss($norms[$i], $norms[$j])) {
                    continue;
                }
                $ri = $root($norms[$i]);
                $rj = $root($norms[$j]);
                if ($ri === $rj) {
                    continue;
                }
                // Racine déterministe : la plus petite forme du groupe.
                if ($rj < $ri) {
                    [$ri, $rj] = [$rj, $ri];
                }
                $parent[$rj] = $ri;
            }
        }

        $groups = [];
        $byRootNorm = [];
        foreach ($norms as $n) {
            $byRootNorm[$root($n)][] = $n;
        }
        foreach ($byRootNorm as $memberNorms) {
            $members = [];
            foreach ($memberNorms as $n) {
                foreach ($byNorm[$n] as $name) {
                    $members[(string) $name] = true;
                }
            }
            $members = array_keys($members);
            if (count($members) < 2) {
                continue;
            }
            usort($members, static fn (string $a, string $b): int => strnatcasecmp($a, $b));
            $groups[] = $members;
        }

        usort($groups, static fn (array $a, array $b): int => strnatcasecmp($a[0], $b[0]));

        return $groups;
    }

    /**
     * Deux formes normalisées distantes d'AU PLUS une édition ?
     *
     * Méthode pure en O(n) : première divergence par la gauche, première
     * par la droite — le centre restant doit tenir en une substitution,
     * une insertion/suppression ou une transposition de deux caractères
     * adjacents. Formes égales, non ASCII ou trop courtes : jamais.
     */
    private static function isNearMiss(string $a, string $b): bool
    {
        if ($a === $b) {
            return false;
        }

        // ASCII uniquement : couper une chaîne multioctet par octet
        // produirait de faux positifs (la passe 1 reste applicable aux
        // formes exotiques via la table d'accents).
        if (preg_match('/[^\x20-\x7E]/', $a . $b) === 1) {
            return false;
        }

        $la = strlen($a);
        $lb = strlen($b);
        if ($la < self::NEAR_MISS_MIN_LENGTH || $lb < self::NEAR_MISS_MIN_LENGTH) {
            return false;
        }
        if (abs($la - $lb) > 1) {
            return false;
        }

        $i = 0;
        while ($i < $la && $i < $lb && $a[$i] === $b[$i]) {
            $i++;
        }

        $ja = $la - 1;
        $jb = $lb - 1;
        while ($ja >= $i && $jb >= $i && $a[$ja] === $b[$jb]) {
            $ja--;
            $jb--;
        }

        $midA = $ja - $i + 1;
        $midB = $jb - $i + 1;

        // Chiffres exclus : « Sucette 1 » vs « Sucette 3 » est une
        // variante numérotée (deux produits), pas une faute de frappe.
        $isTypoChar = static function (string $c): bool {
            return $c === ' ' || ctype_alpha($c);
        };

        // Substitution unique d'une lettre.
        if ($midA === 1 && $midB === 1) {
            return $isTypoChar($a[$i]) && $isTypoChar($b[$i]);
        }

        // Insertion/suppression d'un caractère (« red bull » / « redbull »,
        // « Monster » / « Monsteur ») : l'espace en plus ou en moins
        // n'existe déjà plus côté carte publique (StockPublic::normalizeKey).
        if ($midA + $midB === 1) {
            return $isTypoChar($midA === 1 ? $a[$i] : $b[$i]);
        }

        // Transposition de deux lettres adjacentes : « bleu » / « blue ».
        return $midA === 2 && $midB === 2
            && ctype_alpha($a[$i] . $a[$i + 1])
            && $a[$i] === $b[$i + 1]
            && $a[$i + 1] === $b[$i];
    }
}
