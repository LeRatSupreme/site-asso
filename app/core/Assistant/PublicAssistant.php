<?php

declare(strict_types=1);

namespace App\Core\Assistant;

use App\Core\Logger;
use App\Core\RateLimiter;

/**
 * Assistant conversationnel PUBLIC de l'AEIC (widget chat du site).
 *
 * Chemin volontairement SÉPARÉ de l'assistant admin (pont Python +
 * AssistantToolsController) : ici, aucun outil, aucun jeton, aucune
 * donnée interne — juste un prompt public éditable (app/config/
 * assistant_public.php) envoyé au llama-server local (API compatible
 * OpenAI, même format que scripts/assistant.py en mode direct).
 *
 * Contrat figé avec le widget front (messages EXACTS) :
 *  - 400 {"ok":false,"error":"Message invalide."}
 *  - 429 {"ok":false,"error":"Trop de questions, reviens dans quelques minutes."}
 *  - 503 {"ok":false,"error":"L'assistant est occupé, réessaie dans un instant."}   (sémaphore plein)
 *  - 503 {"ok":false,"error":"L'assistant est indisponible, réessaie dans un instant."} (LLM down/timeout)
 *  - 200 {"ok":true,"reply":"..."}
 *
 * Garde-fous :
 *  - rate limit 10 questions / 5 min / IP (RateLimiter partagé, fichiers) ;
 *  - sémaphore 2 appels LLM simultanés (flock sur 2 fichiers de slot,
 *    protège llama-server 4 threads) — verrou TOUJOURS libéré avant la
 *    réponse (json() du contrôleur fait exit) ;
 *  - fail-closed : tout échec réseau/parse → 503 générique, détail en
 *    Logger::error uniquement, jamais dans la réponse ;
 *  - journal d'observation logs/assistant_public.jsonl : une ligne par
 *    requête {ts, ip_hash, question, reply, latency_ms, status} — l'IP
 *    n'est JAMAIS écrite en clair (sha256 tronqué, sel APP_KEY).
 *
 * Toutes les dépendances (storage rate limit, URL LLM, journal, locks)
 * sont injectables pour les tests unitaires (tests/Unit/AssistantPublicTest).
 */
final class PublicAssistant
{
    /** llama-server local (même cible que scripts/assistant.py). */
    public const LLM_URL_DEFAUT = 'http://127.0.0.1:8080/v1/chat/completions';

    /** Modèle servi par llama-server (constante LLM_MODELE du pont admin). */
    private const LLM_MODELE = 'Qwen3-4B';

    /** Génération publique : sobre et courte (pas de réflexion, peu de tokens). */
    private const TEMPERATURE = 0.4;
    private const MAX_TOKENS  = 400;

    /** Timeout curl : 60 s pour la connexion, 60 s au total (contrat). */
    private const TIMEOUT_CONNECT_S = 60;
    private const TIMEOUT_TOTAL_S   = 60;

    /** Rate limit : 10 questions / 5 minutes / IP. */
    public const RATE_MAX        = 10;
    public const RATE_FENETRE_S  = 300;

    /** Appels LLM simultanés maximum (2 slots flock). */
    private const SLOTS_MAX = 2;

    /** Limites d'entrée (contrat front figé). */
    private const QUESTION_MAX         = 500;
    private const HISTORY_MAX_ITEMS    = 6;
    private const HISTORY_CONTENT_MAX  = 800;

    /** Troncatures du journal d'observation. */
    private const JOURNAL_QUESTION_MAX = 500;
    private const JOURNAL_REPLY_MAX    = 1000;

    /** Messages d'erreur EXACTS du contrat (le front s'y raccroche). */
    public const MSG_INVALIDE     = 'Message invalide.';
    public const MSG_RATE         = 'Trop de questions, reviens dans quelques minutes.';
    public const MSG_OCCUPE       = "L'assistant est occupé, réessaie dans un instant.";
    public const MSG_INDISPONIBLE = "L'assistant est indisponible, réessaie dans un instant.";

    private RateLimiter $limiter;
    private string $llmUrl;
    private string $journalPath;
    private string $cheminLock;

    /**
     * @param RateLimiter|null $limiter     Compteur partagé (défaut : cache/ratelimit).
     * @param string|null      $llmUrl      Cible LLM (défaut : llama-server local).
     * @param string|null      $journalPath Fichier JSONL (défaut : logs/assistant_public.jsonl).
     * @param string|null      $cheminLock  Préfixe des fichiers de sémaphore (défaut :
     *                                      cache/assistant_public → .0.lock / .1.lock).
     */
    public function __construct(
        ?RateLimiter $limiter = null,
        ?string $llmUrl = null,
        ?string $journalPath = null,
        ?string $cheminLock = null
    ) {
        $this->limiter     = $limiter ?? new RateLimiter();
        $this->llmUrl      = $llmUrl ?? self::LLM_URL_DEFAUT;
        $this->journalPath = $journalPath ?? (AEIC_ROOT . '/logs/assistant_public.jsonl');
        $this->cheminLock  = $cheminLock ?? (AEIC_ROOT . '/cache/assistant_public');
    }

    /**
     * Traite une requête POST /assistant/ask de bout en bout :
     * rate limit → validation → sémaphore → appel LLM → journal.
     *
     * @param mixed $questionRaw $_POST['question'] (string attendue).
     * @param mixed $historyRaw  $_POST['history'] (string JSON optionnelle).
     *
     * @return array{http:int, ok:bool, reply:?string, error:?string}
     *         Verdict prêt à encoder : ok=true → {ok, reply}, sinon {ok, error}.
     */
    public function traiter(mixed $questionRaw, mixed $historyRaw, string $ip): array
    {
        // Garde anti-abus d'abord : le travail coûteux n'est jamais engagé
        // pour une IP déjà à saturation.
        $cle = 'assistant_public:' . $ip;
        if ($this->limiter->tooManyAttempts($cle, self::RATE_MAX, self::RATE_FENETRE_S)) {
            return $this->conclure(429, false, null, self::MSG_RATE, $questionRaw, 0, $ip);
        }
        $this->limiter->hit($cle);

        $question = self::validerQuestion($questionRaw);
        if ($question === null) {
            return $this->conclure(400, false, null, self::MSG_INVALIDE, $questionRaw, 0, $ip);
        }

        $history = self::validerHistory($historyRaw);
        if ($history === null) {
            return $this->conclure(400, false, null, self::MSG_INVALIDE, $questionRaw, 0, $ip);
        }

        $slot = $this->acquerirSlot();
        if ($slot === null) {
            return $this->conclure(503, false, null, self::MSG_OCCUPE, $questionRaw, 0, $ip);
        }

        $debut    = microtime(true);
        $reponse  = $this->callLlm(
            self::buildPayload(self::buildMessages(self::promptSysteme(), $history, $question))
        );
        $latencyMs = (int) round((microtime(true) - $debut) * 1000);

        // Libération AVANT toute réponse : le contrôleur termine par un
        // exit (json()) — le verrou ne doit jamais lui survivre.
        $this->libererSlot($slot);

        if ($reponse['erreur'] !== null) {
            Logger::error('assistant public: appel LLM en échec', [
                'errno' => $reponse['errno'],
                'http'  => $reponse['http'],
            ]);

            return $this->conclure(503, false, null, self::MSG_INDISPONIBLE, $questionRaw, $latencyMs, $ip);
        }

        $reply = self::extractReply($reponse['body']);
        if ($reply === null) {
            Logger::error('assistant public: réponse LLM inexploitable', [
                'http' => $reponse['http'],
            ]);

            return $this->conclure(503, false, null, self::MSG_INDISPONIBLE, $questionRaw, $latencyMs, $ip);
        }

        return $this->conclure(200, true, $reply, null, $questionRaw, $latencyMs, $ip);
    }

    // -----------------------------------------------------------------
    //  Validation des entrées (contrat : 1..500 / history 0..6 × 1..800)
    // -----------------------------------------------------------------

    /** Question valide (string UTF-8 bien formée, 1..500 caractères après trim) ou null. */
    public static function validerQuestion(mixed $brut): ?string
    {
        if (!is_string($brut)) {
            return null;
        }
        if (!mb_check_encoding($brut, 'UTF-8')) {
            return null;
        }

        $question = trim($brut);
        if ($question === '' || mb_strlen($question) > self::QUESTION_MAX) {
            return null;
        }

        return $question;
    }

    /**
     * Historique de conversation (champ optionnel, string JSON).
     *
     * Accepté : absent, null ou chaîne vide (traité comme « aucun »,
     * tolérance douce côté front) ou JSON tableau de 0..6 items
     * {role: "user"|"assistant", content: 1..800 caractères après trim}.
     * Tout le reste (JSON cassé, objet, role inconnu, 7 items…) → null.
     *
     * @return list<array{role:string, content:string>>|null
     */
    public static function validerHistory(mixed $brut): ?array
    {
        if ($brut === null || (is_string($brut) && trim($brut) === '')) {
            return [];
        }
        if (!is_string($brut)) {
            return null;
        }
        if (!mb_check_encoding($brut, 'UTF-8')) {
            return null;
        }

        $data = json_decode($brut, true);
        if (!is_array($data) || !array_is_list($data)) {
            return null;
        }
        if (count($data) > self::HISTORY_MAX_ITEMS) {
            return null;
        }

        $messages = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                return null;
            }
            $role    = $item['role'] ?? null;
            $content = $item['content'] ?? null;
            if (($role !== 'user' && $role !== 'assistant') || !is_string($content)) {
                return null;
            }
            if (!mb_check_encoding($content, 'UTF-8')) {
                return null;
            }
            $content = trim($content);
            if ($content === '' || mb_strlen($content) > self::HISTORY_CONTENT_MAX) {
                return null;
            }
            $messages[] = ['role' => $role, 'content' => $content];
        }

        return $messages;
    }

    // -----------------------------------------------------------------
    //  Construction de la requête LLM (imitation scripts/assistant.py,
    //  mode DIRECT : thinking désactivé — jamais de tools ici)
    // -----------------------------------------------------------------

    /**
     * Messages au format chat : prompt système PUIS historique PUIS
     * question courante (l'ordre est la défense anti-injection).
     *
     * @param list<array{role:string, content:string}> $history
     *
     * @return list<array{role:string, content:string}>
     */
    public static function buildMessages(string $promptSysteme, array $history, string $question): array
    {
        $messages = [['role' => 'system', 'content' => $promptSysteme]];
        foreach ($history as $message) {
            $messages[] = [
                'role'    => (string) $message['role'],
                'content' => (string) $message['content'],
            ];
        }
        $messages[] = ['role' => 'user', 'content' => $question];

        return $messages;
    }

    /**
     * Payload /v1/chat/completions — STRICTEMENT sans « tools » ni
     * « tool_choice » (l'assistant public n'a aucun outil, par design).
     *
     * @param list<array{role:string, content:string}> $messages
     *
     * @return array<string, mixed>
     */
    public static function buildPayload(array $messages): array
    {
        return [
            'model'                => self::LLM_MODELE,
            'messages'             => $messages,
            'temperature'          => self::TEMPERATURE,
            'max_tokens'           => self::MAX_TOKENS,
            'stream'               => false,
            // Mêmes options que le pont admin en mode direct (Qwen3 sans
            // réflexion) : réponse immédiate, pas de bloc <think>.
            'chat_template_kwargs' => ['enable_thinking' => false],
        ];
    }

    /**
     * Appel HTTP direct au llama-server local (curl maison, mêmes
     * conventions que Mailer/GlmOcrClient). Aucune API externe.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{http:int, body:?string, erreur:?string, errno:int}
     */
    public function callLlm(array $payload): array
    {
        if (!function_exists('curl_init')) {
            return ['http' => 0, 'body' => null, 'erreur' => 'extension curl absente', 'errno' => -1];
        }

        $corps = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $ch = curl_init($this->llmUrl);
        if ($ch === false) {
            return ['http' => 0, 'body' => null, 'erreur' => 'curl_init', 'errno' => -1];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $corps,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_CONNECT_S,
            CURLOPT_TIMEOUT        => self::TIMEOUT_TOTAL_S,
            CURLOPT_RETURNTRANSFER => true,
            // Cible locale : aucun proxy d'environnement ne doit détourner
            // l'appel (durcissement).
            CURLOPT_PROXY          => '',
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $brut  = curl_exec($ch);
        $errno = curl_errno($ch);
        $http  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($brut === false || $errno !== 0) {
            return ['http' => $http, 'body' => null, 'erreur' => 'curl errno ' . $errno, 'errno' => $errno];
        }
        if ($http !== 200) {
            return ['http' => $http, 'body' => null, 'erreur' => 'http ' . $http, 'errno' => 0];
        }

        return ['http' => $http, 'body' => (string) $brut, 'erreur' => null, 'errno' => 0];
    }

    /**
     * Extrait la réponse texte du corps /v1/chat/completions : retire
     * défensivement un éventuel bloc <think>…</think> (le thinking est
     * désactivé côté payload, mais on ne montre JAMAIS de réflexion
     * interne au visiteur). Réponse vide/invalide → null (fail-closed).
     */
    public static function extractReply(?string $body): ?string
    {
        if ($body === null || $body === '') {
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }

        $choices = $data['choices'] ?? null;
        if (!is_array($choices) || !is_array($choices[0] ?? null)) {
            return null;
        }

        $message = $choices[0]['message'] ?? null;
        if (!is_array($message) || !is_string($message['content'] ?? null)) {
            return null;
        }

        $contenu = (string) preg_replace('/<think>.*?<\/think>/s', '', $message['content']);
        $contenu = trim($contenu);

        return $contenu === '' ? null : $contenu;
    }

    // -----------------------------------------------------------------
    //  Sémaphore 2 slots (flock) — protège llama-server
    // -----------------------------------------------------------------

    /**
     * Tente d'acquérir un des 2 slots (flock non bloquant). Les deux
     * pris (ou fichiers inouvrables) → null → 503 « occupé ».
     *
     * @return resource|null Handle du slot acquis, à passer à libererSlot().
     */
    private function acquerirSlot()
    {
        $dossier = dirname($this->cheminLock);
        if (!is_dir($dossier)) {
            @mkdir($dossier, 0o775, true);
        }

        for ($i = 0; $i < self::SLOTS_MAX; $i++) {
            $handle = @fopen($this->cheminLock . '.' . $i . '.lock', 'c');
            if ($handle === false) {
                continue;
            }
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return $handle;
            }
            @fclose($handle);
        }

        return null;
    }

    /**
     * @param resource $handle Handle renvoyé par acquerirSlot().
     */
    private function libererSlot($handle): void
    {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    // -----------------------------------------------------------------
    //  Journal d'observation + helpers
    // -----------------------------------------------------------------

    /**
     * Journalise la requête (une ligne JSON par requête, y compris les
     * refus 400/429/503 — l'utilisateur veut « regarder un peu »), puis
     * renvoie le verdict. Un échec d'écriture ne casse jamais la réponse.
     *
     * @return array{http:int, ok:bool, reply:?string, error:?string}
     */
    private function conclure(
        int $http,
        bool $ok,
        ?string $reply,
        ?string $error,
        mixed $questionBrut,
        int $latencyMs,
        string $ip
    ): array {
        $ligne = json_encode([
            'ts'         => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format(DATE_ATOM),
            'ip_hash'    => self::hashIp($ip),
            'question'   => is_string($questionBrut)
                ? mb_substr(trim($questionBrut), 0, self::JOURNAL_QUESTION_MAX)
                : '',
            'reply'      => mb_substr($reply ?? '', 0, self::JOURNAL_REPLY_MAX),
            'latency_ms' => $latencyMs,
            'status'     => $http,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($ligne !== false) {
            @file_put_contents($this->journalPath, $ligne . "\n", FILE_APPEND | LOCK_EX);
        }

        return ['http' => $http, 'ok' => $ok, 'reply' => $reply, 'error' => $error];
    }

    /**
     * Empreinte courte de l'IP (jamais l'IP en clair dans le journal) :
     * sha256(sel APP + IP) tronqué à 16 hex — suffisant pour distinguer
     * les visiteurs, insuffisant pour remonter à l'IP.
     */
    public static function hashIp(string $ip): string
    {
        $sel = defined('APP_KEY') ? APP_KEY : 'aeic-public';

        return substr(hash('sha256', $sel . '|' . $ip), 0, 16);
    }

    /**
     * Prompt système public (fichier DÉDIÉ éditable/enrichissable).
     */
    private static function promptSysteme(): string
    {
        /** @var string $prompt */
        $prompt = require AEIC_ROOT . '/app/config/assistant_public.php';

        return $prompt;
    }
}
