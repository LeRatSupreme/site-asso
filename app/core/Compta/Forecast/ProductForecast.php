<?php

declare(strict_types=1);

namespace App\Core\Compta\Forecast;

/**
 * Étape 8 : prévision d'unités par produit (réapprovisionnement).
 *
 * Parts de marché par produit avec lissage de Dirichlet (aucun produit
 * vendu n'est à part nulle) :
 *
 *     part_i = (qty_i + 1) / (Σ qty + K)     (K = nombre de produits)
 *
 * Demande moyenne sur l'horizon :
 *
 *     unites_i = part_i × meanN × unitsPerTransaction × days
 *
 * où meanN est le nombre moyen de transactions/jour du modèle (globalN)
 * et unitsPerTransaction le ratio unités/transaction observé. Si un
 * générateur est fourni, l'incertitude Poisson est propagée par tirages
 * sur le TOTAL d'unités (λ = meanN × unitsPerTransaction × days), puis
 * répartie par les parts ; lo/hi = quantiles 0.10 / 0.90 et
 * safety_demand = plafond du quantile 0.90 de la demande. Sans
 * générateur, lo = hi = unites (déterministe).
 *
 * CALIBRATION SUR LE MOTEUR (une seule source d'unités) : quand
 * $caCible est fourni (= Σ des CA des jours prévus par le moteur), un
 * facteur unique f = caCible / Σ_i (unites_i × prix_i) recale unites,
 * lo, hi (et safety = plafond de hi) — INVARIANT : Σ_i unites_i ×
 * prix_i = caCible (±0,5 % par construction, produits à prix connu).
 * Le réapprovisionnement et le bénéfice (ProfitForecast) dérivent donc
 * des MÊMES unités, à l'échelle du CA prévu par le moteur — plus
 * d'écart structurel entre les deux tableaux. $part, lui, reste la
 * part de marché brute (inchangée par la calibration).
 */
final class ProductForecast
{
    /**
     * Prévoit les unités vendues par produit sur $days jours ouverts.
     *
     * @param list<ProductStat>      $products             Statistiques produits de la fenêtre.
     * @param DemandModel            $m                    Modèle ajusté (fournit meanN via globalN).
     * @param float                  $unitsPerTransaction  Unités vendues par transaction (Σ qty / Σ n).
     * @param int                    $days                 Horizon en jours ouverts.
     * @param ?\Random\Randomizer    $rng                  Générateur seedé (null → sorties déterministes).
     * @param int                    $draws                Tirages Monte Carlo (si $rng fourni).
     * @param ?float                 $caCible              CA prévu 7 j du moteur (calibration ;
     *                                                     null = pas de calibration).
     *
     * @return list<array{key:string,label:string,category:string,part:float,unites:float,lo:float,hi:float,safety_demand:int,stock:?int,suggestion:?int,unitPrice:?float,unitCost:?float,costSuspect:bool}>
     *              Triée par suggestion décroissante (nulls en fin) puis label ; ne
     *              contient que les produits avec qty > 0 OU suggestion > 0.
     *              unitPrice/unitCost/costSuspect portent le contrat consommé
     *              par ProfitForecast::compute() (bénéfice dérivé des mêmes unités).
     */
    public static function units(
        array $products,
        DemandModel $m,
        float $unitsPerTransaction,
        int $days = 7,
        ?\Random\Randomizer $rng = null,
        int $draws = 1000,
        ?float $caCible = null
    ): array {
        $items = array_values(array_filter($products, static fn ($p): bool => $p instanceof ProductStat));
        if ($items === []) {
            return [];
        }

        $days    = max(1, $days);
        $draws   = max(0, $draws);
        $k       = count($items);
        $sumQty  = 0;
        foreach ($items as $p) {
            $sumQty += $p->qty;
        }

        $meanN = self::meanN($m);
        $lambda = max(0.0, $meanN) * max(0.0, $unitsPerTransaction) * $days;

        // Tirages Poisson sur le TOTAL d'unités (une seule fois, partagés par produit).
        $totals = null;
        if ($rng !== null && $draws > 0 && $lambda > 0.0) {
            $totals = [];
            for ($d = 0; $d < $draws; $d++) {
                $totals[] = (float) Stats::poissonSample($lambda, $rng);
            }
        }

        // Passe 1 : unités BRUTES par produit (parts Dirichlet × demande).
        $rows = [];
        foreach ($items as $p) {
            $part = ($p->qty + 1) / ($sumQty + $k);
            $unites = $part * $lambda;

            if ($totals !== null) {
                $drawn = [];
                foreach ($totals as $t) {
                    $drawn[] = $part * $t;
                }
                $unites = Stats::mean($drawn);
                $lo     = Stats::quantile($drawn, 0.10);
                $hi     = Stats::quantile($drawn, 0.90);
            } else {
                $lo = $unites;
                $hi = $unites;
            }

            $rows[] = [
                'key'           => $p->key,
                'label'         => $p->label,
                'category'      => $p->category,
                'part'          => $part,
                'unites'        => $unites,
                'lo'            => $lo,
                'hi'            => $hi,
                'stock'         => $p->stock,
                'unitPrice'     => $p->unitPrice,
                'unitCost'      => $p->unitCost,
                'costSuspect'   => $p->costSuspect,
                '_qty'          => $p->qty,
            ];
        }

        // Passe 2 : CALIBRATION sur le CA prévu par le moteur. Un seul
        // facteur (mise à l'échelle uniforme) : parts et hiérarchie des
        // produits inchangées, Σ unites × prix = caCible pour les prix connus.
        if ($caCible !== null && $caCible > 0.0) {
            $brutCa = 0.0;
            foreach ($rows as $r) {
                if ($r['unitPrice'] !== null && $r['unitPrice'] > 0.0) {
                    $brutCa += $r['unites'] * (float) $r['unitPrice'];
                }
            }
            if ($brutCa > 0.0) {
                $facteur = $caCible / $brutCa;
                foreach ($rows as &$r) {
                    $r['unites'] *= $facteur;
                    $r['lo']     *= $facteur;
                    $r['hi']      = max($r['lo'], $r['hi'] * $facteur);
                }
                unset($r);
            }
        }

        // Passe 3 : safety/suggestion sur les valeurs FINALES, filtre et tri.
        $out = [];
        foreach ($rows as $r) {
            $qty = (float) $r['_qty'];
            $safety = (int) ceil($r['hi']);
            $suggestion = $r['stock'] !== null ? max(0, $safety - (int) $r['stock']) : null;

            if ($qty > 0.0 || ($suggestion !== null && $suggestion > 0)) {
                unset($r['_qty']);
                $r['safety_demand'] = $safety;
                $r['suggestion']    = $suggestion;
                $out[] = $r;
            }
        }

        usort($out, static function (array $a, array $b): int {
            $sa = $a['suggestion'] ?? -1;
            $sb = $b['suggestion'] ?? -1;
            if ($sa !== $sb) {
                return $sb <=> $sa;
            }

            return strcmp($a['label'], $b['label']);
        });

        return $out;
    }

    /**
     * Nombre moyen de transactions par jour ouvert du modèle : globalN
     * s'il est disponible (> 0), sinon expectedN de demain (date système,
     * repli documenté — jamais atteint via ForecastEngine).
     */
    private static function meanN(DemandModel $m): float
    {
        if ($m->globalN > 0.0) {
            return $m->globalN;
        }
        try {
            $demain = new \DateTimeImmutable('tomorrow', new \DateTimeZone(Hyper::TZ));
        } catch (\Throwable) {
            return 0.0;
        }

        return $m->expectedN($demain);
    }
}
