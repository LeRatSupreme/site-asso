<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Gestion minimale de l'authentification par session.
 *
 * La session est démarrée automatiquement depuis database.php.
 * En cas de login, l'ID de session est régénéré pour empêcher la fixation.
 */
final class Auth
{
    public const ROLE_ADMIN = 'ADMIN';
    public const ROLE_TRESORERIE = 'TRESORERIE';
    public const ROLE_ELEVE = 'ELEVE';

    /**
     * Démarre la session si elle ne l'est pas déjà.
     */
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Connecte un utilisateur : stocke son ID et son rôle en session,
     * puis régénère l'ID de session (anti-fixation).
     */
    public static function login(string $userId, string $role): void
    {
        self::startSession();
        session_regenerate_id(true);

        $_SESSION['user_id'] = $userId;
        $_SESSION['user_role'] = $role;
    }

    /**
     * Déconnecte l'utilisateur courant et détruit la session.
     */
    public static function logout(): void
    {
        self::startSession();

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }

    /**
     * Indique si un utilisateur est connecté.
     */
    public static function check(): bool
    {
        self::startSession();

        return isset($_SESSION['user_id']);
    }

    /**
     * Renvoie l'ID de l'utilisateur connecté (ou null).
     */
    public static function id(): ?string
    {
        self::startSession();

        return $_SESSION['user_id'] ?? null;
    }

    /**
     * Renvoie le rôle de l'utilisateur connecté (ou null).
     */
    public static function role(): ?string
    {
        self::startSession();

        return $_SESSION['user_role'] ?? null;
    }

    /**
     * Indique si l'utilisateur courant est administrateur.
     */
    public static function isAdmin(): bool
    {
        return self::role() === self::ROLE_ADMIN;
    }

    /**
     * Renvoie l'enregistrement complet de l'utilisateur connecté (depuis la DB),
     * ou null s'il n'est pas connecté / introuvable.
     *
     * @return array<string,mixed>|null
     */
    public static function user(): ?array
    {
        $id = self::id();
        if ($id === null) {
            return null;
        }

        try {
            $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);

            $user = $stmt->fetch();
        } catch (\Throwable) {
            return null;
        }

        return $user ?: null;
    }
}
