<?php

declare(strict_types=1);

namespace App\Core\Compta\Reco;

/**
 * Score et ordonnancement des candidats de recommandations (statique,
 * pur) : score = gain médian × facteur de confiance ÷ effort, puis
 * classement tenant compte de l'historique (actifs, refus récents),
 * du cap R11 et du dédoublonnage par produit.
 */
final class Scoring
{
    /**
     * Score d'un candidat : gain['median'] × CONFIANCE_FACTEURS[confiance]
     * ÷ effort. Confiance inconnue → facteur 0 (score nul) ; effort
     * invalidé à ≥ 1.
     *
     * @param array<string,mixed> $candidat
     */
    public static function score(array $candidat): float
    {
        $gain = is_array($candidat['gain'] ?? null) ? $candidat['gain'] : [];
        $median = (float) ($gain['median'] ?? 0.0);
        $confiance = is_string($candidat['confiance'] ?? null) ? $candidat['confiance'] : '';
        $facteur = (float) (Hyper::CONFIANCE_FACTEURS[$confiance] ?? 0.0);
        $effort = max(1, is_int($candidat['effort'] ?? null) ? $candidat['effort'] : 1);

        return $median * $facteur / $effort;
    }

    /**
     * Ordonne les candidats retenus :
     *  1) exclut ceux dont fingerprint ∈ historique['actifs'] ou ∈
     *     historique['refus'] avec date_refus + REFUS_JOURS > now
     *     (date 'Y-m-d') ;
     *  2) tri : R11 d'abord (cap R11_MAX candidats R11), puis score
     *     décroissant, égalités par numéro de règle croissant ;
     *  3) dédoublonnage par produit : un produit (key) n'apparaît que
     *     dans la première reco retenue qui le contient (les clés déjà
     *     prises sont retirées du candidat ; coupé si plus rien d'unique) ;
     *  4) cap MAX_ACTIVES au total.
     *
     * @param list<array<string,mixed>> $candidats
     * @param array{actifs:list<string>,refus:list<array{fingerprint:string,date:string}>,appliquees:list<array{fingerprint:string,produits:list<string>}>} $historique
     *
     * @return list<array<string,mixed>>
     */
    public static function classe(array $candidats, array $historique, \DateTimeImmutable $now): array
    {
        // 1) Historique : actifs bloquent, refus récents bloquent.
        $actifs = [];
        foreach ($historique['actifs'] ?? [] as $fp) {
            if (is_string($fp)) {
                $actifs[$fp] = true;
            }
        }
        $refus = [];
        foreach ($historique['refus'] ?? [] as $r) {
            $fp = is_array($r) ? ($r['fingerprint'] ?? null) : null;
            $date = is_array($r) ? ($r['date'] ?? null) : null;
            if (!is_string($fp) || !is_string($date)) {
                continue;
            }
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
            if ($dt === false) {
                continue;
            }
            $refus[$fp] = $dt->modify('+' . Hyper::REFUS_JOURS . ' days');
        }

        $restants = [];
        foreach ($candidats as $c) {
            $fp = is_string($c['fingerprint'] ?? null) ? $c['fingerprint'] : null;
            if ($fp !== null) {
                if (isset($actifs[$fp]) || (isset($refus[$fp]) && $refus[$fp] > $now)) {
                    continue;
                }
            }
            $restants[] = $c;
        }

        // 2) Tri : R11 en tête (cap R11_MAX), puis score desc, règle croissante.
        $cmp = static function (array $a, array $b): int {
            $s = self::score($b) <=> self::score($a);
            if ($s !== 0) {
                return $s;
            }

            return self::numeroRegle(is_string($a['regle'] ?? null) ? $a['regle'] : '')
                <=> self::numeroRegle(is_string($b['regle'] ?? null) ? $b['regle'] : '');
        };
        $r11 = [];
        $autres = [];
        foreach ($restants as $c) {
            if (($c['regle'] ?? null) === 'R11') {
                $r11[] = $c;
            } else {
                $autres[] = $c;
            }
        }
        usort($r11, $cmp);
        usort($autres, $cmp);
        $restants = array_merge(array_slice($r11, 0, Hyper::R11_MAX), $autres);

        // 3) Dédoublonnage par produit (on retire les clés déjà prises).
        $utilises = [];
        $retenus = [];
        foreach ($restants as $c) {
            $keys = is_array($c['keys'] ?? null) ? array_values(array_filter($c['keys'], 'is_string')) : [];
            $uniques = array_values(array_diff($keys, array_keys($utilises)));
            if ($uniques === []) {
                continue;
            }
            if (count($uniques) !== count($keys)) {
                $c['keys'] = $uniques;
                if (isset($c['produits']) && is_array($c['produits'])) {
                    $c['produits'] = array_values(array_filter(
                        $c['produits'],
                        static fn ($pr): bool => is_array($pr) && in_array($pr['key'] ?? null, $uniques, true)
                    ));
                }
            }
            foreach ($uniques as $k) {
                $utilises[$k] = true;
            }
            $retenus[] = $c;
        }

        // 4) Cap global.
        return array_slice($retenus, 0, Hyper::MAX_ACTIVES);
    }

    /** Numéro de règle ('R11' → 11) pour le départage ; inconnu → très grand. */
    private static function numeroRegle(string $regle): int
    {
        return preg_match('/^R(\d+)$/', $regle, $m) === 1 ? (int) $m[1] : PHP_INT_MAX;
    }
}
