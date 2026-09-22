<?php

declare(strict_types=1);

namespace App\Core\Compta;

use App\Models\InventoryCount;
use App\Models\ProductAlias;

/**
 * Stock affiché sur la carte publique de la cafétéria (page d'accueil).
 *
 * Source de vérité : le stock THÉORIQUE issu de l'inventaire compta
 * (InventoryCount::theoreticalStocksMap() = dernier comptage + achats
 * − ventes − pertes), et non `products.stock` qui est obsolète.
 *
 * Les clés d'inventaire (product_key, libellés canoniques SumUp :
 * « Bueno », « Coca », « Red Bull »…) ne correspondent pas toujours aux
 * noms affichés sur la carte (« Kinder Bueno », « Coca-Cola »…) :
 * un appariement tolérant est appliqué (voir stockForMenuProduct()).
 *
 * Le calcul est mis en cache fichier (5 min) et le cache est invalidé
 * événementiellement après chaque mouvement de stock (voir invalidate()) :
 * la carte reflète ainsi les commandes, achats, pertes et imports presque
 * immédiatement, le TTL ne servant que de filet de sécurité.
 */
final class StockPublic
{
    /** Durée de validité du cache fichier (secondes). */
    private const CACHE_TTL = 300;

    /**
     * Version du format de cache : incrémenter si la structure du payload
     * ou la normalisation change, afin d'invalider les fichiers existants.
     */
    private const CACHE_VERSION = 'v1';

    /**
     * Carte de stock théorique pour la carte publique.
     *
     * Clé = product_key normalisé (minuscules, sans accents, espaces ni
     * ponctuation — voir normalizeKey()), valeur = quantité (bornée à >= 0).
     *
     * Performance : le calcul du stock théorique exécute une requête par
     * produit déjà compté (comptage + achats + ventes + pertes), ce qui est
     * inenvisageable à chaque affichage de la page d'accueil publique. Le
     * résultat est donc mis en cache fichier pendant 5 minutes (TTL) et le
     * cache est invalidé après chaque mouvement de stock (voir invalidate()) :
     * le stock affiché suit ainsi les mouvements presque en temps réel, le
     * TTL ne couvrant que les mouvements oubliés.
     *
     * Aucune erreur n'est fatale : si le cache ou la base échouent, on
     * renvoie une carte (éventuellement vide) et la page s'affiche.
     *
     * @return array<string,int>
     */
    public static function menuStockMap(): array
    {
        $file = self::cacheFile();

        $cached = self::readCache($file);
        if ($cached !== null) {
            return $cached;
        }

        $map = self::normalizeMap(InventoryCount::theoreticalStocksMap());

        self::writeCache($file, $map);

        return $map;
    }

    /**
     * Invalide le cache du stock carte (à appeler après tout mouvement
     * de stock : comptage, achat, perte, import SumUp, commande élève).
     */
    public static function invalidate(): void
    {
        @unlink(self::cacheFile());
    }

    /**
     * Clé d'inventaire appariée à un nom de produit de la carte, ou null
     * si aucune clé ne correspond.
     *
     * Mêmes règles que stockForMenuProduct() (normalisation, inclusion
     * symétrique ≥ 4 caractères, correspondance la plus longue), complétées
     * par un repli sur les alias de ventes (matchKeyViaAlias()).
     *
     * La clé retournée est la clé BRUTE d'inventaire (telle que stockée
     * dans inventory_counts / product_stocks) afin que les ajustements de
     * référence (ProductStock::adjust) touchent la bonne ligne ; à défaut
     * de clé brute retrouvée, la clé normalisée est retournée.
     */
    public static function resolveKey(string $productName): ?string
    {
        $nameKey = self::normalizeKey($productName);
        if ($nameKey === '') {
            return null;
        }

        $map = self::menuStockMap();

        $key = self::matchKey($nameKey, $map) ?? self::matchKeyViaAlias($nameKey, $map);
        if ($key === null) {
            return null;
        }

        // La carte publique est indexée par clés normalisées, la référence
        // compta par clés brutes : on restitue la clé brute correspondante.
        foreach (array_keys(InventoryCount::lastCountsMap()) as $rawKey) {
            if (self::normalizeKey((string) $rawKey) === $key) {
                return (string) $rawKey;
            }
        }

        return $key;
    }

    /**
     * Quantité de stock à afficher pour un produit de la carte, ou null si
     * elle est inconnue (le produit s'affiche alors sans badge ni nombre,
     * SANS repli sur products.stock, volontairement ignoré ici).
     *
     * Règles d'appariement nom de carte ↔ product_key d'inventaire :
     *  1. match exact des clés normalisées (casse/accents/espaces ignorés) ;
     *  2. sinon inclusion symétrique : le product_key normalisé est contenu
     *     dans le nom normalisé du produit, ou l'inverse. L'aiguille doit
     *     faire au moins 4 caractères normalisés afin d'éviter les faux
     *     positifs (« eau » ⊂ « eaubulle », « bn » ⊂ « bnvani »…) ; en cas
     *     de plusieurs candidats, on retient la correspondance la plus
     *     longue (« coca cherry » plutôt que « coca ») ;
     *  3. sinon repli par alias de ventes (matchKeyViaAlias()).
     *
     * @param array<string,int> $stockMap Carte renvoyée par menuStockMap().
     */
    public static function stockForMenuProduct(string $name, array $stockMap): ?int
    {
        $nameKey = self::normalizeKey($name);
        if ($nameKey === '' || $stockMap === []) {
            return null;
        }

        $key = self::matchKey($nameKey, $stockMap) ?? self::matchKeyViaAlias($nameKey, $stockMap);

        // Jamais de quantité négative affichée (survente/pertes non journalisées).
        return $key === null ? null : max(0, $stockMap[$key]);
    }

    /**
     * Cœur de l'appariement sur une carte à clés normalisées : match exact,
     * sinon inclusion symétrique avec une aiguille d'au moins 4 caractères,
     * correspondance la plus longue. Retourne la clé de la carte, ou null.
     *
     * @param array<string,int> $stockMap
     */
    private static function matchKey(string $nameKey, array $stockMap): ?string
    {
        if ($nameKey === '' || $stockMap === []) {
            return null;
        }

        // 1) Match exact.
        if (isset($stockMap[$nameKey])) {
            return $nameKey;
        }

        // 2) Inclusion symétrique, aiguille >= 4 caractères, match le plus long.
        $best = null;
        $bestLen = 0;
        foreach ($stockMap as $key => $qty) {
            if (strlen($key) >= 4 && str_contains($nameKey, $key) && strlen($key) > $bestLen) {
                $best = $key;
                $bestLen = strlen($key);
                continue;
            }
            if (strlen($nameKey) >= 4 && str_contains($key, $nameKey) && strlen($nameKey) > $bestLen) {
                $best = $key;
                $bestLen = strlen($nameKey);
            }
        }

        return $best;
    }

    /**
     * Repli par alias de ventes : un libellé SumUp mappé (table
     * product_aliases : raw_description → product_key) peut relier le nom
     * de la carte à une clé comptée (ex. « Café » relié à « Café glacé »).
     * Égalité stricte des clés normalisées du nom et du libellé brut
     * (pas d'inclusion : les alias sont nombreux, on évite les faux
     * positifs), et la clé cible doit exister dans la carte.
     *
     * Silencieux sans base : product_aliases indisponible → aucun repli
     * (jamais de fatal sur la page d'accueil publique ni en tests).
     *
     * @param array<string,int> $stockMap
     */
    private static function matchKeyViaAlias(string $nameKey, array $stockMap): ?string
    {
        try {
            $aliases = ProductAlias::all();
        } catch (\Throwable) {
            return null;
        }

        foreach ($aliases as $alias) {
            $rawKey = self::normalizeKey((string) ($alias['raw_description'] ?? ''));
            if ($rawKey === '' || $rawKey !== $nameKey) {
                continue;
            }

            $targetKey = self::normalizeKey((string) ($alias['product_key'] ?? ''));
            if ($targetKey !== '' && array_key_exists($targetKey, $stockMap)) {
                return $targetKey;
            }
        }

        return null;
    }

    /**
     * Un libellé est-il déjà couvert par un des noms de produits passés,
     * selon EXACTEMENT les mêmes règles que stockForMenuProduct() :
     * match exact des clés normalisées, sinon inclusion symétrique avec
     * une aiguille d'au moins 4 caractères normalisés.
     *
     * Sert à la synchronisation automatique de la carte
     * (ProductAutoSync) : éviter de créer une fiche pour un libellé qui
     * s'apparie déjà à un produit existant.
     *
     * @param list<string> $productNames Noms bruts des fiches existantes.
     */
    public static function matchesAnyProduct(string $label, array $productNames): bool
    {
        $labelKey = self::normalizeKey($label);
        if ($labelKey === '') {
            return false;
        }

        // 1) Match exact (indexé une seule fois pour toute la liste).
        $nameKeys = [];
        foreach ($productNames as $name) {
            $key = self::normalizeKey((string) $name);
            if ($key === $labelKey) {
                return true;
            }
            if ($key !== '') {
                $nameKeys[$key] = true;
            }
        }

        // 2) Inclusion symétrique, aiguille >= 4 caractères.
        foreach (array_keys($nameKeys) as $key) {
            if (strlen($key) >= 4 && str_contains($labelKey, $key)) {
                return true;
            }
            if (strlen($labelKey) >= 4 && str_contains($key, $labelKey)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalisation réutilisable pour l'appariement : minuscules, sans
     * accents, ponctuation et espaces supprimés (« Red Bull », « RedBull »
     * et « red-bull » convergent vers « redbull »).
     *
     * S'appuie sur AliasSuggester::normalizeKey() (déjà utilisée en compta
     * pour consolider les libellés SumUp) puis retire les espaces.
     */
    public static function normalizeKey(string $value): string
    {
        return str_replace(' ', '', AliasSuggester::normalizeKey($value));
    }

    /**
     * Normalise la carte brute de l'inventaire : clés normalisées, quantités
     * bornées à >= 0 (jamais de stock négatif affiché). Si deux clés
     * distinctes convergent vers la même clé normalisée, on garde le max
     * (comportement conservateur : ne sous-estime jamais le stock affiché).
     *
     * @param array<string,int> $raw Carte product_key → stock théorique.
     *
     * @return array<string,int>
     */
    private static function normalizeMap(array $raw): array
    {
        $map = [];
        foreach ($raw as $key => $qty) {
            $norm = self::normalizeKey((string) $key);
            if ($norm === '') {
                continue;
            }

            $qty = max(0, (int) $qty);
            $map[$norm] = isset($map[$norm]) ? max($map[$norm], $qty) : $qty;
        }

        return $map;
    }

    private static function cacheFile(): string
    {
        return AEIC_ROOT . '/cache/menu-stock-'
            . substr(sha1('menu-stock-' . self::CACHE_VERSION), 0, 12) . '.json';
    }

    /**
     * Lit le cache s'il est présent, valide et non expiré, sinon null.
     *
     * @return array<string,int>|null
     */
    private static function readCache(string $file): ?array
    {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (
            !is_array($data)
            || !isset($data['expires'], $data['stocks'])
            || !is_int($data['expires'])
            || !is_array($data['stocks'])
            || $data['expires'] <= time()
        ) {
            return null;
        }

        $map = [];
        foreach ($data['stocks'] as $key => $qty) {
            if (is_int($qty)) {
                $map[(string) $key] = max(0, $qty);
            }
        }

        return $map;
    }

    /**
     * Écrit le cache (best effort : un échec d'écriture ne doit jamais
     * casser la page d'accueil publique).
     *
     * @param array<string,int> $map
     */
    private static function writeCache(string $file, array $map): void
    {
        try {
            $dir = dirname($file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0o775, true);
            }

            $payload = json_encode(
                ['expires' => time() + self::CACHE_TTL, 'stocks' => $map],
                JSON_THROW_ON_ERROR
            );

            @file_put_contents($file, $payload, LOCK_EX);
        } catch (\Throwable) {
            // Cache indisponible : le calcul direct a déjà fourni la carte.
        }
    }
}
