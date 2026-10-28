<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Assistant\PublicAssistant;
use App\Core\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de l'assistant conversationnel PUBLIC
 * (App\Core\Assistant\PublicAssistant — endpoint POST /assistant/ask).
 *
 * Aucun test ici ne touche le vrai llama-server ni les vrais dossiers
 * logs/ et cache/ : rate limiter, journal et verrous sont redirigés vers
 * un dossier temporaire, et l'URL LLM pointe sur un port local fermé
 * (connexion refusée immédiate = « llama-server down »).
 */
final class AssistantPublicTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/aeic-assistant-test-' . getmypid() . '-' . uniqid('', true);
        @mkdir($this->tmp, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $fichier) {
            @unlink($fichier);
        }
        @rmdir($this->tmp);
    }

    /** Service branché sur un « llama-server down » (port fermé). */
    private function service(): PublicAssistant
    {
        return new PublicAssistant(
            new RateLimiter($this->tmp),
            'http://127.0.0.1:1/v1/chat/completions',
            $this->tmp . '/assistant_public.jsonl',
            $this->tmp . '/assistant_public'
        );
    }

    // -------------------------------------------------------------
    //  Validation question / history
    // -------------------------------------------------------------

    public function test_question_invalide_renvoie_400_message_exact(): void
    {
        $s = $this->service();

        foreach ([null, '', '   ', str_repeat('a', 501), ['injection'], 42] as $brut) {
            $r = $s->traiter($brut, null, '1.2.3.4');
            self::assertSame(400, $r['http'], 'doit rejeter : ' . var_export($brut, true));
            self::assertFalse($r['ok']);
            self::assertSame('Message invalide.', $r['error']);
        }
    }

    public function test_valider_question_accepte_1_a_500(): void
    {
        self::assertSame('Salut', PublicAssistant::validerQuestion('  Salut  '));
        self::assertSame(str_repeat('a', 500), PublicAssistant::validerQuestion(str_repeat('a', 500)));
        self::assertNull(PublicAssistant::validerQuestion(str_repeat('a', 501)));
        self::assertNull(PublicAssistant::validerQuestion(''));
    }

    public function test_history_invalide_renvoie_400(): void
    {
        $s = $this->service();

        $invalides = [
            'ce n est pas du json',
            '{"role":"user","content":"objet et non liste"}',
            '[{"role":"system","content":"role interdit"}]',
            '[{"role":"user","content":""}]',
            '[{"role":"user","content":"' . str_repeat('a', 801) . '"}]',
            '[{"role":"user"}]',
            json_encode(array_fill(0, 7, ['role' => 'user', 'content' => 'x'])),
            ['role' => 'user', 'content' => 'tableau et non string'],
            '42',
        ];

        foreach ($invalides as $history) {
            $r = $s->traiter('Bonjour', $history, '1.2.3.4');
            self::assertSame(400, $r['http'], 'doit rejeter : ' . var_export($history, true));
            self::assertSame('Message invalide.', $r['error']);
        }
    }

    public function test_valider_history_tolerances_et_limites(): void
    {
        self::assertSame([], PublicAssistant::validerHistory(null));
        self::assertSame([], PublicAssistant::validerHistory(''));
        self::assertSame([], PublicAssistant::validerHistory('   '));

        $attendu = [
            ['role' => 'user', 'content' => 'Salut'],
            ['role' => 'assistant', 'content' => 'Salut !'],
        ];
        self::assertSame($attendu, PublicAssistant::validerHistory(
            '[{"role":"user","content":" Salut "},{"role":"assistant","content":"Salut !","extra":"ignore"}]'
        ));

        $six = json_encode(array_fill(0, 6, ['role' => 'user', 'content' => 'x']));
        self::assertCount(6, PublicAssistant::validerHistory($six));
    }

    public function test_question_utf8_malforme_renvoie_400_sans_json_exception(): void
    {
        // Régression audit : avant le fix, les octets invalides passaient
        // validerQuestion puis faisaient échouer json_encode(JSON_THROW_ON_ERROR)
        // dans callLlm() → JsonException non attrapée → 500 au lieu de 400.
        $question = "\x80\x81ab";

        self::assertNull(PublicAssistant::validerQuestion($question));

        $r = $this->service()->traiter($question, null, '1.2.3.4');
        self::assertSame(400, $r['http']);
        self::assertFalse($r['ok']);
        self::assertSame('Message invalide.', $r['error']);
    }

    public function test_history_utf8_malforme_renvoie_400(): void
    {
        // Content d'un item avec octets UTF-8 invalides → rejet (400 / null).
        $history = '[{"role":"user","content":"' . "\x80\x81" . '"}]';
        self::assertNull(PublicAssistant::validerHistory($history));

        $r = $this->service()->traiter('Bonjour', $history, '1.2.3.4');
        self::assertSame(400, $r['http']);
        self::assertSame('Message invalide.', $r['error']);

        // La chaîne brute history elle-même malformée est aussi rejetée.
        self::assertNull(PublicAssistant::validerHistory("\x80\x81"));
    }

    // -------------------------------------------------------------
    //  Construction du payload LLM
    // -------------------------------------------------------------

    public function test_payload_llm_format_exact_sans_tools(): void
    {
        $messages = PublicAssistant::buildMessages('PROMPT', [
            ['role' => 'user', 'content' => 'Salut'],
            ['role' => 'assistant', 'content' => 'Salut !'],
        ], 'Question ?');

        self::assertSame('system', $messages[0]['role']);
        self::assertSame('PROMPT', $messages[0]['content']);
        self::assertSame('user', $messages[1]['role']);
        self::assertSame('assistant', $messages[2]['role']);
        self::assertSame('user', $messages[3]['role']);
        self::assertSame('Question ?', $messages[3]['content']);

        $payload = PublicAssistant::buildPayload($messages);
        self::assertSame('Qwen3-4B', $payload['model']);
        self::assertSame($messages, $payload['messages']);
        self::assertSame(0.4, $payload['temperature']);
        self::assertSame(400, $payload['max_tokens']);
        self::assertFalse($payload['stream']);
        self::assertSame(['enable_thinking' => false], $payload['chat_template_kwargs']);
        // L'assistant public n'a AUCUN outil : jamais de tools ni tool_choice.
        self::assertArrayNotHasKey('tools', $payload);
        self::assertArrayNotHasKey('tool_choice', $payload);
    }

    public function test_prompt_systeme_fichier_dedie_est_riche(): void
    {
        /** @var string $prompt */
        $prompt = require AEIC_ROOT . '/app/config/assistant_public.php';

        self::assertIsString($prompt);
        self::assertNotSame('', trim($prompt));
        self::assertStringContainsString('AEIC', $prompt);
        self::assertStringContainsString('19 rue Louis David', $prompt);
        // Garde-fous obligatoires présents dans le prompt éditable.
        self::assertStringContainsString('DONNÉES', $prompt);
        self::assertStringContainsString('AUCUNE donnée interne', $prompt);
    }

    // -------------------------------------------------------------
    //  Réponses : 503 LLM down, 429 burst, journal
    // -------------------------------------------------------------

    public function test_llm_injoignable_renvoie_503_generique(): void
    {
        $r = $this->service()->traiter('Qui es-tu ?', null, '1.2.3.4');

        self::assertFalse($r['ok']);
        self::assertSame(503, $r['http']);
        self::assertSame("L'assistant est indisponible, réessaie dans un instant.", $r['error']);
    }

    public function test_429_apres_burst_de_10_questions(): void
    {
        $s = $this->service();

        for ($i = 0; $i < 10; $i++) {
            $r = $s->traiter('Question ' . $i, null, '5.6.7.8');
            // llama-server down dans le test → 503, mais la question est
            // comptée (rate limit AVANT l'appel LLM).
            self::assertSame(503, $r['http']);
        }

        $r = $s->traiter('Onzième question', null, '5.6.7.8');
        self::assertSame(429, $r['http']);
        self::assertSame('Trop de questions, reviens dans quelques minutes.', $r['error']);

        // Une autre IP n'est pas pénalisée (clé par IP).
        $r = $s->traiter('Question autre IP', null, '9.9.9.9');
        self::assertSame(503, $r['http']);
    }

    public function test_journal_jsonl_une_ligne_par_requete(): void
    {
        $path = $this->tmp . '/assistant_public.jsonl';
        $s = $this->service();

        $s->traiter('', null, '1.2.3.4');              // 400
        $s->traiter('Bonjour', null, '1.2.3.4');       // 503 (LLM down)

        $lignes = array_values(array_filter(explode("\n", (string) @file_get_contents($path))));
        self::assertCount(2, $lignes);

        $premiere = json_decode($lignes[0], true);
        self::assertSame(400, $premiere['status']);
        self::assertSame('', $premiere['reply']);

        $seconde = json_decode($lignes[1], true);
        self::assertSame(503, $seconde['status']);
        self::assertSame('Bonjour', $seconde['question']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $seconde['ip_hash']);
        self::assertNotSame('', $seconde['ts']);
        self::assertIsInt($seconde['latency_ms']);
    }

    public function test_fichiers_runtime_sont_0666_reouvrables_cli_et_web(): void
    {
        // Incident 28/10 (prod) : un journal/verrou créé par un run CLI
        // ubuntu (umask → 0664, owner ubuntu) empêchait Apache www-data
        // de rouvrir les fichiers (fopen 'c' échoue → 503 « occupé »
        // déterministe pour tout le web). Tout fichier runtime de
        // l'assistant doit donc finir en 0666, ré-ouvrable par l'autre
        // utilisateur du VPS mono-admin.

        // 1) Journal écrit par traiter().
        $journal = $this->tmp . '/assistant_public.jsonl';
        $s = $this->service();
        $s->traiter('Bonjour', null, '1.2.3.4'); // 503 (LLM down) mais journal écrit
        clearstatcache(true, $journal);
        self::assertFileExists($journal);
        self::assertSame(0666, fileperms($journal) & 0777);

        // 2) Fichier de slot acquis (API privée → réflexion, pas de
        // nouvelle API publique inventée pour le test).
        $acquerir = new \ReflectionMethod(PublicAssistant::class, 'acquerirSlot');
        $handle = $acquerir->invoke($s);
        self::assertIsResource($handle);

        $lock = $this->tmp . '/assistant_public.0.lock'; // slot 0 essayé en premier
        clearstatcache(true, $lock);
        self::assertFileExists($lock);
        self::assertSame(0666, fileperms($lock) & 0777);

        $liberer = new \ReflectionMethod(PublicAssistant::class, 'libererSlot');
        $liberer->invoke($s, $handle);
    }

    // -------------------------------------------------------------
    //  Helpers : extraction et hachage IP
    // -------------------------------------------------------------

    public function test_extract_reply_retire_le_bloc_think(): void
    {
        $body = json_encode(['choices' => [['message' => ['content' => '<think>raisonnement interne</think> Voilà la réponse !']]]]);
        self::assertSame('Voilà la réponse !', PublicAssistant::extractReply($body));

        $multiligne = json_encode(['choices' => [['message' => ['content' => "<think>ligne1\nligne2</think>\nRéponse"]]]]);
        self::assertSame('Réponse', PublicAssistant::extractReply($multiligne));
    }

    public function test_extract_reply_invalide_renvoie_null(): void
    {
        self::assertNull(PublicAssistant::extractReply(null));
        self::assertNull(PublicAssistant::extractReply('pas du json'));
        self::assertNull(PublicAssistant::extractReply('{"choices":[]}'));
        self::assertNull(PublicAssistant::extractReply('{"choices":[{"message":{"content":""}}]}'));
        self::assertNull(PublicAssistant::extractReply('{"choices":[{"message":{"content":"   "}}]}'));
    }

    public function test_hash_ip_court_et_deterministe(): void
    {
        self::assertSame(PublicAssistant::hashIp('1.2.3.4'), PublicAssistant::hashIp('1.2.3.4'));
        self::assertNotSame(PublicAssistant::hashIp('1.2.3.4'), PublicAssistant::hashIp('1.2.3.5'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', PublicAssistant::hashIp('1.2.3.4'));
    }

    // -------------------------------------------------------------

    public function test_format_json_exact_du_contrat(): void
    {
        // Le contrôleur encode exactement ces formes (JSON_UNESCAPED_UNICODE
        // | JSON_UNESCAPED_SLASHES via Controller::json) — verrouillage du
        // contrat consommé par le widget front.
        $s = $this->service();

        $r = $s->traiter('', null, '1.2.3.4');
        $json = json_encode(['ok' => false, 'error' => $r['error']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::assertSame('{"ok":false,"error":"Message invalide."}', $json);

        $r = $s->traiter('ok', null, '1.2.3.4');
        self::assertSame(503, $r['http']);
        $json = json_encode(['ok' => false, 'error' => $r['error']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::assertSame('{"ok":false,"error":"L\'assistant est indisponible, réessaie dans un instant."}', $json);
    }
}
