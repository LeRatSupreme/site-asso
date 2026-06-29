<?php

declare(strict_types=1);

namespace App\Core\Security;

/**
 * Implémentation TOTP (RFC 6238) / HOTP (RFC 4226) en PHP pur.
 *
 * Aucune dépendance externe. Génère et vérifie des codes à 6 chiffres
 * à partir d'un secret partagé encodé en Base32.
 */
final class Totp
{
    /** Nombre de chiffres du code. */
    private const DIGITS = 6;

    /** Pas de temps en secondes. */
    private const STEP = 30;

    /** Alphabet Base32 (RFC 4648). */
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Génère un secret aléatoire encodé en Base32 (20 octets → 32 caractères).
     */
    public static function generateSecret(int $bytes = 20): string
    {
        $random = random_bytes($bytes);

        return self::base32Encode($random);
    }

    /**
     * Calcule le code TOTP pour l'instant donné.
     *
     * @param int|null $timestamp Horodatage (null = maintenant).
     */
    public static function code(string $secret, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        $counter = (int) floor($timestamp / self::STEP);

        return self::hotp($secret, $counter);
    }

    /**
     * Vérifie un code en acceptant une fenêtre de tolérance (± `window` pas).
     *
     * @param int $window Nombre de pas de tolérance avant/après (0 = strict).
     */
    public static function verify(string $secret, string $code, int $window = 1, ?int $timestamp = null): bool
    {
        if (strlen($code) !== self::DIGITS || !ctype_digit($code)) {
            return false;
        }

        $timestamp = $timestamp ?? time();
        $counter = (int) floor($timestamp / self::STEP);

        // Comparaison à durée constante sur chaque candidat.
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::hotp($secret, $counter + $i), $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Construit l'URI otpauth (scancode par les applications d'authentification).
     */
    public static function uri(string $secret, string $label, string $issuer = 'AEIC'): string
    {
        $label = rawurlencode($issuer . ':' . $label);

        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            $label,
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::STEP
        );
    }

    /**
     * HOTP (RFC 4226) : code à partir d'un compteur.
     */
    private static function hotp(string $secret, int $counter): string
    {
        $key = self::base32Decode($secret);
        if ($key === '' || strlen($key) < 8) {
            return str_repeat('0', self::DIGITS);
        }

        // Compteur sur 8 octets (big-endian).
        $bin = pack('N*', 0) . pack('N*', $counter);

        $hash = hash_hmac('sha1', $bin, $key, true);

        // Troncature dynamique.
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        );

        $otp = $binary % (10 ** self::DIGITS);

        return str_pad((string) $otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Encode des octets binaires en Base32.
     */
    public static function base32Encode(string $data): string
    {
        if ($data === '') {
            return '';
        }

        $result = '';
        $buffer = 0;
        $bitsLeft = 0;

        foreach (str_split($data) as $byte) {
            $buffer = ($buffer << 8) | ord($byte);
            $bitsLeft += 8;
            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $result .= self::BASE32_ALPHABET[($buffer >> $bitsLeft) & 0x1F];
            }
        }

        if ($bitsLeft > 0) {
            $result .= self::BASE32_ALPHABET[($buffer << (5 - $bitsLeft)) & 0x1F];
        }

        return $result;
    }

    /**
     * Décode une chaîne Base32 en octets binaires (normalise en majuscules).
     */
    public static function base32Decode(string $data): string
    {
        $data = strtoupper(rtrim($data, '='));
        if ($data === '') {
            return '';
        }

        $result = '';
        $buffer = 0;
        $bitsLeft = 0;

        for ($i = 0, $len = strlen($data); $i < $len; $i++) {
            $char = $data[$i];
            $value = strpos(self::BASE32_ALPHABET, $char);
            if ($value === false) {
                return '';
            }
            $buffer = ($buffer << 5) | $value;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $result .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $result;
    }
}
