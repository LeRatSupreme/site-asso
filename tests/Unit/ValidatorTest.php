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
            'prenom' => 'Alex',
            'nom'    => 'Martin',
            'email'  => 'alex@exemple.fr',
        ]);

        self::assertSame([], $errors);
    }

    public function test_prenom_ou_nom_manquant_est_rejete(): void
    {
        $errors = Validator::registration([
            'prenom' => '',
            'nom'    => '',
            'email'  => 'a@b.fr',
        ]);

        self::assertTrue(in_array('Le prénom est obligatoire.', $errors, true));
        self::assertTrue(in_array('Le nom est obligatoire.', $errors, true));
    }

    public function test_email_invalide_est_rejete(): void
    {
        $errors = Validator::registration([
            'prenom' => 'Alex',
            'nom'    => 'Martin',
            'email'  => 'pas-un-email',
        ]);

        self::assertTrue(in_array('L\'adresse e-mail n\'est pas valide.', $errors, true));
    }

    public function test_email_manquant_est_rejete(): void
    {
        $errors = Validator::registration([
            'prenom' => 'Alex',
            'nom'    => 'Martin',
            'email'  => '',
        ]);

        self::assertTrue(in_array('L\'adresse e-mail est obligatoire.', $errors, true));
    }

    public function test_is_valid_email(): void
    {
        self::assertTrue(Validator::isValidEmail('contact@aeic.fr'));
        self::assertFalse(Validator::isValidEmail('contact@aeic'));
        self::assertFalse(Validator::isValidEmail(''));
    }
}
