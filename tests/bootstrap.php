<?php

declare(strict_types=1);

/**
 * Bootstrap PHPUnit.
 * Charge l'autoloader Composer et la configuration de base.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Charge les helpers (déjà inclus via composer "files", mais on s'assure de la présence
// des constantes APP_URL etc. nécessaires aux tests d'URL).
if (!defined('APP_URL')) {
    define('APP_URL', 'https://example.test');
}
