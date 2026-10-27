<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Compta\T1\T1AutoFusion;
use App\Core\Compta\T1\T1MergeArchive;
use App\Core\Compta\T1\T1Quarantaine;
use App\Core\Compta\T1\T1Suggestions;
use App\Models\Model;

/**
 * Autonomie graduée des fusions (T1.5) sur la base de test :
 *
 *  - niveau 1 (casse pure) : le CHARGEMENT de la page Inventaire
 *    fusionne immédiatement les doublons de casse vivants + DEUX audits
 *    (inventory.merge origine=auto avec archive pré-fusion, puis
 *    t1.pair_feedback origine=auto) ;
 *  - niveau 2 (quarantaine 48 h) : paire sûre déclarée en attente,
 *    AUCUNE fusion avant maturité ; veto humain → jamais fusionnée +
 *    paire négative journalisée ;
 *  - maturité simulée (horloge injectable) : 47 h 59 → rien, 48 h 01 →
 *    fusion réelle + audits ;
 *  - garde-fou coûts (dev-couts) : coûts unitaires divergents > 15 % →
 *    jamais déclarée en quarantaine ;
 *  - restauration : la clé source est recréée depuis l'archive
 *    (fiche/alias/stock), l'historique des ventes reste sur la cible ;
 *  - quarantaine de TEST (tests/cache/t15/, bootstrap) : la vraie
 *    data/eval_t1/t15/quarantaine.json n'est jamais touchée.
 *
 * Base `aeic_test` requise (les tests sont sautés sinon).
 */
final class T1AutoFusionIntegrationTest extends IntegrationTestCase
{
    private string $rootId = 'u_t15auto_root';

    /** Quarantaine de test (redirigée par tests/bootstrap.php). */
    private string $qChemin;

    /** Candidats dédiés au niveau 2 (le livrable réel ne contient pas de paire ≥ seuil). */
    private string $cChemin;

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

        $this->seedUser($this->rootId, 't15auto-root@exemple.fr', 'Password123456', 'SUPERADMIN');
        Model::setTestPdo($this->pdo);

        // Quarantaine vierge pour chaque test (le chemin lui-même vient
        // du bootstrap — JAMAIS la vraie quarantaine du dépôt).
        $this->qChemin = T1AutoFusion::cheminQuarantaine();
        @unlink($this->qChemin);
        @unlink($this->qChemin . '.lock');

        // Candidats dédiés : une seule paire niveau 2 (Fanta → Fanta
        // Orange, préfixe sans chiffre, score au-dessus du seuil mesuré).
        $this->cChemin = sys_get_temp_dir() . '/aeic_t15_candidats_' . uniqid('', true) . '.json';
        file_put_contents($this->cChemin, (string) json_encode([
            'candidats' => [['a' => 'Fanta', 'b' => 'Fanta Orange', 'score' => 0.9228]],
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->qChemin);
        @unlink($this->qChemin . '.lock');
        @unlink($this->cChemin);
        Model::setTestPdo(null);
        parent::tearDown();
    }

    /**
     * Sème une paire niveau 2 : une vente SumUp sur la base + stocks de
     * référence des deux côtés (formes DISTINCTES : la collation ci de
     * product_stocks interdit deux lignes ne différant que par la casse).
     */
    private function seedPaire(string $base, string $variante, int $stockBase, int $stockVariante): void
    {
        $this->pdo->prepare(
            "INSERT INTO sales (id, transaction_ref, sold_at, payment_method, quantity, description, product_key, price_ttc)
             VALUES ('s_t15_1', 'T15REF1', '2026-10-20 10:00:00', 'CARTE', 1, ?, ?, 2.00)"
        )->execute([$base, $base]);
        $ins = $this->pdo->prepare('INSERT INTO product_stocks (product_key, stock) VALUES (?,?)');
        $ins->execute([$base, $stockBase]);
        $ins->execute([$variante, $stockVariante]);
    }

    /**
     * Sème un doublon de CASSE PURE (niveau 1), cas réel : le stock de
     * référence vit sous « Pepsi » (saisi en admin) et les ventes SumUp
     * sous « pepsi » — les GROUP BY ci de keyStats ne peuvent fusionner
     * les casses qu'entre TABLES, c'est ce qui rend la paire vivante.
     * L'orientation conserve la clé des VENTES (« pepsi »).
     */
    private function seedCasse(string $stockCasse, string $ventesCasse, int $stock): void
    {
        $this->pdo->prepare('INSERT INTO product_stocks (product_key, stock) VALUES (?,?)')
            ->execute([$stockCasse, $stock]);
        $sale = $this->pdo->prepare(
            "INSERT INTO sales (id, transaction_ref, sold_at, payment_method, quantity, description, product_key, price_ttc)
             VALUES (?, ?, '2026-10-20 10:00:00', 'CARTE', 1, ?, ?, 2.00)"
        );
        // Références DISTINCTES : l'index unique uniq_sale_line est en
        // collation ci, « Pepsi » et « pepsi » y sont le même libellé.
        $sale->execute(['s_t15_c1', 'T15C1', $ventesCasse, $ventesCasse]);
        $sale->execute(['s_t15_c2', 'T15C2', $ventesCasse, $ventesCasse]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function auditRows(string $action): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM audit_logs WHERE action = ? ORDER BY created_at, id');
        $stmt->execute([$action]);

        /** @var list<array<string,mixed>> $rows */
        return $stmt->fetchAll();
    }

    // ── Niveau 1 : casse pure, fusion immédiate au chargement ───────────

    public function test_niveau_1_fusionne_au_chargement_avec_deux_audits(): void
    {
        // Doublon de casse réel : stock sous « Pepsi », ventes SumUp sous
        // « pepsi » — la clé des ventes est conservée par la fusion.
        $this->seedCasse('Pepsi', 'pepsi', 5);

        $r = $this->request('GET', '/admin/compta/inventaire', [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0), 'La page Inventaire doit répondre.');

        // Fusion réelle : le stock suit la clé des ventes (« pepsi », 5,
        // une seule ligne — jamais doublée), toutes les ventes dessus.
        self::assertSame('2', (string) $this->pdo->query("SELECT COUNT(*) FROM sales WHERE product_key = 'pepsi'")->fetchColumn());
        self::assertSame('5', (string) $this->pdo->query("SELECT stock FROM product_stocks WHERE product_key = 'pepsi'")->fetchColumn());
        self::assertSame('0', (string) $this->pdo->query("SELECT COUNT(*) FROM product_stocks WHERE product_key = 'Pepsi' COLLATE utf8mb4_bin")->fetchColumn(), 'La casse admin a été absorbée.');

        // DEUX audits : inventory.merge (origine auto, niveau 1, motif
        // casse, ARCHIVE pré-fusion) puis t1.pair_feedback (positive).
        $merges = $this->auditRows('inventory.merge');
        self::assertCount(1, $merges, 'La fusion auto niveau 1 doit être auditée inventory.merge.');
        $details = json_decode((string) $merges[0]['details'], true);
        self::assertSame('Pepsi', $details['source'], 'La clé sans vente (stock admin) est absorbée.');
        self::assertSame('pepsi', $details['target'], 'La clé des ventes SumUp est conservée.');
        self::assertSame('auto', $details['origine']);
        self::assertSame(1, $details['niveau']);
        self::assertSame('casse', $details['motif']);
        self::assertArrayHasKey('archive', $details, 'Toute fusion (auto comprise) archive un snapshot pré-fusion.');
        self::assertSame(5, $details['archive']['source']['stock'], 'Le snapshot source conserve le stock AVANT fusion.');
        self::assertNull($details['archive']['target']['stock'], 'La clé des ventes n\'a pas de ligne de stock propre (snapshot honnête).');

        $feedbacks = $this->auditRows(T1Suggestions::ACTION_FEEDBACK);
        self::assertCount(1, $feedbacks, 'La fusion auto écrit aussi une paire positive pour la boucle P3.');
        $fb = json_decode((string) $feedbacks[0]['details'], true);
        self::assertSame('fusion', $fb['decision']);
        self::assertSame('auto', $fb['origine']);

        // La carte « Fusions automatiques » rend la fusion appliquée
        // (section appliquées) avec son bouton de restauration.
        $body = (string) ($r['body'] ?? '');
        self::assertStringContainsString('id="fusions-auto"', $body);
        self::assertStringContainsString('Fusions automatiques appliquées', $body);
        self::assertStringContainsString('Restaurer la clé', $body);
    }

    public function test_niveau_1_plafonne_a_20_fusions_par_passage(): void
    {
        // 25 doublons de casse purs (ventes sous la casse basse, stock
        // sous la casse d'origine) : un seul passage n'en fusionne que 20
        // (plafond de sécurité), le suivant terminera.
        $ins = $this->pdo->prepare('INSERT INTO product_stocks (product_key, stock) VALUES (?,1)');
        $sale = $this->pdo->prepare(
            "INSERT INTO sales (id, transaction_ref, sold_at, payment_method, quantity, description, product_key, price_ttc)
             VALUES (?, 'T15P', '2026-10-20 10:00:00', 'CARTE', 1, ?, ?, 2.00)"
        );
        for ($i = 0; $i < 25; $i++) {
            $cle = 'Prod' . $i;
            $ins->execute([$cle]);
            $sale->execute(['s_t15_p' . $i, strtolower($cle), strtolower($cle)]);
        }

        $this->request('GET', '/admin/compta/inventaire', [], [], $this->rootId);
        self::assertCount(
            20,
            $this->auditRows('inventory.merge'),
            'Le plafond de sécurité limite un passage à 20 fusions automatiques.'
        );

        $this->request('GET', '/admin/compta/inventaire', [], [], $this->rootId);
        self::assertCount(
            25,
            $this->auditRows('inventory.merge'),
            'Le passage suivant termine le reste (clés toujours vivantes).'
        );
    }

    // ── Niveau 2 : quarantaine 48 h, veto, maturité ─────────────────────

    public function test_niveau_2_quarantaine_puis_veto_ne_fusionne_jamais(): void
    {
        $this->seedPaire('Fanta', 'Fanta Orange', 3, 4);

        // Passage direct (candidats dédiés) : la paire est DÉCLARÉE en
        // quarantaine, PAS fusionnée — aucune écriture produit.
        $keyStats = \App\Models\ProductKeyMerge::keyStats();
        $res = T1AutoFusion::passage($keyStats, $this->qChemin, $this->cChemin);
        self::assertSame(1, $res['declarees']);
        self::assertSame(0, $res['appliquees']);
        self::assertSame(0, $res['niveau1']);

        $q = new T1Quarantaine($this->qChemin);
        $entrees = $q->entrees();
        self::assertCount(1, $entrees);
        self::assertSame(T1Quarantaine::STATUT_EN_ATTENTE, $entrees[0]['statut']);
        self::assertSame('Fanta Orange', $entrees[0]['source'], 'Orientation : la clé des ventes (Fanta) est la cible.');
        self::assertSame('Fanta', $entrees[0]['target']);

        self::assertSame('1', (string) $this->pdo->query("SELECT COUNT(*) FROM sales WHERE product_key = 'Fanta'")->fetchColumn());
        self::assertSame([], $this->auditRows('inventory.merge'));

        // Veto humain par la route (CSRF/permission comme t1Decide).
        $r = $this->request('POST', '/admin/compta/inventaire/t1/auto/veto', [
            'id' => $entrees[0]['id'],
        ], [], $this->rootId);
        self::assertSame(302, (int) ($r['code'] ?? 0));
        self::assertSame(T1Quarantaine::STATUT_ANNULE, (new T1Quarantaine($this->qChemin))->trouver($entrees[0]['id'])['statut']);

        // Paire négative journalisée (boucle P3, origine auto).
        $feedbacks = $this->auditRows(T1Suggestions::ACTION_FEEDBACK);
        self::assertCount(1, $feedbacks);
        $fb = json_decode((string) $feedbacks[0]['details'], true);
        self::assertSame('ignorer', $fb['decision']);
        self::assertSame('auto', $fb['origine']);
        self::assertSame('veto_quarantaine', $fb['motif']);

        // La maturité ne contourne JAMAIS un veto : ventes intactes.
        $mure = (new \DateTimeImmutable($entrees[0]['created_at']))->modify('+49 hours')->format('Y-m-d\TH:i:sP');
        T1AutoFusion::passage(\App\Models\ProductKeyMerge::keyStats(), $this->qChemin, $this->cChemin, $mure);
        self::assertSame('1', (string) $this->pdo->query("SELECT COUNT(*) FROM sales WHERE product_key = 'Fanta'")->fetchColumn());
        self::assertSame([], $this->auditRows('inventory.merge'), 'Un veto ne fusionne jamais, même à maturité.');
    }

    public function test_niveau_2_maturite_47h59_rien_48h01_fusion(): void
    {
        $this->seedPaire('Fanta', 'Fanta Orange', 3, 4);

        $cree = T1AutoFusion::maintenantIso();
        T1AutoFusion::passage(\App\Models\ProductKeyMerge::keyStats(), $this->qChemin, $this->cChemin, $cree);

        // 47 h 59 plus tard (durée écoulée, Paris) : RIEN.
        $t4759 = (new \DateTimeImmutable($cree))->modify('-1 second')->modify('+48 hours')->format('Y-m-d\TH:i:sP');
        $res = T1AutoFusion::passage(\App\Models\ProductKeyMerge::keyStats(), $this->qChemin, $this->cChemin, $t4759);
        self::assertSame(0, $res['appliquees']);
        self::assertSame('1', (string) $this->pdo->query("SELECT COUNT(*) FROM sales WHERE product_key = 'Fanta'")->fetchColumn());

        // 48 h 01 plus tard : fusion réelle + audits + quarantaine appliquée.
        $t4801 = (new \DateTimeImmutable($cree))->modify('+48 hours +1 minute')->format('Y-m-d\TH:i:sP');
        $res = T1AutoFusion::passage(\App\Models\ProductKeyMerge::keyStats(), $this->qChemin, $this->cChemin, $t4801);
        self::assertSame(1, $res['appliquees']);

        // Orientation : la clé des ventes (Fanta) est la cible — la vente
        // et le stock additionné (3 + 4 = 7) sont sur « Fanta ».
        self::assertSame('1', (string) $this->pdo->query("SELECT COUNT(*) FROM sales WHERE product_key = 'Fanta'")->fetchColumn());
        self::assertSame('7', (string) $this->pdo->query("SELECT stock FROM product_stocks WHERE product_key = 'Fanta'")->fetchColumn());

        $merges = $this->auditRows('inventory.merge');
        self::assertCount(1, $merges);
        $details = json_decode((string) $merges[0]['details'], true);
        self::assertSame('auto', $details['origine']);
        self::assertSame(2, $details['niveau'], 'Fusion de quarantaine mûre : niveau 2.');

        $q = new T1Quarantaine($this->qChemin);
        $entree = $q->entrees()[0];
        self::assertSame(T1Quarantaine::STATUT_APPLIQUE, $entree['statut']);
        self::assertSame($merges[0]['id'], (string) $entree['audit_id'], 'La quarantaine pointe l\'audit archivé (restauration).');

        // L'écran des fusions auto propose la restauration de cette paire.
        $page = $this->request('GET', '/admin/compta/inventaire', [], [], $this->rootId);
        $body = (string) ($page['body'] ?? '');
        self::assertStringContainsString('Fanta Orange', $body);
        self::assertStringContainsString('Restaurer la clé', $body);
    }

    public function test_restauration_recree_la_cle_source_apres_fusion_mure(): void
    {
        // Prépare la même fusion mûre que le test de maturité.
        $this->seedPaire('Fanta', 'Fanta Orange', 3, 4);
        $cree = T1AutoFusion::maintenantIso();
        T1AutoFusion::passage(\App\Models\ProductKeyMerge::keyStats(), $this->qChemin, $this->cChemin, $cree);
        $t4801 = (new \DateTimeImmutable($cree))->modify('+48 hours +1 minute')->format('Y-m-d\TH:i:sP');
        T1AutoFusion::passage(\App\Models\ProductKeyMerge::keyStats(), $this->qChemin, $this->cChemin, $t4801);

        $merges = $this->auditRows('inventory.merge');
        self::assertCount(1, $merges);
        $auditId = (string) $merges[0]['id'];

        // L'historique repointé reste sur la cible (limite documentée) :
        // la cible de la fusion est « Fanta » (clé des ventes).
        self::assertSame('1', (string) $this->pdo->query("SELECT COUNT(*) FROM sales WHERE product_key = 'Fanta'")->fetchColumn());

        $r = $this->request('POST', '/admin/compta/inventaire/t1/auto/restaurer', [
            'audit_id' => $auditId,
        ], [], $this->rootId);
        self::assertSame(302, (int) ($r['code'] ?? 0));

        // La clé source (« Fanta Orange ») est recréée : stock du snapshot
        // (4), et la cible rend ce qu'elle avait absorbé (7 → 3) — total
        // physique préservé.
        self::assertSame('4', (string) $this->pdo->query("SELECT stock FROM product_stocks WHERE product_key = 'Fanta Orange'")->fetchColumn());
        self::assertSame('3', (string) $this->pdo->query("SELECT stock FROM product_stocks WHERE product_key = 'Fanta'")->fetchColumn());
        // Les ventes RESTENT sur la cible (jamais re-scindées).
        self::assertSame('1', (string) $this->pdo->query("SELECT COUNT(*) FROM sales WHERE product_key = 'Fanta'")->fetchColumn());
        self::assertSame('0', (string) $this->pdo->query("SELECT COUNT(*) FROM sales WHERE product_key = 'Fanta Orange'")->fetchColumn());

        // Audit dédié.
        $restaures = $this->auditRows(T1MergeArchive::ACTION_RESTORE);
        self::assertCount(1, $restaures);
        self::assertSame('Fanta Orange', (string) $restaures[0]['entity_id'], 'La clé restaurée est la source de la fusion.');
    }

    public function test_garde_fou_couts_divergents_ne_declare_rien(): void
    {
        $this->seedPaire('Fanta', 'Fanta Orange', 3, 4);
        // Deux lots en cours divergents (0,50 vs 1,00 : 100 % d'écart).
        $lot = $this->pdo->prepare(
            "INSERT INTO product_costs (id, product_key, cost_price, valid_from, created_at)
             VALUES (?, ?, ?, '2026-10-01', NOW())"
        );
        $lot->execute(['cost_t15_a', 'Fanta', 0.50]);
        $lot->execute(['cost_t15_b', 'Fanta Orange', 1.00]);

        $res = T1AutoFusion::passage(\App\Models\ProductKeyMerge::keyStats(), $this->qChemin, $this->cChemin);
        self::assertSame(0, $res['declarees'], 'Coûts divergents > 15 % : la paire reste niveau 3 (humain).');
        self::assertSame([], (new T1Quarantaine($this->qChemin))->entrees());
        self::assertSame('1', (string) $this->pdo->query("SELECT COUNT(*) FROM sales WHERE product_key = 'Fanta'")->fetchColumn());
    }

    /**
     * Miroir du script de preuve dev-secu (/tmp/t15_secu_preuve_couts.php,
     * revue T15-B constat 1c) : la garde coûts est RE-vérifiée au MOMENT
     * de l'application d'une quarantaine mûre. Paire déclarée SANS coûts,
     * lots divergents (100 % d'écart) saisis PENDANT les 48 h, maturité
     * 48 h 01 simulée → JAMAIS de fusion automatique (règle dev-couts
     * « zéro perte ») : la paire quitte « en_attente » par expiration et
     * aucune écriture produit n'a lieu — le doute profite à l'humain.
     */
    public function test_garde_fou_couts_apparus_pendant_quarantaine_expire_sans_fusion(): void
    {
        // 1. Paire niveau 2 déclarée SANS coûts connus (rien à opposer).
        $this->seedPaire('Fanta', 'Fanta Orange', 3, 4);
        $cree = T1AutoFusion::maintenantIso();
        $r1 = T1AutoFusion::passage(\App\Models\ProductKeyMerge::keyStats(), $this->qChemin, $this->cChemin, $cree);
        self::assertSame(1, $r1['declarees'], 'Sans coûts connus à la déclaration, la paire entre en quarantaine.');

        // 2. PENDANT la quarantaine : lots de coûts divergents saisis
        //    (0,50 vs 1,00 = 100 % d'écart, loin au-delà des 15 %).
        $lot = $this->pdo->prepare(
            "INSERT INTO product_costs (id, product_key, cost_price, valid_from, created_at)
             VALUES (?, ?, ?, '2026-10-01', NOW())"
        );
        $lot->execute(['cost_t15_c', 'Fanta', 0.50]);
        $lot->execute(['cost_t15_d', 'Fanta Orange', 1.00]);

        // 3. Maturité 48 h 01 simulée : AUCUNE fusion malgré la maturité.
        $t4801 = (new \DateTimeImmutable($cree))->modify('+48 hours +1 minute')->format('Y-m-d\TH:i:sP');
        $r2 = T1AutoFusion::passage(\App\Models\ProductKeyMerge::keyStats(), $this->qChemin, $this->cChemin, $t4801);
        self::assertSame(0, $r2['appliquees'], 'Coûts divergents apparus pendant la quarantaine : jamais de fusion automatique.');
        self::assertSame(1, $r2['expirees'], 'La paire quitte « en_attente » par expiration (rétrogradation humaine).');

        // 4. Aucune écriture produit : stocks et ventes intacts, zéro audit.
        self::assertSame([], $this->auditRows('inventory.merge'));
        self::assertSame('1', (string) $this->pdo->query("SELECT COUNT(*) FROM sales WHERE product_key = 'Fanta'")->fetchColumn());
        self::assertSame('4', (string) $this->pdo->query("SELECT stock FROM product_stocks WHERE product_key = 'Fanta Orange'")->fetchColumn());
        self::assertSame('3', (string) $this->pdo->query("SELECT stock FROM product_stocks WHERE product_key = 'Fanta'")->fetchColumn());

        // 5. Entrée sortie de en_attente : statut expire + raison tracée.
        $entree = (new T1Quarantaine($this->qChemin))->entrees()[0];
        self::assertSame(T1Quarantaine::STATUT_EXPIRE, $entree['statut']);
        self::assertStringContainsString('couts divergents', (string) $entree['expire_raison']);
    }

    public function test_bouton_appliquer_maintenant_n_avance_pas_les_immatures(): void
    {
        $this->seedPaire('Fanta', 'Fanta Orange', 3, 4);
        T1AutoFusion::passage(\App\Models\ProductKeyMerge::keyStats(), $this->qChemin, $this->cChemin);

        $r = $this->request('POST', '/admin/compta/inventaire/t1/auto/appliquer', [], [], $this->rootId);
        self::assertSame(302, (int) ($r['code'] ?? 0));

        // Quarantaine de 48 h NON révolue : le bouton manuel ne force rien.
        self::assertSame('1', (string) $this->pdo->query("SELECT COUNT(*) FROM sales WHERE product_key = 'Fanta'")->fetchColumn());
        self::assertSame([], $this->auditRows('inventory.merge'));
    }
}
