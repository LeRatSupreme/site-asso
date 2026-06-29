<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Security\RecoveryCodes;
use App\Core\Security\Totp;

/**
 * Modèle du 2FA (table `two_factor`) : secret TOTP + codes de récupération.
 *
 * Le secret est stocké en clair (chiffrage au repos recommandé en production
 * via une clé applicative). Les codes de récupération sont stockés hachés.
 */
final class TwoFactor extends Model
{
    protected static string $table = 'two_factor';

    /**
     * Récupère l'enregistrement 2FA d'un utilisateur (ou null).
     *
     * @return array<string,mixed>|null
     */
    public static function forUser(string $userId): ?array
    {
        try {
            $stmt = static::pdo()->prepare('SELECT * FROM two_factor WHERE user_id = ? LIMIT 1');
            $stmt->execute([$userId]);

            $row = $stmt->fetch();
        } catch (\Throwable) {
            return null;
        }

        return $row ?: null;
    }

    /**
     * Indique si le 2FA est activé pour un utilisateur.
     */
    public static function isEnabled(string $userId): bool
    {
        $row = self::forUser($userId);

        return $row !== null && (int) ($row['enabled']) === 1;
    }

    /**
     * Amorce l'activation : génère et stocke un secret (non encore confirmé).
     *
     * @return array{secret:string, recovery:list<string>}
     */
    public static function beginSetup(string $userId): array
    {
        $secret = Totp::generateSecret();
        $recovery = RecoveryCodes::generate();

        self::upsert($userId, [
            'secret'         => $secret,
            'enabled'        => 0,
            'recovery_codes' => json_encode(RecoveryCodes::hash($recovery), JSON_UNESCAPED_UNICODE),
            'enabled_at'     => null,
        ]);

        return ['secret' => $secret, 'recovery' => $recovery];
    }

    /**
     * Confirme l'activation après vérification d'un code valide.
     */
    public static function enable(string $userId): void
    {
        self::upsert($userId, [
            'enabled'    => 1,
            'enabled_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Désactive complètement le 2FA.
     */
    public static function disable(string $userId): void
    {
        $stmt = static::pdo()->prepare('DELETE FROM two_factor WHERE user_id = ?');
        $stmt->execute([$userId]);
    }

    /**
     * Vérifie un code TOTP pour un utilisateur.
     */
    public static function verifyCode(string $userId, string $code): bool
    {
        $row = self::forUser($userId);
        if ($row === null || empty($row['secret'])) {
            return false;
        }

        return Totp::verify((string) $row['secret'], $code);
    }

    /**
     * Tente de consommer un code de récupération.
     *
     * @return bool Succès (le code est alors retiré de la liste).
     */
    public static function useRecoveryCode(string $userId, string $code): bool
    {
        $row = self::forUser($userId);
        if ($row === null || empty($row['recovery_codes'])) {
            return false;
        }

        /** @var list<string> $hashed */
        $hashed = json_decode((string) $row['recovery_codes'], true) ?: [];
        [$ok, $remaining] = RecoveryCodes::verifyAndConsume($code, $hashed);

        if ($ok) {
            $stmt = static::pdo()->prepare(
                'UPDATE two_factor SET recovery_codes = ? WHERE user_id = ?'
            );
            $stmt->execute([json_encode($remaining, JSON_UNESCAPED_UNICODE), $userId]);
        }

        return $ok;
    }

    /**
     * Vérifie un code (TOTP ou récupération) — utilisé lors du login 2FA.
     */
    public static function verify(string $userId, string $code): bool
    {
        if (self::verifyCode($userId, $code)) {
            return true;
        }

        return self::useRecoveryCode($userId, $code);
    }

    /**
     * @param array<string,mixed> $fields
     */
    private static function upsert(string $userId, array $fields): void
    {
        $existing = self::forUser($userId);

        if ($existing === null) {
            $cols = ['user_id'];
            $placeholders = ['?'];
            $values = [$userId];
            foreach ($fields as $col => $val) {
                $cols[] = '`' . $col . '`';
                $placeholders[] = '?';
                $values[] = $val;
            }
            $stmt = static::pdo()->prepare(
                'INSERT INTO two_factor (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')'
            );
            $stmt->execute($values);
        } else {
            $set = [];
            $values = [];
            foreach ($fields as $col => $val) {
                $set[] = '`' . $col . '` = ?';
                $values[] = $val;
            }
            $values[] = $userId;
            $stmt = static::pdo()->prepare('UPDATE two_factor SET ' . implode(', ', $set) . ' WHERE user_id = ?');
            $stmt->execute($values);
        }
    }
}
