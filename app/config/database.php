<?php

declare(strict_types=1);

/**
 * Connexion à la base de données (PDO, singleton).
 *
 * Les paramètres sont lus depuis les variables d'environnement chargées
 * par config.php. La connexion est partagée (une seule instance PDO
 * par requête) via une variable statique.
 */

use App\Core\Auth;

require_once __DIR__ . '/config.php';

/**
 * Retourne l'instance PDO partagée de l'application.
 *
 * @throws PDOException si la connexion échoue.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = env('DB_HOST', '127.0.0.1');
    $name = env('DB_NAME', 'aeic');
    $user = env('DB_USER', 'aeic');
    $pass = env('DB_PASS', '');

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $name);

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_PERSISTENT         => false,
    ]);

    // Force le timezone MySQL sur UTC pour la cohérence des dates.
    $pdo->exec("SET time_zone = '+00:00'");

    return $pdo;
}

// Démarrage automatique de la session (nécessaire pour Auth + CSRF).
Auth::startSession();
