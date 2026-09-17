<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Middleware;
use App\Core\RateLimiter;
use App\Core\Security\Totp;
use App\Core\Security\TwoFactorPolicy;
use App\Models\TwoFactor;

/**
 * Authentification à deux facteurs : vérification au login, configuration, désactivation.
 */
final class TwoFactorController extends Controller
{
    /** Tentatives de code max par session 2FA (compteur en session). */
    private const MAX_ATTEMPTS = 5;

    /** Tentatives max par IP sur la fenêtre (anti brute-force massif). */
    private const IP_MAX_ATTEMPTS = 10;

    /** Fenêtre de limitation IP (secondes) : 10 minutes. */
    private const IP_WINDOW = 600;

    // -----------------------------------------------------------------
    //  Vérification 2FA (étape de connexion)
    // -----------------------------------------------------------------

    public function verifyForm(): void
    {
        Middleware::requireGuest();

        $pending = $_SESSION['_2fa_pending'] ?? null;
        if (!is_array($pending) || ($pending['expires'] ?? 0) < time()) {
            unset($_SESSION['_2fa_pending']);
            $this->setFlash('error', 'Session 2FA expirée, veuillez vous reconnecter.');
            redirect(url('/login'));
        }

        $this->render('auth/twofactor_verify', [
            'title'       => 'Vérification en deux étapes — AEIC',
            'description' => 'Saisissez votre code d\'authentification.',
        ]);
    }

    public function verify(): void
    {
        Middleware::requireGuest();

        $limiter = new RateLimiter();
        $ipKey = '2fa:' . client_ip();

        // Limitation par IP (anti brute-force massif).
        if ($limiter->tooManyAttempts($ipKey, self::IP_MAX_ATTEMPTS, self::IP_WINDOW)) {
            unset($_SESSION['_2fa_pending']);
            $this->setFlash('error', 'Trop de tentatives. Reconnecte-toi.');
            redirect(url('/login'));
        }

        $pending = $_SESSION['_2fa_pending'] ?? null;
        if (!is_array($pending) || ($pending['expires'] ?? 0) < time()) {
            unset($_SESSION['_2fa_pending']);
            $this->setFlash('error', 'Session 2FA expirée, veuillez vous reconnecter.');
            redirect(url('/login'));
        }

        // Limitation par compte : compteur de tentatives en session.
        $attempts = (int) ($pending['attempts'] ?? 0);
        if ($attempts >= self::MAX_ATTEMPTS) {
            unset($_SESSION['_2fa_pending']);
            $this->setFlash('error', 'Trop de tentatives. Reconnecte-toi.');
            redirect(url('/login'));
        }

        $code = trim((string) ($_POST['code'] ?? ''));

        if (TwoFactor::verify((string) $pending['user_id'], str_replace(' ', '', $code))) {
            $limiter->clear($ipKey);
            unset($_SESSION['_2fa_pending']);
            Auth::login((string) $pending['user_id'], (string) $pending['role']);
            $this->setFlash('success', 'Connexion réussie.');
            redirect(url('/'));
        }

        // Échec : incrémente le compteur session et le compteur IP.
        $pending['attempts'] = $attempts + 1;
        $_SESSION['_2fa_pending'] = $pending;
        $limiter->hit($ipKey);

        if ($pending['attempts'] >= self::MAX_ATTEMPTS) {
            unset($_SESSION['_2fa_pending']);
            $this->setFlash('error', 'Trop de tentatives. Reconnecte-toi.');
            redirect(url('/login'));
        }

        $this->setFlash('error', 'Code incorrect. Vérifie que l\'horloge de ton téléphone est en réglage automatique et que le code est frais (régénéré il y a moins de 30 s).');
        redirect(url('/login/verify'));
    }

    // -----------------------------------------------------------------
    //  Configuration 2FA (setup)
    // -----------------------------------------------------------------

    public function setupForm(): void
    {
        Middleware::requireLogin();

        $userId = (string) Auth::id();
        $enabled = TwoFactor::isEnabled($userId);

        if (!$enabled) {
            // Source de vérité : le secret en attente EN BASE. Tant que le
            // 2FA n'est pas activé, on réaffiche TOUJOURS le même secret :
            // aucune régénération au chargement de la page (c'était la cause
            // de codes toujours refusés : le secret changeait à chaque
            // affichage pendant que l'application gardait l'ancien).
            $secret = TwoFactor::pendingSecret($userId);

            if ($secret === null) {
                // Première visite : on amorce le setup.
                $setup = TwoFactor::beginSetup($userId);
                $_SESSION['_2fa_pending_secret'] = $setup['secret'];
                $_SESSION['_2fa_pending_recovery'] = $setup['recovery'];
            } elseif (!isset($_SESSION['_2fa_pending_secret'])
                || !hash_equals((string) $_SESSION['_2fa_pending_secret'], $secret)
            ) {
                // Setup déjà commencé mais session perdue/désynchronisée :
                // on resynchronise sur le secret existant (jamais régénéré).
                // Les codes de récupération en clair étant perdus avec la
                // session, on en regénère (le secret TOTP reste stable).
                $_SESSION['_2fa_pending_secret'] = $secret;
                $_SESSION['_2fa_pending_recovery'] = TwoFactor::regenerateRecoveryCodes($userId);
            }
        }

        $this->render('account/twofactor_setup', [
            'title'        => 'Authentification à deux facteurs — AEIC',
            'description'  => 'Sécurisez votre compte avec le 2FA.',
            'enabled'      => $enabled,
            'secret'       => $enabled ? '' : ($_SESSION['_2fa_pending_secret'] ?? ''),
            'recovery'     => $enabled ? [] : ($_SESSION['_2fa_pending_recovery'] ?? []),
            'required'     => TwoFactorPolicy::requires((string) Auth::role()),
        ]);
    }

    /**
     * Régénération explicite de la clé (bouton « Générer une nouvelle clé ») :
     * c'est le SEUL chemin qui renouvelle le secret pendant le setup.
     */
    public function setupRegenerate(): void
    {
        Middleware::requireLogin();

        $userId = (string) Auth::id();
        if (TwoFactor::isEnabled($userId)) {
            redirect(url('/account/2fa/setup'));
        }

        $setup = TwoFactor::beginSetup($userId);
        $_SESSION['_2fa_pending_secret'] = $setup['secret'];
        $_SESSION['_2fa_pending_recovery'] = $setup['recovery'];

        $this->setFlash('info', 'Nouvelle clé générée : remplacez l\'ancienne entrée dans votre application d\'authentification, puis confirmez avec un code.');
        redirect(url('/account/2fa/setup'));
    }

    public function setupConfirm(): void
    {
        Middleware::requireLogin();

        $userId = (string) Auth::id();
        $code = str_replace(' ', '', trim((string) ($_POST['code'] ?? '')));

        // On vérifie contre le secret en attente EN BASE (et non la session) :
        // plusieurs onglets ou une session reconstruite comparent ainsi bien
        // le secret affiché à l'utilisateur.
        $secret = TwoFactor::pendingSecret($userId);

        if ($secret === null) {
            // Pas de setup en cours (session perdue avant écriture, etc.) :
            // le chargement de la page setup en amorce un nouveau.
            $this->setFlash('error', 'Configuration expirée : une nouvelle clé vous a été attribuée, recommencez l\'ajout dans votre application.');
            redirect(url('/account/2fa/setup'));
        }

        if (!Totp::verify($secret, $code)) {
            $this->setFlash('error', 'Code incorrect. Vérifie que l\'horloge de ton téléphone est en réglage automatique et que le code est frais (régénéré il y a moins de 30 s), puis réessaie.');
            redirect(url('/account/2fa/setup'));
        }

        TwoFactor::enable($userId);
        unset($_SESSION['_2fa_pending_secret'], $_SESSION['_2fa_pending_recovery']);

        // Journalisation.
        \App\Models\AuditLog::log('twofactor.enable', $userId, 'user', $userId);

        $this->setFlash('success', 'Authentification à deux facteurs activée. Conservez vos codes de récupération en lieu sûr.');
        redirect(url('/account/2fa/setup'));
    }

    public function disable(): void
    {
        Middleware::requireLogin();

        $userId = (string) Auth::id();

        // Un ADMIN/TRÉSORERIE ne peut pas désactiver un 2FA obligatoire.
        if (TwoFactorPolicy::requires((string) Auth::role())) {
            $this->setFlash('error', 'Le 2FA est obligatoire pour votre rôle et ne peut être désactivé.');
            redirect(url('/account/2fa/setup'));
        }

        TwoFactor::disable($userId);
        \App\Models\AuditLog::log('twofactor.disable', $userId, 'user', $userId);

        $this->setFlash('success', 'Authentification à deux facteurs désactivée.');
        redirect(url('/account/2fa/setup'));
    }
}
