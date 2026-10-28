<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Captcha\CaptchaService;
use App\Core\Controller;
use App\Core\Logger;
use App\Models\CaptchaSetting;

/**
 * CAPTCHA auto-hébergé : image anti-bot + page de démonstration.
 *
 * L'image est volontairement non mémorisable (no-cache) : chaque affichage
 * génère un nouveau challenge en session (hashé, usage unique). La démo
 * valide le code saisi SANS redirection (re-rendu direct avec le résultat).
 */
final class CaptchaController extends Controller
{
    /**
     * PNG du code courant (génère et stocke le challenge en session).
     */
    public function image(): void
    {
        $service = new CaptchaService(CaptchaSetting::snapshot());
        $code = $service->generate();

        // En-têtes AVANT toute sortie : l'image ne doit jamais être mise en
        // cache (chaque affichage = nouveau challenge).
        header('Content-Type: image/png');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo $service->render($code);
        exit;
    }

    /**
     * Page de démonstration : GET = formulaire vierge, POST = vérification.
     */
    public function demo(): void
    {
        $success = false;
        $error = '';
        $errorId = '';

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (CaptchaService::honeypotFilled($_POST)) {
                // Échec générique : on ne révèle PAS la cause au client
                // (le honeypot ne doit pas être détectable comme tel).
                Logger::warning('captcha.honeypot', ['ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli')]);
                $error = 'Vérification échouée. Merci de réessayer avec une nouvelle image.';
                $errorId = 'captcha-error';
            } elseif ((new CaptchaService(CaptchaSetting::snapshot()))->verify((string) ($_POST['captcha'] ?? ''))) {
                $success = true;
            } else {
                $error = 'Code incorrect ou expiré. Recopiez le code de la nouvelle image.';
                $errorId = 'captcha-error';
            }
        }

        $this->render('captcha/demo', [
            'title'       => 'Démo CAPTCHA — AEIC',
            'description' => 'Démonstration du code de vérification anti-robot auto-hébergé de l\'AEIC.',
            'success'     => $success,
            'error'       => $error,
            'errorId'     => $errorId,
        ], 'public');
    }
}
