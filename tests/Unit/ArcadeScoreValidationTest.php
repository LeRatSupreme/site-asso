<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\GameScore;
use PHPUnit\Framework\TestCase;

/**
 * Tests purs de la validation des scores d'arcade (memory, snake, tetris) :
 * liste blanche des modes, bornage des scores et compteurs.
 *
 * Garde-fous critiques :
 * - tout mode doit tenir dans la colonne game_scores.mode VARCHAR(20) ;
 * - un score envoyé par le client ne peut jamais dépasser les bornes serveur.
 */
final class ArcadeScoreValidationTest extends TestCase
{
    public function test_modes_memory_contient_les_7_tailles(): void
    {
        $modes = GameScore::arcadeModes()['memory'] ?? [];

        self::assertSame(
            ['4x3', '4x4', '6x4', '6x6', '8x6', '8x8', '10x8'],
            $modes
        );
    }

    public function test_modes_snake_contient_les_9_combinaisons_terrain_vitesse(): void
    {
        $modes = GameScore::arcadeModes()['snake'] ?? [];

        self::assertSame([
            'murs-lent', 'murs-normal', 'murs-rapide',
            'portail-lent', 'portail-normal', 'portail-rapide',
            'obstacles-lent', 'obstacles-normal', 'obstacles-rapide',
        ], $modes);
    }

    public function test_modes_tetris_contient_marathon(): void
    {
        self::assertSame(['marathon'], GameScore::arcadeModes()['tetris'] ?? []);
    }

    public function test_tous_les_modes_tiennent_dans_la_colonne_varchar20(): void
    {
        foreach (GameScore::arcadeModes() as $game => $modes) {
            self::assertNotEmpty($modes, "Le jeu $game doit avoir au moins un mode.");
            foreach ($modes as $mode) {
                self::assertLessThanOrEqual(
                    20,
                    strlen($mode),
                    "Le mode '$mode' du jeu $game dépasse VARCHAR(20)."
                );
                self::assertSame(strtolower($mode), $mode, "Le mode '$mode' doit être en minuscules.");
            }
        }
    }

    public function test_mode_valide_est_accepte(): void
    {
        self::assertTrue(GameScore::isValidArcadeMode('memory', '4x3'));
        self::assertTrue(GameScore::isValidArcadeMode('memory', '10x8'));
        self::assertTrue(GameScore::isValidArcadeMode('snake', 'murs-normal'));
        self::assertTrue(GameScore::isValidArcadeMode('snake', 'obstacles-rapide'));
        self::assertTrue(GameScore::isValidArcadeMode('tetris', 'marathon'));
    }

    public function test_mode_invalide_est_rejete(): void
    {
        // Jeu inconnu.
        self::assertFalse(GameScore::isValidArcadeMode('pong', 'normal'));
        // Mode inconnu pour un jeu connu.
        self::assertFalse(GameScore::isValidArcadeMode('memory', 'normal'));
        self::assertFalse(GameScore::isValidArcadeMode('snake', 'murs'));
        self::assertFalse(GameScore::isValidArcadeMode('tetris', 'sprint'));
        // Casse et espaces : le contrôleur normalise, la validation est stricte.
        self::assertFalse(GameScore::isValidArcadeMode('snake', 'MURS-NORMAL'));
        self::assertFalse(GameScore::isValidArcadeMode('snake', 'murs-normal '));
    }

    public function test_clamp_score_borne_les_valeurs_extremes(): void
    {
        self::assertSame(0, GameScore::clampScore(-1));
        self::assertSame(0, GameScore::clampScore(-999_999));
        self::assertSame(0, GameScore::clampScore(0));
        self::assertSame(42, GameScore::clampScore(42));
        self::assertSame(1_000_000, GameScore::clampScore(1_000_000));
        self::assertSame(1_000_000, GameScore::clampScore(2_000_000));
        self::assertSame(1_000_000, GameScore::clampScore(PHP_INT_MAX));
    }

    public function test_clamp_counter_borne_les_compteurs(): void
    {
        self::assertNull(GameScore::clampCounter(null));
        self::assertNull(GameScore::clampCounter(-1));
        self::assertSame(0, GameScore::clampCounter(0));
        self::assertSame(36, GameScore::clampCounter(36));
        self::assertSame(10_000, GameScore::clampCounter(10_000));
        self::assertSame(10_000, GameScore::clampCounter(999_999));
    }
}
