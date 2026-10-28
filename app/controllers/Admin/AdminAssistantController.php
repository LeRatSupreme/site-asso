<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Logger;
use App\Core\RateLimiter;

/**
 * Page « Assistant » de l'espace admin (T4-G) : interface du pont local
 * scripts/assistant.py (T4-F), qui orchestre le modèle LLM et les
 * endpoints outils lecture-seule (T4-E).
 *
 *   GET  /admin/assistant       → la page (champ de question + réponse)
 *   POST /admin/assistant/ask   → AJAX interne : exécution du pont
 *
 * Le JETON serveur des outils (ASSISTANT_TOKEN) ne transite JAMAIS côté
 * client : c'est le pont Python qui le lit dans config.env et l'ajoute
 * aux appels vers /internal/assistant/* — cette page ne fait qu'exécuter
 * le pont et relayer sa réponse.
 *
 * Sécurité : guardSystemOrPage('inventory') (page du groupe Système,
 * attribuable individuellement — même garde que la page Inventaire) ;
 * CSRF vérifié par le routeur pour tout POST non kiosque ; rate limit
 * par utilisateur (chaque question mobilise le modèle local jusqu'à
 * 2 minutes) ; messages d'erreur génériques, détails en log.
 *
 * Règle spec T4 §0 (« jamais de chiffre sans source ») : la réponse
 * affiche TOUJOURS les sources renvoyées par le pont (outil appelé +
 * paramètres) et un avertissement quand une réponse chiffrée n'est
 * adossée à aucun appel d'outil (audit R4 répliqué côté page).
 *
 * Mémoire de conversation (T4-P1-B) : l'historique des 6 derniers
 * échanges vit en SESSION PHP (clé dédiée, purgée après 30 min
 * d'inactivité — et à la déconnexion : Auth::logout() vide toute la
 * session). Il est transmis au pont par FICHIER temporaire (jamais en
 * ligne de commande), injecté par le pont entre le préfixe système
 * stable et la question courante ; « reset=1 » purge la mémoire.
 */
final class AdminAssistantController extends AdminBaseController
{
    /** Interpréteur du pont : venv dédié (stdlib pure, zéro dépendance). */
    private const PONT_PYTHON = AEIC_ROOT . '/.venv-assistant/bin/python3';

    /** Script d'orchestration LLM ↔ outils (T4-F). */
    private const PONT_SCRIPT = AEIC_ROOT . '/scripts/assistant.py';

    /** Budget serveur : le pont a son propre timeout global de 120 s ;
     *  on coupe le sous-processus juste après pour ne jamais bloquer plus. */
    private const TIMEOUT_S = 120;

    /** Longueur maximale d'une question (contrat du pont : saisie courte). */
    private const QUESTION_MAX = 500;

    /** Rate limit : 6 questions par minute et par utilisateur (le modèle
     *  est lent : pas de mitraillage ni de pile de sous-processus). */
    private const RATE_MAX     = 6;
    private const RATE_FENETRE = 60;

    /** Plafond de sortie lue sur le tube stdout du pont (garde-fou). */
    private const SORTIE_MAX = 2_097_152;

    // -------------------------------------------------------------
    //  Mémoire de conversation (T4-P1-B — brief V1-B)
    // -------------------------------------------------------------

    /** Clé session : ['ts' => horodatage, 'echanges' => [['q','r'], …]]. */
    private const CLE_SESSION = 'assistant_conversation';

    /** Échanges mémorisés maximum (contrat du pont : 12 messages). */
    private const MAX_ECHANGES = 6;

    /** Purge de la mémoire après 30 minutes d'inactivité. */
    private const DUREE_VIE_S = 1800;

    /** Bornes du contexte transmis au pont (le pont revalide — contrat
     *  scripts/assistant.py : CONTEXTE_MESSAGE_MAX_CARACTERES, …_TOTAL_…). */
    private const CONTEXTE_MESSAGE_MAX = 800;
    private const CONTEXTE_TOTAL_MAX   = 4000;

    /** Réponse mémorisée tronquée à (l'affichage garde l'essentiel,
     *  le contexte est re-tronqué par messagesContexte). */
    private const REPONSE_STOCKEE_MAX = 2000;

    /**
     * La page : champ de question + zone de réponse (rendu côté navigateur
     * en AJAX vers ask(), ci-dessous).
     */
    public function index(): void
    {
        $user = $this->guardSystemOrPage('inventory');

        $this->renderAdmin('admin/assistant', [
            'user'       => $user,
            'askUrl'     => url('/admin/assistant/ask'),
            'historique' => $this->historiquePourAffichage(),
        ]);
    }

    /**
     * AJAX interne : exécute le pont avec la question, renvoie la réponse
     * + ses sources. Codes HTTP : 200 (réponse), 400 (question invalide),
     * 429 (rate limit), 502/503/504 (pont en échec — message générique,
     * détail en log).
     */
    public function ask(): void
    {
        $user = $this->guardSystemOrPage('inventory');

        // « Nouvelle conversation » (T4-P1-B) : purge de la mémoire de
        // session, sans question ni mobilisation du modèle.
        if (trim((string) ($_POST['reset'] ?? '')) === '1') {
            unset($_SESSION[self::CLE_SESSION]);
            $this->json(['ok' => true, 'historique' => []]);
        }

        // Rate limit par utilisateur : une question = jusqu'à 2 minutes de
        // modèle local ; le même clic spammerait le service.
        $limiter = new RateLimiter();
        $cle = 'assistant-ask-' . (string) ($user['id'] ?? 'anonyme');
        if ($limiter->tooManyAttempts($cle, self::RATE_MAX, self::RATE_FENETRE)) {
            Logger::warning('assistant page: rate limit atteint', ['user' => (string) ($user['id'] ?? '')]);
            $this->json(['ok' => false, 'error' => 'Trop de questions à la suite — patiente une minute.'], 429);
        }
        $limiter->hit($cle);

        $question = trim((string) ($_POST['question'] ?? ''));
        if ($question === '') {
            $this->json(['ok' => false, 'error' => 'Pose d\'abord une question (ventes, stock, commandes…).'], 400);
        }
        if (mb_strlen($question) > self::QUESTION_MAX) {
            $this->json(['ok' => false, 'error' => 'Question trop longue (' . self::QUESTION_MAX . ' caractères maximum).'], 400);
        }

        if (!is_file(self::PONT_PYTHON) || !is_file(self::PONT_SCRIPT)) {
            Logger::error('assistant page: pont introuvable', ['python' => self::PONT_PYTHON, 'script' => self::PONT_SCRIPT]);
            $this->json(['ok' => false, 'error' => 'L\'assistant n\'est pas installé sur ce serveur.'], 503);
        }

        // Le sous-processus peut durer 120 s : l'attente passe dans
        // stream_select (appel système), qui ne compte pas dans le
        // max_execution_time — on l'annule quand même par précaution.
        set_time_limit(0);

        // Mémoire multi-tours (T4-P1-B) : historique de session → fichier
        // temporaire (0600, jamais en ligne de commande) transmis au pont,
        // qui l'injecte après le préfixe système stable puis re-tronque.
        $fichierContexte = '';
        $messages = $this->messagesContexte($this->lireHistorique());
        if ($messages !== []) {
            $fichierContexte = (string) tempnam(sys_get_temp_dir(), 'aeic-ctx-');
            file_put_contents($fichierContexte, json_encode($messages, JSON_UNESCAPED_UNICODE), LOCK_EX);
        }

        // Exécution à blanc (tests d'intégration : ASSISTANT_DRY_RUN=1,
        // jamais posée en production) : le pont imprime le prompt construit
        // sans appeler le LLM — permet de vérifier l'historique transmis.
        $dryRun = getenv('ASSISTANT_DRY_RUN') === '1';

        try {
            [$code, $sortie, $err, $expire] = $this->executerPont($question, $fichierContexte, $dryRun);
        } finally {
            if ($fichierContexte !== '') {
                @unlink($fichierContexte);
            }
        }

        if ($expire) {
            Logger::error('assistant page: timeout côté page', ['user' => (string) ($user['id'] ?? '')]);
            $this->json(['ok' => false, 'error' => 'Réponse trop lente : la question a été annulée après 2 minutes. Essaie une question plus simple.'], 504);
        }

        // Le pont réussi imprime TOUJOURS la session en JSON sur stdout
        // (--json), même quand la session elle-même échoue (ok=false) :
        // on y lit le message à afficher.
        $session = json_decode($sortie, true);

        if ($dryRun) {
            // Mode à blanc : on renvoie les messages construits (préfixe
            // système + historique + question) et on mémorise l'échange
            // exactement comme en production — c'est ce qui permet de
            // tester la mémoire sur DEUX questions liées.
            $messagesPont = is_array($session) && is_array($session['messages'] ?? null)
                ? $session['messages']
                : [];
            $this->historiser($question, '(exécution à blanc)');
            $this->json([
                'ok'         => true,
                'reponse'    => '(exécution à blanc)',
                'dry_run'    => ['messages' => $messagesPont],
                'historique' => $this->historiquePourAffichage(),
            ]);
        }

        if ($code === 0 && is_array($session) && ($session['ok'] ?? false) === true) {
            $this->historiser($question, (string) ($session['reponse'] ?? ''));
            $this->json($this->formaterReussite($session));
        }

        $erreur = is_array($session) ? trim((string) ($session['erreur'] ?? '')) : '';
        Logger::error('assistant page: échec du pont', [
            'code'   => $code,
            'erreur' => $erreur !== '' ? $erreur : mb_substr(trim($err), 0, 300),
        ]);

        // Service LLM éteint (code 3 du pont) → message clair, le bouton
        // de la page reste utilisable dès qu'il est relancé.
        if ($code === 3 || str_starts_with($erreur, 'Le service de l\'assistant')) {
            $this->json(['ok' => false, 'error' => 'L\'assistant local est éteint (service llm-aeic arrêté). Relance-le (systemctl --user start llm-aeic), puis repose la question.'], 503);
        }
        if ($code === 4) {
            // Jeton absent (fail-closed côté pont) : générique, détail en log.
            $this->json(['ok' => false, 'error' => 'L\'assistant n\'est pas configuré sur ce serveur.'], 503);
        }
        if (str_contains($erreur, 'Délai dépassé')) {
            $this->json(['ok' => false, 'error' => 'Réponse trop lente : la question a été annulée. Essaie une question plus courte.'], 504);
        }

        $this->json(['ok' => false, 'error' => 'L\'assistant n\'a pas pu répondre à cette question. Reformule-la.'], 502);
    }

    /**
     * Formate la réponse renvoyée à la page : réponse, sources (outil +
     * paramètres), nombre d'appels, durée — et l'avertissement anti-
     * invention R4 (réponse chiffrée sans AUCUN appel d'outil).
     *
     * @param array<string,mixed> $session Session --json du pont.
     *
     * @return array<string,mixed>
     */
    private function formaterReussite(array $session): array
    {
        $reponse = (string) ($session['reponse'] ?? '');
        $sources = is_array($session['sources'] ?? null) ? array_values(array_map('strval', $session['sources'])) : [];
        $appels  = is_array($session['appels_outils'] ?? null) ? count($session['appels_outils']) : 0;

        // Audit R4 (même règle que le pont) : des chiffres sans appel
        // d'outil = à signaler, jamais à masquer.
        $avertissement = ($appels === 0 && preg_match('/\d/', $reponse) === 1)
            ? 'Attention : cette réponse contient des chiffres sans aucun appel d\'outil — à vérifier.'
            : '';

        return [
            'ok'           => true,
            'reponse'      => $reponse,
            'sources'      => $sources,
            'appels'       => $appels,
            'duree_ms'     => (int) ($session['duree_ms'] ?? 0),
            'avertissement' => $avertissement,
            // Mémoire multi-tours (T4-P1-B) : conversation complète
            // (bulles) pour rafraîchir l'affichage de la page.
            'historique'   => $this->historiquePourAffichage(),
        ];
    }

    // -----------------------------------------------------------------
    //  Mémoire de conversation (T4-P1-B)
    // -----------------------------------------------------------------

    /**
     * Historique de conversation encore valide en session.
     *
     * Purge silencieuse si la clé est absente, malformée ou plus vieille
     * que DUREE_VIE_S (30 min) ; la déconnexion purge aussi (Auth::logout()
     * vide $_SESSION). Renvoie au plus MAX_ECHANGES échanges ['q','r']. 
     *
     * @return array<int,array{q:string,r:string}>
     */
    private function lireHistorique(): array
    {
        $stockee = $_SESSION[self::CLE_SESSION] ?? null;
        if (!is_array($stockee)) {
            return [];
        }
        $ts = (int) ($stockee['ts'] ?? 0);
        $echanges = $stockee['echanges'] ?? null;
        if (!is_array($echanges) || $ts === 0 || (time() - $ts) > self::DUREE_VIE_S) {
            unset($_SESSION[self::CLE_SESSION]);

            return [];
        }

        $propres = [];
        foreach ($echanges as $echange) {
            if (is_array($echange) && is_string($echange['q'] ?? null) && is_string($echange['r'] ?? null)) {
                $propres[] = ['q' => $echange['q'], 'r' => $echange['r']];
            }
        }

        return array_slice($propres, -self::MAX_ECHANGES);
    }

    /**
     * Mémorise un échange réussi (question + réponse) et rafraîchit
     * l'horodatage de la mémoire.
     */
    private function historiser(string $question, string $reponse): void
    {
        $echanges = $this->lireHistorique();
        $echanges[] = [
            'q' => mb_substr($question, 0, self::QUESTION_MAX),
            'r' => mb_substr($reponse, 0, self::REPONSE_STOCKEE_MAX),
        ];
        if (count($echanges) > self::MAX_ECHANGES) {
            $echanges = array_slice($echanges, -self::MAX_ECHANGES);
        }
        $_SESSION[self::CLE_SESSION] = ['ts' => time(), 'echanges' => $echanges];
    }

    /**
     * Historique → messages {role, content} transmis au pont.
     *
     * Bornes du contrat scripts/assistant.py : chaque message ≤ 800
     * caractères, total ≤ 4 000 — les échanges les plus anciens sautent
     * en premier, et la conversation ne démarre jamais par une réponse.
     * Le pont revalide ces mêmes bornes (défense en profondeur).
     *
     * @param array<int,array{q:string,r:string}> $echanges
     *
     * @return list<array{role:string,content:string}>
     */
    private function messagesContexte(array $echanges): array
    {
        $messages = [];
        foreach ($echanges as $echange) {
            $q = mb_substr($echange['q'], 0, self::CONTEXTE_MESSAGE_MAX);
            $r = mb_substr($echange['r'], 0, self::CONTEXTE_MESSAGE_MAX);
            if ($q !== '') {
                $messages[] = ['role' => 'user', 'content' => $q];
            }
            if ($r !== '') {
                $messages[] = ['role' => 'assistant', 'content' => $r];
            }
        }

        $total = 0;
        foreach ($messages as $message) {
            $total += mb_strlen($message['content']);
        }
        while ($messages !== [] && $total > self::CONTEXTE_TOTAL_MAX) {
            $total -= mb_strlen(array_shift($messages)['content']);
        }
        while ($messages !== [] && $messages[0]['role'] === 'assistant') {
            array_shift($messages);
        }

        return $messages;
    }

    /**
     * Historique formaté pour l'affichage (bulles de la page, échappement
     * e() côté vue — jamais de HTML stocké en session).
     *
     * @return list<array{role:'user'|'assistant',texte:string}>
     */
    private function historiquePourAffichage(): array
    {
        $bulles = [];
        foreach ($this->lireHistorique() as $echange) {
            $bulles[] = ['role' => 'user', 'texte' => $echange['q']];
            $bulles[] = ['role' => 'assistant', 'texte' => $echange['r']];
        }

        return $bulles;
    }

    /**
     * Exécute le pont en sous-processus SANS shell : la commande est un
     * tableau d'arguments (proc_open ne passe plus par /bin/sh — aucune
     * interprétation des caractères de la question, injection impossible
     * par construction, plus sûr encore qu'escapeshellarg). L'historique
     * de conversation passe par FICHIER (--context), jamais en argument.
     *
     * Lecture des tubes en non-bloquant avec stream_select jusqu'au
     * budget de TIMEOUT_S ; au-delà le processus est tué.
     *
     * @return array{0:int,1:string,2:string,3:bool} [code de sortie, stdout, stderr, timeout ?]
     */
    private function executerPont(string $question, string $fichierContexte = '', bool $dryRun = false): array
    {
        $commande = [self::PONT_PYTHON, self::PONT_SCRIPT, '--json', $question];
        if ($fichierContexte !== '') {
            $commande[] = '--context';
            $commande[] = $fichierContexte;
        }
        if ($dryRun) {
            $commande[] = '--dry-run';
        }

        $processus = proc_open(
            $commande,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $tubes,
            AEIC_ROOT
        );

        if (!is_resource($processus)) {
            return [1, '', 'proc_open a échoué', false];
        }

        fclose($tubes[0]); // aucune entrée attendue
        stream_set_blocking($tubes[1], false);
        stream_set_blocking($tubes[2], false);

        $sortie = '';
        $err    = '';
        $expire = false;
        $deadline = microtime(true) + self::TIMEOUT_S;

        while (true) {
            $reste = $deadline - microtime(true);
            if ($reste <= 0) {
                $expire = true;
                break;
            }

            $lire = [$tubes[1], $tubes[2]];
            $ecriture = null;
            $except = null;
            $n = stream_select($lire, $ecriture, $except, (int) floor($reste), ((int) floor($reste * 1000000)) % 1000000);

            if ($n === false) {
                break; // erreur select : on sort avec ce qu'on a
            }

            foreach ($lire as $tube) {
                $fragment = (string) fread($tube, 65_536);
                if ($tube === $tubes[1]) {
                    if (strlen($sortie) < self::SORTIE_MAX) {
                        $sortie .= $fragment;
                    }
                } elseif (strlen($err) < self::SORTIE_MAX) {
                    $err .= $fragment;
                }
            }

            if (feof($tubes[1]) && feof($tubes[2])) {
                break; // le pont a terminé d'écrire
            }
        }

        if ($expire) {
            proc_terminate($processus);
        }
        fclose($tubes[1]);
        fclose($tubes[2]);
        $code = proc_close($processus);

        return [$code, $sortie, $err, $expire];
    }
}
