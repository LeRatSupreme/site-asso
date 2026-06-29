<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Garde-fous d'accès (middleware) pour protéger les routes selon l'authentification
 * et le rôle.
 *
 * Ces méthodes sont conçues pour être appelées en tête d'action d'un contrôleur.
 * Elles terminent le script (redirection ou page d'erreur) si l'accès est refusé,
 * sinon ne font rien et laissent l'action s'exécuter.
 */
final class Middleware
{
    /**
     * Exige qu'aucun utilisateur ne soit connecté (pages /login, /register).
     * Redirige vers la page d'origine ou l'accueil si déjà connecté.
     */
    public static function requireGuest(?string $redirect = null): void
    {
        if (Auth::check()) {
            redirect($redirect ?? '/');
        }
    }

    /**
     * Exige un utilisateur connecté. Sinon redirige vers /login avec callback.
     */
    public static function requireLogin(): void
    {
        if (!Auth::check()) {
            $callback = $_SERVER['REQUEST_URI'] ?? '/';
            redirect(url('/login?callbackUrl=') . rawurlencode($callback));
        }
    }

    /**
     * Exige un utilisateur connecté ayant l'un des rôles autorisés.
     * Si non connecté -> redirection login ; si connecté mais rôle insuffisant -> 403.
     *
     * @param list<string> $roles
     */
    public static function requireRole(array $roles): void
    {
        $status = self::resolve($roles);

        if ($status === self::LOGIN) {
            $callback = $_SERVER['REQUEST_URI'] ?? '/';
            redirect(url('/login?callbackUrl=') . rawurlencode($callback));
        }

        if ($status === self::FORBIDDEN) {
            http_response_code(403);
            echo '<h1>Erreur 403 — Accès refusé.</h1>';
            exit;
        }
    }

    /** Statuts de décision d'accès (testables sans effet de bord). */
    public const OK = 'ok';
    public const LOGIN = 'login';
    public const FORBIDDEN = 'forbidden';

    /**
     * Résout la décision d'accès pour l'utilisateur courant et les rôles autorisés.
     *
     * Renvoie :
     *  - self::OK         si connecté et autorisé ;
     *  - self::LOGIN      si non connecté (rediriger vers le login) ;
     *  - self::FORBIDDEN  si connecté mais rôle insuffisant (403).
     *
     * @param list<string> $roles
     */
    public static function resolve(array $roles): string
    {
        if (!Auth::check()) {
            return self::LOGIN;
        }

        return in_array(Auth::role(), $roles, true) ? self::OK : self::FORBIDDEN;
    }

    /**
     * Indique si le rôle courant figure parmi les rôles autorisés.
     *
     * @param list<string> $roles
     */
    public static function isAuthorized(array $roles): bool
    {
        return self::resolve($roles) === self::OK;
    }
}
