<?php

declare(strict_types=1);

namespace App\Core\Compta;

use App\Models\InventoryCount;

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
     * résultat est donc mis en cache fichier pendant 5 minutes (TTL) ;
     * en contrepartie, le stock affiché peut avoir jusqu'à 5 minutes de
     * retard sur l'inventaire — c'est un choix assumé, sans invalidation
     * événementielle.
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
     *     longue (« coca cherry » plutôt que « coca »).
     *
     * @param array<string,int> $stockMap Carte renvoyée par menuStockMap().
     */
    public static function stockForMenuProduct(string $name, array $stockMap): ?int
    {
        $nameKey = self::normalizeKey($name);
        if ($nameKey === '' || $stockMap === []) {
            return null;
        }

        // 1) Match exact.
        if (isset($stockMap[$nameKey])) {
            return max(0, $stockMap[$nameKey]);
        }

        // 2) Inclusion symétrique, aiguille >= 4 caractères, match le plus long.
        $best = null;
        $bestLen = 0;
        foreach ($stockMap as $key => $qty) {
            if (strlen($key) >= 4 && str_contains($nameKey, $key) && strlen($key) > $bestLen) {
                $best = $qty;
                $bestLen = strlen($key);
                continue;
            }
            if (strlen($nameKey) >= 4 && str_contains($key, $nameKey) && strlen($nameKey) > $bestLen) {
                $best = $qty;
                $bestLen = strlen($nameKey);
            }
        }

        // Jamais de quantité négative affichée (survente/pertes non journalisées).
        return $best === null ? null : max(0, $best);
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
