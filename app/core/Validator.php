<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Validation des formulaires d'authentification.
 *
 * Les méthodes renvoient une liste de messages d'erreur (vide = données valides).
 * Les contrôles de format sont purement déterministes (testables sans base) ;
 * l'unicité de l'e-mail est vérifiée séparément par le contrôleur (dépend de la DB).
 */
final class Validator
{
    /** Longueur minimale du mot de passe. */
    public const PASSWORD_MIN = 8;

    /**
     * Valide les données d'inscription.
     *
     * @param array{prenom?:string,nom?:string,email?:string,password?:string,password_confirmation?:string} $data
     * @return list<string> Messages d'erreur (vide si tout est valide).
     */
    public static function registration(array $data): array
    {
        $errors = [];

        $prenom = trim((string) ($data['prenom'] ?? ''));
        $nom = trim((string) ($data['nom'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $confirmation = (string) ($data['password_confirmation'] ?? '');

        if ($prenom === '') {
            $errors[] = 'Le prénom est obligatoire.';
        }
        if ($nom === '') {
            $errors[] = 'Le nom est obligatoire.';
        }

        if ($email === '') {
            $errors[] = 'L\'adresse e-mail est obligatoire.';
        } elseif (!self::isValidEmail($email)) {
            $errors[] = 'L\'adresse e-mail n\'est pas valide.';
        }

        if (mb_strlen($password) < self::PASSWORD_MIN) {
            $errors[] = sprintf('Le mot de passe doit contenir au moins %d caractères.', self::PASSWORD_MIN);
        } elseif (!self::isStrongEnough($password)) {
            $errors[] = 'Le mot de passe doit contenir au moins une lettre et un chiffre.';
        }

        if ($password !== $confirmation) {
            $errors[] = 'La confirmation du mot de passe ne correspond pas.';
        }

        return $errors;
    }

    /**
     * Vérifie qu'une chaîne est un e-mail valide.
     */
    public static function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Vérifie la robustesse minimale : au moins une lettre ET un chiffre.
     */
    public static function isStrongEnough(string $password): bool
    {
        return preg_match('/[A-Za-zÀ-ÿ]/u', $password) === 1
            && preg_match('/[0-9]/', $password) === 1;
    }
}
