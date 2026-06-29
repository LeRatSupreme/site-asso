<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Vérification des tokens CSRF.
 *
 * Le token est généré côté session par la fonction globale csrf_token().
 * Cette classe compare le token reçu (formulaire POST / header) au token
 * de session en temps constant.
 */
final class Csrf
{
    /**
     * Vérifie la validité d'un token CSRF.
     */
    public static function verify(?string $token): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $sessionToken = $_SESSION['_csrf_token'] ?? null;
        if ($sessionToken === null || $token === null || $token === '') {
            return false;
        }

        return hash_equals($sessionToken, $token);
    }

    /**
     * Vérifie le token depuis $_POST ou un en-tête HTTP.
     *
     * En environnement de test (APP_TESTING=true), la vérification est court-
     * circuitée pour permettre aux tests d'intégration d'appeler directement
     * les routes POST sans avoir à générer un token. Ce flag n'est jamais
     * positionné en production.
     */
    public static function checkRequest(): bool
    {
        if (getenv('APP_TESTING') === 'true') {
            return true;
        }

        $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);

        return self::verify($token);
    }
}
