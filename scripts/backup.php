<?php

declare(strict_types=1);

/**
 * Sauvegarde de la base de données AEIC (dump SQL, PHP pur).
 *
 * Usage (CLI) :  php scripts/backup.php [fichier_sortie.sql]
 */

require_once __DIR__ . '/../app/config/database.php';

use App\Core\Backup\Backup;

$outFile = $argv[1] ?? AEIC_ROOT . '/backups/aeic-' . date('Y-m-d-His') . '.sql';

$dump = Backup::dump(db());

$dir = dirname($outFile);
if (!is_dir($dir)) {
    mkdir($dir, 0o775, true);
}

file_put_contents($outFile, $dump);

fwrite(STDOUT, 'Sauvegarde écrite : ' . $outFile . "\n");
