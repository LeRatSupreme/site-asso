<?php

declare(strict_types=1);

/**
 * Restauration d'une sauvegarde AEIC depuis un dump SQL.
 *
 * Usage (CLI) :  php scripts/restore.php chemin/vers/sauvegarde.sql
 *
 * ATTENTION : écrase les données existantes.
 */

require_once __DIR__ . '/../app/config/database.php';

use App\Core\Backup\Backup;

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Usage: php scripts/restore.php <fichier.sql>\n");
    exit(1);
}

$sql = file_get_contents($file);
if ($sql === false || trim($sql) === '') {
    fwrite(STDERR, "Fichier de sauvegarde vide ou illisible.\n");
    exit(1);
}

$count = Backup::restore(db(), $sql);

fwrite(STDOUT, "Restauration terminée : {$count} requêtes exécutées.\n");
