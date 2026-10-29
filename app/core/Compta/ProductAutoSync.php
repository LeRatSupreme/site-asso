<?php

declare(strict_types=1);

namespace App\Core\Compta;

use App\Models\InventoryCount;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Sale;

/**
 * Création AUTOMATIQUE des fiches produits de la carte cafétéria.
 *
 * La carte publique « Notre carte » liste les lignes de la table `products`.
 * Quand un NOUVEAU libellé apparaît dans la compta, sa fiche n'existait
 * pas et il restait invisible sur la carte jusqu'à création manuelle.
 * Cette classe comble l'écart — sous trois gardes (mission E) qui
 * ferment les trous par lesquels les fiches « pourries » entraient :
 *
 *  A. un achat « hors stock » (no_stock = 1 : conso bureau, fournitures)
 *     ne crée JAMAIS de fiche — ni par la passe ciblée (PurchaseSaver
 *     n'envoie pas ces clés) ni par la passe globale (les achats ne
 *     contribuent plus de libellés) ;
 *  B. un libellé OCR de facture brut (clé encore égale à une entrée de
 *     la file scan_labels — règle PurchaseSaver::isRawScanLabel) est
 *     exclu MÊME via sync() global, y compris s'il a été vendu tel quel
 *     à la borne : la fiche attend le nom court saisi par l'utilisateur ;
 *  C. une fiche publique n'est créée que sur VENTE réelle : un achat,
 *     une perte, un comptage ou un stock de référence seul ne suffit
 *     jamais. Les libellés connus de la passe globale viennent donc des
 *     VENTES seules (achat/perte/comptage ne fournissent plus de clés).
 *
 * RÉTROACTIF INTERDIT : la synchro ne supprime JAMAIS une fiche existante
 * (même « pourrie ») — les gardes ne s'appliquent qu'aux créations
 * futures ; le nettoyage de l'existant est un acte admin manuel.
 *
 * Aucun échec ne doit jamais se propager vers l'appelant : la compta et le
 * site public ne doivent pas casser à cause de la synchro. Chaque étape
 * base de données est protégée et un libellé en erreur est simplement
 * sauté (compté dans `skipped`).
 */
final class ProductAutoSync
{
    /**
     * Libellés ne désignant JAMAIS un vrai produit : montants personnalisés,
     * pourboires, dons, frais, remises, adhésions, essais. Déjà normalisés
     * (StockPublic::normalizeKey) — comparés en « contient » ou « égal »
     * avec la clé normalisée du libellé testé.
     *
     * @var list<string>
     */
    private const EXCLUDED_KEYS = [
        'customamount',
        'montantpersonnalise',
        'custom',
        'tip',
        'pourboire',
        'don',
        'donation',
        'frais',
        'fee',
        'remise',
        'discount',
        'adhesion',
        'cotisation',
        'test',
        // Services vendus à la borne, pas des produits rayonnables :
        // « Bowling » n'atterrit jamais en Snacks (garde C).
        'bowling',
    ];

    /**
     * Ordre de test des catégories (le premier mot-clé trouvé gagne) :
     * boissons → snacks → spécial, puis défaut = snacks.
     *
     * @var list<string>
     */
    private const CATEGORY_ORDER = ['cat_boissons', 'cat_snacks', 'cat_special'];

    /**
     * Mots-clés par catégorie, comparés sur le libellé en minuscules SANS
     * accents (même normalisation que AliasSuggester::normalizeKey — les
     * mots-clés accentués sont donc normalisés de la même façon).
     *
     * @var array<string,list<string>>
     */
    private const CATEGORY_KEYWORDS = [
        'cat_boissons' => [
            'coca', 'cola', 'pepsi', 'sprite', 'fanta', 'oasis', 'minute maid',
            'minutemaid', 'lipton', 'fuze', 'orangina', 'schweppes', 'pepper',
            'eau', 'cristaline', 'perrier', 'vittel', 'evian', 'badoit',
            'monster', 'red bull', 'redbull', 'energy', 'energie', 'energétique',
            'jus', 'pulco', 'ice tea', 'icetea', 'the glace', 'thé',
            'cafe', 'café', 'expresso', 'espresso', 'chocolat chaud', 'limonade',
            'sirop', 'gatorade', 'powerade', 'soda', 'bubble tea', 'smoothie',
            'milkshake', 'eau gazeuse',
        ],
        'cat_snacks' => [
            'chips', 'bonbon', 'sucette', 'chocolat', 'mars', 'snickers',
            'kitkat', 'lion', 'twix', 'bueno', 'kinder', 'oreo', 'cookie',
            'biscuit', 'madeleine', 'brownie', 'donut', 'beignet', 'muffin',
            'cupcake', 'cake', 'gateau', 'gâteau', 'croissant', 'pain',
            'viennoiserie', 'popcorn', 'chewing', 'malabar', 'haribo', 'nerds',
            'compote', 'banane', 'pomme', 'fruit', 'barre', 'cereale', 'céréale',
            'tuc', 'pringle', 'cracker', 'friand', 'glace', 'mister freeze',
            'misterfreeze', 'yaourt', 'bn', 'gaufre', 'crepe', 'crêpe',
        ],
        'cat_special' => [
            'menu', 'panini', 'sandwich', 'burger', 'pizza', 'tacos', 'kebab',
            'hot dog', 'hotdog', 'bbq', 'barbecue', 'croque', 'quiche', 'tarte',
            'salade', 'pates', 'pâtes', 'ramen', 'nouille', 'soupe', 'plat',
        ],
    ];

    /** Catégorie par défaut quand aucun mot-clé ne correspond. */
    private const DEFAULT_CATEGORY = 'cat_snacks';

    /**
     * Prix par défaut par catégorie (utilisés faute d'historique de ventes).
     *
     * @var array<string,float>
     */
    private const DEFAULT_PRICES = [
        'cat_boissons' => 1.00,
        'cat_snacks' => 1.00,
        'cat_special' => 2.00,
    ];

    /** Bornes du prix déduit de l'historique des ventes. */
    private const PRICE_MIN = 0.05;
    private const PRICE_MAX = 20.0;

    /** Longueur minimale d'une clé de libellé retenue (sinon exclue). */
    private const MIN_KEY_LENGTH = 3;

    /**
     * Longueur en dessous de laquelle un mot-clé est comparé sur des mots
     * entiers plutôt qu'en sous-chaîne (« eau » ⊂ « gateau » sinon).
     */
    private const SHORT_KEYWORD_LENGTH = 5;

    /**
     * Cache des mots-clés de catégorie normalisés (catégorie → liste).
     *
     * @var array<string,list<string>>|null
     */
    private static ?array $keywordCache = null;

    // -----------------------------------------------------------------
    //  API publique
    // -----------------------------------------------------------------

    /**
     * Synchronise la carte : crée les fiches des libellés connus de
     * l'inventaire non couverts par un produit existant.
     *
     * Garde C — les libellés connus viennent des VENTES seules
     * (product_key ou description) : un achat, une perte, un comptage
     * ou un stock de référence seul ne justifie plus jamais une fiche
     * publique (les fiches des libellés vendus non encore achetés/
     * comptés gardent un stock déduit — voir knownStock).
     *
     * @return array{created: list<string>, skipped: int} created = noms des
     *                                                    produits créés, skipped = libellés ignorés
     *                                                    (déjà couverts, exclus ou en erreur).
     */
    public static function sync(): array
    {
        return self::syncLabels(self::knownLabels());
    }

    /**
     * Même traitement que sync(), limité aux libellés passés — utilisé
     * juste après l'insertion de ventes (cron SumUp) pour ne rescanner
     * que les clés concernées. Les gardes B et C s'appliquent aussi à
     * cette passe ciblée : un libellé jamais vendu ou encore égal à un
     * libellé OCR brut de la file scan_labels ne crée rien.
     *
     * @param list<string> $keys Libellés bruts (product_key ou description).
     *
     * @return array{created: list<string>, skipped: int}
     */
    public static function ensureKeys(array $keys): array
    {
        $labels = [];
        foreach ($keys as $key) {
            $label = trim((string) $key);
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return self::syncLabels(array_values(array_unique($labels)));
    }

    /**
     * Devine la catégorie d'un libellé via ses mots-clés, testés dans
     * l'ordre boissons → snacks → spécial (le premier mot-clé trouvé
     * gagne ; « Chips BBQ » → snacks, « Menu BBQ » → spécial, « Red Bull
     * Pomme » → boissons). Défaut : cat_snacks.
     */
    public static function guessCategory(string $label): string
    {
        $key = AliasSuggester::normalizeKey($label);

        foreach (self::CATEGORY_ORDER as $categoryId) {
            foreach (self::categoryKeywords($categoryId) as $keyword) {
                if (self::containsKeyword($key, $keyword)) {
                    return $categoryId;
                }
            }
        }

        return self::DEFAULT_CATEGORY;
    }

    /**
     * Devine le prix TTC d'un libellé :
     *  1. moyenne des prix unitaires vendus sur les 180 derniers jours
     *     (mêmes lignes que la compta, montants personnalisés exclus),
     *     arrondie au multiple de 0,05 le plus proche et bornée ;
     *  2. sinon prix par défaut de la catégorie déduite.
     */
    public static function guessPrice(string $label, string $categoryId): float
    {
        try {
            $stmt = \db()->prepare(
                'SELECT AVG(price_ttc / NULLIF(quantity, 0))
                 FROM sales
                 WHERE COALESCE(product_key, description) = ?
                   AND is_custom_amount = 0
                   AND sold_at >= DATE_SUB(NOW(), INTERVAL 180 DAY)'
            );
            $stmt->execute([$label]);
            $avg = $stmt->fetchColumn();
        } catch (\Throwable) {
            $avg = false;
        }

        if (is_numeric($avg) && (float) $avg > 0.0) {
            $price = round((float) $avg * 20) / 20;

            return min(self::PRICE_MAX, max(self::PRICE_MIN, $price));
        }

        return self::DEFAULT_PRICES[$categoryId] ?? self::DEFAULT_PRICES[self::DEFAULT_CATEGORY];
    }

    // -----------------------------------------------------------------
    //  Cœur de la synchronisation
    // -----------------------------------------------------------------

    /**
     * Traite une liste de libellés bruts : crée les fiches manquantes,
     * saute ceux déjà couverts, exclus ou en erreur.
     *
     * @param list<string> $labels
     *
     * @return array{created: list<string>, skipped: int}
     */
    private static function syncLabels(array $labels): array
    {
        $created = [];
        $skipped = 0;

        // Noms existants sur la carte (normalisés pour l'appariement).
        $existingNames = [];
        try {
            foreach (Product::allForAdmin() as $row) {
                $existingNames[] = (string) ($row['name'] ?? '');
            }
        } catch (\Throwable) {
            // Carte illisible : on ne crée rien (risque de doublons).
            return ['created' => [], 'skipped' => count($labels)];
        }

        // Ordre de passage : calculé UNE fois, incrémenté à chaque création.
        try {
            $nextOrder = (int) \db()
                ->query('SELECT COALESCE(MAX(`order`), 0) + 1 FROM products')
                ->fetchColumn();
        } catch (\Throwable) {
            $nextOrder = 1;
        }

        // Stocks théoriques lus une seule fois pour toute la passe.
        try {
            $theoretical = InventoryCount::theoreticalStocksMap();
        } catch (\Throwable) {
            $theoretical = [];
        }

        // Garde B — libellés OCR bruts de la file scan_labels, lus une
        // seule fois pour toute la passe (règle PurchaseSaver::isRawScanLabel).
        $scanLabels = self::scanQueueLabels();

        // Garde C — libellés réellement VENDUS, lus une seule fois : une
        // fiche publique n'est jamais créée sans vente (la passe globale
        // ne reçoit déjà que des libellés vendus ; la passe ciblée,
        // appelée après un achat/une perte, est filtrée ici).
        $sold = self::soldKeysMap();

        foreach ($labels as $label) {
            $label = trim((string) $label);

            if ($label === '' || self::isExcluded($label)) {
                $skipped++;
                continue;
            }

            if (StockPublic::matchesAnyProduct($label, $existingNames)) {
                $skipped++;
                continue;
            }

            // Garde C : jamais vendu → jamais de fiche publique.
            if (!self::isSoldLabel($label, $sold)) {
                $skipped++;
                continue;
            }

            // Garde B : la clé est encore un libellé OCR brut non traité
            // (file « Nouvelles appellations ») → pas de fiche au nom long.
            if (self::matchesRawScanLabel($label, $scanLabels)) {
                $skipped++;
                continue;
            }

            try {
                $name = self::prettifyName($label);
                $categoryId = self::guessCategory($label);

                Product::save([
                    'name'         => $name,
                    'description'  => null,
                    'price'        => self::guessPrice($label, $categoryId),
                    'category_id'  => $categoryId,
                    'stock'        => self::knownStock($label, $theoretical),
                    'is_available' => 1,
                    'is_active'    => 1,
                    'order'        => $nextOrder++,
                ]);

                $created[] = $name;
                // Évite de créer deux fiches jumelles dans la même passe
                // (« Bueno_white » puis « Bueno White » par exemple).
                $existingNames[] = $name;
            } catch (\Throwable) {
                // Un libellé en erreur ne doit jamais interrompre la passe.
                $skipped++;
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Libellés connus de l'inventaire : les VENTES seules (garde C —
     * product_key ou description, union dédupliquée, vides retirés).
     * Les achats, pertes, comptages et stocks de référence ne
     * contribuent PLUS de libellés : ils ne justifient pas une fiche
     * publique (un achat « hors stock » ou un comptage isolé ne doit
     * pas faire entrer un faux produit sur la carte) ; ils restent lus
     * par knownStock() pour le STOCK des fiches réellement créées.
     *
     * @return list<string>
     */
    private static function knownLabels(): array
    {
        $labels = [];

        foreach (Sale::distinctProducts() as $label) {
            $labels[] = (string) $label;
        }

        $labels = array_map(
            static fn (string $label): string => trim($label),
            $labels
        );

        return array_values(array_unique(array_filter($labels, static fn (string $l): bool => $l !== '')));
    }

    /**
     * Formes des libellés réellement vendus, pour la garde C : index
     * exact ET normalisé (StockPublic::normalizeKey — casse, accents,
     * séparateurs et espaces repliés) pour rester tolérant aux variantes
     * d'écriture entre la clé passée et les ventes stockées.
     *
     * Une erreur de lecture (base indisponible) retourne une carte
     * VIDE : aucune vente lisible → aucune création (conservateur).
     *
     * @return array{exact: array<string,true>, normalized: array<string,true>}
     */
    private static function soldKeysMap(): array
    {
        $exact = [];
        $normalized = [];

        try {
            foreach (Sale::distinctProducts() as $sold) {
                $sold = trim((string) $sold);
                if ($sold === '') {
                    continue;
                }
                $exact[$sold] = true;
                $normalized[StockPublic::normalizeKey($sold)] = true;
            }
        } catch (\Throwable) {
            // Ventes illisibles : impossible de justifier une fiche.
            return ['exact' => [], 'normalized' => []];
        }

        return ['exact' => $exact, 'normalized' => $normalized];
    }

    /**
     * Le libellé est-il réellement VENDU (garde C) ? Match exact, sinon
     * normalisé — jamais par simple strtolower (règle anti-régression
     * du projet : tout rapprochement de noms passe par les formes
     * partagées StockPublic::normalizeKey).
     *
     * @param array{exact: array<string,true>, normalized: array<string,true>} $sold
     */
    private static function isSoldLabel(string $label, array $sold): bool
    {
        if (isset($sold['exact'][$label])) {
            return true;
        }

        $key = StockPublic::normalizeKey($label);

        return $key !== '' && isset($sold['normalized'][$key]);
    }

    /**
     * Libellés OCR de la file « Nouvelles appellations » (scan_labels),
     * lus une fois par passe — table optionnelle, absente = file vide.
     *
     * @return list<string>
     */
    private static function scanQueueLabels(): array
    {
        try {
            $rows = \db()->query('SELECT label FROM scan_labels')->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        $labels = [];
        foreach ($rows as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /**
     * Le libellé est-il encore un libellé OCR BRUT non traité (garde B)
     * — c.-à-d. égal (à la casse/accents/espaces près) à une entrée de
     * la file scan_labels ? Réutilise la règle partagée
     * PurchaseSaver::isRawScanLabel : la clé du test est la même que
     * celle de la passe ciblée des achats, un libellé vendu tel quel à
     * la borne est donc exclu ici aussi.
     *
     * @param list<string> $scanLabels Entrées de la file scan_labels.
     */
    private static function matchesRawScanLabel(string $label, array $scanLabels): bool
    {
        foreach ($scanLabels as $scanLabel) {
            if (PurchaseSaver::isRawScanLabel($scanLabel, $label)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stock théorique connu d'un libellé : dernier comptage (via la carte
     * des stocks théoriques), sinon stock de référence (product_stocks,
     * alimenté par les comptages/achats/pertes), sinon 0 — toujours borné
     * à >= 0.
     *
     * @param array<string,int> $theoretical Carte product_key → stock théorique.
     */
    private static function knownStock(string $label, array $theoretical): int
    {
        if (array_key_exists($label, $theoretical)) {
            return max(0, (int) $theoretical[$label]);
        }

        $stock = ProductStock::get($label);

        return $stock === null ? 0 : max(0, $stock);
    }

    /**
     * Un libellé est-il exclu de la création automatique ? (montants
     * personnalisés, pourboires, dons, frais, remises, adhésions, tests,
     * ou clé normalisée trop courte pour être un vrai produit).
     */
    private static function isExcluded(string $label): bool
    {
        $key = StockPublic::normalizeKey($label);

        if (strlen($key) < self::MIN_KEY_LENGTH) {
            return true;
        }

        foreach (self::EXCLUDED_KEYS as $excluded) {
            if (str_contains($key, $excluded)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Libellé « embelli » pour la carte : trim, espaces multiples repliés,
     * et première lettre en majuscule si le libellé est tout en minuscules
     * (« sucette 3 sucettes » → « Sucette 3 sucettes »). Les libellés
     * déjà composés (« RedBull Abricot ») restent inchangés.
     */
    private static function prettifyName(string $label): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $label));

        if ($name !== '' && mb_strtolower($name) === $name) {
            $name = mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
        }

        return $name;
    }

    // -----------------------------------------------------------------
    //  Mots-clés de catégorie
    // -----------------------------------------------------------------

    /**
     * Mots-clés normalisés d'une catégorie (mise en cache statique :
     * la normalisation ne dépend que de la constante).
     *
     * @return list<string>
     */
    private static function categoryKeywords(string $categoryId): array
    {
        if (self::$keywordCache === null) {
            self::$keywordCache = [];
        }

        if (!isset(self::$keywordCache[$categoryId])) {
            $normalized = [];
            foreach (self::CATEGORY_KEYWORDS[$categoryId] ?? [] as $keyword) {
                $key = AliasSuggester::normalizeKey($keyword);
                if ($key !== '') {
                    $normalized[] = $key;
                }
            }
            self::$keywordCache[$categoryId] = array_values(array_unique($normalized));
        }

        return self::$keywordCache[$categoryId];
    }

    /**
     * Le libellé (clé normalisée) contient-il le mot-clé ?
     *
     * Les mots-clés longs (>= SHORT_KEYWORD_LENGTH) sont cherchés en
     * sous-chaîne (« red bull » ⊂ « red bull pomme »). Les mots-clés plus
     * courts sont cherchés sur des mots entiers uniquement, afin d'éviter
     * les faux positifs du type « eau » ⊂ « gateau » (« eau » désigne les
     * bouteilles, jamais l'intérieur d'un mot).
     */
    private static function containsKeyword(string $labelKey, string $keyword): bool
    {
        if (strlen($keyword) < self::SHORT_KEYWORD_LENGTH) {
            $pattern = '/\b' . preg_quote($keyword, '/') . '\b/';

            return preg_match($pattern, $labelKey) === 1;
        }

        return str_contains($labelKey, $keyword);
    }
}
