<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Middleware;
use App\Core\Security\Totp;
use App\Core\Security\TwoFactorPolicy;
use App\Models\TwoFactor;

/**
 * Authentification à deux facteurs : vérification au login, configuration, désactivation.
 */
final class TwoFactorController extends Controller
{
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

        $pending = $_SESSION['_2fa_pending'] ?? null;
        if (!is_array($pending) || ($pending['expires'] ?? 0) < time()) {
            unset($_SESSION['_2fa_pending']);
            $this->setFlash('error', 'Session 2FA expirée, veuillez vous reconnecter.');
            redirect(url('/login'));
        }

        $code = trim((string) ($_POST['code'] ?? ''));

        if (TwoFactor::verify((string) $pending['user_id'], str_replace(' ', '', $code))) {
            unset($_SESSION['_2fa_pending']);
            Auth::login((string) $pending['user_id'], (string) $pending['role']);
            $this->setFlash('success', 'Connexion réussie.');
            redirect(url('/'));
        }

        $this->setFlash('error', 'Code incorrect.');
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

        // Si le 2FA est déjà activé, on n'affiche pas le secret.
        if (!$enabled) {
            $setup = TwoFactor::beginSetup($userId);
            $_SESSION['_2fa_pending_secret'] = $setup['secret'];
            $_SESSION['_2fa_pending_recovery'] = $setup['recovery'];
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

    public function setupConfirm(): void
    {
        Middleware::requireLogin();

        $userId = (string) Auth::id();
        $secret = (string) ($_SESSION['_2fa_pending_secret'] ?? '');
        $code = trim((string) ($_POST['code'] ?? ''));

        if ($secret === '' || !Totp::verify($secret, str_replace(' ', '', $code))) {
            $this->setFlash('error', 'Code incorrect. Réessayez.');
            redirect(url('/account/2fa/setup'));
        }

        TwoFactor::enable($userId);
        unset($_SESSION['_2fa_pending_secret']);

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
