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
}
