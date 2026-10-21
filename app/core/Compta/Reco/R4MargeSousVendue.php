<?php

declare(strict_types=1);

namespace App\Core\Compta\Reco;

/**
 * R4 — Forte marge sous-vendue : un produit qui rapporte plus que la
 * médiane mais se vend moins que la médiane mérite de la visibilité
 * (affiche, emplacement caisse, suggestion du jour) — pas de remise.
 *
 * Déclenchement (par produit filtré) : coût utilisable, taux de marge
 * > médiane des taux de marge (strict), volume désaisonnalisé <
 * médiane des volumes (strict), stock non géré ou ≥
 * STOCK_MIN_MISE_EN_AVANT, et quantité désaisonnalisée ≥ «
 * reco_r4_sample_min » (8). Médiane indisponible (moins de 3 produits
 * exploitables) → aucun candidat.
 *
 * Gain hebdomadaire (€) = prévision × « reco_r4_gain_vol » (0,20) ×
 * marge unitaire — l'augmentation de volume est une HYPOTHÈSE ;
 * bas = 0,5 × médian, haut = 1,5 × médian.
 */
final class R4MargeSousVendue implements RecoRule
{
    /** Unités — stock minimal en réserve pour oser une mise en avant (ou stock non géré). */
    public const STOCK_MIN_MISE_EN_AVANT = 5;

    /** Facteur — borne basse du gain = 0,5 × gain médian. */
    public const FACTEUR_BAS = 0.5;

    /** Facteur — borne haute du gain = 1,5 × gain médian. */
    public const FACTEUR_HAUT = 1.5;

    public static function regle(): string
    {
        return 'R4';
    }

    /**
     * Candidats R4 (0..n) : mise en avant des produits à forte marge sous-vendus.
     *
     * @return list<array<string,mixed>>
     */
    public static function evaluer(RecoContext $ctx): array
    {
        $sampleMin = (float) $ctx->param('r4_sample_min', Hyper::R4_SAMPLE_MIN);
        $gainVol = (float) $ctx->param('r4_gain_vol', Hyper::R4_GAIN_VOL);
        $medTaux = $ctx->medianeTauxMarge();
        $medVol = $ctx->medianeVolume();
        if ($medTaux === null) {
            return [];
        }

        $candidats = [];
        foreach ($ctx->produitsFiltres() as $p) {
            if (!$p->coutUtilisable()) {
                continue;
            }
            $taux = (float) $p->tauxMarge();
            if (!($taux > $medTaux)) {
                continue;
            }
            $qty = $ctx->qtyDesaison($p->key);
            if (!($qty < $medVol) || !($qty >= $sampleMin)) {
                continue;
            }
            if (!($p->stock === null || $p->stock >= self::STOCK_MIN_MISE_EN_AVANT)) {
                continue;
            }
            $candidats[] = self::candidat($p, $taux, $qty, $medTaux, $medVol, $gainVol);
        }

        return $candidats;
    }

    /**
     * Candidat de mise en avant d'un produit à forte marge sous-vendu.
     *
     * @return array<string,mixed>
     */
    private static function candidat(RecoProduct $p, float $taux, float $qty, float $medTaux, float $medVol, float $gainVol): array
    {
        $marge = (float) $p->margeUnitaire();
        $median = $p->unitesPrevues7j * $gainVol * $marge;
        $gain = GuardRails::fourchette($median * self::FACTEUR_BAS, $median, $median * self::FACTEUR_HAUT);

        return [
            'regle'           => self::regle(),
            'keys'            => [$p->key],
            'produits'        => [['key' => $p->key, 'label' => $p->label]],
            'titre'           => 'Mettre en avant ' . $p->label . ' (forte marge, sous-vendu)',
            'pourquoi'        => 'Marge ' . self::pct($taux) . ' % (médiane ' . self::pct($medTaux) . ' %) · ' . self::nombre($qty) . ' u./30 j vs médiane ' . self::nombre($medVol),
            'action'          => 'Mettre ' . $p->label . ' en avant : affiche, emplacement caisse ou suggestion du jour',
            'gain'            => $gain,
            'confiance'       => 'moyenne',
            'effort'          => Hyper::EFFORTS['R4'],
            'hypotheses'      => [
                '+' . self::pct($gainVol, 0) . ' % de volume = hypothèse',
                'Mise en avant sans coût : marge unitaire inchangée',
            ],
            'comment_mesurer' => 'Ventes hebdo du produit pendant la mise en avant vs 30 j précédents',
        ];
    }

    /** Nombre à 1 décimale, virgule décimale. */
    private static function nombre(float $x): string
    {
        return number_format($x, 1, ',', '');
    }

    /** Fraction (0..1) en pourcentage, virgule décimale. */
    private static function pct(float $part, int $dec = 1): string
    {
        return number_format($part * 100.0, $dec, ',', '');
    }
}
