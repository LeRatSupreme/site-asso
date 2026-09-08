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
     * Regroupe les noms de produits dont la clé normalisée est identique.
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

        $groups = [];
        foreach ($byNorm as $norm => $members) {
            $members = array_values(array_unique($members));
            if (count($members) < 2) {
                continue;
            }
            usort($members, static fn (string $a, string $b): int => strnatcasecmp($a, $b));
            $groups[] = $members;
        }

        usort($groups, static fn (array $a, array $b): int => strnatcasecmp($a[0], $b[0]));

        return $groups;
    }
}
