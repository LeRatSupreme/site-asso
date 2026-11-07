<?php

declare(strict_types=1);

namespace App\Core\Compta;

/**
 * Règles de variantes de produits distinguées par leur prix de vente.
 *
 * Certains produits sont vendus sous une MÊME clé SumUp mais à des prix
 * différents selon la version commandée. Cas fondateur : le café —
 * « cafe » désigne à la fois :
 *  - le café simple à 0,50 € (1 dosette) ;
 *  - le café allongé à 0,70 € (2 dosettes).
 * SumUp ne connaît qu'un libellé : la seule donnée discriminante est le
 * prix TTC de la ligne. À l'import (CSV manuel comme synchro API cron),
 * les ventes « cafe » à 0,70 € sont donc reclassées vers la clé canonique
 * « cafe allonge » (catégorie « Spécial », comme « cafe ») afin que le
 * journal des ventes, les bénéfices par produit et les coûts de revient
 * distinguent bien les DEUX produits.
 *
 * La `description` SumUp des lignes reclassées n'est JAMAIS modifiée
 * (elle fait partie de la clé unique anti-doublon des ventes et sert de
 * fallback de déduction de stock) — seul le `product_key` change.
 *
 * Pour ajouter une nouvelle règle : compléter la constante RULES avec
 * la clé de base (en minuscules, telle qu'elle ressort de la clé
 * effective d'une vente), le prix TTC déclencheur (tolérance ± 0,005 €
 * pour absorber les arrondis flottants), la clé canonique de la
 * variante et sa catégorie. La méthode variantFor() et l'UPDATE
 * Sale::applyPriceVariants() s'appliquent alors automatiquement, aux
 * imports suivants comme rétroactivement (le reclassement est
 * idempotent : une ligne déjà renommée ne rematche plus la règle).
 */
final class PriceVariants
{
    /**
     * Règles de variantes : clé de base + prix TTC → clé variante + catégorie.
     *
     * Chaque entrée décrit :
     *  - 'key'      : clé effective de base de la vente (product_key ou
     *                 description, trim + minuscules) qui déclenche la règle ;
     *  - 'price'    : prix TTC unitaire déclencheur (comparaison avec
     *                 tolérance ± 0,005) ;
     *  - 'variant'  : clé canonique cible de la variante ;
     *  - 'category' : catégorie de vente propagée à la variante.
     *
     * @var list<array{key: string, price: float, variant: string, category: string}>
     */
    public const RULES = [
        [
            'key'      => 'cafe',
            'price'    => 0.70,
            'variant'  => 'cafe allonge',
            'category' => 'Spécial',
        ],
    ];

    /** Tolérance de comparaison des prix flottants (en euros). */
    private const PRICE_TOLERANCE = 0.005;

    /**
     * Variante s'appliquant à une clé de produit vendue à un prix donné.
     *
     * Méthode pure (aucune base de données) : normalise la clé (trim +
     * minuscules), puis cherche une règle dont la clé correspond
     * EXACTEMENT (aucune correspondance partielle : « cafe machin » ne
     * déclenche pas la règle de « cafe ») et dont le prix est dans la
     * tolérance ± 0,005 €.
     *
     * La différence de prix est arrondie à 3 décimales avant comparaison :
     * les bornes exactes (0,695 / 0,705) doivent matcher malgré la
     * représentation binaire des flottants (abs(0.705 - 0.70) vaut
     * 0.0050000000000001 en IEEE 754, ce qu'un simple < rejeterait).
     *
     * @param string $productKey Clé effective de la vente (product_key,
     *                           ou description en fallback).
     * @param float  $priceTtc   Prix TTC de la ligne de vente.
     *
     * @return array{key: string, category: string}|null Description de la
     *         variante (clé canonique + catégorie), ou null si aucune
     *         règle ne s'applique.
     */
    public static function variantFor(string $productKey, float $priceTtc): ?array
    {
        $key = mb_strtolower(trim($productKey));

        if ($key === '') {
            return null;
        }

        foreach (self::RULES as $rule) {
            if ($key === $rule['key'] && round(abs($priceTtc - $rule['price']), 3) <= self::PRICE_TOLERANCE) {
                return ['key' => $rule['variant'], 'category' => $rule['category']];
            }
        }

        return null;
    }
}
