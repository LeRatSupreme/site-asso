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
        // 1) Minuscules.
        $s = mb_strtolower(trim($description));

        // 2) Suppression des accents (table manuelle — aucune dépendance intl).
        $s = strtr($s, self::ACCENTS);

        // 3) Normalisation des séparateurs en espaces.
        $s = (string) preg_replace('/[_\-\.()\[\]\{\},;:\/]+/u', ' ', $s);

        // 4) Repliement des espaces multiples + bornes.
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));

        if ($s === '') {
            return '';
        }

        // 5) Retrait itératif des suffixes de couleur/gout.
        $tokens = explode(' ', $s);
        while ($tokens !== [] && in_array(end($tokens), self::SUFFIX_STOPWORDS, true)) {
            array_pop($tokens);
        }

        return implode(' ', $tokens);
    }
}
