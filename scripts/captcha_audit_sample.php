<?php

declare(strict_types=1);

/**
 * AEIC — Échantillons CAPTCHA pour la boucle d'audit OCR (CLI UNIQUEMENT).
 *
 * Génère N paires (image PNG + code en clair en .txt) pour labelliser la
 * boucle d'audit horaire : une IA tierce tente de lire les images, on
 * compare au code attendu. Ces paires NE DOIVENT JAMAIS être exposées par
 * une route HTTP — ce script est le seul point de sortie.
 *
 * Usage :
 *   php scripts/captcha_audit_sample.php [N] [--out=DIR] [--force] [--strict]
 *
 *   N          nombre d'échantillons (1–1000, défaut 20)
 *   --out=DIR  dossier de sortie (défaut /tmp/captcha-audit)
 *   --force    autorise l'écriture dans un dossier non vide
 *   --strict   échoue (exit 1) si la base est injoignable, au lieu
 *              d'utiliser les paramètres par défaut (exit 0 sinon)
 *
 * Sans base joignable (et sans --strict), les réglages par défaut du code
 * sont utilisés et un avertissement est imprimé sur stderr.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/database.php';

use App\Core\Captcha\CaptchaService;
use App\Models\CaptchaSetting;

// ── Parsing des arguments ─────────────────────────────────────────────────────
$count = null;
$outDir = '/tmp/captcha-audit';
$force = false;
$strict = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--force') {
        $force = true;
    } elseif ($arg === '--strict') {
        $strict = true;
    } elseif (str_starts_with($arg, '--out=')) {
        $outDir = substr($arg, strlen('--out='));
    } elseif ($arg === '-h' || $arg === '--help') {
        echo "Usage : php scripts/captcha_audit_sample.php [N] [--out=DIR] [--force] [--strict]\n";
        exit(0);
    } elseif (str_starts_with($arg, '--')) {
        fwrite(STDERR, "Option inconnue : {$arg}\n");
        exit(1);
    } elseif ($count === null && ctype_digit($arg) && (int) $arg > 0) {
        $count = (int) $arg;
    } else {
        fwrite(STDERR, "Argument invalide : {$arg} (attendu : N entier 1–1000)\n");
        exit(1);
    }
}

$count ??= 20;
if ($count < 1 || $count > 1000) {
    fwrite(STDERR, "ERREUR : N doit être compris entre 1 et 1000 (reçu : {$count}).\n");
    exit(1);
}
if (trim($outDir) === '') {
    fwrite(STDERR, "ERREUR : --out ne peut pas être vide.\n");
    exit(1);
}

if (!function_exists('imagepng')) {
    fwrite(STDERR, "ERREUR : l'extension GD est requise pour générer les échantillons.\n");
    exit(1);
}

// ── Réglages : snapshot BDD, défauts si base injoignable ─────────────────────
$settings = null;
try {
    db();
    $settings = CaptchaSetting::snapshot();
} catch (Throwable) {
    if ($strict) {
        fwrite(STDERR, "ERREUR (--strict) : base de données injoignable, abandon.\n");
        exit(1);
    }
    fwrite(STDERR, "ATTENTION : base injoignable — paramètres par défaut utilisés.\n");
}

// ── Dossier de sortie ─────────────────────────────────────────────────────────
$existing = glob($outDir . '/*');
if ($existing !== [] && is_dir($outDir) && !$force) {
    fwrite(STDERR, "ERREUR : {$outDir} n'est pas vide — ajoutez --force pour écraser.\n");
    exit(1);
}
if (!is_dir($outDir) && !@mkdir($outDir, 0755, true) && !is_dir($outDir)) {
    fwrite(STDERR, "ERREUR : impossible de créer {$outDir}.\n");
    exit(1);
}

// ── Génération des paires PNG + code en clair ────────────────────────────────
$service = new CaptchaService($settings);
for ($i = 1; $i <= $count; $i++) {
    $code = $service->generate();
    $png = $service->render($code);

    $base = $outDir . '/captcha_' . str_pad((string) $i, 4, '0', STR_PAD_LEFT);
    if (file_put_contents($base . '.png', $png) === false
        || file_put_contents($base . '.txt', $code) === false
        || !@chmod($base . '.png', 0644)
        || !@chmod($base . '.txt', 0644)
    ) {
        fwrite(STDERR, "ERREUR : écriture impossible pour {$base}\n");
        exit(1);
    }
}

echo sprintf(
    "%d échantillon(s) écrit(s) dans %s (captcha_0001.png + captcha_0001.txt…).\n",
    $count,
    $outDir
);
exit(0);
