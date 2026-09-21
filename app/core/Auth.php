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
    public const ROLE_SUPERADMIN = 'SUPERADMIN';
    public const ROLE_ADMIN = 'ADMIN';
    public const ROLE_TRESORERIE = 'TRESORERIE';
    public const ROLE_EVENEMENTS = 'EVENEMENTS';
    public const ROLE_COMMUNICATION = 'COMMUNICATION';
    public const ROLE_CAFETERIA = 'CAFETERIA';
    public const ROLE_JEUX = 'JEUX';
    public const ROLE_ELEVE = 'ELEVE';

    /** Cache de l'utilisateur chargé depuis la base (une fois par requête). */
    private static ?array $user = null;

    /** Indique si le cache utilisateur a été chargé pour la requête courante. */
    private static bool $userLoaded = false;

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
     * puis régénère l'ID de session (anti-fixation). Le token CSRF est
     * renouvelé à l'élévation de privilège.
     */
    public static function login(string $userId, string $role): void
    {
        self::startSession();
        unset($_SESSION['_csrf_token']);
        session_regenerate_id(true);

        $_SESSION['user_id'] = $userId;
        $_SESSION['user_role'] = $role;
        $_SESSION['_last_regen'] = time();

        self::$user = null;
        self::$userLoaded = false;
    }

    /**
     * Déconnecte l'utilisateur courant et détruit la session.
     */
    public static function logout(): void
    {
        self::startSession();

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            // Mêmes paramètres de cookie que ceux posés par config.php
            // (secure en production) pour une invalidation effective.
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                (bool) $params['secure'],
                (bool) $params['httponly']
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

        self::refreshUser();

        if (!isset($_SESSION['user_id'])) {
            return false;
        }

        // Régénération périodique de l'ID de session (au plus toutes les 30 min).
        $lastRegen = (int) ($_SESSION['_last_regen'] ?? 0);
        if ($lastRegen === 0 || (time() - $lastRegen) > 1800) {
            session_regenerate_id(true);
            $_SESSION['_last_regen'] = time();
        }

        return true;
    }

    /**
     * Recharge l'utilisateur connecté depuis la base (une seule fois par
     * requête) et rafraîchit son rôle en session. Déconnecte si le compte
     * n'existe plus ou a été désactivé.
     */
    private static function refreshUser(): void
    {
        if (self::$userLoaded || !isset($_SESSION['user_id'])) {
            return;
        }

        self::$userLoaded = true;

        try {
            $user = \App\Models\User::find((string) $_SESSION['user_id']);
        } catch (\Throwable) {
            return;
        }

        if ($user === null || (int) ($user['is_active'] ?? 0) !== 1) {
            self::logout();

            return;
        }

        $_SESSION['user_role'] = (string) ($user['role'] ?? self::ROLE_ELEVE);
        self::$user = $user;
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
     * Indique si l'utilisateur courant est administrateur (ADMIN ou
     * Fondateur/SUPERADMIN).
     */
    public static function isAdmin(): bool
    {
        return in_array(self::role(), [self::ROLE_SUPERADMIN, self::ROLE_ADMIN], true);
    }

    /**
     * Renvoie l'enregistrement complet de l'utilisateur connecté (depuis la DB,
     * une requête par requête HTTP), ou null s'il n'est pas connecté /
     * introuvable / désactivé.
     *
     * @return array<string,mixed>|null
     */
    public static function user(): ?array
    {
        self::startSession();
        self::refreshUser();

        return self::$user;
    }
}
