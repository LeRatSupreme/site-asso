<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Auth;

/**
 * Tests d'intégration du flux d'inscription (route POST /register + base).
 *
 * Note : redirect() émet un en-tête Location sans fixer le code HTTP ; on
 * valide donc les redirections via l'en-tête Location.
 */
final class RegistrationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->requireDatabase();
        $this->reset(['consents', 'users', 'settings']);
    }

    public function test_inscription_valide_cree_un_eleve_actif_et_journalise_le_consentement(): void
    {
        $email = 'nouveau' . bin2hex(random_bytes(3)) . '@exemple.fr';

        $response = $this->request('POST', '/register', [
            'prenom'                => 'Ada',
            'nom'                   => 'Lovelace',
            'email'                 => $email,
            'password'              => 'Password1',
            'password_confirmation' => 'Password1',
            'consent'               => '1',
        ]);

        // Redirection vers /login (le compte n'est pas auto-connecté).
        self::assertStringContainsString('/login', $this->location($response));

        $user = $this->pdo->prepare('SELECT * FROM users WHERE email = ?');
        $user->execute([$email]);
        $row = $user->fetch();

        self::assertNotNull($row);
        self::assertSame(Auth::ROLE_ELEVE, $row['role']);
        self::assertSame('1', (string) $row['is_active']);

        $consents = $this->pdo->prepare('SELECT * FROM consents WHERE user_id = ? AND consent_type = ?');
        $consents->execute([$row['id'], 'registration']);
        self::assertNotFalse($consents->fetch(), 'Un consentement RGPD doit être journalisé.');
    }

    public function test_email_deja_utilise_rejete_sans_double_compte(): void
    {
        $this->seedUser('u_existing', 'deja@exemple.fr');

        $countBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

        $response = $this->request('POST', '/register', [
            'prenom'                => 'Dup',
            'nom'                   => 'Able',
            'email'                 => 'deja@exemple.fr',
            'password'              => 'Password1',
            'password_confirmation' => 'Password1',
            'consent'               => '1',
        ]);

        self::assertStringContainsString('/register', $this->location($response));

        $countAfter = (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        self::assertSame($countBefore, $countAfter, 'Aucun compte supplémentaire ne doit être créé.');
    }

    public function test_mot_de_passe_trop_court_rejete(): void
    {
        $countBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

        $response = $this->request('POST', '/register', [
            'prenom'                => 'Court',
            'nom'                   => 'Mdp',
            'email'                 => 'court@exemple.fr',
            'password'              => 'ab1',
            'password_confirmation' => 'ab1',
            'consent'               => '1',
        ]);

        self::assertStringContainsString('/register', $this->location($response));
        self::assertSame($countBefore, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function test_consentement_non_coche_rejete(): void
    {
        $countBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

        $response = $this->request('POST', '/register', [
            'prenom'                => 'No',
            'nom'                   => 'Consent',
            'email'                 => 'noconsent@exemple.fr',
            'password'              => 'Password1',
            'password_confirmation' => 'Password1',
            // consent absent
        ]);

        self::assertStringContainsString('/register', $this->location($response));
        self::assertSame($countBefore, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }
}
