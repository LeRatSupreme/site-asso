<?php

declare(strict_types=1);

/**
 * AEIC — Dump des sources de stock/demande du réapprovisionnement (CLI, LECTURE SEULE).
 *
 * Objet (exigence du 21/10/2026) : pour chaque produit, montrer la SOURCE
 * et la DATE du stock utilisé par chaque page réappro, avant/après
 * l'unification — le diagnostic qui a expliqué les écarts « prod » du
 * 21/10/2026 (Monster Rose 3 vs 13 : stock théorique vs product_stocks ;
 * Bueno white −5 vs « — » : théorique négatif vs clampé à 0).
 *
 * Colonnes par produit :
 *   - liste/réappro (APRÈS unification) : stock THÉORIQUE via StockBook
 *     (résolution exacte → normalisée → alias) + date du dernier comptage ;
 *   - legacy_product_stocks (AVANT, à titre explicatif) : valeur de la
 *     table product_stocks qui alimentait l'ancienne carte Analytics —
 *     les ventes n'y sont PAS déduites depuis le dernier mouvement ;
 *   - divergences : théorique ≠ legacy, stock négatif, sans donnée.
 *
 * Usage :
 *   php scripts/dump_stock_sources.php                  # produits actifs (ventes 30 j)
 *   php scripts/dump_stock_sources.php --jours=14       # fenêtre d'activité
 *   php scripts/dump_stock_sources.php --json           # même contenu en JSON
 *   php scripts/dump_stock_sources.php --tous           # tous les produits d'inventaire, même sans vente
 *
 * Garanties LECTURE SEULE :
 *   - AUCUN INSERT / UPDATE / DELETE n'est émis (SELECT uniquement) ;
 *   - seules des méthodes de lecture des modèles sont appelées
 *     (Sale, InventoryCount, ProductStock, ProductAlias, ProductInfinite,
 *     ProductDiscontinued, ProductPack, AnalyticsForecastData) ;
 *   - le dump ne modifie ni stocks, ni réglages, ni cases cochées.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/database.php';

use App\Models\AnalyticsForecastData;
use App\Models\InventoryCount;
use App\Models\ProductDiscontinued;
use App\Models\ProductInfinite;
use App\Models\ProductStock;

// ── Options CLI ──────────────────────────────────────────────────────────────
$opts   = getopt('', ['json', 'jours::', 'tous']);
$asJson = array_key_exists('json', $opts);
$jours  = max(1, (int) ($opts['jours'] ?? 30));
$tous   = array_key_exists('tous', $opts);

// ── Sources de stock ─────────────────────────────────────────────────────────
// APRÈS unification : les DEUX pages (liste de courses et carte Réappro
// Analytics) consomment le même livre — stock théorique + alias.
$book = AnalyticsForecastData::stockBook();

// AVANT (légitime pour expliquer l'historique) : la table product_stocks.
$legacyRaw = [];
try {
    $legacyRaw = ProductStock::allMap(); // @phpstan-ignore-line — SELECT simple
} catch (Throwable) {
    $legacyRaw = [];
}
$legacy = [];
foreach ($legacyRaw as $k => $v) {
    $legacy[strtolower(trim((string) $k))] = (int) $v;
}
$legacyDates = [];
try {
    $rows = db()->query('SELECT product_key, updated_at FROM product_stocks')->fetchAll();
    foreach ($rows as $r) {
        $legacyDates[strtolower(trim((string) ($r['product_key'] ?? '')))] = (string) ($r['updated_at'] ?? '');
    }
} catch (Throwable) {
    // colonnes de dates absentes : ignoré
}

// Produits concernés : vendus sur la fenêtre (clés brutes des ventes),
// ou l'inventaire complet avec --tous.
$from = date('Y-m-d', strtotime('-' . ($jours - 1) . ' days'));
$keys = [];
try {
    $stmt = db()->prepare(
        'SELECT COALESCE(product_key, description) AS pkey
         FROM sales
         WHERE price_ttc > 0 AND DATE(sold_at) >= ?
         GROUP BY pkey ORDER BY pkey'
    );
    $stmt->execute([$from]);
    foreach ($stmt->fetchAll() as $r) {
        $k = trim((string) ($r['pkey'] ?? ''));
        if ($k !== '') {
            $keys[$k] = true;
        }
    }
} catch (Throwable) {
    // pas de ventes : repli inventaire
}
if ($tous) {
    foreach (InventoryCount::theoreticalStocksMap() as $k => $v) {
        $keys[trim((string) $k)] = true;
    }
}

$infinite = array_flip(array_map('strtolower', ProductInfinite::keys()));
$discontinued = array_flip(array_map('strtolower', ProductDiscontinued::keys()));

$rapport = [
    'genere_le' => date('c'),
    'fenetre_jours' => $jours,
    'source_unifiee' => [
        'pages' => ['liste de courses', 'carte Réappro Analytics'],
        'stock' => 'théorique inventaire (dernier comptage + achats − ventes − pertes)',
        'date_max_comptage' => $book->derniereDate(),
        'demande' => 'prévision ForecastEngine 7 j ouverts (ReapproDemande, partagée)',
    ],
    'produits' => [],
];

foreach (array_keys($keys) as $key) {
    $lk = strtolower($key);
    $e = $book->resolve($key);
    $legacyVal = $legacy[$lk] ?? null; // correspondance exacte lower (historique)
    $legacyAt  = $legacyDates[$lk] ?? null;

    $flags = [];
    if ($e === null) {
        $flags[] = 'sans-donnee-inventaire';
    }
    if ($e !== null && $e['stock'] < 0) {
        $flags[] = 'stock-negatif';
    }
    if ($e !== null && $legacyVal !== null && (int) $legacyVal !== $e['stock']) {
        $flags[] = 'divergence-legacy';
    }
    if (isset($infinite[$lk])) {
        $flags[] = 'stock-infini';
    }
    if (isset($discontinued[$lk])) {
        $flags[] = 'plus-en-vente';
    }

    $rapport['produits'][] = [
        'produit'      => $key,
        'resolution'   => $e['via'] ?? null,
        'cle_stock'    => $e !== null && strcasecmp($e['key'], $key) !== 0 ? $e['key'] : null,
        'stock_unifie' => $e['stock'] ?? null,
        'stock_le'     => $e['at'] ?? null,
        'legacy_product_stocks' => $legacyVal !== null ? (int) $legacyVal : null,
        'legacy_le'    => $legacyAt,
        'flags'        => $flags,
    ];
}

// ── Sortie ───────────────────────────────────────────────────────────────────
if ($asJson) {
    echo json_encode($rapport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(0);
}

echo 'Sources de stock du réapprovisionnement — généré le ', date('d/m/Y H:i'), PHP_EOL;
echo 'Fenêtre d\'activité : ', $jours, ' j · Produits : ', count($rapport['produits']), PHP_EOL;
echo 'Source UNIFIÉE (liste de courses + carte Analytics) : stock théorique inventaire',
    ($book->derniereDate() !== null ? ' — comptages jusqu\'au ' . substr($book->derniereDate(), 0, 10) : ''), PHP_EOL;
echo 'Demande UNIFIÉE : prévision ForecastEngine 7 j ouverts (ReapproDemande)', PHP_EOL;
echo PHP_EOL;
printf(
    "%-28s %-10s %-9s %-12s %-9s %-12s %s\n",
    'PRODUIT', 'RÉSOLU', 'STOCK', 'STOCK LE', 'LEGACY', 'LEGACY LE', 'FLAGS'
);
echo str_repeat('-', 108), PHP_EOL;
foreach ($rapport['produits'] as $p) {
    printf(
        "%-28s %-10s %-9s %-12s %-9s %-12s %s\n",
        mb_strimwidth($p['produit'], 0, 28, '…'),
        $p['resolution'] ?? '—',
        $p['stock_unifie'] ?? '—',
        $p['stock_le'] !== null ? substr($p['stock_le'], 0, 10) : '—',
        $p['legacy_product_stocks'] ?? '—',
        $p['legacy_le'] !== null ? substr($p['legacy_le'], 0, 10) : '—',
        implode(',', $p['flags'])
    );
}
echo PHP_EOL, 'Légende : RÉSOLU = passe de rapprochement (exact|normalise|alias) ;',
    PHP_EOL, 'LEGACY = table product_stocks (ancienne carte Analytics, ventes non déduites).',
    PHP_EOL;

exit(0);
