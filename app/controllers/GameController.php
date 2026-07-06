<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\DailyEnigma;
use App\Models\GameScore;
use App\Models\User;
use App\Models\WordleWord;

/**
 * Zone jeux : Wordle (FR/EN, 3 difficultés, 2 modes) + Énigme quotidienne + classement.
 */
final class GameController extends Controller
{
    /**
     * Menu des jeux disponibles + statistiques personnelles.
     */
    public function index(): void
    {
        $stats = null;
        $user = null;
        $uid = null;

        if (Auth::check()) {
            $user = Auth::user();
            $uid = (string) Auth::id();
            $stats = [
                'fr' => GameScore::getUserStats($uid, 'daily_fr'),
                'en' => GameScore::getUserStats($uid, 'daily_en'),
            ];
        }

        // Classement global (tout le monde, FR + EN confondus).
        $leaderboard = GameScore::getGlobalLeaderboard(200);

        $this->render('game/index', [
            'title'       => '🎮 Zone jeux — AEIC',
            'description' => 'Joue au Wordle AEIC (FR/EN, 3 difficultés), résous l\'énigme du jour et grimpe dans le classement.',
            'user'        => $user,
            'stats'       => $stats,
            'leaderboard' => $leaderboard,
            'currentId'   => $uid,
            'setPseudoUrl' => url('/jeux/set-pseudo'),
            'csrfToken'    => csrf_token(),
        ]);
    }

    /**
     * Définit le pseudo joueur (AJAX, auth requis).
     */
    public function setPseudo(): void
    {
        if (!Auth::check()) {
            $this->json(['success' => false, 'error' => 'auth_required'], 401);
        }

        $raw = file_get_contents('php://input');
        $data = [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
        $data = array_merge($_POST, $data);

        $token = (string) ($data['_csrf'] ?? '');
        if (!hash_equals(csrf_token(), $token)) {
            $this->json(['success' => false, 'error' => 'csrf'], 419);
        }

        $pseudo = (string) ($data['pseudo'] ?? '');
        $uid = (string) Auth::id();

        $normalized = User::normalizePseudo($pseudo);
        if ($normalized === '') {
            $this->json(['success' => false, 'error' => 'invalid', 'message' => '3 à 20 caractères (lettres, chiffres, espaces, - _ .)'], 422);
        }

        if (!User::isPseudoAvailable($normalized, $uid)) {
            $this->json(['success' => false, 'error' => 'taken', 'message' => 'Ce pseudo est déjà utilisé.'], 409);
        }

        $saved = User::setPseudo($uid, $normalized);

        $this->json([
            'success' => true,
            'pseudo'  => $saved,
        ]);
    }

    /**
     * Page du jeu Wordle : sélection langue / difficulté / mode, grille adaptative.
     * Le mot est fourni par l'endpoint AJAX getWord() (un seul mot par partie).
     */
    public function wordle(): void
    {
        $user = Auth::check() ? Auth::user() : null;

        $this->render('game/wordle', [
            'title'          => '🎮 Wordle AEIC — FR / EN',
            'description'    => 'Le Wordle de l\'AEIC : 3 niveaux de difficulté, un mot du jour commun et un mode libre illimité.',
            'user'           => $user,
            'isLoggedIn'     => Auth::check(),
            'submitUrl'      => url('/jeux/wordle/submit'),
            'getWordUrl'     => url('/jeux/wordle/word'),
            'csrfToken'      => csrf_token(),
            'leaderboardUrl' => url('/jeux/leaderboard'),
        ]);
    }

    /**
     * Endpoint AJAX : retourne UN mot (JSON) selon la langue, la difficulté et le mode.
     *
     *   mode = daily  → mot du jour (commun, change à minuit Paris)
     *   mode = free   → mot aléatoire (différent à chaque appel)
     *
     * On ne renvoie qu'un seul mot (jamais toute la liste) pour éviter
     * d'envoyer 10 000+ mots au navigateur.
     */
    public function getWord(): void
    {
        $language   = strtolower(trim((string) ($_GET['lang'] ?? 'fr')));
        $difficulty = strtolower(trim((string) ($_GET['difficulty'] ?? 'facile')));
        $mode       = strtolower(trim((string) ($_GET['mode'] ?? 'free')));

        if (!in_array($language, WordleWord::LANGUAGES, true)) {
            $this->json(['error' => 'invalid_language'], 400);
        }
        if (!WordleWord::isValidDifficulty($difficulty)) {
            $this->json(['error' => 'invalid_difficulty'], 400);
        }
        if (!in_array($mode, ['daily', 'free'], true)) {
            $mode = 'free';
        }

        // Le mode quotidien est TOUJOURS en 5 lettres (facile) :
        // même mot pour tout le monde, série de victoires comparable.
        if ($mode === 'daily') {
            $difficulty = 'facile';
        }

        if ($mode === 'daily') {
            $word = WordleWord::dailyWord($language, $difficulty);
        } else {
            $word = WordleWord::randomWord($language, $difficulty);
        }

        if ($word === '') {
            $this->json(['error' => 'no_word_available', 'length' => WordleWord::LENGTHS[$difficulty]], 404);
        }

        $this->json([
            'word'       => $word,
            'length'     => strlen($word),
            'difficulty' => $difficulty,
            'language'   => $language,
            'mode'       => $mode,
        ]);
    }

    /**
     * Enregistre le résultat d'une partie Wordle (CSRF + auth requis, 1/jour pour le mode daily).
     */
    public function submitWordle(): void
    {
        if (!Auth::check()) {
            $this->json(['success' => false, 'error' => 'auth_required'], 401);
        }

        // Accepte JSON ou form-data.
        $raw = file_get_contents('php://input');
        $data = [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
        $data = array_merge($_POST, $data);

        // Token CSRF : accepté depuis le body (_csrf) OU le header (X-CSRF-Token).
        $token = (string) ($data['_csrf'] ?? '');
        if ($token === '') {
            $headerToken = '';
            foreach (['HTTP_X_CSRF_TOKEN', 'HTTP_X_CSRFTOKEN'] as $key) {
                if (!empty($_SERVER[$key])) {
                    $headerToken = (string) $_SERVER[$key];
                    break;
                }
            }
            $token = $headerToken;
        }
        if (!hash_equals(csrf_token(), $token)) {
            $this->json(['success' => false, 'error' => 'csrf'], 419);
        }

        $uid = (string) Auth::id();
        $mode = strtolower(trim((string) ($data['mode'] ?? 'free')));
        $language = strtolower(trim((string) ($data['lang'] ?? 'fr')));
        $difficulty = strtolower(trim((string) ($data['difficulty'] ?? 'facile')));
        $won = filter_var($data['won'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $attempts = isset($data['attempts']) ? (int) $data['attempts'] : null;

        if (!in_array($mode, ['daily', 'free'], true)) {
            $mode = 'free';
        }
        if (!in_array($language, ['fr', 'en'], true)) {
            $language = 'fr';
        }
        if (!WordleWord::isValidDifficulty($difficulty)) {
            $difficulty = 'facile';
        }
        if ($attempts !== null && ($attempts < 1 || $attempts > 6)) {
            $attempts = null;
        }

        // On ne sauvegarde en base QUE le mode quotidien (1 fois par jour).
        $saved = false;
        if ($mode === 'daily') {
            // Le mode quotidien est toujours en 5 lettres : clé dédiée daily_{lang}.
            $gameMode = 'daily_' . $language;
            $alreadyPlayed = GameScore::hasPlayedToday($uid, $gameMode);
            try {
                GameScore::saveWordleResult($uid, $gameMode, $won, null, $attempts);
                $saved = !$alreadyPlayed;
            } catch (\Throwable $e) {
                // Ignore : on ne veut pas casser l'expérience utilisateur.
            }
        }

        $this->json([
            'success' => true,
            'saved'   => $saved,
            'mode'    => $mode,
        ]);
    }

    /**
     * Classement Wordle, filtrable par mode (FR/EN).
     */
    public function leaderboard(): void
    {
        // Classement global (toutes langues, pseudo) — tout le monde.
        $rows = GameScore::getGlobalLeaderboard(200);
        $currentId = Auth::check() ? (string) Auth::id() : null;

        $this->render('game/leaderboard', [
            'title'       => '🏆 Classement Wordle — AEIC',
            'description' => 'Classement des joueurs de Wordle AEIC par série de victoires en cours (mot quotidien 5 lettres).',
            'mode'        => 'global',
            'rows'        => $rows,
            'currentId'   => $currentId,
        ]);
    }

    // ===================== ÉNIGME QUOTIDIENNE =====================

    /**
     * Page de l'énigme quotidienne.
     */
    public function enigma(): void
    {
        $enigma = DailyEnigma::enigmaOfDay();
        $lang = (string) ($_SESSION['lang'] ?? 'fr');

        $this->render('game/enigma', [
            'title'       => '🧩 Énigme du jour — AEIC',
            'description' => 'Une nouvelle énigme chaque jour, identique pour tous les joueurs. Saurez-vous la résoudre ?',
            'enigma'      => $enigma,
            'lang'        => $lang,
            'submitUrl'   => url('/jeux/enigme/check'),
            'csrfToken'   => csrf_token(),
        ]);
    }

    /**
     * Vérifie la réponse à l'énigme du jour (AJAX).
     */
    public function checkEnigma(): void
    {
        $raw = file_get_contents('php://input');
        $data = [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
        $data = array_merge($_POST, $data);

        $token = (string) ($data['_csrf'] ?? '');
        if (!hash_equals(csrf_token(), $token)) {
            $this->json(['success' => false, 'error' => 'csrf'], 419);
        }

        $userAnswer = (string) ($data['answer'] ?? '');
        $enigma = DailyEnigma::enigmaOfDay();

        if ($enigma === null) {
            $this->json(['success' => false, 'error' => 'no_enigma'], 404);
        }

        $correct = DailyEnigma::isCorrect($userAnswer, (string) ($enigma['answer'] ?? ''));

        $this->json([
            'success' => true,
            'correct' => $correct,
        ]);
    }
}
