<?php

declare(strict_types=1);

namespace App\Core\Compta\Reco;

use App\Core\Compta\Forecast\Stats;

/**
 * R6 — Structuration du panier : seuil cadeau.
 *
 * Contexte : trop de paniers ne contiennent qu'une seule ligne. On
 * propose d'offrir un petit cadeau au-delà d'un seuil de montant (le
 * quantile choisi des montants) pour inciter au franchissement.
 *
 * Déclenchement : part de paniers à 1 ligne > « reco_panier_1article_seuil ».
 * Seuil : quantile « reco_panier_percentile » des montants de TOUS les
 * paniers. Cadeau : produit filtré le moins cher dont le coût est connu
 * et ≤ « reco_cout_cadeau_max », avec stock non géré (null) ou > 10.
 *
 * Le gain hebdomadaire (€) : (paniers proches du seuil ÷ 4,33) × taux de
 * franchissement × (marge unitaire moyenne des articles proches − coût
 * du cadeau). Aucun prix n'est proposé : pas de prix_effectifs.
 */
final class R6Panier
{
    /** € — demi-fenêtre de proximité autour du seuil (hypothèse : un panier à ±2 € du seuil peut « sauter »). */
    public const FENETRE_PROXIMITE_EUR = 2.0;

    /** Semaines par mois — conversion du volume mensuel observé en rythme hebdomadaire. */
    public const SEMAINES_PAR_MOIS = 4.33;

    /** Fraction — part des paniers proches du seuil supposée franchir grâce au cadeau (hypothèse). */
    public const TAUX_FRANCHISSEMENT = 0.10;

    /** Unités — un cadeau doit avoir un stock strictement supérieur à ce niveau (réserve en deçà). */
    public const STOCK_CADEAU_MIN = 10;

    public static function regle(): string
    {
        return 'R6';
    }

    /**
     * Candidats R6 (0 ou 1) : offre d'un cadeau au-delà d'un seuil de panier.
     *
     * @return list<array<string,mixed>>
     */
    public static function evaluer(RecoContext $ctx): array
    {
        $n = $ctx->nPaniers();
        if ($n === 0) {
            return [];
        }

        $montants = [];
        $aUneLigne = 0;
        foreach ($ctx->dataset()->paniers as $b) {
            $montants[] = $ctx->montantPanier($b);
            if (count($b->lignes) === 1) {
                ++$aUneLigne;
            }
        }
        $part = $aUneLigne / $n;
        if (!($part > (float) $ctx->param('panier_1article_seuil', Hyper::PANIER_1ARTICLE_SEUIL))) {
            return [];
        }

        $cadeau = self::choisirCadeau($ctx);
        if ($cadeau === null) {
            return [];
        }

        $seuil = Stats::quantile($montants, (float) $ctx->param('panier_percentile', Hyper::PANIER_PERCENTILE));
        [$nb, $margeMoy] = self::paniersProches($ctx, $seuil);
        $coutCadeau = (float) $cadeau->cout;

        $parSemaine = $nb / self::SEMAINES_PAR_MOIS;
        $median = $parSemaine * self::TAUX_FRANCHISSEMENT * $margeMoy
            - $parSemaine * self::TAUX_FRANCHISSEMENT * $coutCadeau;
        $gain = GuardRails::fourchette($median * 0.5, $median, $median * 1.5);

        $confiance = $n >= Hyper::ASSOC_PANIERS_MIN ? 'moyenne' : 'faible';
        $hypotheses = [
            'Taux de franchissement +' . number_format(self::TAUX_FRANCHISSEMENT * 100.0, 0, ',', '')
                . ' % des paniers proches du seuil (hypothèse)',
            'Fenêtre de proximité ±' . self::eur(self::FENETRE_PROXIMITE_EUR) . ' € autour du seuil (constante)',
            'Conversion mois → semaine : ÷ ' . number_format(self::SEMAINES_PAR_MOIS, 2, ',', ''),
        ];
        if ($confiance === 'faible') {
            $hypotheses[] = 'Moins de ' . Hyper::ASSOC_PANIERS_MIN . ' paniers observés : estimation peu fiable (hypothèse)';
        }

        return [[
            'regle'           => self::regle(),
            'keys'            => [$cadeau->key],
            'produits'        => [['key' => $cadeau->key, 'label' => $cadeau->label]],
            'titre'           => 'Seuil cadeau pour enrichir les paniers',
            'pourquoi'        => self::pct($part) . ' % des paniers n\'ont qu\'une seule ligne · seuil cadeau proposé : ' . self::eur($seuil) . ' €',
            'action'          => 'Offrir ' . $cadeau->label . ' au-delà de ' . self::eur($seuil) . ' € d\'achat',
            'gain'            => $gain,
            'confiance'       => $confiance,
            'effort'          => Hyper::EFFORTS['R6'],
            'hypotheses'      => $hypotheses,
            'comment_mesurer' => 'Part de paniers à 1 ligne et panier moyen avant/après 14 jours ouverts',
        ]];
    }

    /**
     * Cadeau retenu : produit filtré le moins cher, coût connu et ≤
     * « reco_cout_cadeau_max », stock non géré (null) ou > STOCK_CADEAU_MIN.
     */
    private static function choisirCadeau(RecoContext $ctx): ?RecoProduct
    {
        $max = (float) $ctx->param('cout_cadeau_max', Hyper::COUT_CADEAU_MAX);
        $choisi = null;
        foreach ($ctx->produitsFiltres() as $p) {
            if ($p->cout === null || $p->cout > $max) {
                continue;
            }
            if (!($p->stock === null || $p->stock > self::STOCK_CADEAU_MIN)) {
                continue;
            }
            if ($choisi === null || $p->cout < $choisi->cout) {
                $choisi = $p;
            }
        }

        return $choisi;
    }

    /**
     * Nombre de paniers dont le montant est dans [seuil − fenêtre, seuil]
     * et marge unitaire moyenne de leurs articles (coût utilisable, sinon 0 €).
     *
     * @return array{0:int,1:float}
     */
    private static function paniersProches(RecoContext $ctx, float $seuil): array
    {
        $borne = $seuil - self::FENETRE_PROXIMITE_EUR;
        $nb = 0;
        $marges = [];
        foreach ($ctx->dataset()->paniers as $b) {
            $m = $ctx->montantPanier($b);
            if ($m < $borne || $m > $seuil) {
                continue;
            }
            ++$nb;
            foreach ($b->lignes as $ligne) {
                $p = is_string($ligne['key'] ?? null) ? $ctx->produitParCle($ligne['key']) : null;
                $marges[] = ($p !== null && $p->coutUtilisable()) ? (float) $p->margeUnitaire() : 0.0;
            }
        }

        return [$nb, Stats::mean($marges)];
    }

    /** Montant en € (2 décimales, virgule décimale, sans séparateur de milliers). */
    private static function eur(float $x): string
    {
        return number_format($x, 2, ',', '');
    }

    /** Part (0..1) en pourcentage à 1 décimale, virgule décimale. */
    private static function pct(float $x): string
    {
        return number_format($x * 100.0, 1, ',', '');
    }
}
