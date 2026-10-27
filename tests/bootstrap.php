<?php

declare(strict_types=1);

/**
 * Bootstrap PHPUnit.
 * Charge l'autoloader Composer et la configuration de base.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Constantes applicatives minimales pour les tests (sans charger la config
// complète ni la base de données).
if (!defined('AEIC_ROOT')) {
    define('AEIC_ROOT', dirname(__DIR__));
}
if (!defined('AEIC_VIEWS')) {
    define('AEIC_VIEWS', AEIC_ROOT . '/views');
}
if (!defined('AEIC_PUBLIC')) {
    define('AEIC_PUBLIC', AEIC_ROOT . '/public');
}
if (!defined('APP_URL')) {
    define('APP_URL', 'https://example.test');
}
if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', false);
}
if (!defined('APP_TESTING')) {
    // Bootstrap réservé aux tests : on force le mode test (capture e-mails,
    // CSRF/2FA neutralisés) quoi qu'il arrive.
    define('APP_TESTING', true);
}
if (!defined('AEIC_T15_QUARANTAINE')) {
    // T1.5 — quarantaine des fusions auto REDIRIGÉE hors du repo pendant
    // les tests (tests/cache/ est ignoré) : un chargement de la page
    // Inventaire en test ne doit JAMAIS écrire la vraie quarantaine
    // data/eval_t1/t15/quarantaine.json (même comportement que le pont
    // AEIC_T15 des tests T1AutoFusion).
    define('AEIC_T15_QUARANTAINE', dirname(__DIR__) . '/tests/cache/t15/quarantaine.json');
}

/**
 * Lecture d'une variable d'environnement (équivalent minimal de config.php,
 * non chargé en contexte de test).
 */
if (!function_exists('env')) {
    function env(string $key, string $default = ''): string
    {
        $value = getenv($key);

        return ($value === false || $value === '') ? $default : $value;
    }
}
