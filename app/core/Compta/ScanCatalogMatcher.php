<?php

declare(strict_types=1);

namespace App\Core\Compta;

use App\Models\Product;
use App\Models\ProductAlias;
use App\Models\Sale;

/**
 * Reconnaît les libellés de lignes d'une facture scannée (OCR brut, ex.
 * « RED BULL WHITE BOITE 25CL ») dans le catalogue de l'asso : fiches
 * produits, alias de ventes (product_aliases : libellé brut SumUp ->
 * clé canonique) et clés déjà vendues. Chaque ligne du scan reçoit
 * ainsi une clé « product » directement exploitable pour créer les
 * achats (le même stock que les ventes).
 *
 * La clé retournée est la CLÉ D'AGRÉGATION existante (celle des achats
 * et des ventes : « Monster Blanche », « oasis pomme poire »…), avec
 * l'orthographe de la fiche produit quand elle existe.
 *
 * Heuristique déterministe, en trois paliers :
 *  1. égalité exacte des clés normalisées (casse/accents/espaces
 *     ignorés : « KIT KAT » = « KitKat ») ;
 *  2. appariement par mots-clés : mots signifiants du libellé vs de
 *     chaque entrée du catalogue, avec tolérance de saisine
 *     (Levenshtein <= 1, ex. « SNACK » ~ « SNAK ») et dictionnaire
 *     EN/FR des couleurs et goûts (« WHITE » ~ « BLANCHE »,
 *     « PEACH » ~ « PECHE ») ; l'entrée qui matche le plus de mots
 *     gagne, à égalité parfaite on considère l'appariement ambigu et
 *     on ne choisit pas ;
 *  3. inclusion symétrique de clé normalisée (aiguille >= 4
 *     caractères, correspondance la plus longue — même esprit que
 *     StockPublic::matchKey) : « SNICKERS 50G » contient « snickers ».
 *
 * Les mots de conditionnement (BTE, BOITE, BARRE, SLIM, GRS, CL,
 * KG…), les mots vide (DE, LA…) et les jetons chiffrés (50G, 33CL,
 * T10, SLIM33CL…) sont ignorés au palier mots-clés.
 *
 * Sans base de données (tests, panne) : catalogue vide, aucun match,
 * jamais d'exception — le scan reste utilisable sans enrichissement.
 */
final class ScanCatalogMatcher
{
    /**
     * Mots sans valeur discriminante pour l'appariement mots-clés :
     * mots vides français, conditionnements et unités de vente.
     *
     * @var list<string>
     */
    private const STOPWORDS = [
        'de', 'du', 'la', 'le', 'les', 'et', 'en', 'au', 'aux', 'sans', 'avec', 'pour',
        'barre', 'barres', 'bte', 'boite', 'boites', 'canette', 'canettes',
        'sachet', 'sachets', 'pack', 'packs', 'slim', 'grs', 'pcs', 'unite', 'unites',
    ];

    /**
     * Dictionnaire EN -> FR des couleurs/goûts présents du côté SumUp
     * comme côté factures fournisseurs (METRO libelle en anglais,
     * la carte en français). Comparaison dans les deux sens, sur les
     * jetons d'au moins 4 caractères.
     *
     * @var array<string, list<string>>
     */
    private const EN_FR = [
        'white'  => ['blanche', 'blanches', 'blanc', 'blancs'],
        'black'  => ['noire', 'noires', 'noir', 'noirs'],
        'blue'   => ['bleue', 'bleues', 'bleu', 'bleus'],
        'green'  => ['verte', 'vertes', 'vert', 'verts'],
        'pink'   => ['rose', 'roses'],
        'red'    => ['rouge', 'rouges'],
        'yellow' => ['jaune', 'jaunes'],
        'purple' => ['violet', 'violette', 'violets'],
        'peach'  => ['peche', 'peches'],
        'apple'  => ['pomme', 'pommes'],
    ];

    /**
     * Catalogue depuis la base : fiches produits (orthographe de
     * référence), alias de ventes (libellé brut ET clé cible), clés
     * déjà vendues. Échec de lecture : liste vide, sans exception.
     *
     * @return list<array{key:string, name:string, tokens:list<string>}>
     */
    public static function buildCatalog(): array
    {
        $fiches = [];
        try {
            foreach (Product::allForAdmin() as $row) {
                $name = trim((string) ($row['name'] ?? ''));
                if ($name !== '') {
                    $fiches[] = $name;
                }
            }
        } catch (\Throwable) {
            $fiches = [];
        }

        $aliases = [];
        try {
            $aliases = ProductAlias::all();
        } catch (\Throwable) {
            $aliases = [];
        }

        $sales = [];
        try {
            $sales = Sale::distinctProducts();
        } catch (\Throwable) {
            $sales = [];
        }

        return self::catalogFrom($fiches, $aliases, $sales);
    }

    /**
     * Assemble le catalogue (méthode pure, testée unitairement) :
     * déduplication par clé normalisée, priorité fiche produit >
     * alias > clé de vente, tri naturel par nom pour un comportement
     * déterministe.
     *
     * @param list<string>                        $ficheNames Noms des fiches produits.
     * @param list<array<string,mixed>>           $aliasRows  Lignes product_aliases
     *                                                        (raw_description, product_key).
     * @param list<string>                        $saleKeys   Clés distinctes des ventes.
     *
     * @return list<array{key:string, name:string, tokens:list<string>}>
     */
    public static function catalogFrom(array $ficheNames, array $aliasRows, array $saleKeys): array
    {
        // Orthographe de référence par clé normalisée (les fiches).
        $ficheByNorm = [];
        foreach ($ficheNames as $name) {
            $ficheByNorm[self::spaceless($name)] = $name;
        }

        // Résout une clé cible d'alias : orthographe de la fiche quand
        // elle existe (« bounty » -> « Bounty »), sinon la clé telle
        // quelle (« oasis pomme poire » — clé SumUp réellement vendue).
        $resolve = static function (string $target) use ($ficheByNorm): string {
            $norm = self::spaceless($target);

            return $ficheByNorm[$norm] ?? $target;
        };

        $byNorm = [];

        // 1) Fiches produits : priorité maximale (orthographe canonique).
        foreach ($ficheNames as $name) {
            $byNorm[self::spaceless($name)] = $name;
        }

        // 2) Alias : le libellé brut mène à sa clé cible ; la cible
        //    elle-même devient aussi une entrée candidate.
        foreach ($aliasRows as $row) {
            $raw = trim((string) ($row['raw_description'] ?? ''));
            $target = trim((string) ($row['product_key'] ?? ''));
            if ($raw !== '' && !isset($byNorm[self::spaceless($raw)])) {
                $byNorm[self::spaceless($raw)] = $target === '' ? $raw : $resolve($target);
            }
            if ($target !== '' && !isset($byNorm[self::spaceless($target)])) {
                $byNorm[self::spaceless($target)] = $resolve($target);
            }
        }

        // 3) Clés de vente : filet de sécurité pour ce qui n'est ni
        //    fiche ni alias.
        foreach ($saleKeys as $key) {
            if ($key !== '' && !isset($byNorm[self::spaceless($key)])) {
                $byNorm[self::spaceless($key)] = $resolve($key);
            }
        }

        $catalog = [];
        foreach ($byNorm as $norm => $name) {
            if ($norm === '') {
                continue;
            }
            $catalog[] = [
                'key'    => $norm,
                'name'   => $name,
                'tokens' => self::significantTokens($name),
            ];
        }

        usort($catalog, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return $catalog;
    }

    /**
     * Meilleure clé de catalogue pour un libellé de facture, ou null
     * si rien ne s'apparie avec confiance.
     *
     * @param array<array{key:string, name:string, tokens:list<string>}>|null $catalog
     */
    public static function match(string $label, ?array $catalog = null): ?string
    {
        $label = trim($label);
        if ($label === '') {
            return null;
        }

        $catalog ??= self::buildCatalog();
        if ($catalog === []) {
            return null;
        }

        $norm = self::spaceless($label);
        if ($norm === '') {
            return null;
        }

        // Palier 1 : égalité exacte des clés normalisées.
        foreach ($catalog as $cand) {
            if ($cand['key'] === $norm) {
                return $cand['name'];
            }
        }

        // Palier 2 : appariement par mots-clés (le plus riche gagne,
        // ambiguïté refusée).
        $byTokens = self::matchByTokens($label, $catalog);
        if ($byTokens !== null) {
            return $byTokens;
        }

        // Palier 3 : inclusion symétrique, aiguille la plus longue.
        return self::matchByInclusion($norm, $catalog);
    }

    /**
     * Enrichit les lignes d'une facture scannée : chaque ligne reçoit
     * « product » (clé de catalogue reconnue, ou null). Le libellé OCR
     * brut reste inchangé dans « label ». Sans base : lignes
     * inchangées (aucun champ « product » ajouté).
     *
     * @param list<array<string,mixed>>                  $lines
     * @param array<array{key:string, name:string, tokens:list<string>}>|null $catalog
     *
     * @return list<array<string,mixed>>
     */
    public static function enrichLines(array $lines, ?array $catalog = null): array
    {
        if ($catalog === null) {
            $catalog = self::buildCatalog();
        }
        if ($catalog === []) {
            return $lines;
        }

        foreach ($lines as $i => $line) {
            $label = trim((string) ($line['label'] ?? ''));
            $lines[$i]['product'] = $label === '' ? null : self::match($label, $catalog);
        }

        return $lines;
    }

    /**
     * Palier mots-clés : compte les mots signifiants du libellé
     * présents (exactement, à une faute près, ou via le dictionnaire
     * EN/FR) dans chaque entrée. Gagnant unique exigé : strictement
     * plus de mots matchés que le suivant, ou autant mais une couverture
     * de l'entrée strictement meilleure ; au moins 2 mots matchés.
     *
     * @param array<array{key:string, name:string, tokens:list<string>}> $catalog
     */
    private static function matchByTokens(string $label, array $catalog): ?string
    {
        $labelTokens = self::significantTokens($label);
        if (count($labelTokens) < 2) {
            // Un seul mot signifiant : pas assez de signal pour trancher
            // entre variantes (« MONSTER » seul ne dit pas laquelle).
            return null;
        }

        $best = null;   // [name, matched, coverage]
        $second = null;
        foreach ($catalog as $cand) {
            if ($cand['tokens'] === []) {
                continue;
            }

            $matched = 0;
            $candMatched = 0;
            foreach ($labelTokens as $lt) {
                $hit = false;
                foreach ($cand['tokens'] as $ct) {
                    if (self::tokensEquivalent($lt, $ct)) {
                        $hit = true;
                        break;
                    }
                }
                if ($hit) {
                    $matched++;
                }
            }
            foreach ($cand['tokens'] as $ct) {
                foreach ($labelTokens as $lt) {
                    if (self::tokensEquivalent($lt, $ct)) {
                        $candMatched++;
                        break;
                    }
                }
            }

            $score = [$cand['name'], $matched, $candMatched / count($cand['tokens'])];
            if ($best === null || self::scoreBetter($score, $best)) {
                $second = $best;
                $best = $score;
            } elseif ($second === null || self::scoreBetter($score, $second)) {
                $second = $score;
            }
        }

        if ($best === null || $best[1] < 2) {
            return null;
        }

        // Égalité parfaite au sommet : ambigu, on ne choisit pas.
        if ($second !== null && $second[1] === $best[1] && $second[2] === $best[2]) {
            return null;
        }

        return $best[0];
    }

    /**
     * @param array{0:string,1:int,2:float} $a
     * @param array{0:string,1:int,2:float} $b
     */
    private static function scoreBetter(array $a, array $b): bool
    {
        if ($a[1] !== $b[1]) {
            return $a[1] > $b[1];
        }

        return $a[2] > $b[2];
    }

    /**
     * Palier inclusion : la clé normalisée de l'entrée est contenue
     * dans le libellé (ou l'inverse), aiguille d'au moins 4 caractères,
     * la plus longue gagne (puis tri naturel du nom pour rester
     * déterministe). Même logique que StockPublic::matchKey.
     *
     * @param array<array{key:string, name:string, tokens:list<string>}> $catalog
     */
    private static function matchByInclusion(string $labelNorm, array $catalog): ?string
    {
        $best = null;
        $bestNeedle = 0;
        foreach ($catalog as $cand) {
            $needle = 0;
            if (strlen($cand['key']) >= 4 && str_contains($labelNorm, $cand['key'])) {
                $needle = strlen($cand['key']);
            } elseif (strlen($labelNorm) >= 4 && str_contains($cand['key'], $labelNorm)) {
                $needle = strlen($labelNorm);
            }

            if ($needle > $bestNeedle) {
                $best = $cand['name'];
                $bestNeedle = $needle;
            }
        }

        return $best;
    }

    /**
     * Deux mots signifiants sont-ils équivalents : identiques, à une
     * faute de frappe près (Levenshtein <= 1 sur des mots d'au moins
     * 4 caractères — pluriels inclus), ou traduits par le dictionnaire
     * EN/FR des couleurs/goûts.
     */
    private static function tokensEquivalent(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        // Dictionnaire EN/FR (jetons d'au moins 4 caractères).
        if (strlen($a) >= 4 && strlen($b) >= 4) {
            foreach (self::EN_FR as $en => $frs) {
                foreach ($frs as $fr) {
                    if (($a === $en && $b === $fr) || ($b === $en && $a === $fr)) {
                        return true;
                    }
                }
            }
        }

        // Une faute de frappe près (ASCII uniquement : levenshtein()
        // travaille octet par octet).
        if (strlen($a) >= 4 && strlen($b) >= 4
            && strlen($a) === mb_strlen($a) && strlen($b) === mb_strlen($b)
            && levenshtein($a, $b) <= 1) {
            return true;
        }

        return false;
    }

    /**
     * Mots signifiants d'un libellé : minuscules sans accents,
     * séparateurs repliés en espaces (AliasSuggester::normalizeKey),
     * puis retrait des mots vides, des jetons chiffrés (50G, 33CL,
     * T10, SLIM33CL…) et des jetons trop courts (B de « Nutella B »,
     * G, CL…).
     *
     * @return list<string>
     */
    private static function significantTokens(string $value): array
    {
        $out = [];
        foreach (explode(' ', AliasSuggester::normalizeKey($value)) as $token) {
            if ($token === '' || in_array($token, self::STOPWORDS, true)) {
                continue;
            }
            if (strlen($token) <= 2 || preg_match('/\d/', $token) === 1) {
                continue;
            }
            $out[] = $token;
        }

        return $out;
    }

    /**
     * Clé normalisée sans espaces (« Red Bull Blanche », « RedBull
     * blanche » et « redbull blanche » convergent) — même règle que
     * StockPublic::normalizeKey().
     */
    private static function spaceless(string $value): string
    {
        return str_replace(' ', '', AliasSuggester::normalizeKey($value));
    }
}
