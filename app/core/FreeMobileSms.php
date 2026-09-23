<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Client de l'API SMS Free Mobile (smsapi.free-mobile.fr).
 *
 * L'option « Notifications par SMS » du forfait Free Mobile fournit un
 * couple utilisateur/clé qui permet d'envoyer un SMS sur SA propre ligne :
 *     https://smsapi.free-mobile.fr/sendmsg?user=...&pass=...&msg=...
 *
 * Codes de retour de l'API :
 *  - 200 : SMS envoyé ;
 *  - 400 : un paramètre est manquant ;
 *  - 402 : trop de SMS envoyés en trop peu de temps ;
 *  - 403 : service non activé sur le compte / identifiants invalides ;
 *  - 500 : erreur côté Free ;
 *  - 503 : quota de SMS dépassé.
 */
final class FreeMobileSms
{
    private const ENDPOINT = 'https://smsapi.free-mobile.fr/sendmsg';

    /** Longueur max d'un message acceptée par l'API. */
    public const MAX_LENGTH = 999;

    /**
     * Envoie un SMS via l'API Free Mobile.
     *
     * @param string $user  Identifiant Free Mobile (8 chiffres)
     * @param string $pass  Clé d'identification (paramètre « Notifications par SMS »)
     * @param string $message Corps du message (tronqué à MAX_LENGTH)
     *
     * @return array{ok:bool, status:int, error:?string}
     */
    public static function send(string $user, string $pass, string $message): array
    {
        $user  = trim($user);
        $pass  = trim($pass);
        $message = trim($message);

        if ($user === '' || $pass === '' || $message === '') {
            return ['ok' => false, 'status' => 0, 'error' => 'Identifiant, clé ou message manquant.'];
        }

        if (mb_strlen($message) > self::MAX_LENGTH) {
            $message = mb_substr($message, 0, self::MAX_LENGTH);
        }

        $url = self::ENDPOINT . '?' . http_build_query([
            'user' => $user,
            'pass' => $pass,
            'msg'  => $message,
        ]);

        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => 10,
                'ignore_errors' => true,
                'header'        => "User-Agent: AEIC-Site/1.0\r\n",
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        $status = self::responseStatus($http_response_header ?? []);

        if ($status === 200) {
            return ['ok' => true, 'status' => 200, 'error' => null];
        }

        return ['ok' => false, 'status' => $status, 'error' => self::errorMessage($status, $body)];
    }

    /**
     * Extrait le code HTTP depuis l'en-tête $http_response_header.
     *
     * @param list<string> $headers
     */
    private static function responseStatus(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', (string) $header, $m)) {
                return (int) $m[1];
            }
        }

        return 0;
    }

    private static function errorMessage(int $status, string|false $body): string
    {
        $detail = '';
        if (is_string($body) && $body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded) && isset($decoded['message'])) {
                $detail = ' : ' . (string) $decoded['message'];
            }
        }

        return match ($status) {
            0   => 'API injoignable (réseau, DNS ou TLS).',
            400 => 'Requête invalide (paramètre manquant).' . $detail,
            402 => 'Trop de SMS envoyés en peu de temps.' . $detail,
            403 => 'Service non activé sur la ligne ou identifiants invalides.' . $detail,
            500 => 'Erreur côté Free.' . $detail,
            503 => 'Quota de SMS dépassé.' . $detail,
            default => 'Erreur HTTP ' . $status . '.' . $detail,
        };
    }
}
