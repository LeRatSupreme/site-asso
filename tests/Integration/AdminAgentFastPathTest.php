<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * V1+ « questions préparées » — validations demandées :
 *
 *  1. FAST-PATH : « Il me reste combien de Bueno ? » (préparée, active,
 *     routage frais, GABARIT pré-écrit) répond SANS LLM — mode=fastpath,
 *     gabarit rempli sur le stock LIVE (aucun chiffre figé), réponse
 *     sourcée, < 5 s, entrée mode=fastpath au journal (redirigé vers
 *     tests/cache pour ne jamais polluer le vrai journal).
 *  2. Voie LLM INTACTE : en exécution à blanc (ASSISTANT_DRY_RUN=1, hook
 *     de test du contrôleur), la même question préparée N'EMPRUNTE PAS
 *     le fast-path et le pont répond normalement (prompt construit).
 *  3. Page /admin/agent : l'agrégat du journal s'affiche, les payloads
 *     d'injection du journal sont rendus INERTES (échappés).
 *  4. Sans session admin : la page est refusée.
 *  7. Aperçu live du gabarit depuis la page Agent (validation 7 du
 *     steer V1+) : les outils sont rejoués en lecture seule, le flash
 *     montre le gabarit REMPLI sur données live.
 *
 * Le rejeu du fast-path appelle le VRAI endpoint interne (boucle locale,
 * https://asso.aremond.ovh épinglé 127.0.0.1 — même mécanique que le
 * pont) : lecture seule, exactement ce que fait le pont à chaque
 * question réelle. Le jeton est lu de config.env sans jamais être
 * affiché.
 */
final class AdminAgentFastPathTest extends IntegrationTestCase
{
    private string $adminId = 'u_agent_fp';

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireDatabase();
        $this->reset(['users', 'assistant_questions_preparees']);
        $this->seedUser($this->adminId, 'agent-fp@exemple.fr', 'Password123456', 'SUPERADMIN');
        @mkdir(dirname(__DIR__) . '/cache', 0777, true);
        @unlink(self::cheminJournalTest());
    }

    protected function extraEnv(): array
    {
        $env = [
            'AEIC_ASSISTANT_JOURNAL' => self::cheminJournalTest(),
            'ASSISTANT_OUTILS_URL'   => 'https://asso.aremond.ovh', // vhost réel, épinglage boucle locale
        ];
        $jeton = self::jetonConfig();
        if ($jeton !== '') {
            $env['ASSISTANT_TOKEN'] = $jeton;
        }

        return $env;
    }

    public function test_question_preparee_repond_rapide_et_sourcee_en_fastpath(): void
    {
        if (self::jetonConfig() === '') {
            self::markTestSkipped('ASSISTANT_TOKEN absent de config.env : fast-path non testable ici.');
        }

        $question = 'Il me reste combien de Bueno ?';
        $this->pdo->prepare(
            'INSERT INTO assistant_questions_preparees
                (question_norm, question_affichee, categorie, active, ordre, routage_json, gabarit_reponse, mapping_json, source_journal, cree_le)
             VALUES (?, ?, \'quantite\', 1, 1, ?, ?, ?, 1, NOW())'
        )->execute([
            'il me reste combien de bueno',
            $question,
            '[{"outil":"stock_produit","params":{"nom":"Bueno"}}]',
            'Il vous reste {stock} unités de {cle_canonique} en stock. Dernier comptage : {dernier_comptage}.',
            '{"stock":"stock","cle_canonique":"cle_canonique","dernier_comptage":"dernier_comptage"}',
        ]);

        $debut = microtime(true);
        $r = $this->request('POST', '/admin/assistant/ask', ['question' => $question], [], $this->adminId);
        $elapsed = microtime(true) - $debut;

        self::assertSame(200, (int) ($r['code'] ?? 0));
        $corps = json_decode((string) ($r['body'] ?? ''), true);
        self::assertTrue((bool) ($corps['ok'] ?? false), 'ask doit réussir.');
        self::assertSame('fastpath', $corps['mode'] ?? null, 'La question préparée doit emprunter le fast-path.');
        self::assertMatchesRegularExpression('/^Il vous reste \d+ unités de Bueno en stock\. Dernier comptage : \d{4}-\d{2}-\d{2}/', (string) ($corps['reponse'] ?? ''), 'Le gabarit pré-écrit doit être rempli avec le stock LIVE (aucun chiffre figé).');
        self::assertStringContainsString('stock_produit(nom=Bueno)', implode(';', (array) ($corps['sources'] ?? [])), 'La réponse doit citer sa source (outil + paramètres).');
        self::assertLessThan(5.0, $elapsed, 'Le fast-path doit répondre en moins de 5 s (mesuré ' . round($elapsed, 2) . ' s).');

        // Journal : entrée mode=fastpath (journal REDIRIGÉ vers tests/cache).
        self::assertFileExists(self::cheminJournalTest());
        $lignes = file(self::cheminJournalTest(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $derniere = json_decode((string) end($lignes), true);
        self::assertTrue((bool) ($derniere['ok'] ?? false));
        self::assertSame('fastpath', $derniere['mode'] ?? null);
        self::assertSame($question, $derniere['question'] ?? null);
    }

    public function test_la_voie_llm_reste_intacte_malgre_une_question_preparee(): void
    {
        // Question préparée présente + dry-run : le contrôleur COURT-CIRUITE
        // le fast-path et exécute le PONT — preuve que la voie LLM normale
        // reste le repli de référence (contrat inchangé).
        $this->pdo->prepare(
            'INSERT INTO assistant_questions_preparees
                (question_norm, question_affichee, categorie, active, ordre, routage_json, source_journal, cree_le)
             VALUES (?, ?, \'quantite\', 1, 1, ?, 1, NOW())'
        )->execute([
            'il me reste combien de bueno',
            'Il me reste combien de Bueno ?',
            '[{"outil":"stock_produit","params":{"nom":"Bueno"}}]',
        ]);

        // ASSISTANT_DRY_RUN=1 pour CETTE requête uniquement : request()
        // n'a pas de paramètre d'env par appel — la variable est posée
        // dans l'environnement du process PHPUnit (hérité par le
        // sous-processus via inheritedEnv) puis retirée.
        putenv('ASSISTANT_DRY_RUN=1');
        $_ENV['ASSISTANT_DRY_RUN'] = '1';
        try {
            $r = $this->request(
                'POST',
                '/admin/assistant/ask',
                ['question' => 'Il me reste combien de Bueno ?'],
                [],
                $this->adminId
            );
        } finally {
            putenv('ASSISTANT_DRY_RUN');
            unset($_ENV['ASSISTANT_DRY_RUN']);
        }

        self::assertSame(200, (int) ($r['code'] ?? 0));
        $corps = json_decode((string) ($r['body'] ?? ''), true);
        self::assertTrue((bool) ($corps['ok'] ?? false));
        self::assertArrayNotHasKey('mode', $corps, 'En dry-run le pont (voie LLM) doit répondre, pas le fast-path.');
        self::assertIsArray($corps['dry_run']['messages'] ?? null, 'Le pont doit avoir construit son prompt.');
        self::assertSame('Il me reste combien de Bueno ?', $corps['dry_run']['messages'][count($corps['dry_run']['messages']) - 1]['content'] ?? null);
    }

    public function test_la_page_agent_affiche_l_agregat_et_rend_les_payloads_inertes(): void
    {
        $this->pdo->prepare(
            'INSERT INTO assistant_questions_preparees
                (question_norm, question_affichee, categorie, active, ordre, routage_json, source_journal, cree_le)
             VALUES (?, ?, \'restock\', 1, 1, ?, 1, NOW())'
        )->execute([
            'qu est ce qu il faut que je rachete',
            "Qu'est-ce qu'il faut que je rachète ?",
            '[{"outil":"produits_a_commander","params":{}}]',
        ]);

        $r = $this->request('GET', '/admin/agent', [], [], $this->adminId);

        self::assertSame(200, (int) ($r['code'] ?? 0));
        $html = (string) ($r['body'] ?? '');
        self::assertStringContainsString('Questions posées', $html);
        self::assertStringContainsString('Questions préparées', $html);
        self::assertStringContainsString('Il me reste combien de Bueno ?', $html, 'Le top question du journal doit apparaître.');
        self::assertStringContainsString(e("Qu'est-ce qu'il faut que je rachète ?"), $html, 'La question préparée doit apparaître (échappée par e(), comme tout le journal).');

        // Payload d'injection RÉEL du journal : rendu ÉCHAPPÉ (inerte).
        $extrait = '"; rm -rf / ; $(touch /tmp/pwned_secu)';
        self::assertStringContainsString(e($extrait), $html, 'Le payload doit être rendu échappé (htmlspecialchars).');
        self::assertStringNotContainsString($extrait, $html, 'Le payload brut ne doit JAMAIS apparaître tel quel.');
        self::assertStringContainsString('⚠', $html, 'Le badge d\'injection suspectée doit être signalé.');
    }

    public function test_la_page_agent_est_reservee_aux_admins(): void
    {
        $r = $this->request('GET', '/admin/agent'); // sans session
        self::assertNotSame(200, (int) ($r['code'] ?? 0), 'Sans session admin, la page doit être refusée.');
    }

    public function test_validation_7_apercu_du_gabarit_sur_donnees_live(): void
    {
        if (self::jetonConfig() === '') {
            self::markTestSkipped('ASSISTANT_TOKEN absent de config.env.');
        }
        $this->pdo->prepare(
            'INSERT INTO assistant_questions_preparees
                (question_norm, question_affichee, categorie, active, ordre, routage_json, gabarit_reponse, mapping_json, source_journal, cree_le)
             VALUES (?, ?, \'quantite\', 1, 1, ?, ?, ?, 1, NOW())'
        )->execute([
            'il me reste combien de bueno',
            'Il me reste combien de Bueno ?',
            '[{"outil":"stock_produit","params":{"nom":"Bueno"}}]',
            'Il vous reste {stock} unités de {cle_canonique}.',
            '{"stock":"stock","cle_canonique":"cle_canonique"}',
        ]);

        $r = $this->request('POST', '/admin/agent/apercu', ['id' => (string) $this->dernierId()], [], $this->adminId);
        self::assertSame(302, (int) ($r['code'] ?? 0), 'L\'aperçu redirige vers la page.');

        $page = $this->request('GET', '/admin/agent', [], [], $this->adminId);
        $html = (string) ($page['body'] ?? '');
        self::assertStringContainsString('unités de Bueno', $html, 'L\'aperçu flashé doit montrer le gabarit REMPLI sur données live.');
    }

    public function test_gabarit_avec_placeholder_exotique_est_refuse(): void
    {
        // Durcissement dev-secu : un gabarit contenant un token exotique
        // (casse mixte ici) non EXACTEMENT couvert par le mapping est
        // REFUSÉ par la validation — jamais enregistré tel quel.
        $this->pdo->prepare(
            'INSERT INTO assistant_questions_preparees
                (question_norm, question_affichee, categorie, active, ordre, routage_json, mapping_json, source_journal, cree_le)
             VALUES (?, ?, \'quantite\', 1, 1, ?, ?, 1, NOW())'
        )->execute([
            'il me reste combien de bueno',
            'Il me reste combien de Bueno ?',
            '[{"outil":"stock_produit","params":{"nom":"Bueno"}}]',
            '{"stock":"stock"}',
        ]);

        $r = $this->request('POST', '/admin/agent/gabarit', ['id' => $this->dernierId(), 'gabarit' => '{Stock} restants'], [], $this->adminId);
        self::assertSame(302, (int) ($r['code'] ?? 0), 'Le refus redirige vers la page.');

        $page = $this->request('GET', '/admin/agent', [], [], $this->adminId);
        $html = (string) ($page['body'] ?? '');
        self::assertStringContainsString('Gabarit refusé', $html, 'La validation doit refuser le placeholder exotique.');
        self::assertStringContainsString('Stock', $html, 'Le flash cite le token fautif (échappé par e()).');

        $gabaritEnBase = (string) $this->pdo->query('SELECT gabarit_reponse FROM assistant_questions_preparees WHERE id = ' . (int) $this->dernierId())->fetchColumn();
        self::assertSame('', $gabaritEnBase, 'Rien ne doit être enregistré pour un gabarit refusé.');
    }

    private function dernierId(): string
    {
        return (string) $this->pdo->query('SELECT MAX(id) FROM assistant_questions_preparees')->fetchColumn();
    }

    private static function cheminJournalTest(): string
    {
        return dirname(__DIR__) . '/cache/assistant_fastpath.jsonl';
    }

    /** Jeton serveur des outils (config.env) — lu, jamais affiché. */
    private static function jetonConfig(): string
    {
        $chemin = dirname(__DIR__, 2) . '/config.env';
        if (!is_file($chemin)) {
            return '';
        }
        $lignes = file($chemin, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lignes === false ? [] : $lignes as $ligne) {
            if (preg_match('/^\s*ASSISTANT_TOKEN\s*=\s*(.+?)\s*$/', $ligne, $m) === 1) {
                return trim($m[1], "\"'");
            }
        }

        return '';
    }
}
