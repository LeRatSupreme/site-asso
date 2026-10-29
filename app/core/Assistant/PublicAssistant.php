<?php

declare(strict_types=1);

namespace App\Core\Assistant;

use App\Core\Compta\KioskPages;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Models\Kiosque;
use App\Models\Setting;

/**
 * Assistant conversationnel PUBLIC de l'AEIC (widget chat du site).
 *
 * Deux chemins, UN seul moteur (un seul point d'assemblage) :
 *  - traiter()      → endpoint POST /assistant/ask (site public) ;
 *  - traiterKiosk()  → endpoint POST /kiosque/assistant/ask/{token}
 *    (pages kiosque : le JETON du kiosque EST l'authentification —
 *    kiosques personnalisés et jetons legacy admin/membres).
 * Les deux partagent validation, FAQ (app/config/assistant_faq.php),
 * sémaphore (2 slots protégeant le MÊME llama-server), appel LLM et
 * mécanique de journal — seuls le prompt (app/config/
 * assistant_public.php vs assistant_kiosk.php), la clé de rate limit et
 * le fichier de journal (logs/assistant_public.jsonl vs
 * logs/assistant_kiosk.jsonl, avec champ additif « kiosk ») diffèrent.
 *
 * Chemin volontairement SÉPARÉ de l'assistant admin (pont Python +
 * AssistantToolsController) : ici, aucun outil, aucun jeton d'outil,
 * aucune donnée interne — juste un prompt public/kiosque éditable
 * (app/config/assistant_public.php, assistant_kiosk.php) envoyé au
 * llama-server local (API compatible OpenAI, même format que
 * scripts/assistant.py en mode direct).
 *
 * Contrat figé avec le widget front (messages EXACTS) :
 *  - 400 {"ok":false,"error":"Message invalide."}
 *  - 429 {"ok":false,"error":"Trop de questions, reviens dans quelques minutes."}
 *  - 503 {"ok":false,"error":"L'assistant est occupé, réessaie dans un instant."}   (sémaphore plein)
 *  - 503 {"ok":false,"error":"L'assistant est indisponible, réessaie dans un instant."} (LLM down/timeout)
 *  - 200 {"ok":true,"reply":"..."}
 *  - kiosque uniquement — 404 {"ok":false,"error":"Kiosque introuvable."}
 *    (jeton inconnu/révoqué, fail-closed sans énumération)
 *
 * Garde-fous :
 *  - rate limit 10 questions / 5 min / IP (RateLimiter partagé, fichiers) ;
 *  - sémaphore 2 appels LLM simultanés (flock sur 2 fichiers de slot,
 *    protège llama-server 4 threads) — verrou TOUJOURS libéré avant la
 *    réponse (json() du contrôleur fait exit) ;
 *  - fail-closed : tout échec réseau/parse → 503 générique, détail en
 *    Logger::error uniquement, jamais dans la réponse ;
 *  - FAQ fast-path (app/config/assistant_faq.php) : question dont un
 *    déclencheur apparaît (après normalisation casse/accents/ponctuation,
 *    déclencheur le plus long gagnant) → réponse pré-rédigée http 200
 *    instantanée, SANS sémaphore ni appel LLM (l'historique n'est pas
 *    nécessaire : il n'est validé que sur le chemin LLM) ;
 *  - journal d'observation logs/assistant_public.jsonl : une ligne par
 *    requête {ts, ip_hash, origin, question, reply, latency_ms, status,
 *    faq} — le champ additif faq (true/false) distingue réponses
 *    pré-rédigées et générations LLM, le champ additif origin (« vps » /
 *    « humain ») distingue le trafic du serveur lui-même du trafic
 *    étudiant (le front ignore les champs inconnus) ; l'IP
 *    n'est JAMAIS écrite en clair (sha256 tronqué, sel APP_KEY) ;
 *    le chemin kiosque journalise pareil dans logs/assistant_kiosk.jsonl
 *    avec un champ additif « kiosk » (nom du kiosque).
 *
 * Toutes les dépendances (storage rate limit, URL LLM, journaux, locks)
 * sont injectables pour les tests unitaires (tests/Unit/AssistantPublicTest,
 * tests/Unit/AssistantKioskTest).
 */
final class PublicAssistant
{
    /** llama-server local (même cible que scripts/assistant.py). */
    public const LLM_URL_DEFAUT = 'http://127.0.0.1:8080/v1/chat/completions';

    /** Modèle servi par llama-server (constante LLM_MODELE du pont admin). */
    private const LLM_MODELE = 'Qwen3-4B';

    /** Génération publique : sobre et très courte (FAQ de secours ~40
     *  mots → 200 tokens suffisent largement, préfill+génère moins cher). */
    private const TEMPERATURE = 0.4;
    private const MAX_TOKENS  = 200;

    /** Timeout curl : 60 s pour la connexion, 60 s au total (contrat). */
    private const TIMEOUT_CONNECT_S = 60;
    private const TIMEOUT_TOTAL_S   = 60;

    /** Rate limit : 10 questions / 5 minutes / IP. */
    public const RATE_MAX        = 10;
    public const RATE_FENETRE_S  = 300;

    /** IP publique du VPS hébergeant le site : une requête client dont
     *  l'IP est exactement celle-ci vient du serveur lui-même (cron,
     *  healthcheck, monitoring…), pas d'un visiteur — étiquetée « vps »
     *  dans le journal (comparaison string stricte, sinon « humain »). */
    private const IP_VPS = '51.254.129.80';

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

    /** Jeton kiosque inconnu/révoqué : message générique unique (pas
     *  d'énumération possible), même discipline que les autres pages
     *  kiosque face à un mauvais jeton. */
    public const MSG_KIOSK_INTROUVABLE = 'Kiosque introuvable.';

    /** Rôles affichés pour les jetons LEGACY (settings), quand la requête
     *  ne vient pas d'un kiosque personnalisé (table kiosques). */
    public const ROLE_KIOSK_ADMIN_LEGACY   = 'Bureau (admin)';
    public const ROLE_KIOSK_MEMBRE_LEGACY  = 'Équipe cafétéria';

    /** Longueur max du nom de kiosque injecté dans le prompt kiosk
     *  (donnée admin-controlled → nettoyée, cf. nettoyerRoleKiosk). */
    public const KIOSK_ROLE_MAX = 60;

    private RateLimiter $limiter;
    private string $llmUrl;
    private string $journalPath;
    private string $journalKioskPath;
    private string $cheminLock;

    /**
     * @param RateLimiter|null $limiter        Compteur partagé (défaut : cache/ratelimit).
     * @param string|null      $llmUrl         Cible LLM (défaut : llama-server local).
     * @param string|null      $journalPath    Fichier JSONL public (défaut : logs/assistant_public.jsonl).
     * @param string|null      $cheminLock     Préfixe des fichiers de sémaphore (défaut :
     *                                         cache/assistant_public → .0.lock / .1.lock). Les
     *                                         chemins public ET kiosque partagent CES slots : le
     *                                         sémaphore protège le llama-server, pas l'endpoint.
     * @param string|null      $journalKioskPath Fichier JSONL kiosque (défaut :
     *                                         logs/assistant_kiosk.jsonl).
     */
    public function __construct(
        ?RateLimiter $limiter = null,
        ?string $llmUrl = null,
        ?string $journalPath = null,
        ?string $cheminLock = null,
        ?string $journalKioskPath = null
    ) {
        $this->limiter        = $limiter ?? new RateLimiter();
        $this->llmUrl         = $llmUrl ?? self::LLM_URL_DEFAUT;
        $this->journalPath    = $journalPath ?? (AEIC_ROOT . '/logs/assistant_public.jsonl');
        $this->cheminLock     = $cheminLock ?? (AEIC_ROOT . '/cache/assistant_public');
        $this->journalKioskPath = $journalKioskPath ?? (AEIC_ROOT . '/logs/assistant_kiosk.jsonl');
    }

    /**
     * Traite une requête POST /assistant/ask de bout en bout :
     * rate limit → validation question → FAQ (fast-path, pas de LLM)
     * → validation history → sémaphore → appel LLM → journal.
     *
     * @param mixed $questionRaw $_POST['question'] (string attendue).
     * @param mixed $historyRaw  $_POST['history'] (string JSON optionnelle).
     *
     * @return array{http:int, ok:bool, reply:?string, error:?string}
     *         Verdict prêt à encoder : ok=true → {ok, reply}, sinon {ok, error}.
     */
    public function traiter(mixed $questionRaw, mixed $historyRaw, string $ip): array
    {
        return $this->executer(
            'assistant_public:' . $ip,
            self::promptSysteme(),
            null,
            $questionRaw,
            $historyRaw,
            $ip
        );
    }

    // -----------------------------------------------------------------
    //  Chemin KIOSQUE (jeton = authentification)
    // -----------------------------------------------------------------

    /**
     * Traite une requête POST /kiosque/assistant/ask/{token} de bout en
     * bout : résolution du rôle par le jeton → rate limit DÉDIÉ (clé
     * IP+jeton, indépendante de la clé publique) → même pipeline que le
     * public (question → FAQ partagée → history → sémaphore → LLM →
     * journal kiosk DÉDIÉ avec champ « kiosk »).
     *
     * Le modèle ne voit AUCUNE donnée interne, quel que soit le kiosque
     * (même trésorier/président) : le prompt kiosk ne contient que le
     * nom du kiosque (nettoyé) et les labels de ses pages.
     *
     * Jeton inconnu/révoqué → 404 générique fail-closed, sans énumération
     * (message unique, journalisé avec kiosk vide) ; il ne consomme pas
     * le compteur de rate limit — la clé contient le jeton, chaque sonde
     * d'énumération aurait de toute façon sa propre clé.
     *
     * @param mixed $questionRaw $_POST['question'] (string attendue).
     * @param mixed $historyRaw  $_POST['history'] (string JSON optionnelle).
     *
     * @return array{http:int, ok:bool, reply:?string, error:?string}
     */
    public function traiterKiosk(string $token, mixed $questionRaw, mixed $historyRaw, string $ip): array
    {
        $kiosk = self::resoudreKiosk($token);
        if ($kiosk === null) {
            return $this->conclure(404, false, null, self::MSG_KIOSK_INTROUVABLE, $questionRaw, 0, $ip, false, '');
        }

        // Clé DÉDIÉE (jamais « assistant_public: ») : un kiosque saturé ne
        // pénalise ni le site public ni les autres kiosques, et réciproquement.
        // Jeton TRIMÉ comme dans resoudreKiosk() : sinon un jeton paddé
        // d'espacements (%20, %09 décodés par le routage) passerait la
        // résolution mais créerait une clé de compteur distincte à chaque
        // padding — limite 10/5 min contournable.
        $cle = 'assistant_kiosk:' . self::hashIp($ip) . ':' . substr(sha1(trim($token)), 0, 8);

        return $this->executer(
            $cle,
            self::buildPromptKiosk($kiosk['role'], $kiosk['pages']),
            $kiosk['role'],
            $questionRaw,
            $historyRaw,
            $ip
        );
    }

    /**
     * Résout le rôle d'affichage d'un jeton kiosque :
     *  1. kiosque personnalisé (Kiosque::findByToken, timing-safe) → son
     *     nom + les ids de ses tuiles ;
     *  2. jeton ADMIN legacy (settings.admin_kiosk_token) → « Bureau
     *     (admin) » + LEGACY_ADMIN_PAGES ;
     *  3. jeton MEMBRES legacy (settings.reappro_kiosk_token) →
     *     « Équipe cafétéria » + LEGACY_MEMBER_PAGES ;
     *  sinon null (→ 404 générique). Ordre personnalisé → legacy, comme
     *  KioskComptageController::hubUrlFor (et à la différence de
     *  KioskPages::tokenOptions, qui teste les jetons legacy d'abord) ;
     *  mêmes comparaisons hash_equals que ces deux gardes.
     *
     * @return array{role:string, pages:list<string>}|null
     */
    public static function resoudreKiosk(string $token): ?array
    {
        $given = trim($token);
        if ($given === '') {
            return null;
        }

        try {
            $k = Kiosque::findByToken($given);
        } catch (\Throwable) {
            // Table kiosques absente (migration non appliquée) : les
            // kiosques personnalisés sont simplement inactifs.
            $k = null;
        }
        if ($k !== null) {
            return ['role' => (string) $k['name'], 'pages' => $k['pages']];
        }

        $admin = trim((string) Setting::get('admin_kiosk_token', ''));
        if ($admin !== '' && hash_equals($admin, $given)) {
            return ['role' => self::ROLE_KIOSK_ADMIN_LEGACY, 'pages' => KioskPages::LEGACY_ADMIN_PAGES];
        }

        $membre = trim((string) Setting::get('reappro_kiosk_token', ''));
        if ($membre !== '' && hash_equals($membre, $given)) {
            return ['role' => self::ROLE_KIOSK_MEMBRE_LEGACY, 'pages' => KioskPages::LEGACY_MEMBER_PAGES];
        }

        return null;
    }

    /**
     * Construit le prompt système KIOSQUE (app/config/assistant_kiosk.php)
     * avec {ROLE} et {PAGES} remplacés (strtr) :
     *  - le rôle est NETTOYÉ (nettoyerRoleKiosk) par construction : un nom
     *    de kiosque forgé en admin ne peut pas réécrire le prompt ;
     *  - les pages deviennent les labels du catalogue (KioskPages), ids
     *    inconnus ignorés (même tolérance que le hub) ; kiosque sans
     *    tuile connue → « aucune ».
     *
     * @param list<string> $pages ids de tuiles (catalogue KioskPages)
     */
    public static function buildPromptKiosk(string $role, array $pages): string
    {
        $labels = [];
        foreach ($pages as $pageId) {
            $tuile = KioskPages::get((string) $pageId);
            if ($tuile !== null) {
                $labels[] = $tuile['label'];
            }
        }

        return strtr(self::promptSystemeKiosk(), [
            '{ROLE}'  => self::nettoyerRoleKiosk($role),
            '{PAGES}' => $labels === [] ? 'aucune' : implode(', ', $labels),
        ]);
    }

    /**
     * Nettoie un nom de kiosque (donnée admin-controlled) avant injection
     * dans le prompt système :
     *  - trim, puis tout retour ligne / tabulation / séparateur Unicode →
     *    espace, et espaces multiples ramenés à un seul : SANS saut de
     *    ligne, un nom ne peut pas ouvrir une nouvelle section markdown
     *    (« ## … ») dans le prompt — c'est la défense anti-injection ;
     *  - tronqué à KIOSK_ROLE_MAX caractères (borne la surcharge) ;
     *  - vide après nettoyage → « Kiosque » (le prompt reste cohérent).
     *
     * Choix volontairement simple et robuste (pas d'échappement HTML : la
     * sortie ne part que dans un prompt LLM et un journal JSON).
     */
    public static function nettoyerRoleKiosk(string $role): string
    {
        $role = trim($role);
        $role = (string) preg_replace('/[\r\n\t\v\f\x{0085}\x{2028}\x{2029}]+/u', ' ', $role);
        $role = (string) preg_replace('/ {2,}/', ' ', $role);
        $role = trim(mb_substr($role, 0, self::KIOSK_ROLE_MAX));

        return $role === '' ? 'Kiosque' : $role;
    }

    /**
     * Prompt système kiosque (fichier DÉDIÉ éditable, placeholders
     * {ROLE}/{PAGES} remplacés par buildPromptKiosk).
     */
    private static function promptSystemeKiosk(): string
    {
        /** @var string $prompt */
        $prompt = require AEIC_ROOT . '/app/config/assistant_kiosk.php';

        return $prompt;
    }

    /**
     * Pipeline partagé public/kiosque : rate limit → validation question
     * → FAQ → validation history → sémaphore → LLM → journal.
     *
     * @param string      $cle       Clé de rate limit (publique ou kiosk).
     * @param string      $promptSysteme Prompt système déjà construit.
     * @param string|null $kiosk     null → chemin public ; sinon le nom du
     *                               kiosque (journal kiosk + champ « kiosk »).
     *
     * @return array{http:int, ok:bool, reply:?string, error:?string}
     */
    private function executer(
        string $cle,
        string $promptSysteme,
        ?string $kiosk,
        mixed $questionRaw,
        mixed $historyRaw,
        string $ip
    ): array {
        // Garde anti-abus d'abord : le travail coûteux n'est jamais engagé
        // pour une IP déjà à saturation.
        if ($this->limiter->tooManyAttempts($cle, self::RATE_MAX, self::RATE_FENETRE_S)) {
            return $this->conclure(429, false, null, self::MSG_RATE, $questionRaw, 0, $ip, false, $kiosk);
        }
        $this->limiter->hit($cle);

        $question = self::validerQuestion($questionRaw);
        if ($question === null) {
            return $this->conclure(400, false, null, self::MSG_INVALIDE, $questionRaw, 0, $ip, false, $kiosk);
        }

        // FAQ fast-path : réponse pré-rédigée instantanée. AVANT le
        // sémaphore et la validation de l'historique (inutile ici, la
        // réponse ne dépend que de la question courante) — zéro appel LLM.
        // FAQ PARTAGÉE public/kiosque (app/config/assistant_faq.php).
        $reponseFaq = self::chercherFaq($question);
        if ($reponseFaq !== null) {
            return $this->conclure(200, true, $reponseFaq, null, $questionRaw, 0, $ip, true, $kiosk);
        }

        $history = self::validerHistory($historyRaw);
        if ($history === null) {
            return $this->conclure(400, false, null, self::MSG_INVALIDE, $questionRaw, 0, $ip, false, $kiosk);
        }

        $slot = $this->acquerirSlot();
        if ($slot === null) {
            return $this->conclure(503, false, null, self::MSG_OCCUPE, $questionRaw, 0, $ip, false, $kiosk);
        }

        $debut    = microtime(true);
        $reponse  = $this->callLlm(
            self::buildPayload(self::buildMessages($promptSysteme, $history, $question))
        );
        $latencyMs = (int) round((microtime(true) - $debut) * 1000);

        // Libération AVANT toute réponse : le contrôleur termine par un
        // exit (json()) — le verrou ne doit jamais lui survivre.
        $this->libererSlot($slot);

        $origine = $kiosk === null ? 'assistant public' : 'assistant kiosk';

        if ($reponse['erreur'] !== null) {
            Logger::error($origine . ': appel LLM en échec', [
                'errno' => $reponse['errno'],
                'http'  => $reponse['http'],
            ]);

            return $this->conclure(503, false, null, self::MSG_INDISPONIBLE, $questionRaw, $latencyMs, $ip, false, $kiosk);
        }

        $reply = self::extractReply($reponse['body']);
        if ($reply === null) {
            Logger::error($origine . ': réponse LLM inexploitable', [
                'http' => $reponse['http'],
            ]);

            return $this->conclure(503, false, null, self::MSG_INDISPONIBLE, $questionRaw, $latencyMs, $ip, false, $kiosk);
        }

        return $this->conclure(200, true, $reply, null, $questionRaw, $latencyMs, $ip, false, $kiosk);
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
    //  FAQ fast-path (app/config/assistant_faq.php) — zéro appel LLM
    // -----------------------------------------------------------------

    /**
     * Normalisation partagée question/déclencheurs : minuscules,
     * translittération simple des accents (é→e…), toute ponctuation et
     * tout espacement multiple ramenés à UN espace, bords tronqués.
     * Ex. « Salut, c'est quoi l'AEIC ? » → « salut c est quoi l aeic ».
     *
     * Publique : partagée avec la V1 questions préparées
     * (AgentQuestions::normaliser) — UNE seule règle de normalisation
     * questions pour tout le site.
     */
    public static function normaliserTexte(string $texte): string
    {
        $texte = mb_strtolower($texte, 'UTF-8');

        // Translittération manuelle volontairement simple (iconv
        // ASCII//TRANSLIT dépend de la locale) : suffisant pour le français
        // courant.
        $texte = strtr($texte, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'å' => 'a',
            'ç' => 'c',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ý' => 'y', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae',
        ]);

        // Tout ce qui n'est pas lettre/chiffre (ponctuation, apostrophes,
        // espacements multiples…) → un seul espace.
        $texte = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $texte);

        return trim($texte);
    }

    /**
     * Cherche une réponse FAQ pour la question : entrée gagnante = celle
     * dont le déclencheur LE PLUS LONG est sous-chaîne de la question
     * normalisée ; à égalité de longueur, la première entrée du fichier
     * gagne (ordre conservé par le parcours). Aucun match → null (chemin
     * LLM classique).
     */
    private static function chercherFaq(string $question): ?string
    {
        /** @var list<array{declencheurs: list<string>, reponse: string}> $faq */
        $faq = require AEIC_ROOT . '/app/config/assistant_faq.php';

        $normalisee = self::normaliserTexte($question);
        if ($normalisee === '') {
            return null;
        }

        $meilleure  = null;
        $longueurMax = 0;
        foreach ($faq as $entree) {
            foreach ($entree['declencheurs'] as $declencheur) {
                // Déclencheurs stockés déjà normalisés ; re-normaliser est
                // idempotent et pardonnant à l'édition du fichier.
                $declencheur = self::normaliserTexte((string) $declencheur);
                if ($declencheur === '') {
                    continue;
                }
                if (mb_strlen($declencheur) > $longueurMax
                    && mb_strpos($normalisee, $declencheur) !== false) {
                    $meilleure  = (string) $entree['reponse'];
                    $longueurMax = mb_strlen($declencheur);
                }
            }
        }

        return $meilleure;
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
            // Umask du process créateur hétérogène (CLI ubuntu vs Apache
            // www-data sur le VPS mono-admin) : un verrou créé 0664 par
            // l'un doit rester ouvrable par l'autre, sinon fopen 'c'
            // échoue côté web et tout /assistant/ask répond 503 « occupé »
            // (incident réel du 28/10 : locks 0664 owner ubuntu → web 503
            // déterministe). chmod explicite, idempotent, échec silencieux.
            @chmod($this->cheminLock . '.' . $i . '.lock', 0o666);
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
     * refus 400/404/429/503 — l'utilisateur veut « regarder un peu »), puis
     * renvoie le verdict. Un échec d'écriture ne casse jamais la réponse.
     *
     * @param bool        $faq   true si la réponse vient du fast-path FAQ.
     * @param string|null $kiosk null → journal public (logs/assistant_public
     *                           .jsonl) ; chaîne (même vide) → journal kiosk
     *                           DÉDIÉ logs/assistant_kiosk.jsonl avec champ
     *                           additif « kiosk » (nom du kiosque, vide pour
     *                           un jeton inconnu) : l'observation public et
     *                           kiosk reste séparable.
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
        string $ip,
        bool $faq = false,
        ?string $kiosk = null
    ): array {
        $donnees = [
            'ts'         => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format(DATE_ATOM),
            'ip_hash'    => self::hashIp($ip),
            // Origine de la requête (champ additif, le front ignore les
            // champs inconnus) : le trafic du VPS lui-même ne doit plus
            // compter pour du trafic étudiant dans les rapports
            // d'observation (selfreview 06h : 26/58 questions VPS).
            'origin'     => $ip === self::IP_VPS ? 'vps' : 'humain',
            'question'   => is_string($questionBrut)
                ? mb_substr(trim($questionBrut), 0, self::JOURNAL_QUESTION_MAX)
                : '',
            'reply'      => mb_substr($reply ?? '', 0, self::JOURNAL_REPLY_MAX),
            'latency_ms' => $latencyMs,
            'status'     => $http,
            'faq'        => $faq,
        ];

        $journal = $this->journalPath;
        if ($kiosk !== null) {
            $donnees['kiosk'] = $kiosk;
            $journal          = $this->journalKioskPath;
        }

        $ligne = json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($ligne !== false) {
            $ecrit = @file_put_contents($journal, $ligne . "\n", FILE_APPEND | LOCK_EX);
            if ($ecrit !== false) {
                // Même raison que les verrous : umask du process créateur
                // hétérogène (CLI ubuntu vs Apache www-data sur le VPS
                // mono-admin) — le journal écrit par l'un doit rester
                // ouvrable par l'autre (incident réel du 28/10). Idempotent,
                // coût négligeable à 10 req/5 min, échec silencieux.
                @chmod($journal, 0o666);
            }
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
