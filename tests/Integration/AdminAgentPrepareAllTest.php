<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * « Tout préparer » (POST /admin/agent/prepare-all) — validations demandées :
 *
 *  1. BATCH SÉLECTIF : seuls les groupes PRÉPARABLES (verdict
 *     AgentQuestions::preparable() OK + routage présent) sont upsertés ;
 *     un groupe KO (injection suspectée / trop rare / sans routage) est
 *     IGNORÉ, jamais enregistré. Le flash résume préparées/ignorées et
 *     UN SEUL audit `agent.prepare_all` est écrit pour tout le batch.
 *  2. RÉGRESSION « Préparer » unitaire (POST /admin/agent/prepare) :
 *     messages flash historiques EXACTS (« Aucun succès routé au
 *     journal… », « Non préparable : {motif}. », succès inchangé).
 *  3. COHÉRENCE nb_preparables : le « (N) » du bouton « Tout préparer »
 *     affiché par GET /admin/agent est exactement le nombre de questions
 *     que le batch upsertera.
 *
 * Journal CONTRÔLÉ : le contrôleur résout le journal via
 * AEIC_ASSISTANT_JOURNAL (même résolution que FastPath), posé PAR
 * REQUÊTE SIMULÉE (extraEnv, exactement comme AdminAgentFastPathTest)
 * vers un journal de test sous tests/cache/ (nom unique par process :
 * deux exécutions concurrentes de la suite ne se marchent pas dessus).
 * Le VRAI logs/assistant.jsonl n'est PLUS JAMAIS renommé, écrit ni
 * supprimé ici (l'ancien save/restore par rename l'a détruit
 * définitivement le 31/10 sous deux pipelines concurrents) :
 * tearDownAfterClass prouve son intégrité (taille figée en
 * setUpBeforeClass, aucune écriture hors tests/cache/).
 */
final class AdminAgentPrepareAllTest extends IntegrationTestCase
{
    private string $adminId = 'u_agent_prepall';

    // --- Questions du journal contrôlé (verdicts attendus) ------------
    /** Préparable : catégorie quantite, 2 occurrences, succès routé. */
    private const Q_STOCK = 'Combien de Bueno il me reste ?';
    /** Préparable : catégorie restock (prioritaire), 1 seule occurrence. */
    private const Q_RESTOCK = "Qu'est-ce qu'il faut que je rachète ?";
    /** KO : payload d'injection RÉEL du journal → jamais préparable. */
    private const Q_INJECTION = '"; rm -rf / ; $(touch /tmp/pwned_secu)';
    /** KO : succès sans AUCUN appel d'outil en HTTP 200 → sans routage. */
    private const Q_SANS_ROUTAGE = 'Quelle est la météo au pôle nord ?';
    /** KO : 1 occurrence, catégorie marge (non prioritaire) → trop rare. */
    private const Q_RARE = 'Quelle est la marge sur les KitKat ?';

    /** Taille du VRAI journal figée en setUpBeforeClass (null = absent). */
    private static ?int $tailleJournalReel = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Taille du journal réel figée AVANT toute requête de la classe :
        // la preuve d'intégrité de tearDownAfterClass s'y compare.
        $journal = self::cheminJournalReel();
        self::$tailleJournalReel = is_file($journal) ? (int) filesize($journal) : null;
    }

    public static function tearDownAfterClass(): void
    {
        // PREUVE D'INTÉGRITÉ : cette classe ne doit jamais altérer le vrai
        // journal — s'il existait au départ, il doit être intact à la fin
        // (ni tronqué, ni modifié, ni détruit — régression de l'incident
        // du 31/10).
        $journal = self::cheminJournalReel();
        if (self::$tailleJournalReel !== null) {
            static::assertFileExists($journal, 'logs/assistant.jsonl a DISPARU pendant la classe : ce journal de production doit rester intact.');
            static::assertSame(
                self::$tailleJournalReel,
                is_file($journal) ? (int) filesize($journal) : -1,
                'logs/assistant.jsonl a été MODIFIÉ pendant la classe : ce journal de production doit rester intact (lecture seule).'
            );
        }

        @unlink(self::cheminJournalTest()); // journal contrôlé jetable (tests/cache/)
        parent::tearDownAfterClass();
    }

    protected function extraEnv(): array
    {
        // EXACTEMENT le mécanisme d'AdminAgentFastPathTest : l'override est
        // passé PAR REQUÊTE SIMULÉE (le contrôleur tourne dans un
        // sous-processus dédié — sans env par requête, il lirait le vrai
        // journal au lieu du journal contrôlé).
        return ['AEIC_ASSISTANT_JOURNAL' => self::cheminJournalTest()];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireDatabase();
        $this->reset(['users', 'assistant_questions_preparees']);
        $this->seedUser($this->adminId, 'agent-prepall@exemple.fr', 'Password123456', 'SUPERADMIN');

        // Journal contrôlé ÉCRIT sous tests/cache/ uniquement (jamais un
        // rename du vrai journal — voir docblock de la classe).
        @mkdir(dirname(self::cheminJournalTest()), 0777, true);
        file_put_contents(self::cheminJournalTest(), implode("\n", $this->lignesJournalControle()) . "\n");
    }

    // ------------------------------------------------------------------
    //  1. Batch « Tout préparer » : sélectif + résumé + audit unique
    // ------------------------------------------------------------------

    public function test_le_batch_prepare_uniquement_les_groupes_preparables(): void
    {
        // N affiché par la page = ce que le batch préparera réellement.
        $page = $this->request('GET', '/admin/agent', [], [], $this->adminId);
        self::assertSame(200, (int) ($page['code'] ?? 0));
        self::assertSame(
            2,
            $this->nbPreparablesAffiche((string) ($page['body'] ?? '')),
            'Le bouton doit afficher « Tout préparer (2) » : 2 groupes préparables dans le journal contrôlé.'
        );

        $auditAvant = $this->nbAuditsPrepareAll();

        $r = $this->request('POST', '/admin/agent/prepare-all', [], [], $this->adminId);
        self::assertSame(302, (int) ($r['code'] ?? 0), 'Le batch redirige vers la page.');

        // Flash résumé : 2 préparées, 3 ignorées, motifs distincts cités.
        $suite = $this->request('GET', '/admin/agent', [], [], $this->adminId);
        $html = (string) ($suite['body'] ?? '');
        self::assertStringContainsString('2 questions préparées', $html, 'Le flash doit annoncer les 2 préparées.');
        self::assertStringContainsString('3 ignorées', $html, 'Le flash doit annoncer les 3 ignorées.');
        self::assertStringContainsString(e('injection suspectée — jamais préparable'), $html);
        self::assertStringContainsString(e('aucun succès routé au journal'), $html);
        self::assertStringContainsString(e('question trop rare (1×) et catégorie non prioritaire'), $html);

        // Base : EXACTEMENT les 2 groupes préparables, rien d'autre.
        self::assertSame(2, $this->nbPreparees(), 'Seules les questions préparables doivent être upsertées.');
        self::assertSame(1, $this->nbPrepareesAffichee(self::Q_STOCK), 'La question stock préparée doit être en base.');
        self::assertSame(1, $this->nbPrepareesAffichee(self::Q_RESTOCK), 'La question restock préparée doit être en base.');
        self::assertSame(0, $this->nbPrepareesAffichee(self::Q_INJECTION), 'Une question d\'injection ne doit JAMAIS être upsertée.');
        self::assertSame(0, $this->nbPrepareesAffichee(self::Q_SANS_ROUTAGE), 'Un groupe sans routage ne doit pas être upserté.');
        self::assertSame(0, $this->nbPrepareesAffichee(self::Q_RARE), 'Un groupe trop rare ne doit pas être upserté.');

        // Audit : UN SEUL pour tout le batch (pas un par question).
        self::assertSame(
            1,
            $this->nbAuditsPrepareAll() - $auditAvant,
            'Le batch doit écrire exactement UN audit agent.prepare_all.'
        );
    }

    public function test_le_batch_est_reserve_aux_admins(): void
    {
        $auditAvant = $this->nbAuditsPrepareAll();

        $r = $this->request('POST', '/admin/agent/prepare-all'); // sans session
        self::assertNotSame(200, (int) ($r['code'] ?? 0), 'Sans session admin, le batch doit être refusé.');
        self::assertSame(0, $this->nbPreparees(), 'Rien ne doit être enregistré sans session.');
        self::assertSame(0, $this->nbAuditsPrepareAll() - $auditAvant, 'Aucun audit sans session.');
    }

    // ------------------------------------------------------------------
    //  2. Régression « Préparer » unitaire : messages historiques intacts
    // ------------------------------------------------------------------

    public function test_prepare_unitaire_sans_routage_message_historique(): void
    {
        $r = $this->request('POST', '/admin/agent/prepare', ['question' => self::Q_SANS_ROUTAGE], [], $this->adminId);
        self::assertSame(302, (int) ($r['code'] ?? 0));

        $page = $this->request('GET', '/admin/agent', [], [], $this->adminId);
        self::assertStringContainsString(
            e('Aucun succès routé au journal pour cette question (aucun appel d\'outil en HTTP 200) — repose-la d\'abord à l\'assistant.'),
            (string) ($page['body'] ?? ''),
            'Le message flash historique du « Préparer » unitaire doit être conservé à l\'identique.'
        );
        self::assertSame(0, $this->nbPreparees(), 'Aucun upsert en cas d\'échec.');
    }

    public function test_prepare_unitaire_non_preparable_injection_message_historique(): void
    {
        $r = $this->request('POST', '/admin/agent/prepare', ['question' => self::Q_INJECTION], [], $this->adminId);
        self::assertSame(302, (int) ($r['code'] ?? 0));

        $page = $this->request('GET', '/admin/agent', [], [], $this->adminId);
        self::assertStringContainsString(
            e('Non préparable : injection suspectée — jamais préparable.'),
            (string) ($page['body'] ?? ''),
            'Le verdict KO doit produire le message historique « Non préparable : {motif}. ».'
        );
        self::assertSame(0, $this->nbPreparees());
    }

    public function test_prepare_unitaire_trop_rare_message_historique(): void
    {
        $r = $this->request('POST', '/admin/agent/prepare', ['question' => self::Q_RARE], [], $this->adminId);
        self::assertSame(302, (int) ($r['code'] ?? 0));

        $page = $this->request('GET', '/admin/agent', [], [], $this->adminId);
        self::assertStringContainsString(
            e('Non préparable : question trop rare (1×) et catégorie non prioritaire.'),
            (string) ($page['body'] ?? '')
        );
        self::assertSame(0, $this->nbPreparees());
    }

    public function test_prepare_unitaire_succes_upsert_et_message_historique(): void
    {
        $r = $this->request('POST', '/admin/agent/prepare', ['question' => self::Q_STOCK], [], $this->adminId);
        self::assertSame(302, (int) ($r['code'] ?? 0));

        $page = $this->request('GET', '/admin/agent', [], [], $this->adminId);
        self::assertStringContainsString(
            e('Question préparée : réponse instantanée par gabarit pré-écrit rempli sur les données live.'),
            (string) ($page['body'] ?? ''),
            'Le message de succès historique doit être conservé à l\'identique.'
        );
        self::assertSame(1, $this->nbPreparees(), 'Le succès unitaire doit upserter exactement 1 question.');
        self::assertSame(1, $this->nbPrepareesAffichee(self::Q_STOCK));
    }

    // ------------------------------------------------------------------
    //  3. Cohérence nb_preparables ↔ batch (couvert aussi par le test 1)
    // ------------------------------------------------------------------

    public function test_nb_preparables_est_incoherent_si_le_batch_change_d_avis(): void
    {
        // Le bouton affiche N AVANT le batch, le batch prépare N questions,
        // et le bouton affiche ENCORE N après (le journal ne change pas :
        // « préparable » ≠ « préparée ») — aucune divergence possible.
        $avant = $this->nbPreparablesAffiche((string) ($this->request('GET', '/admin/agent', [], [], $this->adminId))['body'] ?? '');
        $this->request('POST', '/admin/agent/prepare-all', [], [], $this->adminId);
        self::assertSame($avant, $this->nbPreparees(), 'Le compteur affiché doit prédire exactement le nombre d\'upserts du batch.');

        $apres = $this->nbPreparablesAffiche((string) ($this->request('GET', '/admin/agent', [], [], $this->adminId))['body'] ?? '');
        self::assertSame($avant, $apres, 'nb_preparables ne dépend pas des préparées (journal inchangé).');
    }

    // ------------------------------------------------------------------
    //  Journal contrôlé (sous tests/cache/ — le vrai journal reste intouché)
    // ------------------------------------------------------------------

    /** Journal de test : sous tests/cache/, unique par process (parallèle-safe). */
    private static function cheminJournalTest(): string
    {
        return dirname(__DIR__) . '/cache/assistant_prepall_' . (string) getmypid() . '.jsonl';
    }

    /** VRAI journal de production — LECTURE de taille uniquement (preuve d'intégrité). */
    private static function cheminJournalReel(): string
    {
        return dirname(__DIR__, 2) . '/logs/assistant.jsonl';
    }

    /** @return list<string> lignes JSONL au format réel du pont (logs/assistant.jsonl) */
    private function lignesJournalControle(): array
    {
        $lignes = [];
        // Groupe préparable n°1 : 2 occurrences de la même question.
        $lignes[] = self::entree(self::Q_STOCK, 'stock_produit', ['nom' => 'Bueno'], 200,
            '{"outil":"stock_produit","nom_demande":"Bueno","trouve":true,"cle_canonique":"Bueno","stock":24,"dernier_comptage":"2026-10-13 17:21:35"}',
            'Il vous reste 24 unités de Bueno.');
        $lignes[] = self::entree(self::Q_STOCK, 'stock_produit', ['nom' => 'Bueno'], 200,
            '{"outil":"stock_produit","nom_demande":"Bueno","trouve":true,"cle_canonique":"Bueno","stock":24,"dernier_comptage":"2026-10-13 17:21:35"}',
            'Il vous reste 24 unités de Bueno.');
        // Groupe préparable n°2 : 1 occurrence mais catégorie restock prioritaire.
        $lignes[] = self::entree(self::Q_RESTOCK, 'produits_a_commander', [], 200,
            '{"outil":"produits_a_commander","nb_alertes":8}',
            'Il faut racheter des Monster.');
        // KO injection : succès routé mais payload d'injection réel.
        $lignes[] = self::entree(self::Q_INJECTION, 'stock_produit', ['nom' => 'Evian'], 200,
            '{"outil":"stock_produit","nom_demande":"Evian","trouve":true,"cle_canonique":"Evian","stock":10}',
            'Il reste 10 Evian.');
        // KO sans routage : appel d'outil en échec HTTP (jamais 200).
        $lignes[] = self::entree(self::Q_SANS_ROUTAGE, 'stock_produit', ['nom' => 'Evian'], 500, '', '');
        // KO trop rare : 1 occurrence, catégorie marge non prioritaire.
        $lignes[] = self::entree(self::Q_RARE, 'marge_produit', ['produit' => 'KitKat'], 200,
            '{"outil":"marge_produit","produit":"KitKat","marge":0.42}',
            'La marge sur les KitKat est de 42 %.');

        return $lignes;
    }

    /**
     * @param array<string,string> $arguments
     */
    private static function entree(string $question, string $outil, array $arguments, int $statutHttp, string $resultat, string $reponse): string
    {
        return (string) json_encode([
            'horodatage'  => '2026-10-28T10:00:00+01:00',
            'question'    => $question,
            'ok'          => $statutHttp === 200,
            'duree_ms'    => 1500,
            'appels_outils' => [[
                'outil'       => $outil,
                'arguments'   => $arguments,
                'statut_http' => $statutHttp,
                'ms'          => 300,
                'resultat'    => $resultat,
            ]],
            'reponse' => $reponse,
            'erreur'  => $statutHttp === 200 ? '' : 'outil en échec',
        ], JSON_UNESCAPED_UNICODE);
    }

    // ------------------------------------------------------------------
    //  Helpers d'assertion
    // ------------------------------------------------------------------

    private function nbPreparees(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM assistant_questions_preparees')->fetchColumn();
    }

    private function nbPrepareesAffichee(string $question): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM assistant_questions_preparees WHERE question_affichee = ?');
        $stmt->execute([$question]);

        return (int) $stmt->fetchColumn();
    }

    private function nbAuditsPrepareAll(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'agent.prepare_all'")->fetchColumn();
    }

    /** Extrait le N du bouton « Tout préparer (N) » rendu par la page. */
    private function nbPreparablesAffiche(string $html): int
    {
        if (preg_match('/Tout préparer \((\d+)\)/u', $html, $m) !== 1) {
            self::fail('Bouton « Tout préparer (N) » introuvable dans la page (N>0 attendu).');
        }

        return (int) $m[1];
    }
}
