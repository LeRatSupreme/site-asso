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
     * Définit le pseudo joueur (pour le classement des jeux).
     * Normalise : trim, 3 à 20 caractères, alphanumériques + - _ .
     * Retourne le pseudo normalisé ou '' si invalide.
     */
    public static function setPseudo(string $userId, string $pseudo): string
    {
        $pseudo = self::normalizePseudo($pseudo);
        if ($pseudo === '') {
            return '';
        }

        // Vérifie l'unicité (insensible à la casse).
        $stmt = static::pdo()->prepare(
            'SELECT 1 FROM users WHERE LOWER(pseudo) = LOWER(?) AND id <> ? LIMIT 1'
        );
        $stmt->execute([$pseudo, $userId]);
        if ($stmt->fetch() !== false) {
            return ''; // déjà pris
        }

        $stmt = static::pdo()->prepare('UPDATE users SET pseudo = ? WHERE id = ?');
        $stmt->execute([$pseudo, $userId]);

        return $pseudo;
    }

    /**
     * Normalise un pseudo : 3 à 20 caractères alphanumériques, tirets, underscores.
     */
    public static function normalizePseudo(string $pseudo): string
    {
        $pseudo = trim($pseudo);
        if ($pseudo === '') {
            return '';
        }
        // Caractères autorisés : lettres, chiffres, espaces, tirets, underscores, points.
        if (!preg_match('/^[\p{L}\p{N} _\-.]{3,20}$/u', $pseudo)) {
            return '';
        }
        // Replie les espaces multiples.
        $pseudo = preg_replace('/\s+/u', ' ', $pseudo);
        return $pseudo;
    }

    /**
     * Vérifie qu'un pseudo est disponible (non utilisé par un autre utilisateur).
     */
    public static function isPseudoAvailable(string $pseudo, ?string $excludeUserId = null): bool
    {
        $pseudo = self::normalizePseudo($pseudo);
        if ($pseudo === '') {
            return false;
        }
        if ($excludeUserId !== null) {
            $stmt = static::pdo()->prepare(
                'SELECT 1 FROM users WHERE LOWER(pseudo) = LOWER(?) AND id <> ? LIMIT 1'
            );
            $stmt->execute([$pseudo, $excludeUserId]);
        } else {
            $stmt = static::pdo()->prepare(
                'SELECT 1 FROM users WHERE LOWER(pseudo) = LOWER(?) LIMIT 1'
            );
            $stmt->execute([$pseudo]);
        }
        return $stmt->fetch() === false;
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
     * Crée un nouvel utilisateur (rôle ELEVE, compte actif mais e-mail non
     * encore vérifié : `email_verified_at` vaut NULL jusqu'à confirmation).
     *
     * @return string L'identifiant (généré) du nouvel utilisateur.
     */
    public static function create(array $data): string
    {
        $id = $data['id'] ?? self::generateId();
        $email = self::normalizeEmail((string) $data['email']);
        $hash = (string) $data['password']; // déjà hashé par l'appelant.

        $stmt = static::pdo()->prepare(
            'INSERT INTO users (id, prenom, nom, email, password, role, is_active, email_verified_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, NULL)'
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
     * Marque l'e-mail d'un utilisateur comme vérifié (confirmation d'inscription).
     */
    public static function markEmailVerified(string $userId): void
    {
        $stmt = static::pdo()->prepare(
            'UPDATE users SET email_verified_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$userId]);
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
