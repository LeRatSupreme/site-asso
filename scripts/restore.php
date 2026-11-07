<?php

declare(strict_types=1);

/**
 * Restauration d'une sauvegarde AEIC depuis un dump SQL.
 *
 * Usage (CLI) :  php scripts/restore.php <fichier.sql> [--db <nom_base>] [--force]
 *
 * Ciblage :
 *   - `--db <nom_base>` : base cible explicite, créée au préalable par
 *     l'opérateur (le script ne crée JAMAIS la base) ;
 *   - sans `--db` : la cible est la base de config.env (comportement
 *     historique) — mais la restauration y est REFUSÉE sans `--force`.
 *
 * Garde anti-incident (26/10/2026 20:00:39 : un dump a été rejoué dans la
 * production parce que config.env écrasait silencieusement le DB_NAME de
 * l'opérateur) : toute restauration visant la base de config.env — par
 * défaut OU via `--db` explicite — exige `--force`, ou une confirmation
 * interactive (retaper exactement le nom de la base). En non-interactif
 * sans `--force` : refus immédiat (exit 1), sans connexion ni écriture.
 *
 * ATTENTION : écrase les données existantes de la base cible.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/database.php';

use App\Core\Backup\Backup;

/** Identifiant de base autorisé pour --db (rien d'exotique dans le DSN). */
const RESTORE_DB_PATTERN = '/^[A-Za-z0-9_]+$/';

/** Ouvre une connexion PDO explicite vers la base cible (jamais via db()). */
function restore_pdo(string $dbName): PDO
{
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', env('DB_HOST', '127.0.0.1'), $dbName),
        env('DB_USER', 'aeic'),
        env('DB_PASS', ''),
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    $pdo->exec("SET time_zone = '+00:00'");

    return $pdo;
}

/** Nombre de tables actuellement présentes dans la base (null si indisponible). */
function restore_existing_tables(PDO $pdo, string $dbName): ?int
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?');
        $stmt->execute([$dbName]);
        $count = $stmt->fetchColumn();

        return is_numeric($count) ? (int) $count : null;
    } catch (Throwable) {
        return null;
    }
}

// --- Arguments ---------------------------------------------------------------

$force = false;
$dbOption = '';
$file = '';

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];

    if ($arg === '--force') {
        $force = true;
    } elseif ($arg === '--db') {
        $dbOption = (string) ($argv[$i + 1] ?? '');
        if (preg_match(RESTORE_DB_PATTERN, $dbOption) !== 1) {
            fwrite(STDERR, "Option --db : nom de base manquant ou invalide (identifiant MySQL simple attendu).\n");
            fwrite(STDERR, "Usage: php scripts/restore.php <fichier.sql> [--db <nom_base>] [--force]\n");
            exit(1);
        }
        $i++;
    } elseif ($file === '' && !str_starts_with($arg, '--')) {
        $file = $arg;
    } else {
        fwrite(STDERR, "Argument inconnu ou en trop : {$arg}\n");
        fwrite(STDERR, "Usage: php scripts/restore.php <fichier.sql> [--db <nom_base>] [--force]\n");
        exit(1);
    }
}

if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Usage: php scripts/restore.php <fichier.sql> [--db <nom_base>] [--force]\n");
    exit(1);
}

$sql = file_get_contents($file);
if ($sql === false || trim($sql) === '') {
    fwrite(STDERR, "Fichier de sauvegarde vide ou illisible.\n");
    exit(1);
}

// --- Résolution de la cible --------------------------------------------------

// Base de config.env (la garde de aeic_load_env() garantit qu'un DB_NAME
// passé dans l'environnement réel du process PRIME sur cette valeur).
$configDb = env('DB_NAME', 'aeic');
$target = $dbOption !== '' ? $dbOption : $configDb;

// Tables que le dump va dropper/recréer (comptage syntaxique, sans connexion).
$dumpTables = preg_match_all('/^DROP\s+TABLE\s+IF\s+EXISTS\s+`?([A-Za-z0-9_]+)`?/im', $sql) ?: 0;

fwrite(STDOUT, "Fichier source        : {$file}\n");
fwrite(STDOUT, "Base cible            : {$target}\n");
fwrite(STDOUT, "Tables du dump        : {$dumpTables} (droppées puis recréées)\n");

// --- Garde : jamais la base de config.env sans --force (incident 20:00:39) ---

$pdo = null;
$existingShown = false;

if ($target === $configDb && !$force) {
    fwrite(STDOUT, "\nREFUS : la base cible est celle de config.env (`{$configDb}`).\n");
    fwrite(STDOUT, "Incident du 26/10/2026 20:00:39 : un dump y a été rejoué par erreur —\n");
    fwrite(STDOUT, "cette restauration exige `--force` (ou la confirmation interactive).\n");

    if (function_exists('posix_isatty') && posix_isatty(STDIN)) {
        // Lecture seule, pour informer la décision.
        try {
            $pdo = restore_pdo($target);
        } catch (Throwable $e) {
            fwrite(STDERR, "Connexion impossible à `{$target}` : " . $e->getMessage() . "\n");
            exit(1);
        }
        $existing = restore_existing_tables($pdo, $target);
        fwrite(STDOUT, 'Tables présentes      : ' . ($existing === null ? 'indisponible' : (string) $existing) . " dans la base cible\n");
        $existingShown = true;

        fwrite(STDOUT, "Pour confirmer, retapez EXACTEMENT le nom de la base ({$configDb}) : ");
        $answer = trim((string) fgets(STDIN));
        if ($answer !== $configDb) {
            fwrite(STDERR, "Confirmation incorrecte — abandon, aucune écriture effectuée.\n");
            exit(1);
        }
    } else {
        fwrite(STDERR, "STDIN non interactif : confirmation impossible. Relancez avec --force pour restaurer malgré tout dans `{$configDb}`.\n");
        exit(1);
    }
} elseif ($target === $configDb) {
    fwrite(STDOUT, "\nATTENTION --force : restauration dans la base de config.env (`{$configDb}`).\n");
}

// --- Connexion à la cible (jamais créée par ce script) ------------------------

try {
    $pdo ??= restore_pdo($target);
} catch (Throwable $e) {
    fwrite(STDERR, "Connexion impossible à la base cible `{$target}` : " . $e->getMessage() . "\n");
    fwrite(STDERR, "restore.php ne crée jamais la base cible — créez-la explicitement avant (CREATE DATABASE …).\n");
    exit(1);
}

if (!$existingShown) {
    $existing = restore_existing_tables($pdo, $target);
    fwrite(STDOUT, 'Tables présentes      : ' . ($existing === null ? 'indisponible' : (string) $existing) . " dans la base cible\n");
}

fwrite(STDOUT, "→ Restauration dans `{$target}`…\n");
$count = Backup::restore($pdo, $sql);

fwrite(STDOUT, "Restauration terminée : {$count} requêtes exécutées (base `{$target}`).\n");
