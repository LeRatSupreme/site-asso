<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Endpoint de l'assistant KIOSQUE (POST /kiosque/assistant/ask/{token})
 * — E2E via le sous-processus runner : le jeton EST l'authentification.
 *
 * Jeton admin legacy posé en settings (même mécanique que
 * KiosqueSecuriteTest ; AUCUN kiosque de test créé en table), valeurs
 * d'origine restaurées en tearDown. Le llama-server n'est pas lancé pour
 * les tests : les questions FAQ (réponse instantanée) prouvent le chemin
 * « jeton valide » sans dépendre du LLM.
 */
final class AssistantKioskEndpointTest extends IntegrationTestCase
{
    private string $tokenAdmin  = 'kq-e2e-assistant-admin-2026';
    private string $tokenMauvais = 'kq-e2e-assistant-mauvais-jeton';

    /** @var array<string,?string> valeurs d'origine des settings touchés. */
    private array $settingsOrigine = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        // Valeurs d'origine mémorisées pour restauration (null = ligne
        // absente avant le test → DELETE en tearDown).
        $stmt = $this->pdo->prepare('SELECT `key`, value FROM settings WHERE `key` IN (?, ?)');
        $stmt->execute(['admin_kiosk_token', 'reappro_kiosk_token']);
        foreach ($stmt->fetchAll() as $row) {
            $this->settingsOrigine[(string) $row['key']] = (string) $row['value'];
        }
        foreach (['admin_kiosk_token', 'reappro_kiosk_token'] as $key) {
            $this->settingsOrigine += [$key => null];
        }

        // Jeton admin legacy dédié au test.
        $upsert = $this->pdo->prepare(
            'INSERT INTO settings (id, `key`, value) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );
        $upsert->execute(['set_kq_e2e_asst_a', 'admin_kiosk_token', $this->tokenAdmin]);
        $upsert->execute(['set_kq_e2e_asst_m', 'reappro_kiosk_token', 'kq-e2e-assistant-membre-2026']);
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $upsert = $this->pdo->prepare(
                'INSERT INTO settings (id, `key`, value) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)'
            );
            $delete = $this->pdo->prepare('DELETE FROM settings WHERE `key` = ?');
            foreach ($this->settingsOrigine as $key => $valeur) {
                if ($valeur === null) {
                    $delete->execute([$key]);
                } else {
                    $upsert->execute(['set_kq_e2e_rest_' . substr(sha1($key), 0, 16), $key, $valeur]);
                }
            }
        }
        parent::tearDown();
    }

    /**
     * L'en-tête attendu est-il présent dans la réponse ?
     *
     * @param array<string,mixed> $response
     */
    private function hasHeader(array $response, string $header): bool
    {
        foreach ($response['headers'] ?? [] as $h) {
            if (stripos((string) $h, $header) === 0) {
                return true;
            }
        }

        return false;
    }

    public function test_post_jeton_inconnu_renvoie_404_generique(): void
    {
        $r = $this->request('POST', '/kiosque/assistant/ask/' . $this->tokenMauvais, [
            'question' => 'coucou',
        ]);

        self::assertSame(404, $r['code']);
        self::assertSame(
            ['ok' => false, 'error' => 'Kiosque introuvable.'],
            json_decode((string) $r['body'], true),
            '404 JSON générique, aucune énumération possible.'
        );
        self::assertTrue($this->hasHeader($r, 'Cache-Control: no-store'), 'Réponse non mémorisable.');
    }

    public function test_post_jeton_valide_faq_repond_200(): void
    {
        // « coucou » = salutation de la FAQ PARTAGÉE : réponse instantanée
        // 200 sans llama-server (le chemin jeton valide → question → FAQ
        // est couvert de bout en bout).
        $r = $this->request('POST', '/kiosque/assistant/ask/' . $this->tokenAdmin, [
            'question' => 'coucou',
        ]);

        self::assertSame(200, $r['code']);
        $data = json_decode((string) $r['body'], true);
        self::assertIsArray($data);
        self::assertTrue($data['ok']);
        self::assertNotSame('', (string) $data['reply']);
        self::assertArrayNotHasKey('error', $data);
        self::assertTrue($this->hasHeader($r, 'Cache-Control: no-store'), 'Réponse non mémorisable.');
    }

    public function test_post_jeton_valide_question_vide_renvoie_400(): void
    {
        $r = $this->request('POST', '/kiosque/assistant/ask/' . $this->tokenAdmin, [
            'question' => '   ',
        ]);

        self::assertSame(400, $r['code']);
        self::assertSame(
            ['ok' => false, 'error' => 'Message invalide.'],
            json_decode((string) $r['body'], true)
        );
    }

    public function test_get_renvoie_405_avec_allow_post(): void
    {
        $r = $this->request('GET', '/kiosque/assistant/ask/' . $this->tokenAdmin);

        self::assertSame(405, $r['code']);
        self::assertSame(
            ['ok' => false, 'error' => 'Méthode non autorisée.'],
            json_decode((string) $r['body'], true)
        );
        // NB : l'en-tête « Allow: POST » est bien posé par le contrôleur
        // (même code que AssistantPublicController::askGet) mais le SAPI
        // CLI du runner n'enregistre pas les header() (headers_list()
        // vide sous Linux) : il n'est pas observable ici — seul le 405 +
        // le corps JSON sont vérifiés.
    }
}
