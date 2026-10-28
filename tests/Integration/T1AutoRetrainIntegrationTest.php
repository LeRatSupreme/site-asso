<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Compta\T1\T1AutoRetrain;
use App\Models\Model;

/**
 * Boucle de ré-entraînement automatique (P2-auto, brief A1) sur la base
 * de test :
 *
 *  - les décisions « t1.pair_feedback » (fusions ET ignorés, origines
 *    modele/residuel/auto) postérieures au dernier entraînement font
 *    MONTER le compteur de l'indicateur (ligne sobre sur la carte
 *    « Suggestions de rapprochement ») ;
 *  - à 30 paires distinctes (dédoublonnage par entity_id : la décision
 *    la plus récente gagne ; les paires antérieures au dernier run ne
 *    comptent pas), le chargement de la page Inventaire lance le script
 *    du pipeline EN ARRIÈRE-PLAN (statut « entrainement_en_cours »
 *    persisté sous verrou AVANT le lancement) ;
 *  - un chargement immédiat ne relance RIEN (plafond 1 run, jamais de
 *    double lancement) ;
 *  - après un échec, l'anti-tempête retarde le nouvel essai (15 min).
 *
 * Le script invoqué est un FAUX pipeline (AEIC_P2AUTO_SCRIPT, variable
 * honorée uniquement en APP_TESTING) qui trace son passage : le VRAI
 * scripts/t1_auto_retrain.sh n'est jamais exécuté depuis un test. État,
 * journal et log sont redirigés vers tests/cache/p2auto/ (bootstrap +
 * runner) : data/eval_t1/p2/auto/ (prod) et le jeu figé restent INTACTS.
 *
 * Base `aeic_test` requise (les tests sont sautés sinon).
 */
final class T1AutoRetrainIntegrationTest extends IntegrationTestCase
{
    private string $rootId = 'u_p2auto_root';

    /** Chemins redirigés (tests/cache/p2auto/). */
    private string $etatChemin;
    private string $journalChemin;
    private string $scriptChemin;
    private string $marqueurChemin;

    /** Dernier entraînement simulé : il y a 7 jours (UTC). */
    private string $dernierRunIso;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->requireDatabase();

        $this->reset([
            'users', 'audit_logs',
            'sales', 'import_batches', 'purchases', 'losses', 'product_aliases',
            'product_costs', 'inventory_counts', 'product_stocks', 'promotions',
            'inventory_tablet_lines', 'product_discontinued', 'product_infinite',
            'product_zero_since', 'product_packs', 'shopping_checks',
            'cafeteria_order_items', 'cafeteria_orders', 'products',
        ]);
        try {
            $pdo->exec('TRUNCATE TABLE scan_labels');
        } catch (\Throwable) {
            // Table absente du schéma de test : purge tolérée par merge.
        }

        $this->seedUser($this->rootId, 'p2auto-root@exemple.fr', 'Password123456', 'SUPERADMIN');
        Model::setTestPdo($this->pdo);

        // État/journal/log/script de test (jamais data/eval_t1/p2/auto/).
        $this->etatChemin = T1AutoRetrain::cheminEtat();
        $this->journalChemin = T1AutoRetrain::cheminJournal();
        $this->scriptChemin = dirname($this->etatChemin) . '/faux_retrain.sh';
        $this->marqueurChemin = dirname($this->etatChemin) . '/marqueur_runs.txt';
        $this->purgerFichiers();

        $this->dernierRunIso = gmdate('Y-m-d\TH:i:s+00:00', time() - 7 * 86400);
        $this->ecrireEtatInitial('pret', null);

        // Faux pipeline : trace SON passage puis simule une durée — la
        // preuve du plafond 1 run se joue pendant qu'il « tourne ».
        file_put_contents($this->scriptChemin, sprintf(
            "#!/usr/bin/env bash\nprintf 'run %%s\\n' \"\$(date -u +%%Y-%%m-%%dT%%H:%%M:%%SZ)\" >> %s\nsleep 2\n",
            escapeshellarg($this->marqueurChemin)
        ));
    }

    protected function tearDown(): void
    {
        $this->purgerFichiers();
        Model::setTestPdo(null);
        parent::tearDown();
    }

    /** Fichiers de la boucle (test) supprimés avant/après chaque essai. */
    private function purgerFichiers(): void
    {
        foreach ([$this->etatChemin, $this->etatChemin . '.lock', $this->journalChemin,
                  $this->scriptChemin, $this->marqueurChemin,
                  dirname($this->etatChemin) . '/retrain.log'] as $f) {
            @unlink($f);
        }
    }

    /** État initial (dernier entraînement il y a 7 jours). */
    private function ecrireEtatInitial(string $statut, ?string $dernierLancementIso): void
    {
        file_put_contents($this->etatChemin, (string) json_encode([
            'version'            => 1,
            'dernier_run'        => $this->dernierRunIso,
            'modele_version'     => 'modèle de test',
            'paires_cumulees'    => 0,
            'seuil'              => 30,
            'statut'             => $statut,
            'dernier_lancement'  => $dernierLancementIso,
            'historique'         => [],
        ], JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string,mixed> */
    private function lireEtat(): array
    {
        $data = json_decode((string) @file_get_contents($this->etatChemin), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Sème une décision « t1.pair_feedback » (created_at UTC explicite —
     * la table audit_logs est en DEFAULT CURRENT_TIMESTAMP, on impose la
     * notre pour piloter le filtre « depuis le dernier entraînement »).
     */
    private function seedFeedback(
        string $entityId,
        string $a,
        string $b,
        string $decision,
        string $origine,
        string $creeUtc,
        ?float $score = null
    ): void {
        $this->pdo->prepare(
            'INSERT INTO audit_logs (id, user_id, action, entity_type, entity_id, details, ip_address, created_at)
             VALUES (?, NULL, ?, ?, ?, ?, NULL, ?)'
        )->execute([
            'aud_p2a_' . bin2hex(random_bytes(5)),
            't1.pair_feedback',
            'product_pair',
            $entityId,
            (string) json_encode([
                'a'        => $a,
                'b'        => $b,
                'score'    => $score,
                'decision' => $decision,
                'origine'  => $origine,
            ], JSON_UNESCAPED_UNICODE),
            $creeUtc,
        ]);
    }

    /** Nombre de lancements tracés par le faux pipeline (0 si absent). */
    private function nbRuns(): int
    {
        $contenu = (string) @file_get_contents($this->marqueurChemin);

        return substr_count($contenu, 'run ');
    }

    /**
     * Attend (borné) que le faux pipeline ait tracé $attendu lancement(s).
     */
    private function attendreRuns(int $attendu): void
    {
        $fin = microtime(true) + 6.0;
        while (microtime(true) < $fin) {
            if ($this->nbRuns() >= $attendu) {
                return;
            }
            usleep(150_000);
        }
    }

    protected function extraEnv(): array
    {
        // Le vrai pipeline n'est JAMAIS exécuté depuis un test : seul le
        // faux script est éligible au lancement (APP_TESTING).
        return ['AEIC_P2AUTO_SCRIPT' => $this->scriptChemin];
    }

    public function test_compteur_monte_puis_declenchement_arriere_plan_sans_double_lancement(): void
    {
        $now = gmdate('Y-m-d H:i:s');

        // ── 12 paires nouvelles (10 fusions dont 1 auto, 2 ignorés) + 1
        //    paire ANTÉRIEURE au dernier entraînement (ne compte pas) ──
        $this->seedFeedback('p2a_001', 'Fanta', 'Fanta Orange', 'fusion', 'modele', gmdate('Y-m-d H:i:s', time() - 120), 0.9228);
        for ($i = 2; $i <= 10; $i++) {
            $this->seedFeedback('p2a_' . sprintf('%03d', $i), "Produit $i", "Produit $i variante", 'fusion', 'modele', $now, 0.85);
        }
        $this->seedFeedback('p2a_011', 'Redbull Bleu', 'Redbull Blanche', 'ignorer', 'residuel', $now);
        $this->seedFeedback('p2a_012', 'Pepsi', 'pepsi', 'fusion', 'auto', $now);
        $this->seedFeedback('p2a_vieux', 'Ancien', 'Ancien chose', 'fusion', 'modele', gmdate('Y-m-d H:i:s', time() - 30 * 86400));

        $r = $this->request('GET', '/admin/compta/inventaire', [], [], $this->rootId);

        self::assertSame(200, $r['code']);
        self::assertStringContainsString(
            'Ré-entraînement automatique : 12/30 paires validées — prochain entraînement à 30.',
            (string) $r['body'],
            'Indicateur sobre sur la carte « Suggestions de rapprochement ».'
        );

        $etat = $this->lireEtat();
        self::assertSame(12, $etat['paires_cumulees'], 'Compteur = 12 paires distinctes nouvelles.');
        self::assertSame('pret', $etat['statut']);
        self::assertSame(0, $this->nbRuns(), 'Sous le seuil : aucun lancement.');

        // ── 18 paires de plus (total distinct 30) + 1 DOUBLON de p2a_001
        //    (décision plus récente « ignorer » : dédoublonnage, la paire
        //    ne compte qu'une fois et sa décision finale change) ──
        for ($i = 13; $i <= 30; $i++) {
            $this->seedFeedback('p2a_' . sprintf('%03d', $i), "Produit $i", "Produit $i variante", 'fusion', 'modele', $now, 0.83);
        }
        $this->seedFeedback('p2a_001', 'Fanta', 'Fanta Orange', 'ignorer', 'modele', $now);

        $r = $this->request('GET', '/admin/compta/inventaire', [], [], $this->rootId);

        self::assertSame(200, $r['code']);
        self::assertStringContainsString('Ré-entraînement automatique : entraînement en cours.', (string) $r['body']);

        $etat = $this->lireEtat();
        self::assertSame(30, $etat['paires_cumulees'], '30 paires DISTINCTES (le doublon ne compte pas deux fois).');
        self::assertSame('entrainement_en_cours', $etat['statut'], 'Statut verrouillé AVANT le lancement.');
        $entree = $etat['historique'][0] ?? [];
        self::assertSame('lancement', $entree['evenement'] ?? null);
        self::assertSame(28, $entree['positives'] ?? null, '11+18 fusions moins p2a_001 devenue « ignorer » (décision la plus récente).');
        self::assertSame(2, $entree['negatives'] ?? null, '1 ignoré initial + le doublon récent de p2a_001.');

        $this->attendreRuns(1);
        self::assertSame(1, $this->nbRuns(), 'Le pipeline est invoqué UNE fois, en arrière-plan.');
        self::assertStringContainsString(
            'lancement du pipeline en arrière-plan',
            (string) @file_get_contents($this->journalChemin),
            'Journal data/eval_t1/p2/auto/journal.md (redirigé test) trace le lancement.'
        );

        // ── Rechargement immédiat pendant que le « run » tourne : RIEN ──
        $r = $this->request('GET', '/admin/compta/inventaire', [], [], $this->rootId);
        self::assertSame(200, $r['code']);
        self::assertSame('entrainement_en_cours', $this->lireEtat()['statut']);
        self::assertSame(1, $this->nbRuns(), 'Pas de double lancement (plafond 1 run simultané).');
        self::assertStringContainsString('entraînement en cours.', (string) $r['body']);
    }

    public function test_anti_tempete_apres_echec(): void
    {
        $now = gmdate('Y-m-d H:i:s');

        // 30 paires validées MAIS statut « echec » avec un essai il y a
        // 5 minutes : l'anti-tempête retarde le nouvel essai.
        for ($i = 1; $i <= 30; $i++) {
            $this->seedFeedback('p2a_' . sprintf('%03d', $i), "Produit $i", "Produit $i variante", 'fusion', 'modele', $now);
        }
        $this->ecrireEtatInitial('echec', gmdate('Y-m-d\TH:i:s+00:00', time() - 5 * 60));

        $r = $this->request('GET', '/admin/compta/inventaire', [], [], $this->rootId);
        self::assertSame(200, $r['code']);
        self::assertSame(0, $this->nbRuns(), 'Échec trop récent : pas de nouvel essai.');
        $etat = $this->lireEtat();
        self::assertSame('echec', $etat['statut']);
        self::assertStringContainsString('dernier entraînement en échec', (string) $r['body']);

        // 30 minutes après le dernier essai : le nouvel essai part.
        $this->ecrireEtatInitial('echec', gmdate('Y-m-d\TH:i:s+00:00', time() - 30 * 60));
        $r = $this->request('GET', '/admin/compta/inventaire', [], [], $this->rootId);
        self::assertSame(200, $r['code']);
        $this->attendreRuns(1);
        self::assertSame(1, $this->nbRuns(), 'Anti-tempête expiré : le pipeline repart.');
        self::assertSame('entrainement_en_cours', $this->lireEtat()['statut']);
    }

    /** État « en cours » avec des paires comptées, pour marquer(). */
    private function ecrireEtatEnCours(int $paires): void
    {
        file_put_contents($this->etatChemin, (string) json_encode([
            'version'            => 1,
            'dernier_run'        => $this->dernierRunIso,
            'modele_version'     => 'modèle de test',
            'paires_cumulees'    => $paires,
            'seuil'              => 30,
            'statut'             => 'entrainement_en_cours',
            'dernier_lancement'  => gmdate('Y-m-d\TH:i:s+00:00', time() - 60),
            'historique'         => [],
        ], JSON_UNESCAPED_UNICODE));
    }

    public function test_marquer_refuse_les_statuts_hors_contrat(): void
    {
        $this->ecrireEtatEnCours(30);

        self::assertFalse(T1AutoRetrain::marquer('pret'), 'Seuls echec/remplace/consserve sont marquables (API du script A2).');
        self::assertFalse(T1AutoRetrain::marquer('entrainement_en_cours'));
        self::assertFalse(T1AutoRetrain::marquer('nimportequoi'));
        self::assertSame('entrainement_en_cours', $this->lireEtat()['statut'], 'Rien n a changé.');
    }

    public function test_marquer_echec_garde_les_paires_pour_un_nouvel_essai(): void
    {
        // 30 paires validées, run lancé il y a 1 min, Kaggle injoignable :
        // le script A2 marque l échec — les paires RESTENT comptées
        // (dernier_run inchangé) et la boucle réessaie après l anti-tempête.
        $this->ecrireEtatEnCours(30);

        self::assertTrue(T1AutoRetrain::marquer('echec', null, null, 'kaggle datasets version en échec (2 tentatives)'));

        $etat = $this->lireEtat();
        self::assertSame('echec', $etat['statut']);
        self::assertSame($this->dernierRunIso, $etat['dernier_run'], 'Échec : la borne de consommation n avance PAS.');
        self::assertSame(30, $etat['paires_cumulees'], 'Les 30 paires restent comptées pour le nouvel essai.');
        $entree = $etat['historique'][count($etat['historique']) - 1] ?? [];
        self::assertSame('echec', $entree['evenement'] ?? null);
        self::assertSame('kaggle datasets version en échec (2 tentatives)', $entree['detail'] ?? null);

        $journal = (string) @file_get_contents($this->journalChemin);
        self::assertStringContainsString('run en échec', $journal);
        self::assertStringContainsString('les paires restent comptées, nouvel essai automatique', $journal);
    }

    public function test_marquer_consserve_puis_remplace_consomment_les_paires(): void
    {
        // CONSERVÉ (dry-run ou verdict non meilleur) : les paires exportées
        // sont consommées jusqu à la borne d export, le compteur repart à 0.
        $this->ecrireEtatEnCours(30);
        $borne = '2026-11-20T10:42:00+00:00';

        self::assertTrue(T1AutoRetrain::marquer('consserve', $borne, null, 'dry-run validé'));

        $etat = $this->lireEtat();
        self::assertSame('consserve', $etat['statut']);
        self::assertSame($borne, $etat['dernier_run'], 'La borne d export devient le nouveau dernier_run.');
        self::assertSame(0, $etat['paires_cumulees'], 'Compteur consommé (recompté au passage suivant).');
        self::assertStringContainsString('modèle CONSERVÉ', (string) @file_get_contents($this->journalChemin));

        // REMPLACÉ (verdict MEILLEUR) : idem + la version du modèle notée.
        self::assertTrue(T1AutoRetrain::marquer('remplace', '2026-11-20T11:00:00+00:00', 'modele-auto-v3'));

        $etat = $this->lireEtat();
        self::assertSame('remplace', $etat['statut']);
        self::assertSame('2026-11-20T11:00:00+00:00', $etat['dernier_run']);
        self::assertSame('modele-auto-v3', $etat['modele_version']);
        self::assertStringContainsString('modèle REMPLACÉ', (string) @file_get_contents($this->journalChemin));
    }
}
