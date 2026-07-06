<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\DailyEnigma;
use App\Models\GameScore;
use App\Models\User;
use App\Models\WordleWord;

/**
 * Administration de la zone jeux : vue d'ensemble, joueurs/pseudos,
 * gestion des mots Wordle et des énigmes quotidiennes.
 */
final class AdminGameController extends AdminBaseController
{
    /**
     * Vue d'ensemble : statistiques globales des jeux.
     */
    public function index(): void
    {
        $this->guard();

        $stats = [
            'wordsFr'       => WordleWord::countForDifficulty('fr', 'facile')
                              + WordleWord::countForDifficulty('fr', 'moyen')
                              + WordleWord::countForDifficulty('fr', 'difficile'),
            'wordsEn'       => WordleWord::countForDifficulty('en', 'facile')
                              + WordleWord::countForDifficulty('en', 'moyen')
                              + WordleWord::countForDifficulty('en', 'difficile'),
            'wordsFacile'   => WordleWord::countForDifficulty('fr', 'facile') + WordleWord::countForDifficulty('en', 'facile'),
            'wordsMoyen'    => WordleWord::countForDifficulty('fr', 'moyen') + WordleWord::countForDifficulty('en', 'moyen'),
            'wordsDifficile'=> WordleWord::countForDifficulty('fr', 'difficile') + WordleWord::countForDifficulty('en', 'difficile'),
            'enigmas'       => DailyEnigma::countActive(),
            'players'       => count(GameScore::playersForAdmin()),
        ];

        $this->renderAdmin('admin/jeux/index', [
            'title' => '🎮 Jeux',
            'stats' => $stats,
        ]);
    }

    // ===================== JOUEURS & PSEUDOS =====================

    /**
     * Liste de tous les joueurs avec pseudo (modifiable) et stats.
     */
    public function scores(): void
    {
        $this->guard();

        $players = GameScore::playersForAdmin();

        $this->renderAdmin('admin/jeux/scores', [
            'title'   => '🎮 Joueurs & Pseudos',
            'players' => $players,
        ]);
    }

    /**
     * Modifie le pseudo d'un joueur (POST).
     */
    public function setPseudo(): void
    {
        $this->guard();

        $userId = (string) ($_POST['user_id'] ?? '');
        $pseudo = trim((string) ($_POST['pseudo'] ?? ''));

        if ($userId === '') {
            $this->setFlash('error', 'Utilisateur introuvable.');
            redirect(url('/admin/jeux/scores'));
        }

        // Pseudo vide = on efface le pseudo.
        if ($pseudo === '') {
            $stmt = db()->prepare('UPDATE users SET pseudo = NULL WHERE id = ?');
            $stmt->execute([$userId]);
            $this->audit('user.pseudo.clear', 'user', $userId);
            $this->setFlash('success', 'Pseudo effacé.');
            redirect(url('/admin/jeux/scores'));
        }

        $normalized = User::normalizePseudo($pseudo);
        if ($normalized === '') {
            $this->setFlash('error', 'Pseudo invalide (3 à 20 caractères : lettres, chiffres, espaces, - _ .).');
            redirect(url('/admin/jeux/scores'));
        }

        if (!User::isPseudoAvailable($normalized, $userId)) {
            $this->setFlash('error', 'Ce pseudo est déjà utilisé par un autre joueur.');
            redirect(url('/admin/jeux/scores'));
        }

        User::setPseudo($userId, $normalized);
        $this->audit('user.pseudo.update', 'user', $userId, ['pseudo' => $normalized]);
        $this->setFlash('success', 'Pseudo mis à jour : ' . $normalized);
        redirect(url('/admin/jeux/scores'));
    }

    /**
     * Réinitialise les scores d'un joueur (POST).
     */
    public function resetPlayer(): void
    {
        $this->guard();

        $userId = (string) ($_POST['user_id'] ?? '');
        if ($userId === '') {
            $this->setFlash('error', 'Utilisateur introuvable.');
            redirect(url('/admin/jeux/scores'));
        }

        $deleted = GameScore::resetPlayer($userId);
        $this->audit('game.scores.reset', 'user', $userId, ['deleted' => $deleted]);
        $this->setFlash('success', $deleted . ' score(s) supprimé(s).');
        redirect(url('/admin/jeux/scores'));
    }

    // ===================== MOTS WORDLE =====================

    /**
     * Liste des mots Wordle (avec recherche + filtres).
     */
    public function wordleIndex(): void
    {
        $this->guard();

        $search = trim((string) ($_GET['q'] ?? ''));
        $language = trim((string) ($_GET['lang'] ?? ''));
        $difficulty = trim((string) ($_GET['diff'] ?? ''));
        $perPage = (int) ($_GET['perPage'] ?? 100);
        if (!in_array($perPage, [50, 100, 200, 500], true)) {
            $perPage = 100;
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        $result = WordleWord::adminList(
            $search !== '' ? $search : null,
            $language !== '' ? $language : null,
            $difficulty !== '' ? $difficulty : null,
            $perPage,
            $offset
        );

        $totalPages = max(1, (int) ceil($result['total'] / $perPage));

        $this->renderAdmin('admin/jeux/wordle/index', [
            'title'      => '🎮 Mots Wordle',
            'words'      => $result['rows'],
            'total'      => $result['total'],
            'search'     => $search,
            'langFilter' => $language,
            'diffFilter' => $difficulty,
            'page'       => $page,
            'totalPages' => $totalPages,
            'perPage'    => $perPage,
        ]);
    }

    /**
     * Formulaire d'ajout/édition d'un mot.
     */
    public function wordleForm(?string $id = null): void
    {
        $this->guard();

        $word = ['id' => 0, 'word' => '', 'language' => 'fr', 'difficulty' => 'facile', 'is_active' => 1];
        if ($id !== null && $id !== 'new') {
            $found = WordleWord::findById((int) $id);
            if ($found !== null) {
                $word = $found;
            }
        }

        $this->renderAdmin('admin/jeux/wordle/form', [
            'title' => ($word['id'] ?? 0) > 0 ? '🎮 Modifier le mot' : '🎮 Nouveau mot',
            'word'  => $word,
        ]);
    }

    /**
     * Enregistre (crée ou met à jour) un mot.
     */
    public function saveWordle(): void
    {
        $this->guard();

        $id = WordleWord::save($_POST);
        if ($id > 0) {
            $isNew = empty($_POST['id']);
            $this->audit($isNew ? 'wordle.word.create' : 'wordle.word.update', 'wordle_word', (string) $id);
            $this->setFlash('success', 'Mot enregistré.');
        } else {
            $this->setFlash('error', 'Mot invalide ou doublon (longueur 5 à 7 lettres A-Z).');
        }
        redirect(url('/admin/jeux/wordle'));
    }

    /**
     * Supprime un mot.
     */
    public function deleteWordle(string $id): void
    {
        $this->guard();

        WordleWord::deleteRow((int) $id);
        $this->audit('wordle.word.delete', 'wordle_word', $id);
        $this->setFlash('success', 'Mot supprimé.');
        redirect(url('/admin/jeux/wordle'));
    }

    // ===================== ÉNIGMES =====================

    /**
     * Liste des énigmes.
     */
    public function enigmaIndex(): void
    {
        $this->guard();

        $enigmas = DailyEnigma::allForAdmin();

        $this->renderAdmin('admin/jeux/enigmas/index', [
            'title'   => '🎮 Énigmes',
            'enigmas' => $enigmas,
        ]);
    }

    /**
     * Formulaire d'ajout/édition d'une énigme.
     */
    public function enigmaForm(?string $id = null): void
    {
        $this->guard();

        $enigma = [
            'id' => 0, 'question_fr' => '', 'question_en' => '',
            'answer' => '', 'hint_fr' => '', 'hint_en' => '', 'is_active' => 1,
        ];
        if ($id !== null && $id !== 'new') {
            $found = DailyEnigma::findById((int) $id);
            if ($found !== null) {
                $enigma = $found;
            }
        }

        $this->renderAdmin('admin/jeux/enigmas/form', [
            'title'  => ($enigma['id'] ?? 0) > 0 ? '🎮 Modifier l\'énigme' : '🎮 Nouvelle énigme',
            'enigma' => $enigma,
        ]);
    }

    /**
     * Enregistre (crée ou met à jour) une énigme.
     */
    public function saveEnigma(): void
    {
        $this->guard();

        $id = DailyEnigma::save($_POST);
        if ($id > 0) {
            $isNew = empty($_POST['id']);
            $this->audit($isNew ? 'enigma.create' : 'enigma.update', 'daily_enigma', (string) $id);
            $this->setFlash('success', 'Énigme enregistrée.');
        } else {
            $this->setFlash('error', 'Énigme invalide (question FR + réponse obligatoires).');
        }
        redirect(url('/admin/jeux/enigmes'));
    }

    /**
     * Supprime une énigme.
     */
    public function deleteEnigma(string $id): void
    {
        $this->guard();

        DailyEnigma::deleteRow((int) $id);
        $this->audit('enigma.delete', 'daily_enigma', $id);
        $this->setFlash('success', 'Énigme supprimée.');
        redirect(url('/admin/jeux/enigmes'));
    }
}
