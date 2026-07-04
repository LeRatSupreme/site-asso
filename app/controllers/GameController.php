<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\GameScore;
use App\Models\WordleWord;

/**
 * Zone jeux : Wordle (FR/EN) + classement.
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

        if (Auth::check()) {
            $user = Auth::user();
            $uid = (string) Auth::id();
            $stats = [
                'fr' => GameScore::getUserStats($uid, 'fr'),
                'en' => GameScore::getUserStats($uid, 'en'),
            ];
        }

        $this->render('game/index', [
            'title'       => '🎮 Zone jeux — AEIC',
            'description' => 'Joue au Wordle AEIC (FR/EN), suis ta série de victoires et grimpe dans le classement.',
            'user'        => $user,
            'stats'       => $stats,
        ]);
    }

    /**
     * Page du jeu Wordle : grille, clavier virtuel, dictionnaires FR/EN.
     */
    public function wordle(): void
    {
        $user = Auth::check() ? Auth::user() : null;
        $uid = Auth::check() ? (string) Auth::id() : null;

        $playedToday = [
            'fr' => $uid !== null ? GameScore::hasPlayedToday($uid, 'fr') : false,
            'en' => $uid !== null ? GameScore::hasPlayedToday($uid, 'en') : false,
        ];

        // Mots chargés depuis la base (table wordle_words).
        // Le mot du jour est sélectionné côté client à partir de cette liste,
        // de façon déterministe (même index pour tous les joueurs, pas de
        // répétition tant que la liste n'est pas entièrement parcourue).
        $words = [
            'fr' => WordleWord::allForLanguage('fr'),
            'en' => WordleWord::allForLanguage('en'),
        ];

        $this->render('game/wordle', [
            'title'          => '🎮 Wordle AEIC — FR / EN',
            'description'    => 'Le Wordle de l\'AEIC : un mot de 5 lettres par jour, en français ou en anglais.',
            'user'           => $user,
            'isLoggedIn'     => Auth::check(),
            'playedToday'    => $playedToday,
            'words'          => $words,
            'submitUrl'      => url('/jeux/wordle/submit'),
            'csrfToken'      => csrf_token(),
            'leaderboardUrl' => url('/jeux/leaderboard'),
        ]);
    }

    /**
     * Enregistre le résultat d'une partie Wordle (CSRF + auth requis, 1/jour).
     */
    public function submitWordle(): void
    {
        if (!Auth::check()) {
            $this->json(['success' => false, 'error' => 'auth_required'], 401);
        }

        $token = $_POST['_csrf'] ?? '';
        if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
            $this->json(['success' => false, 'error' => 'csrf'], 419);
        }

        $uid = (string) Auth::id();
        $mode = strtolower(trim((string) ($_POST['mode'] ?? '')));
        $won = filter_var($_POST['won'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $word = trim((string) ($_POST['word'] ?? ''));
        $attempts = isset($_POST['attempts']) ? (int) $_POST['attempts'] : null;

        if (!in_array($mode, ['fr', 'en'], true)) {
            $this->json(['success' => false, 'error' => 'invalid_mode'], 422);
        }
        if ($word !== '' && !preg_match('/^[A-Za-zÀ-ÿ]{5}$/u', $word)) {
            $word = null;
        } elseif ($word !== '') {
            $word = mb_strtoupper($word);
        } else {
            $word = null;
        }
        if ($attempts !== null && ($attempts < 1 || $attempts > 6)) {
            $attempts = null;
        }

        $alreadyPlayed = GameScore::hasPlayedToday($uid, $mode);

        try {
            GameScore::saveWordleResult($uid, $mode, $won, $word, $attempts);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => 'db_error'], 500);
        }

        $stats = GameScore::getUserStats($uid, $mode);

        $this->json([
            'success'       => true,
            'saved'         => !$alreadyPlayed,
            'alreadyPlayed' => $alreadyPlayed,
            'stats'         => $stats,
        ]);
    }

    /**
     * Classement Wordle, filtrable par mode (FR / EN).
     */
    public function leaderboard(): void
    {
        $mode = strtolower(trim((string) ($_GET['mode'] ?? 'fr')));
        if (!in_array($mode, ['fr', 'en'], true)) {
            $mode = 'fr';
        }

        $rows = GameScore::getLeaderboard($mode, 50);
        $currentId = Auth::check() ? (string) Auth::id() : null;

        $this->render('game/leaderboard', [
            'title'       => '🏆 Classement Wordle — AEIC',
            'description' => 'Classement des meilleurs joueurs de Wordle AEIC (FR/EN).',
            'mode'        => $mode,
            'rows'        => $rows,
            'currentId'   => $currentId,
        ]);
    }
}
