<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\DailyEnigma;
use App\Models\User;
use App\Models\WordleWord;
use PHPUnit\Framework\TestCase;

/**
 * Tests purs de la logique des jeux : normalisation des réponses de l'énigme,
 * validation des mots Wordle et normalisation des pseudos du classement.
 */
final class GamesModelsTest extends TestCase
{
    // ---------- Énigme quotidienne ----------

    public function test_enigma_reponse_exacte_acceptee(): void
    {
        self::assertTrue(DailyEnigma::isCorrect('un chat', 'un chat'));
    }

    public function test_enigma_reponse_insensible_a_la_casse_et_aux_espaces(): void
    {
        self::assertTrue(DailyEnigma::isCorrect('  UN   Chat  ', 'un chat'));
        // La ponctuation est supprimée (pas remplacée par un espace) :
        // 'un-chat' et 'unchat' se valent, mais pas avec 'un chat'.
        self::assertTrue(DailyEnigma::isCorrect('un-chat', 'unchat'));
        self::assertFalse(DailyEnigma::isCorrect('un-chat', 'un chat'));
    }

    public function test_enigma_reponse_insensible_aux_accents_et_ponctuation(): void
    {
        self::assertTrue(DailyEnigma::isCorrect('élève', 'eleve'));
        self::assertTrue(DailyEnigma::isCorrect('ÉLÈVE !', 'eleve'));
        self::assertTrue(DailyEnigma::isCorrect("l'école", 'lecole'));
    }

    public function test_enigma_accepte_une_variantes_parmi_plusieurs(): void
    {
        $accepted = 'pain au chocolat|chocolatine|petit pain';

        self::assertTrue(DailyEnigma::isCorrect('chocolatine', $accepted));
        self::assertTrue(DailyEnigma::isCorrect('Pain au chocolat', $accepted));
        self::assertFalse(DailyEnigma::isCorrect('croissant', $accepted));
    }

    public function test_enigma_reponse_vide_est_refusee(): void
    {
        self::assertFalse(DailyEnigma::isCorrect('', 'un chat'));
        self::assertFalse(DailyEnigma::isCorrect('   ', 'un chat'));
        self::assertFalse(DailyEnigma::isCorrect('...', 'un chat'));
    }

    // ---------- Wordle ----------

    public function test_wordle_difficultes_valides(): void
    {
        self::assertTrue(WordleWord::isValidDifficulty('facile'));
        self::assertTrue(WordleWord::isValidDifficulty('moyen'));
        self::assertTrue(WordleWord::isValidDifficulty('difficile'));
    }

    public function test_wordle_difficultes_inconnues_rejetees(): void
    {
        self::assertFalse(WordleWord::isValidDifficulty('heroique'));
        self::assertFalse(WordleWord::isValidDifficulty('Facile'));
        self::assertFalse(WordleWord::isValidDifficulty(''));
    }

    public function test_wordle_longueurs_correspondent_aux_difficultes(): void
    {
        self::assertSame(5, WordleWord::LENGTHS['facile']);
        self::assertSame(6, WordleWord::LENGTHS['moyen']);
        self::assertSame(7, WordleWord::LENGTHS['difficile']);
        self::assertCount(3, WordleWord::LENGTHS);
    }

    public function test_wordle_date_paris_a_le_bon_format(): void
    {
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', WordleWord::parisDate());
    }

    // ---------- Pseudo joueur (classement) ----------

    public function test_pseudo_valide_est_conserve_et_trimme(): void
    {
        self::assertSame('MotusMaster', User::normalizePseudo('  MotusMaster  '));
        self::assertSame('Joueur 2', User::normalizePseudo('Joueur 2'));
        self::assertSame('a-b_c.d', User::normalizePseudo('a-b_c.d'));
        self::assertSame('Élève2026', User::normalizePseudo('Élève2026'));
    }

    public function test_pseudo_replie_les_espaces_multiples(): void
    {
        self::assertSame('Joueur 2', User::normalizePseudo('Joueur    2'));
    }

    public function test_pseudo_invalide_renvoie_chaine_vide(): void
    {
        self::assertSame('', User::normalizePseudo(''));
        self::assertSame('', User::normalizePseudo('   '));
        // Trop court (< 3) ou trop long (> 20).
        self::assertSame('', User::normalizePseudo('ab'));
        self::assertSame('', User::normalizePseudo('abcdefghijklmnopqrstuv'));
        // Caractères interdits.
        self::assertSame('', User::normalizePseudo('joueur<script>'));
        self::assertSame('', User::normalizePseudo('joueur!'));
    }
}
