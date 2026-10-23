<?php

declare(strict_types=1);

namespace App\Core\Compta;

use App\Models\Setting;

/**
 * Jeton et liens « kiosque » : accès téléphone sans connexion à des pages
 * précises (Réappro, Liste de courses, Comptage…). Un seul jeton partagé
 * (settings.reappro_kiosk_token), généré à la première utilisation,
 * révocable via la régénération sur la page Réappro.
 */
final class Kiosk
{
    /**
     * Jeton kiosque (généré à la première utilisation).
     */
    public static function token(): string
    {
        $token = trim((string) Setting::get('reappro_kiosk_token', ''));
        if ($token === '') {
            $token = bin2hex(random_bytes(20));
            Setting::set('reappro_kiosk_token', $token);
        }

        return $token;
    }

    /**
     * URL absolue d'une page kiosque (le jeton est ajouté en fin de chemin).
     * Ex. : Kiosk::url('/kiosque/liste/') → https://…/kiosque/liste/<jeton>
     */
    public static function url(string $path): string
    {
        return url(rtrim($path, '/') . '/' . self::token());
    }

    /**
     * En-têtes « ne pas mémoriser » pour TOUTES les pages à jeton : le
     * lien secret EST l'authentification, aucune réponse (HTML, JSON ou
     * refus 403) ne doit pouvoir être conservée par un cache/le navigateur.
     *
     * Point central unique : appelé par le routeur pour chaque requête
     * /kiosque/*, par Controller::renderKiosk() (défense en profondeur)
     * et par les pages admin qui affichent un lien kiosque (/admin/kiosques,
     * réappro, liste, caisse, comptage à l'aveugle).
     *
     * En APP_TESTING, les en-têtes sont aussi exposés via
     * $GLOBALS['AEIC_TEST_HEADERS'] : le SAPI CLI n'enregistre pas header()
     * (headers_list() renvoie [] sous Linux), le runner d'intégration doit
     * donc les recapturer — même mécanique que AEIC_TEST_LOCATION.
     */
    public static function sendNoStore(): void
    {
        $headers = [
            'Cache-Control: no-store, max-age=0',
            'Pragma: no-cache',
        ];

        if (!headers_sent()) {
            foreach ($headers as $h) {
                header($h);
            }
        }

        if (defined('APP_TESTING') && APP_TESTING) {
            $GLOBALS['AEIC_TEST_HEADERS'] ??= [];
            foreach ($headers as $h) {
                if (!in_array($h, $GLOBALS['AEIC_TEST_HEADERS'], true)) {
                    $GLOBALS['AEIC_TEST_HEADERS'][] = $h;
                }
            }
        }
    }

    /**
     * Jeton kiosque ADMIN (données financières complètes) : totalement
     * indépendant du jeton membres — régénérable séparément. Généré à la
     * première utilisation.
     */
    public static function adminToken(): string
    {
        $token = trim((string) Setting::get('admin_kiosk_token', ''));
        if ($token === '') {
            $token = bin2hex(random_bytes(20));
            Setting::set('admin_kiosk_token', $token);
        }

        return $token;
    }

    /**
     * URL absolue d'une page kiosque ADMIN (jeton admin ajouté en fin de
     * chemin). Ex. : Kiosk::adminUrl('/kiosque/admin/jour/')
     */
    public static function adminUrl(string $path): string
    {
        return url(rtrim($path, '/') . '/' . self::adminToken());
    }
}
