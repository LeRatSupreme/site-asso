<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Security\Crypto;
use App\Core\Security\RecoveryCodes;
use App\Core\Security\Totp;

/**
 * Modèle du 2FA (table `two_factor`) : secret TOTP + codes de récupération.
 *
 * Le secret et la liste hachée des codes de récupération sont chiffrés au
 * repos (AES-256-GCM, voir Crypto) lorsque APP_KEY est définie ; sinon ils
 * sont stockés en clair (fallback historique, avertissement journalisé).
 * La lecture accepte les deux formes : migration transparente des valeurs
 * existantes.
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

        $storedSecret = $secret;
        $recoveryJson = json_encode(RecoveryCodes::hash($recovery), JSON_UNESCAPED_UNICODE);

        if (Crypto::available()) {
            $storedSecret = Crypto::encrypt($secret);
            $recoveryJson = Crypto::encrypt($recoveryJson);
        } else {
            error_log('TwoFactor: APP_KEY absente — secret TOTP et codes de récupération stockés en clair.');
        }

        self::upsert($userId, [
            'secret'         => $storedSecret,
            'enabled'        => 0,
            'recovery_codes' => $recoveryJson,
            'enabled_at'     => null,
        ]);

        return ['secret' => $secret, 'recovery' => $recovery];
    }

    /**
     * Renvoie le secret TOTP en attente de confirmation (2FA non activé),
     * déchiffré — ou null s'il n'y en a pas.
     *
     * C'est la source de vérité du flux de configuration : la page setup
     * l'affiche et la confirmation le vérifie, ce qui garantit que le secret
     * affiché est bien celui contre lequel le code est comparé, même si la
     * session change (nouvel onglet, session reconstruite).
     */
    public static function pendingSecret(string $userId): ?string
    {
        $row = self::forUser($userId);
        if ($row === null || (int) ($row['enabled']) === 1 || empty($row['secret'])) {
            return null;
        }

        $secret = self::decryptStored((string) $row['secret']);

        return $secret !== '' ? $secret : null;
    }

    /**
     * Régénère uniquement les codes de récupération (le secret TOTP reste
     * inchangé) — utilisé quand les codes en clair ont été perdus (session
     * expirée) alors qu'un setup est déjà en cours.
     *
     * @return list<string>
     */
    public static function regenerateRecoveryCodes(string $userId): array
    {
        $recovery = RecoveryCodes::generate();
        $json = json_encode(RecoveryCodes::hash($recovery), JSON_UNESCAPED_UNICODE);
        if (Crypto::available()) {
            $json = Crypto::encrypt($json);
        }

        $stmt = static::pdo()->prepare('UPDATE two_factor SET recovery_codes = ? WHERE user_id = ?');
        $stmt->execute([$json, $userId]);

        return $recovery;
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

        return Totp::verify(self::decryptStored((string) $row['secret']), $code);
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

        $json = self::decryptStored((string) $row['recovery_codes']);

        /** @var list<string> $hashed */
        $hashed = json_decode($json, true) ?: [];
        [$ok, $remaining] = RecoveryCodes::verifyAndConsume($code, $hashed);

        if ($ok) {
            $remainingJson = json_encode($remaining, JSON_UNESCAPED_UNICODE);
            if (Crypto::available()) {
                $remainingJson = Crypto::encrypt($remainingJson);
            }

            $stmt = static::pdo()->prepare(
                'UPDATE two_factor SET recovery_codes = ? WHERE user_id = ?'
            );
            $stmt->execute([$remainingJson, $userId]);
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
     * Déchiffre une valeur stockée (secret ou JSON des codes de récupération).
     *
     * Migration transparente : une valeur historique en clair (préfixe "v1:"
     * absent) est renvoyée telle quelle ; une valeur chiffrée illisible
     * (clé APP_KEY changée, données corrompues) est journalisée et renvoyée
     * vide (la vérification échouera proprement).
     */
    private static function decryptStored(string $value): string
    {
        if (!Crypto::isEncrypted($value)) {
            return $value;
        }

        try {
            return Crypto::decrypt($value);
        } catch (\Throwable) {
            error_log('TwoFactor: déchiffrement impossible (clé APP_KEY modifiée ou données corrompues ?).');

            return '';
        }
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
