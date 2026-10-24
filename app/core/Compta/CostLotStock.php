<?php

declare(strict_types=1);

namespace App\Core\Compta;

use App\Models\InventoryCount;
use App\Models\ProductCost;

/**
 * Allocation « quantité par lot de coût de revient » et écarts de stock.
 *
 * Chaque lot de `product_costs` peut porter `qty_purchased` (quantité
 * achetée couverte par ce lot) :
 *  - INT renseigné : lot « suivi », capacité finie — chaque unité vendue
 *    ou perdue décrémente « moins 1 jusqu'à 0 » ;
 *  - NULL          : lot ancien NON suivi, capacité infinie (absorbe tout,
 *    n'épuise jamais).
 *
 * Une unité qui déborde (lot cible épuisé, ou aucun lot ne couvre la date)
 * est REPORTÉE sur le premier lot SUIVI à venir — y compris un lot saisi
 * rétroactivement après la vente (« je saisis le coût après »). Les lots
 * non suivis rencontrés pendant ce report sont absorbés (comptés) sans
 * bloquer la traversée vers le lot suivi suivant.
 *
 * Deux couches :
 *  - allocate()  : cœur PUR, testable sans DB (aucune dépendance App\Models) ;
 *  - forProduct() / ecarts() : chargement DB + détection des écarts stock
 *    (négatif = unités manquantes → vol/casse possible ; positif = stock
 *    excédentaire → achat non saisi) : achats suivis vs ventes/pertes/
 *    stock théorique actuel.
 *
 * Clés produits — règles du projet (AGENTS.md) : les clés sont réutilisées
 * TELLES QUELLES (aucun strtolower ad hoc, les tables partagent les mêmes
 * clés). Ventes : clé effective `product_key` OU `description`, lignes
 * `is_custom_amount = 1` exclues (cf. Sale::soldQtySince). Pertes :
 * `product_key` + `lost_at` (DATE).
 */
final class CostLotStock
{
    /**
     * Cœur PUR : alloue des unités consommées sur des lots datés.
     *
     * Algorithme par unité (mouvements déroulés unité par unité, dans
     * l'ordre chronologique des dates — à date égale, ordre de la liste ;
     * tri PHP ≥ 8.0 stable) :
     *  1. Lot cible = dernier lot (le plus récent) avec
     *     `valid_from <= date` du mouvement ;
     *  2. cible non suivie (qty_purchased NULL) → absorbée (comptée dans
     *     `alloue`, n'épuise jamais) ;
     *  3. cible suivie avec restant > 0 → décrémente la cible ;
     *  4. sinon (cible suivie épuisée, ou aucun lot ne couvre la date) →
     *     report : parcourt les lots SUIVANTS la cible dans l'ordre
     *     chronologique et consomme le premier lot SUIVI avec restant > 0
     *     (les lots non suivis rencontrés sont absorbés et comptés) ;
     *     aucun trouvé → compteur `non_couvert` +1 (l'unité attendra un
     *     lot futur).
     *
     * @param list<array{id:mixed,valid_from:mixed,qty_purchased:int|string|null}> $lots
     *     Non triés à l'entrée ; tri interne par valid_from ASC puis id ASC
     *     (départage déterministe).
     * @param list<array{date:mixed,qty:mixed}> $mouvements
     *     Unités consommées ce jour-là (ventes + pertes agrégées par jour),
     *     non triés à l'entrée.
     *
     * @return array{lots:array<string,array{alloue:int,restant:int|null,epuise:bool}>,non_couvert:int}
     *     `lots` indexé par id de lot (ordre chronologique) ; `restant` vaut
     *     null pour un lot non suivi (capacité infinie) ; `epuise` est true
     *     dès que `restant` vaut 0 (un lot qty_purchased = 0 ne peut jamais
     *     rien servir, il est donc épuisé d'origine).
     */
    public static function allocate(array $lots, array $mouvements): array
    {
        self::trierLots($lots);

        $nb = count($lots);
        $ids = [];
        $dates = [];
        $restant = [];
        foreach ($lots as $i => $lot) {
            $ids[$i] = (string) ($lot['id'] ?? '');
            $dates[$i] = substr((string) ($lot['valid_from'] ?? ''), 0, 10);
            $restant[$i] = self::normaliseQty($lot['qty_purchased'] ?? null);
        }

        // Tri stable (PHP ≥ 8.0) : à date égale, l'ordre d'entrée est conservé.
        $mvs = array_values($mouvements);
        usort($mvs, static function (array $a, array $b): int {
            return strcmp(
                substr((string) ($a['date'] ?? ''), 0, 10),
                substr((string) ($b['date'] ?? ''), 0, 10)
            );
        });

        $alloue = array_fill(0, $nb, 0);
        $nonCouvert = 0;

        foreach ($mvs as $mv) {
            $qty = self::normaliseQty($mv['qty'] ?? null);
            if ($qty === 0) {
                continue;
            }
            $date = substr((string) ($mv['date'] ?? ''), 0, 10);

            // 1. Lot cible = dernier lot avec valid_from <= date (lots triés ASC).
            $cible = null;
            foreach ($dates as $i => $vf) {
                if ($vf <= $date) {
                    $cible = $i;
                }
            }

            for ($u = 0; $u < $qty; $u++) {
                // 2. Cible non suivie : absorbée, jamais épuisée.
                if ($cible !== null && $restant[$cible] === null) {
                    $alloue[$cible]++;
                    continue;
                }
                // 3. Cible suivie avec du restant : décrémente.
                if ($cible !== null && $restant[$cible] > 0) {
                    $restant[$cible]--;
                    $alloue[$cible]++;
                    continue;
                }
                // 4. Report (overflow) : premier lot SUIVI avec restant > 0
                //    après la cible (dès le premier lot si aucune cible).
                if (self::reporter($restant, $alloue, $cible === null ? 0 : $cible + 1)) {
                    continue;
                }
                $nonCouvert++;
            }
        }

        $out = [];
        foreach ($ids as $i => $id) {
            $out[$id] = [
                'alloue'  => $alloue[$i],
                'restant' => $restant[$i],
                'epuise'  => $restant[$i] !== null && $restant[$i] === 0,
            ];
        }

        return ['lots' => $out, 'non_couvert' => $nonCouvert];
    }

    /**
     * Synthèse d'un produit : allocation par lot + fenêtre ventes/pertes +
     * écart de stock (négatif = unités manquantes → vol/casse possible).
     *
     * Fenêtre temporelle : `vendu`/`perdu` comptent UNIQUEMENT les
     * mouvements à une date >= `premier_lot_suivi.valid_from` (les ventes
     * antérieures relèvent des achats non suivis). L'allocation utilise en
     * revanche TOUS les mouvements (pour le « report sur lot futur »).
     *
     * `ecart` = stock - (achete - vendu - perdu) : < 0 = unités manquantes
     * par rapport au prévu (vol, casse, vente non déclarée) ; > 0 = stock
     * excédentaire (achat non saisi dans les coûts ou quantité erronée).
     * Null si pas de comptage stock ou aucun achat suivi (achete == 0).
     *
     * @return array{
     *     lots:array<string,array{cost_price:string,valid_from:string,valid_to:string|null,qty_purchased:int|null,alloue:int,restant:int|null,epuise:bool}>,
     *     non_couvert:int,
     *     achete:int,
     *     vendu:int,
     *     perdu:int,
     *     stock:int|null,
     *     ecart:int|null,
     *     premier_lot_suivi:string|null
     * }
     */
    public static function forProduct(string $productKey): array
    {
        $stocks = InventoryCount::theoreticalStocksMap();

        return self::calcule(
            $productKey,
            ProductCost::forProduct($productKey),
            isset($stocks[$productKey]) ? (int) $stocks[$productKey] : null
        );
    }

    /**
     * Écarts de stock pour tous les produits ayant au moins un lot suivi.
     *
     * Lots déduits d'un seul ProductCost::all() groupé par product_key
     * (clés réutilisées telles quelles), stock théorique lu une seule fois
     * ; les requêtes ventes/pertes restent par produit (volume faible).
     * Indexé par product_key, même structure que forProduct().
     *
     * @return array<string,array<string,mixed>>
     */
    public static function ecarts(): array
    {
        $stocks = InventoryCount::theoreticalStocksMap();

        $parProduit = [];
        foreach (ProductCost::all() as $row) {
            $parProduit[(string) ($row['product_key'] ?? '')][] = $row;
        }

        $out = [];
        foreach ($parProduit as $key => $lotRows) {
            if ($key === '') {
                continue;
            }
            // Uniquement les produits ayant au moins un lot suivi.
            $aUnLotSuivi = false;
            foreach ($lotRows as $row) {
                if (self::normaliseQty($row['qty_purchased'] ?? null) !== null) {
                    $aUnLotSuivi = true;
                    break;
                }
            }
            if (!$aUnLotSuivi) {
                continue;
            }
            $out[$key] = self::calcule(
                $key,
                $lotRows,
                isset($stocks[$key]) ? (int) $stocks[$key] : null
            );
        }

        return $out;
    }

    /**
     * Assemblage partagé par forProduct() et ecarts().
     *
     * @param list<array<string,mixed>> $lotRows lignes brutes product_costs
     */
    private static function calcule(string $productKey, array $lotRows, ?int $stock): array
    {
        $lots = [];
        $coutById = [];
        $validToById = [];
        $qtyById = [];
        foreach ($lotRows as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $qty = self::normaliseQty($row['qty_purchased'] ?? null);
            $lots[] = [
                'id'            => $id,
                'valid_from'    => substr((string) ($row['valid_from'] ?? ''), 0, 10),
                'qty_purchased' => $qty,
            ];
            $coutById[$id] = (string) ($row['cost_price'] ?? '');
            $validToById[$id] = ($row['valid_to'] ?? null) !== null
                ? substr((string) $row['valid_to'], 0, 10)
                : null;
            $qtyById[$id] = $qty;
        }

        // Tri chronologique (ProductCost retourne valid_from DESC) : le
        // premier lot suivi doit être le PLUS ANCIEN, pas le plus récent.
        self::trierLots($lots);

        // Premier lot suivi (chronologique) : borne de la fenêtre vendu/perdu.
        $premierLotSuivi = null;
        foreach ($lots as $lot) {
            if ($lot['qty_purchased'] !== null) {
                $premierLotSuivi = (string) $lot['valid_from'];
                break;
            }
        }

        $ventes = self::ventesParJour($productKey);
        $pertes = self::pertesParJour($productKey);

        // Allocation : TOUS les mouvements (report sur lot futur inclus).
        $mouvements = [];
        foreach ($ventes as $d => $q) {
            $mouvements[] = ['date' => $d, 'qty' => $q];
        }
        foreach ($pertes as $d => $q) {
            $mouvements[] = ['date' => $d, 'qty' => $q];
        }
        $allocation = self::allocate($lots, $mouvements);

        // Fenêtre : uniquement depuis le premier lot suivi.
        $vendu = 0;
        $perdu = 0;
        $achete = 0;
        if ($premierLotSuivi !== null) {
            foreach ($ventes as $d => $q) {
                if ($d >= $premierLotSuivi) {
                    $vendu += $q;
                }
            }
            foreach ($pertes as $d => $q) {
                if ($d >= $premierLotSuivi) {
                    $perdu += $q;
                }
            }
        }
        foreach ($qtyById as $qty) {
            if ($qty !== null) {
                $achete += $qty;
            }
        }

        $lotsOut = [];
        foreach ($allocation['lots'] as $id => $a) {
            $lotsOut[$id] = [
                'cost_price'    => $coutById[$id],
                'valid_from'    => self::validFromById($lots, $id),
                'valid_to'      => $validToById[$id],
                'qty_purchased' => $qtyById[$id],
                'alloue'        => $a['alloue'],
                'restant'       => $a['restant'],
                'epuise'        => $a['epuise'],
            ];
        }

        $ecart = ($stock !== null && $achete > 0)
            ? $stock - ($achete - $vendu - $perdu)
            : null;

        return [
            'lots'              => $lotsOut,
            'non_couvert'       => $allocation['non_couvert'],
            'achete'            => $achete,
            'vendu'             => $vendu,
            'perdu'             => $perdu,
            'stock'             => $stock,
            'ecart'             => $ecart,
            'premier_lot_suivi' => $premierLotSuivi,
        ];
    }

    /**
     * valid_from d'un lot depuis la liste triée (relecture pour la sortie).
     *
     * @param list<array{id:string,valid_from:string,qty_purchased:int|null}> $lots
     */
    private static function validFromById(array $lots, string $id): string
    {
        foreach ($lots as $lot) {
            if ((string) $lot['id'] === $id) {
                return (string) $lot['valid_from'];
            }
        }

        return '';
    }

    /**
     * Report d'une unité : consomme le premier lot SUIVI avec restant > 0
     * à partir de l'indice donné ; les lots non suivis rencontrés sont
     * absorbés (comptés) sans bloquer la traversée.
     *
     * @param array<int,int|null> $restant
     * @param array<int,int> $alloue
     */
    private static function reporter(array &$restant, array &$alloue, int $depuis): bool
    {
        for ($j = $depuis, $n = count($restant); $j < $n; $j++) {
            if ($restant[$j] === null) {
                $alloue[$j]++; // lot non suivi traversé : absorbé.
                continue;
            }
            if ($restant[$j] > 0) {
                $restant[$j]--;
                $alloue[$j]++;
                return true;
            }
            // Lot suivi épuisé : on continue vers le suivant.
        }

        return false;
    }

    /**
     * Tri des lots par valid_from ASC puis id ASC (départage déterministe).
     *
     * @param list<array<string,mixed>> $lots
     */
    private static function trierLots(array &$lots): void
    {
        usort($lots, static function (array $a, array $b): int {
            $vf = strcmp(
                substr((string) ($a['valid_from'] ?? ''), 0, 10),
                substr((string) ($b['valid_from'] ?? ''), 0, 10)
            );

            return $vf !== 0
                ? $vf
                : strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
        });
    }

    /**
     * Quantité suivie d'un lot : null si absente/NULL (lot non suivi),
     * sinon entier >= 0.
     */
    private static function normaliseQty(mixed $qty): ?int
    {
        if ($qty === null || $qty === '') {
            return null;
        }

        return max(0, (int) $qty);
    }

    /**
     * Ventes du produit agrégées PAR JOUR — règle de clé du projet :
     * clé effective `product_key` OU `description` (cf. Sale::soldQtySince),
     * montants personnalisés exclus.
     *
     * @return array<string,int> jour 'Y-m-d' => quantité vendue
     */
    private static function ventesParJour(string $productKey): array
    {
        try {
            $stmt = \db()->prepare(
                'SELECT DATE(sold_at) d, SUM(quantity) q
                 FROM sales
                 WHERE (product_key = ? OR description = ?)
                   AND is_custom_amount = 0
                 GROUP BY DATE(sold_at)
                 ORDER BY d'
            );
            $stmt->execute([$productKey, $productKey]);

            return self::parJour($stmt);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Pertes du produit agrégées PAR JOUR.
     *
     * @return array<string,int> jour 'Y-m-d' => quantité perdue
     */
    private static function pertesParJour(string $productKey): array
    {
        try {
            $stmt = \db()->prepare(
                'SELECT lost_at d, SUM(quantity) q
                 FROM losses
                 WHERE product_key = ?
                 GROUP BY lost_at
                 ORDER BY lost_at'
            );
            $stmt->execute([$productKey]);

            return self::parJour($stmt);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Lignes [d, q] → map jour => quantité.
     *
     * @param \PDOStatement $stmt
     *
     * @return array<string,int>
     */
    private static function parJour(\PDOStatement $stmt): array
    {
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $day = substr((string) $row['d'], 0, 10);
            $map[$day] = ($map[$day] ?? 0) + (int) $row['q'];
        }

        return $map;
    }
}
