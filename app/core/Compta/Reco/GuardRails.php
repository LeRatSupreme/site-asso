<?php

declare(strict_types=1);

namespace App\Core\Compta\Reco;

/**
 * Garde-fous du moteur de recommandations (statiques, purs) :
 *  1. PRIX PLANCHER — tout prix unitaire proposé ≥ coût × (1 + MARGE_MIN)
 *     (jamais vendre à perte de marge cible) ;
 *  2. COÛT SUSPECT — un produit à coutRaison non nul ne peut être ciblé
 *     ni par les règles tarifaires R1/R3/R5/R6/R7, ni par une reco qui
 *     fixe des prix_effectifs ;
 *  3. EXISTENCE & CATÉGORIE — chaque clé ciblée doit exister dans le
 *     catalogue et hors catégories exclues (Événement, Divers) ;
 *  4. FOURCHETTE DE GAIN — bas ≥ 0 et bas ≤ median ≤ haut (gain crédible) ;
 *  5. CONFIANCE CONNUE — 'faible' | 'moyenne' | 'haute' (CONFIANCE_FACTEURS) ;
 *  6. EFFORT BORNÉ — effort entier dans [1, 3].
 */
final class GuardRails
{
    /** € (tolérance absolue) — fenêtre de comparaison flottante au plancher. */
    private const EPS = 1.0e-9;

    /** Règles — interdites sur les produits à coût suspect (garde-fou 2). */
    private const REGLES_INTERDITES_COUT_SUSPECT = ['R1', 'R3', 'R5', 'R6', 'R7'];

    /**
     * Garde-fou 1 : prix plancher = coût × (1 + margeMin).
     */
    public static function prixPlancher(float $cout, float $margeMin): float
    {
        return $cout * (1.0 + $margeMin);
    }

    /**
     * Normalise une fourchette : bas clampé ≥ 0 ; si l'ordre
     * bas ≤ median ≤ haut n'est pas respecté après clamp, trier.
     *
     * @return array{bas:float,median:float,haut:float}
     */
    public static function fourchette(float $bas, float $median, float $haut): array
    {
        $bas = max(0.0, $bas);
        if (!($bas <= $median && $median <= $haut)) {
            $t = [$bas, $median, $haut];
            sort($t);
            [$bas, $median, $haut] = $t;
        }

        return ['bas' => $bas, 'median' => $median, 'haut' => $haut];
    }

    /**
     * Valide un candidat brut produit par une règle (6 garde-fous du
     * docblock de classe). $candidat attend : regle, keys (list<string>),
     * titre, pourquoi, action, gain (array bas/median/haut), confiance,
     * hypotheses, comment_mesurer, effort, produits — et optionnellement
     * prix_effectifs (list<array{key:string,prix:float}>, prix unitaires
     * finaux proposés).
     *
     * @param array<string,mixed>       $candidat
     * @param array<string,RecoProduct> $produitsParCle
     */
    public static function candidatValide(array $candidat, array $produitsParCle, float $margeMin): bool
    {
        $keys = $candidat['keys'] ?? null;
        if (!is_array($keys) || $keys === []) {
            return false;
        }

        $prixEffectifs = null;
        if (isset($candidat['prix_effectifs'])) {
            if (!is_array($candidat['prix_effectifs'])) {
                return false;
            }
            $prixEffectifs = [];
            foreach ($candidat['prix_effectifs'] as $pe) {
                if (!is_array($pe) || !is_string($pe['key'] ?? null) || !is_numeric($pe['prix'] ?? null)) {
                    return false;
                }
                $prixEffectifs[$pe['key']] = (float) $pe['prix'];
            }
        }

        // Garde-fou 3 : clés existantes et hors catégories exclues.
        foreach ($keys as $k) {
            if (!is_string($k)) {
                return false;
            }
            $p = $produitsParCle[$k] ?? null;
            if ($p === null || in_array($p->category, Hyper::CATEGORIES_EXCLUES, true)) {
                return false;
            }
        }

        // Garde-fou 1 : chaque prix proposé ≥ plancher (tolérance EPS).
        if ($prixEffectifs !== null) {
            foreach ($prixEffectifs as $k => $prix) {
                $p = $produitsParCle[$k] ?? null;
                if ($p === null || $p->cout === null || $p->cout <= 0.0) {
                    return false;
                }
                if ($prix < self::prixPlancher($p->cout, $margeMin) - self::EPS) {
                    return false;
                }
            }
        }

        // Garde-fou 2 : coût suspect interdit en tarifaire / avec prix_effectifs.
        $regle = is_string($candidat['regle'] ?? null) ? $candidat['regle'] : '';
        foreach ($keys as $k) {
            $p = $produitsParCle[$k];
            if ($p->coutRaison !== null
                && ($prixEffectifs !== null || in_array($regle, self::REGLES_INTERDITES_COUT_SUSPECT, true))) {
                return false;
            }
        }

        // Garde-fou 4 : fourchette de gain crédible (bas ≥ 0, ordre respecté).
        $gain = $candidat['gain'] ?? null;
        if (!is_array($gain)) {
            return false;
        }
        $b = $gain['bas'] ?? null;
        $m = $gain['median'] ?? null;
        $h = $gain['haut'] ?? null;
        if (!is_numeric($b) || !is_numeric($m) || !is_numeric($h)) {
            return false;
        }
        $b = (float) $b;
        $m = (float) $m;
        $h = (float) $h;
        if ($b < 0.0 || !($b <= $m && $m <= $h)) {
            return false;
        }

        // Garde-fou 5 : confiance connue.
        $confiance = $candidat['confiance'] ?? null;
        if (!is_string($confiance) || !array_key_exists($confiance, Hyper::CONFIANCE_FACTEURS)) {
            return false;
        }

        // Garde-fou 6 : effort entier borné [1, 3].
        $effort = $candidat['effort'] ?? null;
        if (!is_int($effort) || $effort < 1 || $effort > 3) {
            return false;
        }

        return true;
    }
}
