<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;

/**
 * Modèle des utilisateurs.
 *
 * @table users
 */
final class User extends Model
{
    protected static string $table = 'users';

    /**
     * Nombre d'utilisateurs actifs (membres de l'association).
     */
    public static function countActive(): int
    {
        try {
            $stmt = static::pdo()->query(
                'SELECT COUNT(*) FROM users WHERE is_active = 1'
            );

            return (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Tous les utilisateurs (admin), triés par date d'inscription.
     *
     * @return list<array<string,mixed>>
     */
    public static function allForAdmin(): array
    {
        try {
            $stmt = static::pdo()->query('SELECT * FROM users ORDER BY created_at DESC');

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Nombre d'administrateurs actifs.
     */
    public static function countActiveAdmins(): int
    {
        try {
            $stmt = static::pdo()->prepare(
                'SELECT COUNT(*) FROM users WHERE role = ? AND is_active = 1'
            );
            $stmt->execute([Auth::ROLE_ADMIN]);

            return (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Change le rôle d'un utilisateur.
     */
    public static function setRole(string $userId, string $role): void
    {
        $stmt = static::pdo()->prepare('UPDATE users SET role = ? WHERE id = ?');
        $stmt->execute([$role, $userId]);
    }

    /**
     * Active ou désactive un compte.
     */
    public static function setActive(string $userId, bool $active): void
    {
        $stmt = static::pdo()->prepare('UPDATE users SET is_active = ? WHERE id = ?');
        $stmt->execute([$active ? 1 : 0, $userId]);
    }

    /**
     * Supprime un compte (RGPD : droit à l'effacement).
     *
     * Les commandes cafétéria sont conservées mais déliées de l'utilisateur
     * (ON DELETE SET NULL sur cafeteria_orders.user_id) pour les obligations
     * comptables ; les inscriptions et consentements sont supprimés en cascade.
     */
    public static function delete(string $userId): bool
    {
        $stmt = static::pdo()->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$userId]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Recherche un utilisateur par son adresse e-mail (normalisée en minuscules).
     *
     * @return array<string,mixed>|null
     */
    public static function findByEmail(string $email): ?array
    {
        $email = self::normalizeEmail($email);

        try {
            $stmt = static::pdo()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);

            $row = $stmt->fetch();
        } catch (\Throwable) {
            return null;
        }

        return $row ?: null;
    }

    /**
     * Indique si une adresse e-mail est déjà utilisée.
     */
    public static function emailExists(string $email): bool
    {
        return self::findByEmail($email) !== null;
    }

    /**
     * Crée un nouvel utilisateur (rôle ELEVE, compte actif).
     *
     * @return string L'identifiant (généré) du nouvel utilisateur.
     */
    public static function create(array $data): string
    {
        $id = $data['id'] ?? self::generateId();
        $email = self::normalizeEmail((string) $data['email']);
        $hash = (string) $data['password']; // déjà hashé par l'appelant.

        $stmt = static::pdo()->prepare(
            'INSERT INTO users (id, prenom, nom, email, password, role, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $id,
            trim((string) $data['prenom']),
            trim((string) $data['nom']),
            $email,
            $hash,
            $data['role'] ?? 'ELEVE',
        ]);

        return $id;
    }

    /**
     * Anonymise un compte suite à une demande RGPD d'effacement.
     *
     * Les données personnelles sont purgées (nom, e-mail, mot de passe, avatar)
     * et le compte est désactivé. Les enregistrements à obligation comptable
     * (commandes cafétéria) sont conservés mais déliés de l'identité (la FK
     * user_id passe à NULL via ON DELETE SET NULL sur la table ; ici on ne
     * supprime pas la ligne afin de garder la trace).
     *
     * L'e-mail étant NOT NULL et UNIQUE en base, on conserve une valeur
     * sentinelle déterministe et unique par compte (jamais réutilisée et
     * invalide), afin de respecter la contrainte tout en détruisant
     * l'identité réelle.
     */
    public static function anonymize(string $userId): void
    {
        $stmt = static::pdo()->prepare(
            'UPDATE users
             SET prenom = ?, nom = ?, email = ?, password = ?, image = ?, is_active = 0
             WHERE id = ?'
        );
        $stmt->execute([
            'Compte supprimé',
            'Compte supprimé',
            'deleted_' . $userId . '@invalid.local',
            null,
            null,
            $userId,
        ]);
    }

    /**
     * Normalise une adresse e-mail (trim + minuscules).
     */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Génère un identifiant unique suffisamment long.
     */
    public static function generateId(): string
    {
        return 'usr_' . bin2hex(random_bytes(12));
    }

    /**
     * Met à jour les informations de profil (prénom, nom, e-mail, image).
     *
     * @param array{prenom?:string,nom?:string,email?:string,image?:?string} $data
     */
    public static function updateProfile(string $userId, array $data): void
    {
        $fields = [];
        $values = [];

        foreach (['prenom', 'nom'] as $col) {
            if (isset($data[$col])) {
                $fields[] = $col . ' = ?';
                $values[] = trim((string) $data[$col]);
            }
        }
        if (isset($data['email'])) {
            $fields[] = 'email = ?';
            $values[] = self::normalizeEmail((string) $data['email']);
        }
        if (array_key_exists('image', $data)) {
            $fields[] = 'image = ?';
            $values[] = $data['image'];
        }

        if ($fields === []) {
            return;
        }

        $values[] = $userId;

        $stmt = static::pdo()->prepare(
            'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?'
        );
        $stmt->execute($values);
    }

    /**
     * Change le mot de passe (hash bcrypt).
     */
    public static function changePassword(string $userId, string $newPassword): void
    {
        $hash = password_hash($newPassword, PASSWORD_BCRYPT);

        $stmt = static::pdo()->prepare('UPDATE users SET password = ? WHERE id = ?');
        $stmt->execute([$hash, $userId]);
    }
}
