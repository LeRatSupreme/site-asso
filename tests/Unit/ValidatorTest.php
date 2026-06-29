<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de la validation d'inscription (sans base de données).
 */
final class ValidatorTest extends TestCase
{
    public function test_inscription_valide_renvoie_aucune_erreur(): void
    {
        $errors = Validator::registration([
            'prenom'                => 'Alex',
            'nom'                   => 'Martin',
            'email'                 => 'alex@exemple.fr',
            'password'              => 'Secret123',
            'password_confirmation' => 'Secret123',
        ]);

        self::assertSame([], $errors);
    }

    public function test_prenom_ou_nom_manquant_est_rejete(): void
    {
        $errors = Validator::registration([
            'prenom'                => '',
            'nom'                   => '',
            'email'                 => 'a@b.fr',
            'password'              => 'Secret123',
            'password_confirmation' => 'Secret123',
        ]);

        self::assertTrue(in_array('Le prénom est obligatoire.', $errors, true));
        self::assertTrue(in_array('Le nom est obligatoire.', $errors, true));
    }

    public function test_email_invalide_est_rejete(): void
    {
        $errors = Validator::registration([
            'prenom'                => 'Alex',
            'nom'                   => 'Martin',
            'email'                 => 'pas-un-email',
            'password'              => 'Secret123',
            'password_confirmation' => 'Secret123',
        ]);

        self::assertTrue(in_array('L\'adresse e-mail n\'est pas valide.', $errors, true));
    }

    public function test_mot_de_passe_trop_court_est_rejete(): void
    {
        $errors = Validator::registration([
            'prenom'                => 'Alex',
            'nom'                   => 'Martin',
            'email'                 => 'a@b.fr',
            'password'              => 'Ab1',
            'password_confirmation' => 'Ab1',
        ]);

        self::assertSame(1, count($errors));
        self::assertStringContainsString('8 caractères', $errors[0]);
    }

    public function test_mot_de_passe_sans_chiffre_est_rejete(): void
    {
        $errors = Validator::registration([
            'prenom'                => 'Alex',
            'nom'                   => 'Martin',
            'email'                 => 'a@b.fr',
            'password'              => 'MotDePasseLong',
            'password_confirmation' => 'MotDePasseLong',
        ]);

        self::assertTrue(in_array('Le mot de passe doit contenir au moins une lettre et un chiffre.', $errors, true));
    }

    public function test_confirmation_differente_est_rejetee(): void
    {
        $errors = Validator::registration([
            'prenom'                => 'Alex',
            'nom'                   => 'Martin',
            'email'                 => 'a@b.fr',
            'password'              => 'Secret123',
            'password_confirmation' => 'Autre456',
        ]);

        self::assertTrue(in_array('La confirmation du mot de passe ne correspond pas.', $errors, true));
    }

    public function test_is_valid_email(): void
    {
        self::assertTrue(Validator::isValidEmail('contact@aeic.fr'));
        self::assertFalse(Validator::isValidEmail('contact@aeic'));
        self::assertFalse(Validator::isValidEmail(''));
    }
}
