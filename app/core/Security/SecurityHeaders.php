<?php

declare(strict_types=1);

namespace App\Core\Security;

/**
 * Envoi des en-têtes de sécurité HTTP.
 *
 * CSP, HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy,
 * Permissions-Policy. Le détail de la CSP est configurable (setting
 * `csp_directives`) ; une valeur sûre par défaut est appliquée.
 *
 * Séparation de la logique (build) et des effets de bord (header()) afin
 * de pouvoir tester unitairement les en-têtes produits.
 */
final class SecurityHeaders
{
    /** @var array<string,string>|null En-têtes du dernier appel send() (debug/tests). */
    private static ?array $last = null;

    /**
     * Renvoie la liste des en-têtes de sécurité à appliquer.
     *
     * @param bool $https La requête courante est-elle en HTTPS ?
     * @return array<string,string>
     */
    public static function build(bool $https = false, string $csp = ''): array
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options'        => 'SAMEORIGIN',
            'Referrer-Policy'        => 'strict-origin-when-cross-origin',
            'Permissions-Policy'     => 'geolocation=(), microphone=(), camera=()',
        ];

        $headers['Content-Security-Policy'] = $csp !== '' ? $csp : self::defaultCsp();

        if ($https) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $headers;
    }

    /**
     * Envoie les en-têtes (sauf en CLI où les en-têtes sont sans effet).
     */
    public static function send(bool $https = false, string $csp = ''): void
    {
        $headers = self::build($https, $csp);
        self::$last = $headers;

        if (php_sapi_name() === 'cli' || headers_sent()) {
            return;
        }

        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
    }

    /**
     * Renvoie les derniers en-têtes envoyés (pour inspection / tests).
     *
     * @return array<string,string>
     */
    public static function lastSent(): array
    {
        return self::$last ?? [];
    }

    /**
     * Politique CSP par défaut (permissive sur l'inline du fait du code
     * vanilla sans build, mais restrictive sur les origines externes).
     */
    public static function defaultCsp(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob: https:",
            "font-src 'self'",
            "connect-src 'self'",
            "frame-src 'self' https://www.openstreetmap.org",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]);
    }
}
