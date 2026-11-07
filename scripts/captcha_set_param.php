<?php

declare(strict_types=1);

/**
 * AEIC — Réglage d'un paramètre du CAPTCHA (CLI UNIQUEMENT).
 *
 * Les réglages anti-bot ne passent QUE par ce script (ou la BDD) : aucune
 * interface admin web dans ce lot. Refuse toute clé inconnue ou valeur
 * hors bornes strictes (CaptchaService::BOUNDS) — ex. code_length 12 est
 * refusé, code_length 7 est accepté.
 *
 * Usage :
 *   php scripts/captcha_set_param.php <cle> <valeur>
 *
 *   cle     code_length | rotation_max_degrees | noise_lines | noise_intensity
 *   valeur  entier dans les bornes de la clé
 *
 * Affiche l'ancienne valeur puis la nouvelle (ancienne → nouvelle).
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/database.php';

use App\Core\Captcha\CaptchaService;
use App\Models\CaptchaSetting;

if ($argc !== 3) {
    fwrite(STDERR, "Usage : php scripts/captcha_set_param.php <cle> <valeur>\n");
    fwrite(STDERR, "Clés : " . implode(', ', array_keys(CaptchaService::DEFAULTS)) . "\n");
    exit(1);
}

[, $key, $rawValue] = $argv;

if (!array_key_exists($key, CaptchaService::DEFAULTS)) {
    fwrite(STDERR, "ERREUR : clé inconnue « {$key} ». Clés valides : "
        . implode(', ', array_keys(CaptchaService::DEFAULTS)) . "\n");
    exit(1);
}

if (!preg_match('/^-?\d+$/', $rawValue)) {
    fwrite(STDERR, "ERREUR : la valeur doit être un entier (reçu : « {$rawValue} »).\n");
    exit(1);
}

$value = (int) $rawValue;
$bounds = CaptchaService::BOUNDS[$key];
if ($value < $bounds['min'] || $value > $bounds['max']) {
    fwrite(STDERR, sprintf(
        "ERREUR : %s = %d est hors bornes (accepté : %d à %d).\n",
        $key,
        $value,
        $bounds['min'],
        $bounds['max']
    ));
    exit(1);
}

try {
    $old = CaptchaSetting::get($key);
    CaptchaSetting::set($key, $value);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERREUR : ' . $e->getMessage() . "\n");
    exit(1);
}

echo sprintf("%s : %d → %d\n", $key, $old, $value);
exit(0);
