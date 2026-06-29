<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Middleware;
use App\Core\Mailer;
use App\Core\RateLimiter;
use App\Core\Validator;
use App\Models\Consent;
use App\Models\PasswordReset;
use App\Models\Setting;
use App\Models\TwoFactor;
use App\Models\User;

/**
 * Authentification : inscription, connexion, déconnexion.
 *
 * Sécurité : validation serveur, hash bcrypt, consentement RGPD journalisé,
 * régénération de session au login, limitation des tentatives de connexion.
 */
final class AuthController extends Controller
{
    /** Tentatives de connexion max par fenêtre. */
    private const LOGIN_MAX_ATTEMPTS = 5;

    /** Fenêtre de limitation (secondes) : 10 minutes. */
    private const LOGIN_WINDOW = 600;

    // -----------------------------------------------------------------
    //  Inscription
    // -----------------------------------------------------------------

    /**
     * Formulaire d'inscription.
     */
    public function registerForm(): void
    {
        Middleware::requireGuest();

        $this->render('auth/register', [
            'title'       => 'Créer un compte — AEIC',
            'description' => 'Inscription à l\'espace membre de l\'AEIC.',
        ]);
    }

    /**
     * Traitement de l'inscription.
     */
    public function register(): void
    {
        Middleware::requireGuest();

        $data = [
            'prenom'                => $_POST['prenom'] ?? '',
            'nom'                   => $_POST['nom'] ?? '',
            'email'                 => $_POST['email'] ?? '',
            'password'              => $_POST['password'] ?? '',
            'password_confirmation' => $_POST['password_confirmation'] ?? '',
        ];
        $consent = isset($_POST['consent']);

        $errors = Validator::registration($data);

        if (!$consent) {
            $errors[] = 'Vous devez accepter les conditions d\'utilisation et la politique de confidentialité.';
        }

        // Unicité de l'e-mail (vérification DB).
        if ($data['email'] !== '' && Validator::isValidEmail((string) $data['email']) && User::emailExists((string) $data['email'])) {
            $errors[] = 'Un compte existe déjà avec cette adresse e-mail.';
        }

        // Inscriptions désactivées (feature flag) ?
        if (!Setting::getBool('registrations_enabled', true)) {
            $errors[] = 'Les inscriptions sont temporairement désactivées.';
        }

        if ($errors !== []) {
            $_SESSION['_old'] = [
                'prenom' => $data['prenom'],
                'nom'    => $data['nom'],
                'email'  => $data['email'],
            ];
            $this->setFlash('error', implode(' ', $errors));
            redirect(url('/register'));
        }

        // Création du compte.
        $hash = password_hash($data['password'], PASSWORD_BCRYPT);
        $userId = User::create([
            'prenom'   => $data['prenom'],
            'nom'      => $data['nom'],
            'email'    => $data['email'],
            'password' => $hash,
            'role'     => Auth::ROLE_ELEVE,
        ]);

        // Journalisation du consentement RGPD (preuve datée).
        Consent::log('registration', true, 'cgu-v1', [
            'user_id'    => $userId,
            'email'      => User::normalizeEmail((string) $data['email']),
            'ip_address' => client_ip(),
            'user_agent' => user_agent(),
        ]);

        // E-mail de bienvenue transactionnel (non bloquant).
        try {
            Mailer::send('welcome', User::normalizeEmail((string) $data['email']), 'Bienvenue à l\'AEIC !', [
                'prenom' => $data['prenom'],
            ]);
        } catch (\Throwable) {
            // L'envoi d'e-mail ne doit jamais empêcher la création du compte.
        }

        $this->setFlash('success', 'Votre compte a été créé. Bienvenue à l\'AEIC !');
        redirect(url('/login'));
    }

    // -----------------------------------------------------------------
    //  Connexion
    // -----------------------------------------------------------------

    /**
     * Formulaire de connexion.
     */
    public function loginForm(): void
    {
        Middleware::requireGuest();

        $callback = (string) ($_GET['callbackUrl'] ?? '');

        $this->render('auth/login', [
            'title'       => 'Connexion — AEIC',
            'description' => 'Accédez à votre espace membre AEIC.',
            'callbackUrl' => $callback,
        ]);
    }

    /**
     * Traitement de la connexion.
     */
    public function login(): void
    {
        Middleware::requireGuest();

        $limiter = new RateLimiter();
        $ipKey = 'login:' . client_ip();

        // Limitation des tentatives (anti brute-force).
        if ($limiter->tooManyAttempts($ipKey, self::LOGIN_MAX_ATTEMPTS, self::LOGIN_WINDOW)) {
            $minutes = (int) ceil($limiter->availableIn($ipKey, self::LOGIN_WINDOW) / 60);
            $this->setFlash('error', sprintf(
                'Trop de tentatives échouées. Réessayez dans %d minute(s).',
                max(1, $minutes)
            ));
            redirect(url('/login'));
        }

        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $user = User::findByEmail($email);

        // Message générique : on ne révèle jamais si l'e-mail existe.
        if ($user === null || $user['password'] === null || !password_verify($password, (string) $user['password'])) {
            $limiter->hit($ipKey);
            $this->setFlash('error', 'Identifiants incorrects.');
            redirect(url('/login'));
        }

        // Compte désactivé par un administrateur.
        if ((int) $user['is_active'] !== 1) {
            $limiter->hit($ipKey);
            $this->setFlash('error', 'Ce compte est désactivé. Contactez l\'AEIC.');
            redirect(url('/login'));
        }

        // Succès des identifiants : étape 2FA si activée.
        if (TwoFactor::isEnabled((string) $user['id'])) {
            Auth::startSession();
            $_SESSION['_2fa_pending'] = [
                'user_id' => (string) $user['id'],
                'role'    => (string) $user['role'],
                'expires' => time() + 600,
            ];
            redirect(url('/login/verify'));
        }

        // Sans 2FA : connexion directe (l'obligation 2FA est vérifiée plus loin).
        $limiter->clear($ipKey);
        Auth::login((string) $user['id'], (string) $user['role']);

        $callback = (string) ($_POST['callbackUrl'] ?? '');
        $target = $this->safeCallback($callback);

        $this->setFlash('success', sprintf('Bonjour, %s !', e($user['prenom'])));
        redirect(url($target));
    }

    // -----------------------------------------------------------------
    //  Déconnexion
    // -----------------------------------------------------------------

    /**
     * Déconnexion : détruit la session et revient à l'accueil.
     */
    public function logout(): void
    {
        Auth::logout();
        redirect(url('/'));
    }

    // -----------------------------------------------------------------
    //  Mot de passe oublié
    // -----------------------------------------------------------------

    /**
     * Formulaire de demande de réinitialisation.
     */
    public function forgotForm(): void
    {
        Middleware::requireGuest();

        $this->render('auth/forgot', [
            'title'       => 'Mot de passe oublié — AEIC',
            'description' => 'Réinitialisez votre mot de passe AEIC.',
        ]);
    }

    /**
     * Traitement de la demande : génère un token et envoie l'e-mail.
     *
     * Pour des raisons de sécurité, le message de confirmation est toujours
     * identique, que l'e-mail existe ou non (pas de divulgation de compte).
     */
    public function forgot(): void
    {
        Middleware::requireGuest();

        $email = trim((string) ($_POST['email'] ?? ''));

        $user = $email !== '' && Validator::isValidEmail($email) ? User::findByEmail($email) : null;

        if ($user !== null) {
            $token = PasswordReset::createToken((string) $user['id']);

            try {
                Mailer::send('password_reset', (string) $user['email'], 'Réinitialiser votre mot de passe', [
                    'prenom'     => $user['prenom'] ?? '',
                    'resetUrl'   => APP_URL . url('/reset-password?token=' . $token),
                    'expiresIn'  => PasswordReset::EXPIRES_HOURS,
                ]);
            } catch (\Throwable) {
                // L'échec d'envoi ne doit pas lever d'erreur visible.
            }
        }

        $this->setFlash('success', 'Si un compte existe pour cette adresse, un e-mail de réinitialisation vient d\'être envoyé.');
        redirect(url('/login'));
    }

    /**
     * Formulaire de réinitialisation (depuis le lien e-mail).
     */
    public function resetForm(): void
    {
        Middleware::requireGuest();

        $token = (string) ($_GET['token'] ?? '');

        $this->render('auth/reset', [
            'title'       => 'Nouveau mot de passe — AEIC',
            'description' => 'Définissez un nouveau mot de passe.',
            'token'       => $token,
        ]);
    }

    /**
     * Traitement de la réinitialisation.
     */
    public function reset(): void
    {
        Middleware::requireGuest();

        $token    = (string) ($_POST['token'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['password_confirmation'] ?? '');

        $valid = PasswordReset::validate($token);

        $errors = [];
        if ($valid === null) {
            $errors[] = 'Ce lien de réinitialisation est invalide ou expiré.';
        } elseif (strlen($password) < 8) {
            $errors[] = 'Le mot de passe doit comporter au moins 8 caractères.';
        } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $errors[] = 'Le mot de passe doit contenir au moins une lettre et un chiffre.';
        } elseif ($password !== $confirm) {
            $errors[] = 'La confirmation ne correspond pas.';
        }

        if ($errors !== []) {
            $this->setFlash('error', implode(' ', $errors));
            redirect(url('/reset-password?token=' . rawurlencode($token)));
        }

        User::changePassword((string) $valid['user_id'], $password);
        PasswordReset::consume((string) $valid['reset_id']);

        $this->setFlash('success', 'Votre mot de passe a été réinitialisé. Vous pouvez vous connecter.');
        redirect(url('/login'));
    }

    /**
     * Valide une URL de retour pour éviter les redirections ouvertes.
     * N'autorise que les chemins internes relatifs.
     */
    private function safeCallback(string $callback): string
    {
        if ($callback === '') {
            return '/';
        }

        // Uniquement un chemin interne commençant par "/", pas de protocole.
        if (!str_starts_with($callback, '/') || preg_match('#^//#', $callback) === 1) {
            return '/';
        }

        return $callback;
    }
}
