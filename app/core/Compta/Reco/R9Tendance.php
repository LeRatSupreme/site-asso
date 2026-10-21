<?php

declare(strict_types=1);

namespace App\Core\Compta\Reco;

/**
 * R9 — Tendance : variation forte de la demande entre la période
 * précédente (qtyPrec) et la période courante (quantité désaisonnalisée).
 *
 * Garde-fou 5 : AUCUN candidat si une période creuse est active
 * (la variation peut n'être que saisonnière).
 *
 * Déclenchement par produit : historique connu (qtyPrec), volumes
 * suffisants des deux côtés (« reco_tendance_vol_min ») et variation
 * relative au-delà de « reco_tendance_seuil » (±50 % par défaut).
 *  - Croissance : suivre le stock (gain = surplus hebdo × marge ;
 *    coût inconnu → gain nul, pas de recalcule de demande) ;
 *  - Déclin : investigation, sans gain chiffré (0/0/0).
 */
final class R9Tendance
{
    /** Semaines par mois — conversion du volume mensuel en rythme hebdomadaire. */
    public const SEMAINES_PAR_MOIS = 4.33;

    /** Facteur — borne haute du gain = 1,5 × gain médian (croissance). */
    public const FACTEUR_HAUT = 1.5;

    public static function regle(): string
    {
        return 'R9';
    }

    /**
     * Candidats R9 (0..n) : suivre un stock en hausse, investiguer une baisse.
     *
     * @return list<array<string,mixed>>
     */
    public static function evaluer(RecoContext $ctx): array
    {
        if ($ctx->dataset()->periodeCreuseActive) {
            return [];
        }

        $volMin = (float) $ctx->param('tendance_vol_min', Hyper::TENDANCE_VOL_MIN);
        $seuil = (float) $ctx->param('tendance_seuil', Hyper::TENDANCE_SEUIL);

        $candidats = [];
        foreach ($ctx->produitsFiltres() as $p) {
            $prec = $ctx->dataset()->qtyPrec[$p->key] ?? null;
            if (!is_int($prec) && !is_float($prec)) {
                continue;
            }
            $prec = (float) $prec;
            if ($prec <= 0.0) {
                continue;
            }
            $qty = $ctx->qtyDesaison($p->key);
            $volumeOk = $prec >= $volMin && $qty >= $volMin;
            $ratio = $qty / $prec;
            if (!$volumeOk || abs($ratio - 1.0) <= $seuil) {
                continue;
            }

            $candidats[] = $ratio > 1.0
                ? self::candidatCroissance($ctx, $p, $prec, $qty)
                : self::candidatDeclin($ctx, $p, $prec, $qty);
        }

        return $candidats;
    }

    /**
     * Candidat de croissance : suivre le stock (gain = surplus hebdomadaire × marge).
     *
     * @return array<string,mixed>
     */
    private static function candidatCroissance(RecoContext $ctx, RecoProduct $p, float $prec, float $qty): array
    {
        $marge = $p->margeUnitaire();
        $median = ($qty - $prec) / self::SEMAINES_PAR_MOIS * ($marge ?? 0.0);
        $gain = GuardRails::fourchette(0.0, $median, $median * self::FACTEUR_HAUT);
        $hypotheses = [
            'Croissance supposée linéaire sur les 7 prochains jours (hypothèse)',
            'Marge unitaire constante sur la fenêtre',
        ];
        if ($marge === null) {
            $hypotheses[] = 'Coût inconnu : gain non chiffré (0 €), le suivi de stock reste prioritaire';
        }

        return [
            'regle'           => self::regle(),
            'keys'            => [$p->key],
            'produits'        => [['key' => $p->key, 'label' => $p->label]],
            'titre'           => 'Suivre le stock de ' . $p->label . ' (demande en forte hausse)',
            'pourquoi'        => self::nombre($prec) . ' → ' . self::nombre($qty) . ' u/30 j (+' . self::nombre(($qty / $prec - 1.0) * 100.0) . ' %)',
            'action'          => 'Surveiller le stock de ' . $p->label . ' et anticiper le réapprovisionnement',
            'gain'            => $gain,
            'confiance'       => 'moyenne',
            'effort'          => Hyper::EFFORTS['R9'],
            'hypotheses'      => $hypotheses,
            'comment_mesurer' => 'Ventes hebdomadaires du produit et ruptures sur les 2 prochaines semaines',
        ];
    }

    /**
     * Candidat de déclin : investigation, sans gain chiffré.
     *
     * @return array<string,mixed>
     */
    private static function candidatDeclin(RecoContext $ctx, RecoProduct $p, float $prec, float $qty): array
    {
        return [
            'regle'           => self::regle(),
            'keys'            => [$p->key],
            'produits'        => [['key' => $p->key, 'label' => $p->label]],
            'titre'           => 'Investiguer la baisse de ' . $p->label,
            'pourquoi'        => self::nombre($prec) . ' → ' . self::nombre($qty) . ' u/30 j (' . self::nombre(($qty / $prec - 1.0) * 100.0) . ' %)',
            'action'          => 'Investiguer la baisse de ' . $p->label . ' : vérifier ruptures, concurrence et saisonnalité avant toute action tarifaire',
            'gain'            => GuardRails::fourchette(0.0, 0.0, 0.0),
            'confiance'       => 'moyenne',
            'effort'          => Hyper::EFFORTS['R9'],
            'hypotheses'      => [
                'Pas de gain chiffré (investigation)',
                'Cause à identifier avant d\'agir : rupture, concurrence, saison…',
            ],
            'comment_mesurer' => 'Ventes hebdomadaires du produit sur les 2 prochaines semaines',
        ];
    }

    /** Nombre à 1 décimale, virgule décimale. */
    private static function nombre(float $x): string
    {
        return number_format($x, 1, ',', '');
    }
}
