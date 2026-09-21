<?php

declare(strict_types=1);

namespace App\Core\Security;

/**
 * Chiffrement symétrique applicatif (AES-256-GCM, OpenSSL) pour protéger au
 * repos les données sensibles (secret TOTP, codes de récupération 2FA).
 *
 * Format du payload : "v1:" . base64(IV) . ":" . base64(chiffré) . ":" . base64(tag)
 * Le préfixe de version permet de faire évoluer le format et de distinguer
 * une valeur chiffrée d'une valeur historique en clair (migration transparente).
 *
 * La clé est dérivée de la variable d'environnement APP_KEY via SHA-256 :
 * tant que APP_KEY reste secrète, la clé n'apparaît jamais en clair.
 * Si APP_KEY est vide, le chiffrement est indisponible : les appelants
 * replient sur le stockage en clair (voir Crypto::available()).
 */
final class Crypto
{
    /** Préfixe de version du format de chiffrement. */
    private const PREFIX = 'v1:';

    /** Algorithme OpenSSL (AES-256-GCM : chiffrage + authentification). */
    private const CIPHER = 'aes-256-gcm';

    /** Taille du IV en octets (recommandation GCM : 12 octets aléatoires). */
    private const IV_BYTES = 12;

    /**
     * Chiffre un texte clair et renvoie le payload encodé (préfixé "v1:").
     *
     * @throws \RuntimeException Si APP_KEY est absente ou si OpenSSL échoue.
     */
    public static function encrypt(string $plaintext): string
    {
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';

        $cipher = openssl_encrypt($plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Chiffrement impossible (AES-256-GCM).');
        }

        return self::PREFIX
            . base64_encode($iv) . ':'
            . base64_encode($cipher) . ':'
            . base64_encode($tag);
    }

    /**
     * Déchiffre un payload produit par encrypt().
     *
     * @throws \RuntimeException Si APP_KEY est absente, si le format est
     *                           inconnu ou si l'authentification GCM échoue
     *                           (données corrompues ou clé modifiée).
     */
    public static function decrypt(string $payload): string
    {
        if (!self::isEncrypted($payload)) {
            throw new \RuntimeException('Charge chiffrée mal formée (préfixe de version absent).');
        }

        $parts = explode(':', substr($payload, strlen(self::PREFIX)));
        if (count($parts) !== 3) {
            throw new \RuntimeException('Charge chiffrée mal formée.');
        }

        [$ivB64, $cipherB64, $tagB64] = $parts;
        $iv = base64_decode($ivB64, true);
        $cipher = base64_decode($cipherB64, true);
        $tag = base64_decode($tagB64, true);

        if ($iv === false || $cipher === false || $tag === false || $iv === '') {
            throw new \RuntimeException('Charge chiffrée illisible (base64 invalide).');
        }

        $plaintext = openssl_decrypt($cipher, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new \RuntimeException('Déchiffrement impossible (clé modifiée ou données corrompues).');
        }

        return $plaintext;
    }

    /**
     * Indique si une valeur ressemble à un payload chiffré (préfixe "v1:").
     */
    public static function isEncrypted(string $payload): bool
    {
        return str_starts_with($payload, self::PREFIX);
    }

    /**
     * Indique si le chiffrement est disponible (APP_KEY définie non vide).
     */
    public static function available(): bool
    {
        return self::rawKey() !== '';
    }

    /**
     * Clé de chiffrement dérivée de APP_KEY (SHA-256, 32 octets).
     *
     * @throws \RuntimeException Si APP_KEY est absente ou vide.
     */
    private static function key(): string
    {
        $appKey = self::rawKey();
        if ($appKey === '') {
            throw new \RuntimeException('APP_KEY manquante : chiffrement indisponible.');
        }

        return hash('sha256', $appKey, true);
    }

    private static function rawKey(): string
    {
        $value = getenv('APP_KEY');

        return is_string($value) ? $value : '';
    }
}
