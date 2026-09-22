<?php

declare(strict_types=1);

/**
 * AEIC — Synchro automatique des ventes SumUp (API → table `sales`).
 *
 * À exécuter via cron chaque minute (cf. scripts/README.md) :
 *   * * * * * cd /home/ubuntu/AEIC && php scripts/sync_sumup_sales.php >> logs/sumup-sync.log 2>&1
 *
 * Interroge l'API SumUp sur une fenêtre glissante et insère les nouvelles
 * ventes via le même chemin que l'import CSV (déduplication garantie).
 * Configuration (config.env) :
 *   SUMUP_API_KEY            clé API SumUp — vide = synchro désactivée (exit 0)
 *   SUMUP_MERCHANT_CODE      code marchand (découvert automatiquement si vide)
 *   SUMUP_SYNC_LOOKBACK_HOURS fenêtre de recherche, défaut 24
 *
 * Mode diagnostic sans écriture en base : php scripts/sync_sumup_sales.php --dry-run
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/database.php';

use App\Core\Compta\SumUpApiClient;
use App\Core\Compta\SumUpSalesSync;
use App\Models\ProductAlias;
use App\Models\Sale;

$logTs = date('Y-m-d\TH:i:sP');

// ── Verrou anti-recouvrement : jamais deux synchros en parallèle ─────────
$lockFile = sys_get_temp_dir() . '/aeic-sumup-sync.lock';
$lock = fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo '[' . $logTs . "] synchro déjà en cours, exécution ignorée\n";
    exit(0);
}

$exitCode = 0;

try {
    $apiKey = env('SUMUP_API_KEY', '');
    if ($apiKey === '') {
        echo '[' . $logTs . "] SUMUP_API_KEY absente — synchro désactivée\n";
        exit(0);
    }

    $dryRun = in_array('--dry-run', $_SERVER['argv'] ?? [], true);
    $hours = max(1, (int) env('SUMUP_SYNC_LOOKBACK_HOURS', '24'));
    $limit = min(200, max(10, (int) env('SUMUP_SYNC_MAX_ITEMS', '200')));
    $since = new DateTimeImmutable('-' . $hours . ' hours');

    $client = new SumUpApiClient($apiKey, env('SUMUP_MERCHANT_CODE', '') ?: null);
    $resolver = $dryRun ? null : [ProductAlias::class, 'resolve'];
    $sync = new SumUpSalesSync($client, $resolver);

    $stats = $sync->sync($since, $limit, $dryRun);

    // Nouvelles lignes insérées : on y reporte les catégories du mapping
    // des libellés (silencieux en cas d'échec SQL, jamais bloquant).
    if (!$dryRun && $stats['inserted'] > 0) {
        Sale::syncAliasCategories();
    }

    echo '[' . $logTs . '] '
        . ($dryRun ? 'DRY-RUN ' : '')
        . 'ok : ' . $stats['fetched'] . ' transaction(s) vue(s), '
        . $stats['sales'] . ' nouvelle(s), '
        . $stats['inserted'] . ' ligne(s) insérée(s), '
        . $stats['skipped'] . ' déjà présente(s), '
        . $stats['ignored'] . ' ignorée(s)'
        . ($stats['batch_id'] !== null ? ', lot ' . $stats['batch_id'] : '')
        . " (fenêtre " . $hours . " h)\n";
} catch (Throwable $e) {
    fwrite(STDERR, '[' . $logTs . '] ERREUR synchro SumUp : ' . $e->getMessage() . "\n");
    $exitCode = 1;
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

exit($exitCode);
