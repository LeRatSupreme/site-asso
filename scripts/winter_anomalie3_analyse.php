<?php

declare(strict_types=1);

/**
 * AEIC — Mission WINTER, point 3 (LECTURE SEULE, aucun écrit).
 * Analyse d'opportunité : lots dégénérés (valid_from > valid_to) et
 * chevauchements de périodes restants, par clé, avec impact marge estimé.
 *
 * Impact marge = ΔCOGS rétrospectif si la chaîne était normalisée
 * (normalizePeriods : chaque lot non dernier clôturé au plus tard la
 * veille du suivant ; le dernier reste tel quel — les dégénérés non
 * derniers deviennent des trous d'un jour). On recalcule le coût as-of
 * de CHAQUE vente (is_custom_amount = 0) avant/après : la somme des
 * écarts × quantités = distortion marge historique. Le coût courant
 * (aujourd'hui) avant/après est aussi comparé (impact stock valorisé).
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/database.php';

use App\Models\ProductCost;

$pdo = db();

// Toutes les chaînes de lots, ordre chronologique de normalisation.
$lotsByKey = [];
foreach ($pdo->query('SELECT * FROM product_costs ORDER BY product_key, valid_from, created_at, id') as $l) {
    $lotsByKey[(string) $l['product_key']][] = $l;
}

/** Règle as-of de ProductCost::lotAt sur une chaîne locale. */
function lotAtLocal(array $lots, string $day): ?array
{
    // 1) lot couvrant la date, le plus récent (valid_from DESC, created_at DESC, id DESC)
    $cand = array_values(array_filter($lots, static fn (array $l): bool =>
        substr((string) $l['valid_from'], 0, 10) <= $day
        && ($l['valid_to'] === null || $day <= substr((string) $l['valid_to'], 0, 10))));
    if ($cand !== []) {
        usort($cand, static fn (array $a, array $b): int => [
            substr((string) $b['valid_from'], 0, 10), (string) $b['created_at'], (string) $b['id'],
        ] <=> [substr((string) $a['valid_from'], 0, 10), (string) $a['created_at'], (string) $a['id']]);
        return $cand[0];
    }
    // 2) lot précédent le plus récent
    $cand = array_values(array_filter($lots, static fn (array $l): bool =>
        substr((string) $l['valid_from'], 0, 10) <= $day));
    if ($cand === []) {
        return null;
    }
    usort($cand, static fn (array $a, array $b): int => [
        substr((string) $b['valid_from'], 0, 10), (string) $b['created_at'], (string) $b['id'],
    ] <=> [substr((string) $a['valid_from'], 0, 10), (string) $a['created_at'], (string) $a['id']]);
    return $cand[0];
}

/** Simule normalizePeriods sur une copie de chaîne (aucun écrit). */
function normalizedChain(array $lots): array
{
    $n = count($lots);
    for ($i = 0; $i < $n - 1; $i++) {
        $from = substr((string) $lots[$i]['valid_from'], 0, 10);
        $currentTo = $lots[$i]['valid_to'] === null ? null : substr((string) $lots[$i]['valid_to'], 0, 10);
        $nextFrom = substr((string) $lots[$i + 1]['valid_from'], 0, 10);
        if ($currentTo !== null && $currentTo < $nextFrom) {
            continue; // trou volontaire : conservé
        }
        $target = date('Y-m-d', strtotime($nextFrom . ' -1 day'));
        if ($currentTo !== null && $target < $from) {
            $target = $from;
        }
        if ($target !== $currentTo) {
            $lots[$i]['valid_to'] = $target;
        }
    }

    return $lots;
}

$rows = [];
foreach ($lotsByKey as $key => $lots) {
    $deg = 0;
    $overlap = 0;
    $n = count($lots);
    for ($i = 0; $i < $n; $i++) {
        $to = $lots[$i]['valid_to'] === null ? null : substr((string) $lots[$i]['valid_to'], 0, 10);
        $from = substr((string) $lots[$i]['valid_from'], 0, 10);
        if ($to !== null && $to < $from) {
            $deg++;
        }
        if ($i < $n - 1) {
            $nextFrom = substr((string) $lots[$i + 1]['valid_from'], 0, 10);
            if ($to === null || $to >= $nextFrom) {
                $overlap++;
            }
        }
    }
    if ($deg === 0 && $overlap === 0) {
        continue;
    }

    // Ventes de la clé (clé OU description), ΔCOGS avant/après normalisation.
    $st = $pdo->prepare('SELECT sold_at, quantity FROM sales WHERE (product_key = ? OR description = ?) AND is_custom_amount = 0');
    $st->execute([$key, $key]);
    $dCogs = 0.0;
    $nSales = 0;
    $qty30 = 0;
    $norm = normalizedChain($lots);
    foreach ($st->fetchAll() as $s) {
        $day = substr((string) $s['sold_at'], 0, 10);
        $q = max(1, (int) $s['quantity']);
        $cBefore = lotAtLocal($lots, $day);
        $cAfter = lotAtLocal($norm, $day);
        $dCogs += ((float) ($cAfter['cost_price'] ?? 0.0) - (float) ($cBefore['cost_price'] ?? 0.0)) * $q;
        $nSales++;
        if ($day >= date('Y-m-d', strtotime('-30 days'))) {
            $qty30 += $q;
        }
    }
    // Pertes valorisées, même différence.
    $st = $pdo->prepare('SELECT lost_at, quantity FROM losses WHERE product_key = ?');
    $st->execute([$key]);
    $dLoss = 0.0;
    foreach ($st->fetchAll() as $l) {
        $day = substr((string) $l['lost_at'], 0, 10);
        $q = (int) $l['quantity'];
        $cBefore = lotAtLocal($lots, $day);
        $cAfter = lotAtLocal($norm, $day);
        $dLoss += ((float) ($cAfter['cost_price'] ?? 0.0) - (float) ($cBefore['cost_price'] ?? 0.0)) * $q;
    }

    $today = date('Y-m-d');
    $curBefore = lotAtLocal($lots, $today);
    $curAfter = lotAtLocal($norm, $today);
    $rows[] = [
        'key'         => $key,
        'lots'        => $n,
        'degeneres'   => $deg,
        'chevauch'    => $overlap,
        'ventes_n'    => $nSales,
        'qty_30j'     => $qty30,
        'd_cogs'      => round($dCogs, 3),
        'd_pertes'    => round($dLoss, 3),
        'cout_avant'  => $curBefore === null ? null : (string) $curBefore['cost_price'],
        'cout_apres'  => $curAfter === null ? null : (string) $curAfter['cost_price'],
    ];
}

usort($rows, static fn (array $a, array $b): int => [abs($b['d_cogs']) + abs($b['d_pertes']), $b['degeneres'] + $b['chevauch']]
    <=> [abs($a['d_cogs']) + abs($a['d_pertes']), $a['degeneres'] + $a['chevauch']]);

printf("%-24s %4s %3s %3s %6s %7s %9s %8s  %-7s %-7s\n", 'cle', 'lots', 'DEG', 'CHEV', 'ventes', 'q30j', 'dCOGS', 'dPertes', 'coutAvt', 'coutApr');
foreach ($rows as $r) {
    printf(
        "%-24s %4d %3d %3d %6d %7d %9.3f %8.3f  %-7s %-7s\n",
        $r['key'], $r['lots'], $r['degeneres'], $r['chevauch'], $r['ventes_n'], $r['qty_30j'],
        $r['d_cogs'], $r['d_pertes'],
        $r['cout_avant'] ?? '—', $r['cout_apres'] ?? '—'
    );
}

$totDeg = array_sum(array_column($rows, 'degeneres'));
$totChev = array_sum(array_column($rows, 'chevauch'));
$totCogs = array_sum(array_column($rows, 'd_cogs'));
echo "\nTOTAUX : {$totDeg} lots dégénérés, {$totChev} chevauchements, sur " . count($rows) . " clés.\n";
printf("Σ ΔCOGS si normalisation : %+.3f € (Σ Δ pertes %+.3f €)\n", $totCogs, array_sum(array_column($rows, 'd_pertes')));
