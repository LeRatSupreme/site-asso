<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\GameScore;
use App\Models\Model;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests de GameScore::saveArcadeResult() contre la vraie base aeic_test :
 * upsert du meilleur score du jour, bornage des compteurs, drapeau record.
 *
 * Saute si la base aeic_test est indisponible.
 */
final class ArcadeScoreSaveTest extends TestCase
{
    use TestDatabaseTrait;

    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped('Base aeic_test indisponible : configurez DB_* dans phpunit.xml.');
        }
        $this->pdo = $pdo;

        $this->reset($pdo, ['game_scores', 'users']);
        Model::setTestPdo($pdo);

        $stmt = $pdo->prepare(
            'INSERT INTO users (id, prenom, nom, email, password, role, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(['j1', 'Joueur', 'Un', 'j1@ex.fr', password_hash('SecretPassword123', PASSWORD_BCRYPT), 'eleve', 1]);
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
    }

    public function test_premier_score_est_enregistre_et_compte_comme_record(): void
    {
        $isRecord = GameScore::saveArcadeResult('j1', 'snake', 'murs-normal', 100);

        self::assertTrue($isRecord);

        $row = $this->todayRow('j1', 'snake', 'murs-normal');
        self::assertNotNull($row);
        self::assertSame(100, (int) $row['score']);
        self::assertSame(1, (int) $row['won']);
    }

    public function test_score_plus_faible_ne_remplace_pas_le_record(): void
    {
        self::assertTrue(GameScore::saveArcadeResult('j1', 'snake', 'murs-normal', 100));
        self::assertFalse(GameScore::saveArcadeResult('j1', 'snake', 'murs-normal', 50));

        $row = $this->todayRow('j1', 'snake', 'murs-normal');
        self::assertSame(100, (int) $row['score']);
    }

    public function test_score_plus_eleve_remplace_le_record(): void
    {
        self::assertTrue(GameScore::saveArcadeResult('j1', 'tetris', 'marathon', 50, ['lines' => 3]));
        self::assertTrue(GameScore::saveArcadeResult('j1', 'tetris', 'marathon', 150, ['lines' => 8]));

        $row = $this->todayRow('j1', 'tetris', 'marathon');
        self::assertSame(150, (int) $row['score']);
        self::assertSame(8, (int) $row['attempts']);
    }

    public function test_egalite_conserve_une_seule_ligne(): void
    {
        GameScore::saveArcadeResult('j1', 'memory', '4x4', 500);
        GameScore::saveArcadeResult('j1', 'memory', '4x4', 500);

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS n FROM game_scores WHERE user_id = 'j1' AND game = 'memory' AND mode = '4x4'"
        );
        $stmt->execute();

        self::assertSame(1, (int) $stmt->fetch()['n']);
    }

    public function test_scores_bornes_meme_avec_valeurs_abusives(): void
    {
        GameScore::saveArcadeResult('j1', 'memory', '6x6', PHP_INT_MAX, ['moves' => 999_999]);

        $row = $this->todayRow('j1', 'memory', '6x6');
        self::assertSame(1_000_000, (int) $row['score']);
        self::assertSame(10_000, (int) $row['attempts']);
    }

    public function test_jeux_et_modes_sont_des_lignes_separees(): void
    {
        GameScore::saveArcadeResult('j1', 'snake', 'murs-normal', 10);
        GameScore::saveArcadeResult('j1', 'snake', 'portail-normal', 20);
        GameScore::saveArcadeResult('j1', 'memory', '4x4', 30);

        $stmt = $this->pdo->prepare("SELECT COUNT(*) AS n FROM game_scores WHERE user_id = 'j1'");
        $stmt->execute();

        self::assertSame(3, (int) $stmt->fetch()['n']);
    }

    /**
     * Ligne de score du jour pour le couple jeu/mode donné (ou null).
     */
    private function todayRow(string $userId, string $game, string $mode): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM game_scores
             WHERE user_id = ? AND game = ? AND mode = ? AND played_at = ?
             LIMIT 1'
        );
        $stmt->execute([$userId, $game, $mode, date('Y-m-d')]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }
}
