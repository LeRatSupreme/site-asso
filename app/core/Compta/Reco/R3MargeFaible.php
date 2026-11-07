<?php

declare(strict_types=1);

namespace App\Core\Compta\Reco;

/**
 * R3 — Marge faible sur fort volume : les meilleures ventes (top CA)
 * méritent un test de prix quand elles rapportent peu.
 *
 * Déclenchement (par produit filtré) : clé dans topCaKeys(), coût
 * utilisable, taux de marge < « reco_marge_faible_seuil » (0,25) et
 * quantité désaisonnalisée ≥ « reco_echantillon_min_unites » (20).
 *
 * Hausse testée : DELTA_FORT (0,20 €) si volume désaisonnalisé ≥
 * SEUIL_QTY_DELTA (40 u./30 j), sinon DELTA_FAIBLE (0,10 €). La perte
 * de volume maximale admissible est donnée par perteVolumeMax() :
 * 1 − taux_marge(prix) ÷ taux_marge(prix+Δ) — au-delà, le test détruit
 * de la marge totale. Le prix proposé (prix_effectifs = prix + Δ) est
 * toujours au-dessus du plancher (garde-fou 1 : on HAUSSE le prix).
 *
 * Gain hebdomadaire (€) : bas = 0 (aucune hausse de volume) ; médian =
 * Δ × prévision × PART_VOLUME_CONSERVE ; haut = Δ × prévision +
 * prévision × coût × HAUSSE_VOLUME_ESPEREE (effet volume espéré).
 */
final class R3MargeFaible implements RecoRule
{
    /** € par unité — hausse testée quand le volume désaisonnalisé est fort. */
    public const DELTA_FORT = 0.20;

    /** € par unité — hausse testée sinon. */
    public const DELTA_FAIBLE = 0.10;

    /** Unités/30 j — volume désaisonnalisé à partir duquel la hausse testée passe à DELTA_FORT. */
    public const SEUIL_QTY_DELTA = 40.0;

    /** Fraction — part des ventes supposée conservée au prix haussé (hypothèse médiane). */
    public const PART_VOLUME_CONSERVE = 0.75;

    /** Fraction du coût — volume supplémentaire espéré du test (borne haute, hypothèse). */
    public const HAUSSE_VOLUME_ESPEREE = 0.075;

    public static function regle(): string
    {
        return 'R3';
    }

    /**
     * Perte de volume maximale admissible (fraction des ventes) en
     * haussant le prix de $delta € en restant AUSSI rentable
     * qu'aujourd'hui (marge totale constante) :
     * 1 − ((prix−cout)/prix) ÷ ((prix+Δ−cout)/(prix+Δ)).
     */
    public static function perteVolumeMax(float $prix, float $cout, float $delta): float
    {
        $m0 = ($prix - $cout) / $prix;
        $m1 = ($prix + $delta - $cout) / ($prix + $delta);

        return 1.0 - $m0 / $m1;
    }

    /**
     * Candidats R3 (0..n) : test de prix haussier sur les gros volumes à faible marge.
     *
     * @return list<array<string,mixed>>
     */
    public static function evaluer(RecoContext $ctx): array
    {
        $seuil = (float) $ctx->param('marge_faible_seuil', Hyper::MARGE_FAIBLE_SEUIL);
        $echMin = (float) $ctx->param('echantillon_min_unites', Hyper::SAMPLE_MIN_UNITES_30J);
        $top = $ctx->topCaKeys();

        $candidats = [];
        foreach ($ctx->produitsFiltres() as $p) {
            if (!in_array($p->key, $top, true) || !$p->coutUtilisable()) {
                continue;
            }
            $taux = (float) $p->tauxMarge();
            if (!($taux < $seuil)) {
                continue;
            }
            $qty = $ctx->qtyDesaison($p->key);
            if (!($qty >= $echMin)) {
                continue;
            }
            $candidats[] = self::candidat($p, $taux, $qty);
        }

        return $candidats;
    }

    /**
     * Candidat de test de prix sur un produit du top CA à faible marge.
     *
     * @return array<string,mixed>
     */
    private static function candidat(RecoProduct $p, float $taux, float $qty): array
    {
        $prix = (float) $p->prix;
        $cout = (float) $p->cout;
        $delta = $qty >= self::SEUIL_QTY_DELTA ? self::DELTA_FORT : self::DELTA_FAIBLE;
        $perte = self::perteVolumeMax($prix, $cout, $delta);
        $prev = $p->unitesPrevues7j;

        $median = $delta * $prev * self::PART_VOLUME_CONSERVE;
        $haut = $delta * $prev + $prev * $cout * self::HAUSSE_VOLUME_ESPEREE;
        $gain = GuardRails::fourchette(0.0, $median, $haut);

        return [
            'regle'           => self::regle(),
            'keys'            => [$p->key],
            'produits'        => [['key' => $p->key, 'label' => $p->label]],
            'titre'           => 'Tester une hausse de prix sur ' . $p->label,
            'pourquoi'        => 'Marge ' . self::pct($taux) . ' % · ' . self::nombre($qty) . ' u./30 j (top CA)',
            'action'          => 'Tester +' . self::eur($delta) . ' € pendant ' . Hyper::TEST_PRIX_JOURS . ' jours — rentable tant que vous perdez moins de ' . self::pct($perte) . ' % des ventes',
            'gain'            => $gain,
            'confiance'       => 'moyenne',
            'effort'          => Hyper::EFFORTS['R3'],
            'hypotheses'      => [
                'Élasticité-prix inconnue : hypothèse',
                'Piste complémentaire : renégocier le prix d\'achat (-5 à -10 %)',
            ],
            'comment_mesurer' => 'Marge totale sur les ' . Hyper::TEST_PRIX_JOURS . ' jours du test vs baseline 30 j désaisonnalisée',
            'prix_effectifs'  => [['key' => $p->key, 'prix' => $prix + $delta]],
        ];
    }

    /** Nombre à 1 décimale, virgule décimale. */
    private static function nombre(float $x): string
    {
        return number_format($x, 1, ',', '');
    }

    /** Fraction (0..1) en pourcentage à 1 décimale, virgule décimale. */
    private static function pct(float $part): string
    {
        return number_format($part * 100.0, 1, ',', '');
    }

    /** Montant en € (2 décimales, virgule décimale). */
    private static function eur(float $x): string
    {
        return number_format($x, 2, ',', '');
    }
}
