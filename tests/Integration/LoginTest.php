<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Tests d'intégration du flux de connexion (route POST /login + session).
 */
final class LoginTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->requireDatabase();
        $this->reset(['users', 'settings']);
    }

    public function test_bons_identifiants_connectent_l_utilisateur(): void
    {
        $this->seedUser('u_login_ok', 'login@exemple.fr', 'Password1');

        $response = $this->login('login@exemple.fr', 'Password1');

        // Succès : redirection vers l'accueil (callback par défaut).
        self::assertStringContainsString('//example.test/', $this->location($response));
        self::assertSame('u_login_ok', $response['session']['user_id'] ?? null);
        self::assertSame('ELEVE', $response['session']['user_role'] ?? null);
    }

    public function test_mauvais_mot_de_passe_refuse_la_connexion(): void
    {
        $this->seedUser('u_login_bad', 'bad@exemple.fr', 'Password1');

        $response = $this->login('bad@exemple.fr', 'Mauvais-Mot-De-Passe-1');

        self::assertStringContainsString('/login', $this->location($response));
        self::assertArrayNotHasKey('user_id', $response['session'] ?? []);
    }

    public function test_compte_inactif_refuse_la_connexion(): void
    {
        $this->seedUser('u_login_inactive', 'inactive@exemple.fr', 'Password1', 'ELEVE', 0);

        $response = $this->login('inactive@exemple.fr', 'Password1');

        self::assertStringContainsString('/login', $this->location($response));
        self::assertArrayNotHasKey('user_id', $response['session'] ?? []);
    }
}
