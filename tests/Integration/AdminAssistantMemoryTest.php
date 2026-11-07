<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * T4-P1-B — mémoire de conversation multi-tours de l'assistant :
 *
 *  - deux questions liées : la 2e (« Et la semaine dernière ? ») reçoit
 *    l'historique de la 1re — vérifié sur le prompt CONSTRUIT par le pont
 *    via son mode --dry-run (le contrôleur ajoute ce drapeau quand
 *    ASSISTANT_DRY_RUN=1 : aucun appel LLM ni outil, le pont imprime le
 *    prompt en JSON) : préfixe système stable EN TÊTE, puis historique,
 *    puis question courante ;
 *  - reset=1 purge la mémoire de session (la question suivante repart
 *    sans contexte) ;
 *  - pas de fuite entre utilisateurs : deux sessions distinctes (second
 *    navigateur simulé par nouvelleSession()), l'historique de l'un
 *    n'atteint JAMAIS le prompt de l'autre.
 *
 * Le dry-run mémorise l'échange exactement comme en production (réponse
 * « (exécution à blanc) ») : c'est ce qui permet d'enchaîner les questions
 * sans mobiliser le modèle local.
 */
final class AdminAssistantMemoryTest extends IntegrationTestCase
{
    private string $adminId = 'u_assist_mem_a';
    private string $autreId = 'u_assist_mem_b';

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireDatabase();
        $this->reset(['users']);
        $this->seedUser($this->adminId, 'assist-mem-a@exemple.fr', 'Password123456', 'SUPERADMIN');
        $this->seedUser($this->autreId, 'assist-mem-b@exemple.fr', 'Password123456', 'SUPERADMIN');
    }

    protected function extraEnv(): array
    {
        // Le contrôleur transmet --dry-run au pont (hook de test) : le pont
        // imprime le prompt construit sans appeler le LLM.
        return ['ASSISTANT_DRY_RUN' => '1'];
    }

    /**
     * Corps JSON décodé de la réponse + messages du prompt construit.
     *
     * @return array<int,array{role:string,content:string}>
     */
    private function messagesPont(array $response): array
    {
        self::assertSame(200, (int) ($response['code'] ?? 0), 'ask doit répondre 200.');
        $corps = json_decode((string) ($response['body'] ?? ''), true);
        self::assertIsArray($corps, 'La réponse doit être du JSON.');
        self::assertTrue((bool) ($corps['ok'] ?? false), 'ask doit réussir.');
        self::assertIsArray($corps['dry_run']['messages'] ?? null, 'Le prompt construit doit être renvoyé.');

        return $corps['dry_run']['messages'];
    }

    /**
     * Question 1 sans mémoire → question de suivi : l'historique suit,
     * TOUJOURS entre le préfixe système stable et la question courante.
     */
    public function test_la_deuxieme_question_porte_lhistorique_jusqu_au_pont(): void
    {
        $q1 = 'Combien de Bueno me reste-t-il ?';
        $r1 = $this->request('POST', '/admin/assistant/ask', ['question' => $q1], [], $this->adminId);
        $messages1 = $this->messagesPont($r1);

        // Première question : aucun contexte, préfixe système en tête.
        self::assertSame(
            ['system', 'user'],
            array_column($messages1, 'role'),
            'Sans mémoire, le prompt est système + question.'
        );
        self::assertSame($q1, $messages1[1]['content']);

        // L'échange est mémorisé en session (clé dédiée, pas en clair ailleurs).
        self::assertArrayHasKey('assistant_conversation', $r1['session'] ?? []);
        $echanges = $r1['session']['assistant_conversation']['echanges'] ?? null;
        self::assertIsArray($echanges);
        self::assertSame($q1, $echanges[0]['q'] ?? null, 'La question 1 doit être mémorisée.');

        // Question de suivi : « et la semaine dernière ? » sans tout répéter.
        $q2 = 'Et la semaine dernière ?';
        $r2 = $this->request('POST', '/admin/assistant/ask', ['question' => $q2], [], $this->adminId);
        $messages2 = $this->messagesPont($r2);

        self::assertSame(
            ['system', 'user', 'assistant', 'user'],
            array_column($messages2, 'role'),
            'Le prompt doit être : système, Q1, R1, Q2.'
        );
        // Le préfixe stable de V1-A reste EN TÊTE (réutilisation KV).
        self::assertStringContainsString(
            "Tu es l'assistant de l'association AEIC",
            $messages2[0]['content'],
            'Le préfixe système doit rester le premier message.'
        );
        self::assertSame($q1, $messages2[1]['content'], "L'historique doit porter la Q1.");
        self::assertSame($q2, $messages2[3]['content'], 'La question courante doit venir en dernier.');

        // La conversation complète revient pour l'affichage (bulles).
        $corps2 = json_decode((string) ($r2['body'] ?? ''), true);
        self::assertSame(
            ['user', 'assistant', 'user', 'assistant'],
            array_column($corps2['historique'] ?? [], 'role'),
            "L'historique d'affichage doit contenir les 2 échanges."
        );
    }

    /**
     * reset=1 purge la mémoire : session vidée, question suivante sans
     * contexte.
     */
    public function test_reset_purge_la_memoire(): void
    {
        $this->request(
            'POST',
            '/admin/assistant/ask',
            ['question' => 'Il me reste combien de Coca Cola ?'],
            [],
            $this->adminId
        );

        $r = $this->request('POST', '/admin/assistant/ask', ['reset' => '1'], [], $this->adminId);
        self::assertSame(200, (int) ($r['code'] ?? 0), 'reset doit répondre 200.');
        $corps = json_decode((string) ($r['body'] ?? ''), true);
        self::assertTrue((bool) ($corps['ok'] ?? false), 'reset doit réussir.');
        self::assertSame([], $corps['historique'] ?? null, 'reset doit renvoyer un historique vide.');
        self::assertArrayNotHasKey(
            'assistant_conversation',
            $r['session'] ?? [],
            'La clé de mémoire doit disparaître de la session.'
        );

        // La question suivante repart SANS mémoire.
        $r2 = $this->request('POST', '/admin/assistant/ask', ['question' => 'Et alors ?'], [], $this->adminId);
        $messages = $this->messagesPont($r2);
        self::assertSame(
            ['system', 'user'],
            array_column($messages, 'role'),
            'Après reset, aucune trace de la conversation précédente.'
        );
        self::assertStringNotContainsString('Coca', json_encode($messages, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Deux sessions distinctes (deux navigateurs) : l'historique de
     * l'utilisateur A n'atteint JAMAIS le prompt de l'utilisateur B.
     */
    public function test_deux_sessions_ne_partagent_pas_leur_memoire(): void
    {
        $questionA = 'Combien de Kitkat me reste-t-il en stock ?';
        $r1 = $this->request('POST', '/admin/assistant/ask', ['question' => $questionA], [], $this->adminId);
        $this->messagesPont($r1); // mémoire posée dans LA session de A

        // Second navigateur : session vide, utilisateur B connecté.
        $this->nouvelleSession();
        $r2 = $this->request(
            'POST',
            '/admin/assistant/ask',
            ['question' => 'Mes ventes de la semaine ?'],
            [],
            $this->autreId
        );
        $messages = $this->messagesPont($r2);

        self::assertSame(
            ['system', 'user'],
            array_column($messages, 'role'),
            "La session de B ne doit recevoir aucun message de l'historique de A."
        );
        self::assertStringNotContainsString(
            'Kitkat',
            json_encode($messages, JSON_UNESCAPED_UNICODE) . json_encode($r2['session'] ?? [], JSON_UNESCAPED_UNICODE),
            "Aucune trace de la question de A ni dans le prompt ni dans la session de B."
        );
    }
}
