<?php

declare(strict_types=1);

namespace App\Core\Compta\Reco;

/**
 * R10 — Objectif hebdomadaire en risque : fusionne les deux meilleures
 * actions déjà validées (règles R1/R4/R5/R6) en une recommandation
 * d'urgence quand la probabilité d'atteindre l'objectif de la semaine
 * est trop faible (forecast['semaine']['prob_atteinte'] < probMin).
 *
 * Classe utilitaire SANS evaluer() : construire() assemble le candidat
 * R10 (gains et prix_effectifs = sommes/fusions des 2 sources) et
 * délègue l'empreinte au callable $fingerprint (typiquement
 * RecoContext::fingerprint).
 */
final class R10Objectif
{
    /** Règle émettrice du candidat construit. */
    private const REGLE = 'R10';

    /** Règles sources — seules les actions tarifaires/promo déjà validées alimentent la priorisation. */
    private const REGLES_ELIGIBLES = ['R1', 'R4', 'R5', 'R6'];

    /** Hypothèses — nombre maximal repris des règles sources (fusion ordonnée, dédupliquée). */
    public const HYPOTHESES_MAX = 4;

    /**
     * Construit le candidat R10, ou null si l'objectif n'est pas en
     * risque (prob_atteinte ≥ $probMin ou semaine absente/illisible) ou
     * si moins de 2 candidats sources éligibles.
     *
     * @param ?array<string,mixed> $semaine forecast['semaine'] (objectif, reel, attendu, lo, hi,
     *                                      prob_atteinte 0..1, manquant_par_jour, requis_par_jour, jours_restants)
     * @param list<array<string,mixed>> $candidats Candidats bruts R1/R4/R5/R6 DÉJÀ VALIDES (GuardRails).
     * @param callable $fingerprint fn(string $regle, list<string> $keys): string.
     * @param float  $probMin       Probabilité d'atteinte minimale en dessous de laquelle l'objectif est « en risque ».
     *
     * @return array<string,mixed>|null
     */
    public static function construire(
        ?array $semaine,
        array $candidats,
        callable $fingerprint,
        float $probMin = Hyper::PROB_OBJECTIF_MIN
    ): ?array {
        if ($semaine === null) {
            return null;
        }
        $prob = $semaine['prob_atteinte'] ?? null;
        if (!is_int($prob) && !is_float($prob)) {
            return null;
        }
        $prob = (float) $prob;
        if ($prob >= $probMin) {
            return null;
        }

        $eligibles = [];
        foreach ($candidats as $c) {
            if (!is_array($c) || !is_string($c['regle'] ?? null) || !in_array($c['regle'], self::REGLES_ELIGIBLES, true)) {
                continue;
            }
            $gain = is_array($c['gain'] ?? null) ? $c['gain'] : [];
            if (!is_numeric($gain['median'] ?? null)) {
                continue;
            }
            $eligibles[] = $c;
        }
        usort($eligibles, static fn (array $a, array $b): int => self::composant($b, 'median') <=> self::composant($a, 'median'));
        if (count($eligibles) < 2) {
            return null;
        }
        [$c1, $c2] = [$eligibles[0], $eligibles[1]];

        $keys = [];
        $produits = [];
        foreach ([$c1, $c2] as $c) {
            foreach ((array) ($c['keys'] ?? []) as $k) {
                if (is_string($k) && $k !== '' && !in_array($k, $keys, true)) {
                    $keys[] = $k;
                }
            }
            foreach ((array) ($c['produits'] ?? []) as $pr) {
                if (is_array($pr) && is_string($pr['key'] ?? null) && !in_array($pr['key'], array_column($produits, 'key'), true)) {
                    $produits[] = ['key' => $pr['key'], 'label' => (string) ($pr['label'] ?? '')];
                }
            }
        }

        $gain = [
            'bas'    => self::composant($c1, 'bas') + self::composant($c2, 'bas'),
            'median' => self::composant($c1, 'median') + self::composant($c2, 'median'),
            'haut'   => self::composant($c1, 'haut') + self::composant($c2, 'haut'),
        ];

        $hypotheseVu = [];
        $hypotheses = [];
        foreach ([$c1, $c2] as $c) {
            foreach ((array) ($c['hypotheses'] ?? []) as $h) {
                if (is_string($h) && $h !== '' && !isset($hypotheseVu[$h])) {
                    $hypotheseVu[$h] = true;
                    $hypotheses[] = $h;
                }
                if (count($hypotheses) >= self::HYPOTHESES_MAX) {
                    break 2;
                }
            }
        }

        $prixVu = [];
        $prixEffectifs = [];
        foreach ([$c1, $c2] as $c) {
            foreach ((array) ($c['prix_effectifs'] ?? []) as $pe) {
                if (!is_array($pe) || !is_string($pe['key'] ?? null) || !is_numeric($pe['prix'] ?? null)) {
                    continue;
                }
                if (!isset($prixVu[$pe['key']])) {
                    $prixVu[$pe['key']] = true;
                    $prixEffectifs[] = ['key' => $pe['key'], 'prix' => (float) $pe['prix']];
                }
            }
        }

        $manque = is_numeric($semaine['manquant_par_jour'] ?? null) ? (float) $semaine['manquant_par_jour'] : 0.0;
        $jours = is_numeric($semaine['jours_restants'] ?? null) ? (int) $semaine['jours_restants'] : 0;
        $titre1 = (string) ($c1['titre'] ?? '');
        $titre2 = (string) ($c2['titre'] ?? '');

        return [
            'regle'           => self::REGLE,
            'keys'            => $keys,
            'produits'        => $produits,
            'titre'           => 'Objectif hebdo en risque',
            'pourquoi'        => 'Probabilité d\'atteinte : ' . self::pct($prob) . ' % · manque ' . self::eur($manque) . ' €/jour',
            'action'          => 'Il manque ~' . self::eur($manque) . ' €/jour sur ' . $jours . ' jours restants ; prioriser : '
                . $titre1 . ' puis ' . $titre2,
            'gain'            => $gain,
            'confiance'       => 'moyenne',
            'effort'          => Hyper::EFFORTS['R10'],
            'hypotheses'      => $hypotheses,
            'comment_mesurer' => 'Probabilité d\'atteindre l\'objectif en fin de semaine',
            'prix_effectifs'  => $prixEffectifs,
            'fingerprint'     => $fingerprint(self::REGLE, $keys),
        ];
    }

    /**
     * Composante numérique d'un gain source (« bas » | « median » | « haut »), 0.0 si absente.
     *
     * @param array<string,mixed> $c
     */
    private static function composant(array $c, string $composante): float
    {
        $gain = is_array($c['gain'] ?? null) ? $c['gain'] : [];

        return is_numeric($gain[$composante] ?? null) ? (float) $gain[$composante] : 0.0;
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
