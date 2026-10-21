<?php

declare(strict_types=1);

namespace App\Core\Compta\Reco;

/**
 * R5 — Duos / lots : vendre deux produits ensemble avec une remise
 * modérée sur le total, sans jamais casser le plancher de marge
 * (garde-fou 1). UN seul candidat au maximum.
 *
 * Deux chemins :
 *  (a) ANALYSE DES ASSOCIATIONS (si ≥ « reco_assoc_paniers_min » (30)
 *      paniers multi-articles) : pour chaque paire de produits
 *      co-occurrents — support = co-occurrences ÷ nPaniers(), confiance
 *      = co ÷ paniers(a), lift = confiance ÷ P(b). Paires toujours
 *      soumises aux contraintes : deux produits à coût utilisable de
 *      catégories DIFFÉRENTES, lift > 1,0 strict (anti-association :
 *      une paire dont le lift ≤ 1 n'est jamais retenue). Deux niveaux
 *      de portes :
 *       - FORTE : lift > « reco_assoc_lift_min » (1,2) ET support ≥ «
 *         reco_assoc_support_min » (0,02) ET confiance ≥ «
 *         reco_assoc_conf_min » (0,05) → confiance « moyenne » ;
 *       - VARIANTE (sinon) : lift > « reco_r5_lift_variante_min »
 *         (1,0) ET support ≥ « reco_r5_support_variante_min » (0,005)
 *         ET confiance ≥ « reco_r5_conf_variante_min » (0,03) →
 *         confiance « faible » + hypothèse « Association rare mais
 *         cohérente : hypothèse ».
 *      Choix : meilleure FORTE par lift décroissant (départages :
 *      support, confiance, clés), SINON meilleure VARIANTE — une FORTE
 *      est toujours préférée à une VARIANTE ; a = accroche = plus fort
 *      volume désaisonnalisé des deux, b = cible.
 *  (b) HEURISTIQUE (sinon) : accroche = produit à coût utilisable de
 *      plus fort volume ; cible = produit à coût utilisable de plus
 *      fort taux de marge, de stock non géré ou > STOCK_CIBLE_MIN, et
 *      de catégorie différente de l'accroche ; introuvable → rien.
 *      Confiance « faible » : conversion estimée = hypothèse.
 *
 * Prix duo : rMax = 1 − (ca+cb) ÷ ((1+marge_min)×(pa+pb)) ; r =
 * min(« reco_duo_remise_max » (0,10), rMax, plancher par produit) ;
 * rMax ≤ 0, r ≤ 0 ou marge duo ≤ 0 → rien (le plancher par produit
 * rPlancher = min(1 − ci×(1+marge_min)/pi) garantit le garde-fou 1 sur
 * CHACUN des deux prix_effectifs).
 *
 * Gain hebdomadaire (€) : margeDuoUnitaire = (pa+pb)×(1−r) − ca − cb ;
 * (a) CONVERSION_ANALYSE × paniers(a sans b)/semaine × marge duo ;
 * (b) CONVERSION_HEURISTIQUE × unités prévues (accroche) × marge duo ;
 * bas = 0,5 × médian, haut = 1,5 × médian.
 */
final class R5Duos implements RecoRule
{
    /** Semaines par mois — conversion des paniers observés en rythme hebdomadaire. */
    public const SEMAINES_PAR_MOIS = 4.33;

    /** Fraction — paniers « accroche sans cible » supposés convertis au duo (analyse, hypothèse). */
    public const CONVERSION_ANALYSE = 0.25;

    /** Fraction — unités prévues de l'accroche supposées converties au duo (heuristique, hypothèse). */
    public const CONVERSION_HEURISTIQUE = 0.15;

    /** Unités — stock minimal de la cible en heuristique (au-delà, réserve insuffisante). */
    public const STOCK_CIBLE_MIN = 10;

    /** Sans unité — lift strictement supérieur requis (anti-association : lift ≤ 1 = indépendance). */
    public const LIFT_ANTI_ASSOCIATION = 1.0;

    /** Facteur — borne basse du gain = 0,5 × gain médian. */
    public const FACTEUR_BAS = 0.5;

    /** Facteur — borne haute du gain = 1,5 × gain médian. */
    public const FACTEUR_HAUT = 1.5;

    public static function regle(): string
    {
        return 'R5';
    }

    /**
     * Candidat R5 (0 ou 1) : duo promo accroche + cible.
     *
     * @return list<array<string,mixed>>
     */
    public static function evaluer(RecoContext $ctx): array
    {
        $paniersMin = (int) $ctx->param('assoc_paniers_min', Hyper::ASSOC_PANIERS_MIN);

        return count($ctx->paniersMulti()) >= $paniersMin
            ? self::parAssociations($ctx)
            : self::parHeuristique($ctx);
    }

    /**
     * Chemin (a) : meilleure paire FORTE, sinon meilleure VARIANTE.
     *
     * @return list<array<string,mixed>>
     */
    private static function parAssociations(RecoContext $ctx): array
    {
        $n = $ctx->nPaniers();
        if ($n === 0) {
            return [];
        }
        $liftMin = (float) $ctx->param('assoc_lift_min', Hyper::ASSOC_LIFT_MIN);
        $supportMin = (float) $ctx->param('assoc_support_min', Hyper::ASSOC_SUPPORT_MIN);
        $confMin = (float) $ctx->param('assoc_conf_min', Hyper::ASSOC_CONF_MIN);
        $liftVarMin = (float) $ctx->param('r5_lift_variante_min', Hyper::R5_LIFT_VARIANTE_MIN);
        $supportVarMin = (float) $ctx->param('r5_support_variante_min', Hyper::R5_SUPPORT_VARIANTE_MIN);
        $confVarMin = (float) $ctx->param('r5_conf_variante_min', Hyper::R5_CONF_VARIANTE_MIN);

        // Produits ciblables : filtrés et à coût utilisable (le duo est tarifaire, garde-fou 2).
        $eligibles = [];
        foreach ($ctx->produitsFiltres() as $p) {
            if ($p->coutUtilisable()) {
                $eligibles[$p->key] = $p;
            }
        }

        $nbPaniers = [];
        $co = [];
        foreach ($ctx->dataset()->paniers as $b) {
            $cles = [];
            foreach ($b->lignes as $ligne) {
                $k = $ligne['key'] ?? null;
                if (is_string($k) && isset($eligibles[$k])) {
                    $cles[$k] = true;
                }
            }
            $cles = array_keys($cles);
            sort($cles);
            foreach ($cles as $k) {
                $nbPaniers[$k] = ($nbPaniers[$k] ?? 0) + 1;
            }
            $m = count($cles);
            for ($i = 0; $i < $m; ++$i) {
                for ($j = $i + 1; $j < $m; ++$j) {
                    $paire = $cles[$i] . '|' . $cles[$j];
                    $co[$paire] = ($co[$paire] ?? 0) + 1;
                }
            }
        }

        $fortes = [];
        $variantes = [];
        foreach ($co as $paire => $nb) {
            [$a, $b] = explode('|', $paire);
            $na = $nbPaniers[$a] ?? 0;
            $nbB = $nbPaniers[$b] ?? 0;
            if ($na === 0 || $nbB === 0) {
                continue;
            }
            if ($eligibles[$a]->category === $eligibles[$b]->category) {
                continue; // Duo = deux catégories différentes, toujours.
            }
            $support = $nb / $n;
            $conf = $nb / $na;
            $lift = $conf / ($nbB / $n);
            if ($lift > $liftMin && $support >= $supportMin && $conf >= $confMin) {
                $fortes[] = ['a' => $a, 'b' => $b, 'support' => $support, 'conf' => $conf, 'lift' => $lift, 'co' => $nb];
            } elseif ($lift > self::LIFT_ANTI_ASSOCIATION
                && $lift > $liftVarMin
                && $support >= $supportVarMin
                && $conf >= $confVarMin) {
                $variantes[] = ['a' => $a, 'b' => $b, 'support' => $support, 'conf' => $conf, 'lift' => $lift, 'co' => $nb];
            }
        }
        $fortes = self::trierPaires($fortes);
        $variantes = self::trierPaires($variantes);
        if ($fortes === [] && $variantes === []) {
            return [];
        }

        $niveau = $fortes !== [] ? 'forte' : 'variante';
        $meilleure = ($niveau === 'forte' ? $fortes : $variantes)[0];
        $aKey = $meilleure['a'];
        $bKey = $meilleure['b'];
        if ($ctx->qtyDesaison($bKey) > $ctx->qtyDesaison($aKey)) {
            // L'accroche est le plus fort volume des deux.
            $t = $aKey;
            $aKey = $bKey;
            $bKey = $t;
        }
        $stats = [
            'na'      => (float) ($nbPaniers[$aKey] ?? 0),
            'co'      => (float) $meilleure['co'],
            'support' => $meilleure['support'],
            'lift'    => $meilleure['lift'],
        ];
        $c = self::candidatDuo($ctx, $eligibles[$aKey], $eligibles[$bKey], $stats, $niveau);

        return $c !== null ? [$c] : [];
    }

    /**
     * Trie des paires : lift décroissant, départages support, confiance, clés (déterministe).
     *
     * @param list<array<string,mixed>> $paires
     *
     * @return list<array<string,mixed>>
     */
    private static function trierPaires(array $paires): array
    {
        usort($paires, static function (array $x, array $y): int {
            foreach (['lift', 'support', 'conf'] as $crit) {
                if ($x[$crit] != $y[$crit]) {
                    return $y[$crit] <=> $x[$crit];
                }
            }

            return [$x['a'], $x['b']] <=> [$y['a'], $y['b']];
        });

        return $paires;
    }

    /**
     * Chemin (b) : heuristique accroche (fort volume) + cible (forte marge).
     *
     * @return list<array<string,mixed>>
     */
    private static function parHeuristique(RecoContext $ctx): array
    {
        $accroche = null;
        foreach ($ctx->produitsFiltres() as $p) {
            if (!$p->coutUtilisable()) {
                continue;
            }
            if ($accroche === null || $ctx->qtyDesaison($p->key) > $ctx->qtyDesaison($accroche->key)) {
                $accroche = $p;
            }
        }
        if ($accroche === null) {
            return [];
        }

        $cible = null;
        foreach ($ctx->produitsFiltres() as $p) {
            if (!$p->coutUtilisable() || $p->key === $accroche->key) {
                continue;
            }
            if (!($p->stock === null || $p->stock > self::STOCK_CIBLE_MIN)) {
                continue;
            }
            if ($p->category === $accroche->category) {
                continue;
            }
            if ($cible === null || $p->tauxMarge() > $cible->tauxMarge()) {
                $cible = $p;
            }
        }
        if ($cible === null) {
            return [];
        }

        $c = self::candidatDuo($ctx, $accroche, $cible, null, 'heuristique');

        return $c !== null ? [$c] : [];
    }

    /**
     * Candidat duo commun aux trois niveaux, ou null si aucune remise
     * saine n'est possible (garde-fous 1 et 4).
     *
     * @param array{na:float,co:float,support:float,lift:float}|null $stats Statistiques d'association (chemin a), null en heuristique.
     * @param string $niveau 'forte' | 'variante' | 'heuristique'.
     *
     * @return array<string,mixed>|null
     */
    private static function candidatDuo(RecoContext $ctx, RecoProduct $accroche, RecoProduct $cible, ?array $stats, string $niveau): ?array
    {
        $pa = (float) $accroche->prix;
        $ca = (float) $accroche->cout;
        $pb = (float) $cible->prix;
        $cb = (float) $cible->cout;
        $margeMin = Hyper::MARGE_MIN;
        $total = $pa + $pb;
        $coutTotal = $ca + $cb;

        $rMax = 1.0 - $coutTotal / ((1.0 + $margeMin) * $total);
        if (!($rMax > 0.0)) {
            return null; // Marge duo impossible même au plancher.
        }
        $r = min((float) $ctx->param('duo_remise_max', Hyper::DUO_REMISE_MAX), $rMax);
        // Garde-fou 1 par produit : chaque prix duo ≥ coût × (1 + marge_min).
        $rPlancher = min(1.0 - $ca * (1.0 + $margeMin) / $pa, 1.0 - $cb * (1.0 + $margeMin) / $pb);
        $r = min($r, $rPlancher);
        if (!($r > 0.0)) {
            return null; // Aucune remise ne respecte le plancher : pas de duo.
        }

        $margeDuo = $total * (1.0 - $r) - $coutTotal;
        if (!($margeDuo > 0.0)) {
            return null; // Un duo qui détruit la marge n'est pas une recommandation.
        }
        $prixAccroche = $pa * (1.0 - $r);
        $prixCible = $pb * (1.0 - $r);
        $margeDuoPct = $margeDuo / ($total * (1.0 - $r));
        $margeSeparePct = $coutTotal / $total;

        $conversion = 'Conversion de ' . self::pct(self::CONVERSION_ANALYSE, 0)
            . ' des paniers « accroche sans cible » vers le duo (hypothèse)';
        if ($stats !== null) {
            $sansB = max($stats['na'] - $stats['co'], 0.0);
            $median = self::CONVERSION_ANALYSE * ($sansB / self::SEMAINES_PAR_MOIS) * $margeDuo;
            $pourquoi = 'Présents ensemble dans ' . self::pct($stats['support']) . ' % des paniers · lift ' . self::nombre($stats['lift'], 2)
                . ' · marge duo ' . self::pct($margeDuoPct) . ' % vs ' . self::pct($margeSeparePct) . ' % vendus séparément';
            if ($niveau === 'forte') {
                $confiance = 'moyenne';
                $hypotheses = [$conversion];
            } else {
                $confiance = 'faible';
                $hypotheses = ['Association rare mais cohérente : hypothèse', $conversion];
            }
        } else {
            $median = self::CONVERSION_HEURISTIQUE * $accroche->unitesPrevues7j * $margeDuo;
            $pourquoi = 'Marge duo ' . self::pct($margeDuoPct) . ' % vs ' . self::pct($margeSeparePct) . ' % vendus séparément';
            $confiance = 'faible';
            $hypotheses = [
                'Conversion de ' . self::pct(self::CONVERSION_HEURISTIQUE, 0) . ' des unités prévues de l\'accroche vers le duo (hypothèse, peu de paniers observés)',
            ];
        }
        $gain = GuardRails::fourchette($median * self::FACTEUR_BAS, $median, $median * self::FACTEUR_HAUT);

        return [
            'regle'           => self::regle(),
            'keys'            => [$accroche->key, $cible->key],
            'produits'        => [
                ['key' => $accroche->key, 'label' => $accroche->label],
                ['key' => $cible->key, 'label' => $cible->label],
            ],
            'titre'           => 'Duo promo : ' . $accroche->label . ' + ' . $cible->label,
            'pourquoi'        => $pourquoi,
            'action'          => 'Proposez le duo ' . $accroche->label . ' + ' . $cible->label . ' à ' . self::eur($total * (1.0 - $r))
                . ' € au lieu de ' . self::eur($total) . ' € (-' . self::pct($r) . ' %) — marge duo ' . self::eur($margeDuo)
                . ' € contre ' . self::eur($pb - $cb) . ' € pour ' . $cible->label . ' seul. Affichez-le en caisse pendant 2 semaines.',
            'gain'            => $gain,
            'confiance'       => $confiance,
            'effort'          => Hyper::EFFORTS['R5'],
            'hypotheses'      => $hypotheses,
            'comment_mesurer' => 'Nombre de duos vendus et marge totale pendant 14 jours vs baseline 30 j',
            'prix_effectifs'  => [
                ['key' => $accroche->key, 'prix' => $prixAccroche],
                ['key' => $cible->key, 'prix' => $prixCible],
            ],
        ];
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

    /** Montant en € (2 décimales, virgule décimale). */
    private static function eur(float $x): string
    {
        return number_format($x, 2, ',', '');
    }
}
