<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Model;
use App\Models\Recommendation;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests du modèle Recommendation (couche données du moteur de
 * recommandations commerciales).
 *
 * Saute si la base aeic_test est indisponible.
 */
final class RecommendationModelTest extends TestCase
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

        $this->applyRecommendationsMigration($pdo);
        $this->reset($pdo, ['recommendations']);
        Model::setTestPdo($pdo);
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
    }

    public function test_upsert_puis_re_upsert_ne_duplique_pas(): void
    {
        Recommendation::upsert($this->reco('fp_duo'), $this->fenetre());

        // Re-upsert de la même empreinte : même ligne, contenu rafraîchi.
        Recommendation::upsert($this->reco('fp_duo', 'Titre rafraîchi'), $this->fenetre('2026-10-01'));

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM recommendations');
        self::assertSame(1, (int) $stmt->fetchColumn());

        $row = Recommendation::find('fp_duo');
        self::assertNotNull($row);
        self::assertSame('Titre rafraîchi', (string) $row['titre']);
        self::assertSame('R1', (string) $row['regle']);
        self::assertSame('proposee', (string) $row['statut']);
        self::assertNull($row['decided_at']);
        self::assertStringContainsString('2026-10-01', (string) $row['fenetre_json']);
    }

    public function test_re_upsert_nefface_pas_une_decision_existante(): void
    {
        Recommendation::upsert($this->reco('fp_decide'), $this->fenetre());
        Recommendation::decision('fp_decide', 'plus_tard');
        $avant = Recommendation::find('fp_decide');

        Recommendation::upsert($this->reco('fp_decide', 'Re-proposée'), $this->fenetre());

        $apres = Recommendation::find('fp_decide');
        self::assertSame('plus_tard', (string) $apres['statut']);
        self::assertSame($avant['decided_at'], $apres['decided_at']);
        self::assertSame($avant['created_at'], $apres['created_at']);
    }

    public function test_decision_ignoree_et_refus_recents_la_voit(): void
    {
        Recommendation::upsert($this->reco('fp_refus'), $this->fenetre());

        self::assertTrue(Recommendation::decision('fp_refus', 'ignoree', 'Pas pertinent'));
        // Statut hors liste : refusé, base intacte.
        self::assertFalse(Recommendation::decision('fp_refus', 'proposee'));

        $row = Recommendation::find('fp_refus');
        self::assertSame('ignoree', (string) $row['statut']);
        self::assertSame('Pas pertinent', (string) $row['motif']);
        self::assertNotNull($row['decided_at']);
        self::assertNull($row['applied_at']);

        $refus = Recommendation::refusRecents();
        self::assertCount(1, $refus);
        self::assertSame('fp_refus', $refus[0]['fingerprint']);
        self::assertNotSame('', $refus[0]['date']);
    }

    public function test_actifs_contient_proposee_plus_tard_et_appliquee_fraiche(): void
    {
        Recommendation::upsert($this->reco('fp_proposee'), $this->fenetre());
        Recommendation::upsert($this->reco('fp_plus_tard'), $this->fenetre());
        Recommendation::upsert($this->reco('fp_appliquee'), $this->fenetre());
        Recommendation::upsert($this->reco('fp_ignoree'), $this->fenetre());

        Recommendation::decision('fp_plus_tard', 'plus_tard');
        Recommendation::decision('fp_appliquee', 'appliquee');
        Recommendation::decision('fp_ignoree', 'ignoree');

        $actifs = Recommendation::actifs();
        sort($actifs);
        self::assertSame(['fp_appliquee', 'fp_plus_tard', 'fp_proposee'], $actifs);
    }

    public function test_appliquee_vieille_de_40_jours_nest_plus_active(): void
    {
        Recommendation::upsert($this->reco('fp_vieille'), $this->fenetre());
        Recommendation::decision('fp_vieille', 'appliquee');
        self::assertContains('fp_vieille', Recommendation::actifs());

        // Vieillissement artificiel : appliquée il y a 40 jours (> 30 j).
        $this->pdo->prepare(
            "UPDATE recommendations SET applied_at = DATE_SUB(NOW(), INTERVAL 40 DAY) WHERE id = 'fp_vieille'"
        )->execute();

        self::assertNotContains('fp_vieille', Recommendation::actifs());
    }

    public function test_save_effet_ecrit_effet_checked_at_et_sort_des_en_cours(): void
    {
        Recommendation::upsert($this->reco('fp_effet'), $this->fenetre());
        Recommendation::decision('fp_effet', 'appliquee');
        Recommendation::saveBaseline(
            'fp_effet',
            '[{"date":"2026-10-01","attendu":12.5,"lo":10.0,"hi":15.0,"facteur":1.1}]'
        );

        // Avant mesure : présente dans appliqueesEnCours.
        $enCours = Recommendation::appliqueesEnCours();
        self::assertCount(1, $enCours);
        self::assertSame('fp_effet', $enCours[0]['id']);
        self::assertSame('[{"key":"coca","label":"Coca-Cola"}]', $enCours[0]['produits_json']);
        self::assertNotNull($enCours[0]['baseline_json']);
        self::assertNotNull($enCours[0]['applied_at']);

        Recommendation::saveEffet(
            'fp_effet',
            ['etat' => 130.0, 'effet' => 17.5, 'bas' => 5.0, 'haut' => 30.0, 'jours' => 22, 'note' => 'Effet positif'],
            'concluant'
        );

        $row = Recommendation::find('fp_effet');
        self::assertNotNull($row['effet_checked_at']);
        self::assertSame('concluant', (string) $row['effet_conclusion']);
        $effet = json_decode((string) $row['effet_json'], true);
        self::assertSame(17.5, $effet['effet']);
        self::assertSame('Effet positif', $effet['note']);

        // Après mesure : plus dans appliqueesEnCours.
        self::assertSame([], Recommendation::appliqueesEnCours());
    }

    public function test_historique_ordonne_par_applied_at_desc(): void
    {
        Recommendation::upsert($this->reco('fp_hist_a', 'Première'), $this->fenetre());
        Recommendation::upsert($this->reco('fp_hist_b', 'Seconde'), $this->fenetre());
        Recommendation::decision('fp_hist_a', 'appliquee');
        Recommendation::decision('fp_hist_b', 'appliquee');

        // « a » appliquée 10 jours avant « b ».
        $this->pdo->prepare(
            "UPDATE recommendations SET applied_at = DATE_SUB(NOW(), INTERVAL 10 DAY) WHERE id = 'fp_hist_a'"
        )->execute();

        $hist = Recommendation::historique();
        self::assertCount(2, $hist);
        self::assertSame(['fp_hist_b', 'fp_hist_a'], array_column($hist, 'id'));
        self::assertArrayHasKey('payload_json', $hist[0]);
        self::assertArrayHasKey('effet_json', $hist[0]);
        self::assertArrayHasKey('effet_conclusion', $hist[0]);
        self::assertArrayHasKey('applied_at', $hist[0]);
        self::assertArrayHasKey('effet_checked_at', $hist[0]);
    }

    public function test_proposees_ne_renvoie_que_les_proposees(): void
    {
        Recommendation::upsert($this->reco('fp_p1', 'Idée A'), $this->fenetre());
        Recommendation::upsert($this->reco('fp_p2', 'Idée B'), $this->fenetre());
        Recommendation::decision('fp_p2', 'plus_tard');

        $proposees = Recommendation::proposees();
        self::assertCount(1, $proposees);
        self::assertSame('fp_p1', $proposees[0]['id']);
        self::assertSame('Idée A', $proposees[0]['titre']);
    }

    /**
     * Crée la table recommendations si absente (idempotent, comme les
     * migrations best-effort du trait).
     */
    private function applyRecommendationsMigration(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS recommendations (
                id VARCHAR(64) NOT NULL PRIMARY KEY,
                regle VARCHAR(4) NOT NULL,
                titre VARCHAR(255) NOT NULL,
                produits_json TEXT NOT NULL,
                payload_json TEXT NOT NULL,
                statut ENUM('proposee','appliquee','plus_tard','ignoree') NOT NULL DEFAULT 'proposee',
                motif VARCHAR(255) NULL,
                baseline_json TEXT NULL,
                effet_json TEXT NULL,
                effet_conclusion VARCHAR(20) NULL,
                fenetre_json TEXT NULL,
                created_at DATETIME NOT NULL,
                decided_at DATETIME NULL,
                applied_at DATETIME NULL,
                effet_checked_at DATETIME NULL,
                INDEX idx_reco_statut (statut),
                INDEX idx_reco_created (created_at),
                INDEX idx_reco_applied (applied_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /**
     * Petit jeu de données d'idée (empreinte + règle + produits).
     */
    private function reco(string $id, string $titre = 'Baisse de marge détectée'): array
    {
        return [
            'id'            => $id,
            'regle'         => 'R1',
            'titre'         => $titre,
            'produits_json' => '[{"key":"coca","label":"Coca-Cola"}]',
            'payload_json'  => '{"regle":"R1","titre":"' . $titre . '"}',
        ];
    }

    /**
     * Fenêtre d'analyse (contexte du moteur).
     */
    private function fenetre(string $from = '2026-09-01'): string
    {
        return '{"from":"' . $from . '","to":"2026-09-30","jours_ouverts":22}';
    }
}
