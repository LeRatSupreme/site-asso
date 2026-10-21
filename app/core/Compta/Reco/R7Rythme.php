<?php

declare(strict_types=1);

namespace App\Core\Compta\Reco;

use App\Core\Compta\Forecast\Stats;

/**
 * R7 — Rythme horaire : happy hour sur UN produit nommé aux heures
 * creuses, préparation des duos avant le pic.
 *
 * À partir des parts de demande par heure (RecoContext::partsHoraires,
 * dérivées du cumul forecast['horaire']['s']) : fenêtre creuse = plage
 * contiguë (≥ 2 heures) de parts < « reco_seuil_creux_part » × moyenne
 * la plus large — ou, si aucune plage contiguë n'existe, les 2 heures
 * les plus faibles. Pic = heure de part maximale. UN SEUL candidat.
 *
 * Produit promu : celui au meilleur taux de marge (coût utilisable,
 * stock non géré ou > 0). Remise : remiseMax = 1 − coût × (1 +
 * marge_min) ÷ prix ; remise = min(R7_REMISE_CIBLE, remiseMax) ;
 * remiseMax < « reco_r7_remise_min » (0,05) → aucun candidat (le
 * garde-fou 1 interdirait une vraie promo). prix_effectifs = prix ×
 * (1 − remise) pour CE produit (≥ plancher par construction).
 *
 * Gain hebdomadaire (€) : CA 7 j prévu (unitesPrevues7j × prix des
 * produits à coût utilisable) × part de demande de la fenêtre creuse ×
 * uplift = remise réellement proposée × taux de marge (médiane, secours
 * 0,30 si null). bas = 0,5 × médian, haut = 1,5 × médian.
 */
final class R7Rythme
{
    /** Sans unité — taux de marge de secours si moins de 3 produits à coût fiable (hypothèse documentée). */
    public const TAUX_MARGE_SECOURS = 0.30;

    /** Heures — taille minimale d'une plage contiguë de creux pour définir la fenêtre. */
    public const CREUX_CONTIGU_MIN = 2;

    public static function regle(): string
    {
        return 'R7';
    }

    /**
     * Candidat R7 (0 ou 1) : produit à prix réduit sur la fenêtre creuse, duos préparés avant le pic.
     *
     * @return list<array<string,mixed>>
     */
    public static function evaluer(RecoContext $ctx): array
    {
        $parts = $ctx->partsHoraires();
        if ($parts === null) {
            return [];
        }
        $positives = array_values(array_filter($parts, static fn (float|int $v): bool => (float) $v > 0.0));
        if ($positives === []) {
            return [];
        }
        $moyenne = Stats::mean($positives);
        $seuilCreux = (float) $ctx->param('seuil_creux_part', Hyper::SEUIL_CREUX_PART);

        [$heures] = self::fenetreCreuse($parts, $seuilCreux * $moyenne);
        $h1 = min($heures);
        $h2 = max($heures);
        $pic = self::heurePic($parts);

        [$produit, $ca7j] = self::produitEtCa($ctx);
        if ($produit === null) {
            return [];
        }
        $prix = (float) $produit->prix;
        $cout = (float) $produit->cout;
        $remiseMax = 1.0 - $cout * (1.0 + Hyper::MARGE_MIN) / $prix;
        if ($remiseMax < Hyper::R7_REMISE_MIN) {
            return []; // Aucune vraie promo possible sans casser le plancher de marge.
        }
        $remise = min(Hyper::R7_REMISE_CIBLE, $remiseMax);
        $prixEffectif = $prix * (1.0 - $remise);

        $partCreuse = 0.0;
        foreach ($heures as $h) {
            $partCreuse += (float) ($parts[$h] ?? 0.0);
        }
        $somme = array_sum($parts);
        $secours = $ctx->medianeTauxMarge() === null;
        $taux = $secours ? self::TAUX_MARGE_SECOURS : (float) $ctx->medianeTauxMarge();
        $median = $ca7j * $partCreuse * $remise * $taux;
        $gain = GuardRails::fourchette($median * 0.5, $median, $median * 1.5);

        $hypotheses = [
            'Remise de ' . self::pct($remise) . ' % supposée booster les ventes des heures creuses (hypothèse)',
        ];
        if ($secours) {
            $hypotheses[] = 'Taux de marge de secours ' . number_format(self::TAUX_MARGE_SECOURS, 2, ',', '')
                . ' faute de 3 produits à coût fiable (hypothèse)';
        }

        return [[
            'regle'           => self::regle(),
            'keys'            => [$produit->key],
            'produits'        => [['key' => $produit->key, 'label' => $produit->label]],
            'titre'           => 'Happy hour : ' . $produit->label . ' en heure creuse',
            'pourquoi'        => self::pct($somme > 0.0 ? $partCreuse / (float) $somme : 0.0)
                . ' % de la demande est dans la fenêtre creuse ' . $h1 . '–' . $h2
                . ' · CA prévu 7 j : ' . self::eur($ca7j) . ' €',
            'action'          => 'Entre ' . $h1 . 'h et ' . $h2 . 'h : ' . $produit->label . ' à ' . self::eur($prixEffectif)
                . ' € au lieu de ' . self::eur($prix) . ' € (-' . self::pct($remise) . ' %). Préparez les duos avant le pic de ' . $pic . 'h.',
            'gain'            => $gain,
            'confiance'       => 'moyenne',
            'effort'          => Hyper::EFFORTS['R7'],
            'hypotheses'      => $hypotheses,
            'comment_mesurer' => 'Parts horaires et CA par heure avant/après 14 jours ouverts',
            'prix_effectifs'  => [['key' => $produit->key, 'prix' => $prixEffectif]],
        ]];
    }

    /**
     * Heures creuses retenues : plage contiguë (≥ CREUX_CONTIGU_MIN
     * heures) de parts sous le seuil la plus large ; repli = les 2 heures
     * les plus faibles si aucune plage contiguë n'existe (l'action
     * affiche min–max, le gain ne compte que ces heures).
     *
     * @param array<int,float> $parts
     *
     * @return array{0:list<int>} Heures retenues (croissantes).
     */
    private static function fenetreCreuse(array $parts, float $seuil): array
    {
        $best = [];
        $courant = [];
        foreach ($parts as $h => $part) {
            if ((float) $part < $seuil) {
                $courant[] = (int) $h;
                if (count($courant) > count($best)) {
                    $best = $courant;
                }
            } else {
                $courant = [];
            }
        }
        if (count($best) >= self::CREUX_CONTIGU_MIN) {
            return [$best];
        }

        // Repli : les 2 heures les plus faibles (égalités : heure croissante).
        $tries = $parts;
        asort($tries);
        $deux = array_slice(array_map('intval', array_keys($tries)), 0, 2);
        sort($deux);

        return [$deux];
    }

    /** Heure de part maximale (première atteinte en cas d'égalité). */
    private static function heurePic(array $parts): int
    {
        $pic = 0;
        $max = -INF;
        foreach ($parts as $h => $part) {
            if ((float) $part > $max) {
                $max = (float) $part;
                $pic = (int) $h;
            }
        }

        return $pic;
    }

    /**
     * Produit promu (meilleur taux de marge parmi les produits filtrés
     * à coût utilisable, stock non géré ou > 0 — égalités : ordre
     * d'entrée) et CA 7 j prévu associé (unitesPrevues7j × prix,
     * produits à coût utilisable uniquement).
     *
     * @return array{0:?RecoProduct,1:float}
     */
    private static function produitEtCa(RecoContext $ctx): array
    {
        $meilleur = null;
        $ca7j = 0.0;
        foreach ($ctx->produitsFiltres() as $p) {
            if (!$p->coutUtilisable()) {
                continue;
            }
            $ca7j += $p->unitesPrevues7j * (float) $p->prix;
            if ($p->stock !== null && !($p->stock > 0)) {
                continue;
            }
            if ($meilleur === null || (float) $p->tauxMarge() > (float) $meilleur->tauxMarge()) {
                $meilleur = $p;
            }
        }

        return [$meilleur, $ca7j];
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
