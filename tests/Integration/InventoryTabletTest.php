<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\InventoryCount;
use App\Models\Model;

/**
 * Inventaire tablette SumUp : cycle de vie complet d'une session.
 *
 * Scénarios :
 *   1. La page « Opérations · Inventaire » répond et expose l'onglet
 *      « Nouvelle saisie ».
 *   2. L'enregistrement d'une session calcule les écarts côté serveur
 *      (théorique = comptage) et renvoie vers le détail.
 *   3. La correction crée une perte (manquant, motif VOL) et aligne le
 *      stock de référence sur le surplus — irrévocable.
 *   4. La suppression d'une session corrigée est refusée
 *      (traçabilité à vie).
 *
 * Base `aeic_test` requise (les tests sont sautés sinon).
 */
final class InventoryTabletTest extends IntegrationTestCase
{
    private string $rootId = 'u_tab_root';

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->requireDatabase();
        $this->reset([
            'inventory_tablet_lines',
            'inventory_tablet_sessions',
            'losses',
            'product_stocks',
            'inventory_counts',
            'product_costs',
            'purchases',
            'sales',
            'users',
        ]);
        $this->seedUser($this->rootId, 'tab-root@exemple.fr', 'Password123456', 'SUPERADMIN');

        // Théoriques connus : « eau » compté 150 (un relevé tablette à 100
        // fait donc un MANQUANT de 50) et « redbull » compté 0 (relevé à 10
        // = SURPLUS de 10, alignera le stock de référence à la hausse).
        $this->insertCount('inv_tab_eau', '2026-01-01 10:00:00', 'eau', 150);
        $this->insertCount('inv_tab_rb', '2026-01-01 10:05:00', 'redbull', 0);

        // Les assertions sur les modèles (theoreticalStock) tournent dans le
        // processus de test, pas dans le sous-processus du runner : on
        // injecte la connexion à aeic_test.
        Model::setTestPdo($pdo);
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
        parent::tearDown();
    }

    public function test_page_inventaire_tablette_s_affiche(): void
    {
        $r = $this->request('GET', '/admin/compta/inventaire-tablette', [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0), 'La page Inventaire tablette doit répondre.');
        $body = (string) ($r['body'] ?? '');
        self::assertStringContainsString('Inventaire', $body, 'L\'onglet de niveau 1 « Inventaire » est absent.');
        self::assertStringContainsString('Nouvelle saisie', $body, 'L\'onglet interne « Nouvelle saisie » est absent.');
    }

    public function test_enregistrement_session_calcule_les_ecarts(): void
    {
        $r = $this->request('POST', '/admin/compta/inventaire-tablette/save', [
            'counted_at' => date('Y-m-d'),
            'note'       => 'test vague 2',
            'keys'       => ['eau', 'redbull'],
            'qtys'       => ['100', '10'],
            'avis'       => ['', ''],
        ], [], $this->rootId);

        self::assertSame(302, (int) ($r['code'] ?? 0), 'La sauvegarde doit rediriger (PRG).');
        self::assertStringContainsString(
            '/admin/compta/inventaire-tablette/',
            $this->location($r),
            'La redirection doit mener au détail de la session créée.'
        );

        // Session + 2 lignes en base.
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM inventory_tablet_sessions')->fetchColumn());
        $sessionId = (string) $this->pdo->query('SELECT id FROM inventory_tablet_sessions LIMIT 1')->fetchColumn();
        self::assertNotSame('', $sessionId, 'La session doit être enregistrée.');

        $rows = $this->pdo->query(
            "SELECT product_key, tablet_qty, site_theoretical, gap, unit_cost, loss_value
             FROM inventory_tablet_lines WHERE session_id = '$sessionId'"
        )->fetchAll();

        self::assertCount(2, $rows, 'La session doit porter 2 lignes.');
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[(string) $row['product_key']] = $row;
        }

        // Écart calculé côté serveur : tablette − théorique (eau 100−150 = −50,
        // redbull 10−0 = +10) ; sans coût de revient saisi, aucune valorisation.
        self::assertSame(-50, (int) $byKey['eau']['gap'], 'Écart « eau » erroné.');
        self::assertSame(10, (int) $byKey['redbull']['gap'], 'Écart « redbull » erroné.');
        self::assertSame(150, (int) $byKey['eau']['site_theoretical'], 'Théorique « eau » erroné.');
        self::assertNull($byKey['eau']['loss_value'], 'Perte « eau » : coût manquant, rien à valoriser.');
        self::assertNull($byKey['redbull']['loss_value'], 'Un surplus n\'est jamais valorisé en perte.');
    }

    public function test_correction_cree_les_pertes_et_aligne_le_surplus(): void
    {
        $this->request('POST', '/admin/compta/inventaire-tablette/save', [
            'counted_at' => date('Y-m-d'),
            'note'       => 'avant correction',
            'keys'       => ['eau', 'redbull'],
            'qtys'       => ['100', '10'],
            'avis'       => ['', ''],
        ], [], $this->rootId);

        $sessionId = (string) $this->pdo->query('SELECT id FROM inventory_tablet_sessions LIMIT 1')->fetchColumn();

        // Motifs dans l'ordre du POST = ordre lines() (écart croissant :
        // eau −50 d'abord, redbull +10 ensuite) ; défaut VOL.
        $r = $this->request('POST', '/admin/compta/inventaire-tablette/' . rawurlencode($sessionId) . '/correct', [
            'reasons' => ['VOL', 'VOL'],
            'avis'    => 'relevé contrôlé',
        ], [], $this->rootId);

        $flash = $r['session']['_flash'] ?? [];
        $last  = is_array($flash) && $flash !== [] ? end($flash) : null;
        self::assertSame('success', $last['type'] ?? '', 'La correction doit produire un flash de succès.');

        // Lignes marquées corrigées (irrévocable).
        self::assertSame(
            2,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM inventory_tablet_lines WHERE session_id = '$sessionId' AND corrected = 1"
            )->fetchColumn(),
            'Les deux lignes à écart non nul doivent être marquées corrigées.'
        );

        // Manquant : une perte (motif VOL) de quantité = |gap|.
        self::assertSame(
            1,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM losses WHERE product_key = 'eau' AND quantity = 50 AND reason = 'VOL'"
            )->fetchColumn(),
            'Le manquant « eau » (50 u.) doit créer une perte VOL.'
        );
        self::assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM losses WHERE product_key = 'redbull'")->fetchColumn(),
            'Un surplus ne crée jamais de perte.'
        );

        // Surplus : le stock de référence est aligné sur la tablette ; le
        // manquant est sorti du stock par la perte (0 = 0 − 50 borné).
        self::assertSame(
            10,
            (int) $this->pdo->query("SELECT stock FROM product_stocks WHERE product_key = 'redbull'")->fetchColumn(),
            'Le surplus doit aligner le stock de référence sur la quantité tablette.'
        );
        self::assertSame(
            0,
            (int) $this->pdo->query("SELECT COALESCE(stock, -1) FROM product_stocks WHERE product_key = 'eau'")->fetchColumn(),
            'La perte du manquant doit sortir la quantité du stock de référence (borné à 0).'
        );

        // Surplus : le théorique est rebasé — un comptage horodaté à la
        // seconde de la session (created_at = borne ventes) est inséré
        // pour la clé, le stock théorique repart de la quantité tablette.
        $sess = $this->pdo->query(
            "SELECT created_at, counted_at FROM inventory_tablet_sessions WHERE id = '$sessionId'"
        )->fetch();
        self::assertNotFalse($sess, 'La session corrigée doit rester en base.');

        $count = $this->pdo->query(
            "SELECT counted_qty, theoretical_qty, gap, note, created_by
             FROM inventory_counts
             WHERE product_key = 'redbull' AND counted_at = '" . (string) $sess['created_at'] . "'"
        )->fetch();
        self::assertNotFalse($count, 'Le surplus doit insérer un comptage qui rebase le théorique.');
        self::assertSame(10, (int) $count['counted_qty'], 'Le comptage du surplus porte la quantité tablette.');
        self::assertSame(0, (int) $count['theoretical_qty'], 'Le comptage conserve le théorique d\'origine du site.');
        self::assertSame(10, (int) $count['gap'], 'L\'écart du comptage = tablette − théorique d\'origine.');
        self::assertSame(
            'Releve tablette du ' . (string) $sess['counted_at'],
            (string) $count['note'],
            'La note du comptage référence le relevé.'
        );
        self::assertSame($this->rootId, (string) $count['created_by'], 'L\'auteur du comptage est l\'utilisateur correcteur.');
        self::assertSame(
            10,
            InventoryCount::theoreticalStock('redbull'),
            'Le stock théorique du surplus repart de la quantité tablette (aucun mouvement ensuite).'
        );

        // Manquant : AUCUN comptage créé (la perte porte le théorique),
        // seule la ligne de seed doit exister pour la clé.
        self::assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM inventory_counts WHERE product_key = 'eau'")->fetchColumn(),
            'Un manquant ne crée pas de comptage : la perte fait tomber le théorique.'
        );
        self::assertSame(
            100,
            InventoryCount::theoreticalStock('eau'),
            'Le théorique du manquant est porté par la perte (150 − 50).'
        );
    }

    public function test_suppression_refusee_apres_correction(): void
    {
        $this->request('POST', '/admin/compta/inventaire-tablette/save', [
            'counted_at' => date('Y-m-d'),
            'note'       => 'à préserver',
            'keys'       => ['eau'],
            'qtys'       => ['100'],
            'avis'       => [''],
        ], [], $this->rootId);

        $sessionId = (string) $this->pdo->query('SELECT id FROM inventory_tablet_sessions LIMIT 1')->fetchColumn();

        $this->request('POST', '/admin/compta/inventaire-tablette/' . rawurlencode($sessionId) . '/correct', [
            'reasons' => ['VOL'],
        ], [], $this->rootId);

        $r = $this->request('POST', '/admin/compta/inventaire-tablette/' . rawurlencode($sessionId) . '/delete', [], [], $this->rootId);

        $flash = $r['session']['_flash'] ?? [];
        $last  = is_array($flash) && $flash !== [] ? end($flash) : null;
        self::assertSame('error', $last['type'] ?? '', 'La suppression d\'une session corrigée doit être refusée.');
        self::assertStringContainsString('traçabilité', (string) ($last['message'] ?? ''), 'Le refus doit invoquer la traçabilité.');

        // La session (et ses lignes) reste en base : trace à vie.
        self::assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM inventory_tablet_sessions WHERE id = '$sessionId'")->fetchColumn(),
            'La session corrigée doit être conservée.'
        );
        self::assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM inventory_tablet_lines WHERE session_id = '$sessionId'")->fetchColumn(),
            'Les lignes de la session corrigée doivent être conservées.'
        );
    }

    // -----------------------------------------------------------------
    //  Helpers
    // -----------------------------------------------------------------

    /**
     * Insère un comptage inventaire (théorique = compté, aucun mouvement).
     */
    private function insertCount(string $id, string $countedAt, string $productKey, int $qty): void
    {
        $this->pdo->prepare(
            'INSERT INTO inventory_counts (id, counted_at, product_key, counted_qty, theoretical_qty, gap, created_by)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$id, $countedAt, $productKey, $qty, $qty, 0, 'test']);
    }
}
