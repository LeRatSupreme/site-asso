<?php

declare(strict_types=1);

namespace App\Core\Compta\Reco;

/**
 * R1 — Stock dormant : écouler un stock immobilisé dont la demande
 * PRÉVUE (sortie Forecast, jamais recalculée) est trop faible.
 *
 * Déclenchement (par produit à coût utilisable) :
 *  - stock géré > « reco_stock_dormant_min » (10) ;
 *  - semaines de stock = stock ÷ unitesPrevues7j > «
 *    reco_stock_dormant_semaines » (8) — si aucune demande prévue
 *    (≤ 0), le stock ne s'écoulera pas seul : déclenché si la quantité
 *    désaisonnalisée est strictement positive ;
 *  - ET quantité désaisonnalisée ≥ « reco_echantillon_min_unites » (20).
 *
 * Remise proposée — cible REMISE_CIBLE clampée dans [«
 * reco_remise_dormant_bas » (10 %) .. « reco_remise_dormant_haut »
 * (20 %)] puis plafonnée par la marge minimale (garde-fou 1) :
 *  - remiseMax = 1 − coût × (1 + marge_min) ÷ prix ; si remiseMax ≥ bas :
 *    promo « −X % » avec X = min(remiseMax, cible clampée) et
 *    prix_effectifs = prix × (1 − X) ≥ plancher ;
 *  - SINON « lot 3 pour 2 » si 2/3 ≥ (1 + marge_min) × coût ÷ prix
 *    (prix effectif 2×prix/3, encore au-dessus du plancher) ;
 *  - SINON aucune des deux sorties n'est possible sans casser le
 *    plancher de marge : AUCUN candidat. Mathématiquement, remiseMax
 *    < bas (0,10 par défaut) implique (1+marge_min)×coût/prix > 0,90
 *    > 2/3, donc le lot 3 pour 2 est exclu lui aussi.
 *
 * Gain hebdomadaire (€) : surplus = max(stock − semaines_seuil ×
 * prévision, 0), supposé écoulé en DUREE_ECOULEMENT_SEMAINES au prix
 * remisé ; médian avec unitesPrevues7j, bas avec prevHi (moins
 * d'excédent), haut avec prevLo.
 */
final class R1StockDormant implements RecoRule
{
    /** Fraction du prix — remise cible avant clamp [bas..haut] et plafonnement par la marge minimale. */
    public const REMISE_CIBLE = 0.15;

    /** Semaines — durée d'écoulement du surplus supposée pour chiffrer le gain (hypothèse). */
    public const DUREE_ECOULEMENT_SEMAINES = 4.0;

    /** Fraction du prix — un « lot 3 pour 2 » revient à vendre chaque unité aux 2/3 du prix. */
    public const PRIX_LOT_3POUR2 = 2.0 / 3.0;

    public static function regle(): string
    {
        return 'R1';
    }

    /**
     * Candidats R1 (0..n) : promo « −X % » ou « lot 3 pour 2 » sur les stocks dormants.
     *
     * @return list<array<string,mixed>>
     */
    public static function evaluer(RecoContext $ctx): array
    {
        $stockMin = (float) $ctx->param('stock_dormant_min', Hyper::STOCK_DORMANT_MIN);
        $semMin = (float) $ctx->param('stock_dormant_semaines', Hyper::STOCK_DORMANT_SEMAINES);
        $echMin = (float) $ctx->param('echantillon_min_unites', Hyper::SAMPLE_MIN_UNITES_30J);
        $remiseBas = (float) $ctx->param('remise_dormant_bas', Hyper::REMISE_DORMANT_BAS);
        $remiseHaut = (float) $ctx->param('remise_dormant_haut', Hyper::REMISE_DORMANT_HAUT);

        $candidats = [];
        foreach ($ctx->produitsFiltres() as $p) {
            if (!$p->coutUtilisable() || $p->stock === null || !($p->stock > $stockMin)) {
                continue;
            }
            $prev = $p->unitesPrevues7j;
            $qty = $ctx->qtyDesaison($p->key);
            // Semaines de stock couvertes d'après la PRÉVISION 7 j (pas du passé).
            $semaines = $prev > 0.0 ? $p->stock / $prev : null;
            if ($semaines !== null) {
                if (!($semaines > $semMin)) {
                    continue;
                }
            } elseif (!($qty > 0.0)) {
                // Aucune demande prévue : stock immobile, qualifié dormant.
                continue;
            }
            if (!($qty >= $echMin)) {
                continue;
            }
            $c = self::candidat($p, $semaines, $prev, $qty, $semMin, $remiseBas, $remiseHaut);
            if ($c !== null) {
                $candidats[] = $c;
            }
        }

        return $candidats;
    }

    /**
     * Candidat d'un stock dormant, ou null si aucun prix remisé ne
     * respecte le plancher de marge (garde-fou 1).
     *
     * @return array<string,mixed>|null
     */
    private static function candidat(
        RecoProduct $p,
        ?float $semaines,
        float $prev,
        float $qty,
        float $semMin,
        float $remiseBas,
        float $remiseHaut
    ): ?array {
        $cout = (float) $p->cout;
        $prix = (float) $p->prix;
        $margeMin = Hyper::MARGE_MIN;

        // Cible clampée dans [bas .. haut], plafonnée ensuite par la marge minimale.
        $cible = min($remiseHaut, max($remiseBas, self::REMISE_CIBLE));
        $remiseMax = 1.0 - $cout * (1.0 + $margeMin) / $prix;

        if ($remiseMax >= $remiseBas) {
            $remise = min($remiseMax, $cible);
            $prixEffectif = $prix * (1.0 - $remise);
            $action = 'Lancer une promo « -' . self::pct($remise) . ' % » sur ' . $p->label . ' pour écouler le stock avant péremption';
        } elseif (self::PRIX_LOT_3POUR2 >= (1.0 + $margeMin) * $cout / $prix) {
            // Remise refusée par le garde-fou 1, mais le lot 3 pour 2 reste au-dessus du plancher.
            $prixEffectif = self::PRIX_LOT_3POUR2 * $prix;
            $action = 'Proposer un « lot 3 pour 2 » sur ' . $p->label . ' pour écouler le stock avant péremption';
        } else {
            // Ni remise ni lot possibles sans casser le plancher : aucun candidat.
            return null;
        }

        $margeRemise = $prixEffectif - $cout;
        $bas = self::surplus($p->stock, $semMin, $p->prevHi) / self::DUREE_ECOULEMENT_SEMAINES * $margeRemise;
        $median = self::surplus($p->stock, $semMin, $prev) / self::DUREE_ECOULEMENT_SEMAINES * $margeRemise;
        $haut = self::surplus($p->stock, $semMin, $p->prevLo) / self::DUREE_ECOULEMENT_SEMAINES * $margeRemise;

        return [
            'regle'           => self::regle(),
            'keys'            => [$p->key],
            'produits'        => [['key' => $p->key, 'label' => $p->label]],
            'titre'           => 'Écouler le stock dormant de ' . $p->label,
            'pourquoi'        => $semaines !== null
                ? self::nombre($semaines) . ' sem de stock · ' . self::nombre($prev) . ' u./sem prévues'
                : 'Stock de ' . $p->stock . ' u. sans demande prévue · ' . self::nombre($qty) . ' u./30 j',
            'action'          => $action,
            'gain'            => GuardRails::fourchette($bas, $median, $haut),
            'confiance'       => 'moyenne',
            'effort'          => Hyper::EFFORTS['R1'],
            'hypotheses'      => [
                'Vérifier les dates de péremption avant écoulement',
                'Surplus supposé vendu en ' . self::nombre(self::DUREE_ECOULEMENT_SEMAINES, 0) . ' semaines grâce à la remise (hypothèse)',
            ],
            'comment_mesurer' => 'Marge hebdo du produit pendant la promo vs baseline 30 j désaisonnalisée',
            'prix_effectifs'  => [['key' => $p->key, 'prix' => $prixEffectif]],
        ];
    }

    /** Excédent au-dessus de la couverture seuil, pour une prévision hebdomadaire donnée. */
    private static function surplus(int $stock, float $semMin, float $prev): float
    {
        return max($stock - $semMin * $prev, 0.0);
    }

    /** Nombre à 1 décimale par défaut, virgule décimale. */
    private static function nombre(float $x, int $dec = 1): string
    {
        return number_format($x, $dec, ',', '');
    }

    /** Fraction (0..1) en pourcentage, virgule décimale. */
    private static function pct(float $part, int $dec = 1): string
    {
        return number_format($part * 100.0, $dec, ',', '');
    }
}
