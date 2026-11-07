<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;

/**
 * Sélecteur de langue léger (Fonctionnalité 12).
 *
 * Stocke la préférence FR/EN dans un cookie (1 an) puis redirige vers la page
 * précédente. Aucune dépendance à la base de données.
 */
final class LocaleController extends Controller
{
    public function setLang(): void
    {
        $lang = strtolower((string) ($_POST['lang'] ?? $_GET['lang'] ?? 'fr'));
        if (!in_array($lang, available_langs(), true)) {
            $lang = 'fr';
        }

        $this->setCookie('aeic_lang', $lang, time() + 60 * 60 * 24 * 365);

        $back = $_POST['back'] ?? $_GET['back'] ?? $_GET['redirect'] ?? $_POST['redirect'] ?? $_SERVER['HTTP_REFERER'] ?? '/';
        $back = $this->safeBack((string) $back);

        redirect($back);
    }

    /**
     * Définit un cookie (testable : isolé de setcookie() natif).
     */
    protected function setCookie(string $name, string $value, int $expires): void
    {
        setcookie($name, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * N'autorise que les URLs relatives (anti-redirection ouverte).
     */
    private function safeBack(string $back): string
    {
        $back = trim($back);
        if ($back === '') {
            return '/';
        }

        // URLs absolues : on n'accepte que le même hôte que l'application.
        if (preg_match('#^https?://#i', $back)) {
            $host = parse_url($back, PHP_URL_HOST);
            $appHost = parse_url(APP_URL, PHP_URL_HOST);
            if ($host === false || $host !== $appHost) {
                return '/';
            }

            return $back;
        }

        // URL relative : on s'assure qu'elle commence par un /.
        return '/' . ltrim($back, '/');
    }
}
