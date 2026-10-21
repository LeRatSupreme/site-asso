<?php

declare(strict_types=1);

namespace App\Core\Compta\Forecast;

/**
 * Périodes creuses saisies À LA MAIN (calendrier non apprenable avec
 * moins de ~12 semaines de données : vacances scolaires, Toussaint…).
 *
 * Format du setting « analytics_periode_creuse » :
 *
 *     facteur;AAAA-MM-JJ/AAAA-MM-JJ;AAAA-MM-JJ/AAAA-MM-JJ;…
 *     ex. 0.8;2026-10-17/2026-11-02
 *
 * Le facteur (∈ [0,1 ; 1]) multiplie les prévisions (expectedN/expectedCa
 * → tirages MC et intervalles suivent) pour les dates DANS une plage ;
 * l'observé (« hier », réel du jour) n'est jamais ajusté. 10 plages max.
 * Le moteur désaisonnalise aussi À L'ENTRÉE les jours passés en plage
 * (ca, n, hourlyN ÷ facteur) avant l'apprentissage : le facteur ne compte
 * l'effet creuse qu'une seule fois. Toutes les méthodes sont statiques
 * et pures.
 */
final class SlowPeriods
{
    /** Nombre maximal de plages saisies. */
    public const MAX_RANGES = 10;

    /** Borne basse du facteur de réduction. */
    public const FACTEUR_MIN = 0.1;

    /** Borne haute du facteur de réduction (1 = pas d'ajustement). */
    public const FACTEUR_MAX = 1.0;

    /**
     * Analyse la saisie ; null si invalide (facteur hors bornes, date
     * inexistante, début > fin, plus de 10 plages, fragment mal formé).
     * Chaîne vide → config neutre (facteur 1, aucune plage).
     *
     * @return ?array{facteur:float,ranges:list<array{start:string,end:string}>}
     */
    public static function parse(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['facteur' => 1.0, 'ranges' => []];
        }

        $tokens = array_map(static fn (string $t): string => trim($t), explode(';', $raw));
        $facteurNorm = str_replace([' ', ','], ['', '.'], $tokens[0]);
        if ($facteurNorm === '' || !is_numeric($facteurNorm)) {
            return null;
        }
        $facteur = (float) $facteurNorm;
        if ($facteur < self::FACTEUR_MIN || $facteur > self::FACTEUR_MAX) {
            return null;
        }

        $plages = array_slice($tokens, 1);
        if ($plages === [] || count($plages) > self::MAX_RANGES) {
            return null;
        }

        $ranges = [];
        foreach ($plages as $plage) {
            if (preg_match('/^(\d{4}-\d{2}-\d{2})\/(\d{4}-\d{2}-\d{2})$/', $plage, $m) !== 1) {
                return null;
            }
            if (!self::dateValide($m[1]) || !self::dateValide($m[2]) || $m[1] > $m[2]) {
                return null;
            }
            $ranges[] = ['start' => $m[1], 'end' => $m[2]];
        }

        return ['facteur' => $facteur, 'ranges' => $ranges];
    }

    /**
     * Facteur applicable à une date (1.0 hors période creuse) ; bornes
     * de plage INCLUSIVES.
     *
     * @param ?array{facteur:float,ranges:list<array{start:string,end:string}>} $slow
     */
    public static function facteurPour(?array $slow, \DateTimeImmutable $d): float
    {
        if ($slow === null || (float) ($slow['facteur'] ?? 1.0) >= 1.0) {
            return 1.0;
        }
        $key = $d->format('Y-m-d');
        foreach ((array) ($slow['ranges'] ?? []) as $r) {
            if ($key >= (string) $r['start'] && $key <= (string) $r['end']) {
                return (float) $slow['facteur'];
            }
        }

        return 1.0;
    }

    /**
     * Plages intersectant [debut, fin] (utile : note moteur sur
     * [aujourd'hui, +30 j] et affichage de la plage active en vue).
     *
     * @param ?array{facteur:float,ranges:list<array{start:string,end:string}>} $slow
     * @return list<array{start:string,end:string}>
     */
    public static function plagesActives(?array $slow, \DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        if ($slow === null) {
            return [];
        }
        $d1 = $debut->format('Y-m-d');
        $d2 = $fin->format('Y-m-d');
        $out = [];
        foreach ((array) ($slow['ranges'] ?? []) as $r) {
            if ((string) $r['end'] >= $d1 && (string) $r['start'] <= $d2) {
                $out[] = ['start' => (string) $r['start'], 'end' => (string) $r['end']];
            }
        }

        return $out;
    }

    /**
     * Libellé court d'une plage pour la vue : « 17/10 → 02/11 (×0,8) ».
     *
     * @param array{start:string,end:string} $range
     */
    public static function libellePlage(array $range, float $facteur): string
    {
        $fmt = static fn (string $k): string => (new \DateTimeImmutable($k))->format('d/m');

        return $fmt((string) $range['start']) . ' → ' . $fmt((string) $range['end'])
            . ' (×' . self::facteurFr($facteur) . ')';
    }

    /**
     * Forme canonique stockée en base : « 0.8;2026-10-17/2026-11-02;… ».
     *
     * @param array{facteur:float,ranges:list<array{start:string,end:string}>} $slow
     */
    public static function normalise(array $slow): string
    {
        $parts = [rtrim(rtrim(number_format((float) $slow['facteur'], 2, '.', ''), '0'), '.')];
        foreach ((array) ($slow['ranges'] ?? []) as $r) {
            $parts[] = $r['start'] . '/' . $r['end'];
        }

        return implode(';', $parts);
    }

    /** Facteur au format FR sans zéros inutiles : 0,8 / 0,85 / 1. */
    public static function facteurFr(float $facteur): string
    {
        return rtrim(rtrim(number_format($facteur, 2, ',', ' '), '0'), ',');
    }

    /** Date 'Y-m-d' strictement valide (pas de roulis de calendrier). */
    private static function dateValide(string $key): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $key);

        return $d !== false && $d->format('Y-m-d') === $key;
    }
}
