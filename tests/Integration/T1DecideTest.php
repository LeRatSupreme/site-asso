<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Compta\T1\T1Suggestions;
use App\Models\Model;

/**
 * Boucle P3 « Suggestions de rapprochement » (T1 lot C, spec §6) sur la
 * base de test :
 *
 *  - la page Inventaire rend la carte AU-DESSUS de « Fusionner des clés
 *    produits », ancre #suggestions-rapprochement, avec les formulaires
 *    POST /admin/compta/inventaire/t1/decide (champs cachés + CSRF) ;
 *  - décision « ignorer » : aucune écriture produit, UNE ligne d'audit
 *    t1.pair_feedback (paire négative), la paire disparaît de l'écran ;
 *  - décision « fusion » : fusion réelle (ProductKeyMerge::merge) +
 *    audit inventory.merge à l'identique de mergeKeys + audit
 *    t1.pair_feedback (paire positive) — 2 lignes d'audit écrites ;
 *  - requête invalide : rien n'est écrit (ni fusion, ni audit).
 *
 * Les suggestions affichées viennent des livrables réels
 * data/eval_t1/ (repo) filtrés sur les clés vivantes de la base de
 * TEST : seules les clés « Pepsi » / « Pepsi sans sucre » sont semées,
 * la paire correspondante de candidats_fusion.json est donc la seule
 * suggérée (score recalculé à chaque génération du modèle : le test
 * asserte le MOTIF du badge, pas un score figé).
 *
 * Base `aeic_test` requise (les tests sont sautés sinon).
 */
final class T1DecideTest extends IntegrationTestCase
{
    private string $rootId = 'u_t1decide_root';

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->requireDatabase();

        // Toutes les tables touchées par ProductKeyMerge::merge + les
        // tables lues par la page (même liste que
        // ProductKeyMergeCaseRenameTest) et le journal.
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

        $this->seedUser($this->rootId, 't1decide-root@exemple.fr', 'Password123456', 'SUPERADMIN');

        // Les modèles en process (AuditLog, StockPublic…) parlent à la
        // même base que les requêtes HTTP du runner.
        Model::setTestPdo($this->pdo);
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
        parent::tearDown();
    }

    /**
     * Sème les deux clés de la paire : une vente SumUp sur « Pepsi »
     * (clé des ventes → suggérée comme cible) + stocks de référence
     * des deux côtés (5 + 7 = 12 attendus après fusion).
     */
    private function seedPepsi(): void
    {
        $this->pdo->prepare(
            "INSERT INTO sales (id, transaction_ref, sold_at, payment_method, quantity, description, product_key, price_ttc)
             VALUES ('s_t1_1', 'T1REF1', '2026-10-20 10:00:00', 'CARTE', 1, 'Pepsi', 'Pepsi', 2.00)"
        )->execute();
        $this->pdo->prepare('INSERT INTO product_stocks (product_key, stock) VALUES (?,?)')
            ->execute(['Pepsi', 5]);
        $this->pdo->prepare('INSERT INTO product_stocks (product_key, stock) VALUES (?,?)')
            ->execute(['Pepsi sans sucre', 7]);
    }

    /**
     * Dernier flash posé par la réponse (null si aucun).
     *
     * @param array<string,mixed> $r
     *
     * @return array{type:string,message:string}|null
     */
    private function lastFlash(array $r): ?array
    {
        $flash = $r['session']['_flash'] ?? [];
        $last = is_array($flash) && $flash !== [] ? end($flash) : null;

        return is_array($last) ? $last : null;
    }

    /**
     * Lignes d'audit pour une action (les plus récentes d'abord).
     *
     * @return list<array<string,mixed>>
     */
    private function auditRows(string $action): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM audit_logs WHERE action = ? ORDER BY created_at, id');
        $stmt->execute([$action]);

        /** @var list<array<string,mixed>> $rows */
        return $stmt->fetchAll();
    }

    public function test_la_page_rend_la_carte_au_dessus_de_la_fusion(): void
    {
        $this->seedPepsi();

        $r = $this->request('GET', '/admin/compta/inventaire', [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0), 'La page Inventaire doit répondre.');
        $body = (string) ($r['body'] ?? '');

        // Carte + ancre présentes, AVANT la carte « Fusionner des clés ».
        self::assertStringContainsString('id="suggestions-rapprochement"', $body, 'L\'ancre #suggestions-rapprochement est absente.');
        self::assertStringContainsString('Suggestions de rapprochement', $body, 'La carte « Suggestions de rapprochement » est absente.');
        self::assertLessThan(
            strpos($body, 'id="merge-card"') === false ? PHP_INT_MAX : (int) strpos($body, 'id="merge-card"'),
            (int) strpos($body, 'id="t1-suggestions-card"'),
            'La carte doit apparaître au-dessus de « Fusionner des clés produits ».'
        );

        // La seule paire vivante du jeu : Pepsi / Pepsi sans sucre
        // (score de candidats_fusion.json, recalculé à chaque modèle —
        // 80 % avec l'ancien zéro-shot, 82 % après P2), cible suggérée
        // = la clé des ventes SumUp (« Pepsi »). Assertion sur le motif
        // « ressemblance NN % » : la carte doit afficher le badge, sans
        // figer le score d'un modèle dans le test.
        self::assertMatchesRegularExpression('/ressemblance \d{2} ?%/', $body, 'Le badge de ressemblance est absent ou faux.');
        self::assertStringContainsString('Pepsi sans sucre', $body);
        self::assertStringContainsString('Pepsi', $body);
        self::assertStringNotContainsString('intelligence artificielle', $body, 'Le glossaire §6 interdit « intelligence artificielle » dans les libellés visibles.');

        // Deux formulaires POST (fusion + ignorer), chacun avec CSRF.
        self::assertSame(2, substr_count($body, '/admin/compta/inventaire/t1/decide'), 'La paire doit offrir Fusionner ET Ignorer (2 formulaires).');
        self::assertSame(2, preg_match_all('/<form[^>]*t1\/decide[^>]*>((?:(?!<\/form>).)*)<\/form>/s', $body, $forms), 'Les deux formulaires de décision doivent être trouvés.');
        foreach ($forms[1] as $form) {
            self::assertStringContainsString('name="_csrf"', $form, 'Chaque formulaire de décision doit porter le CSRF.');
        }
        self::assertStringContainsString('name="origine" value="modele"', $form ?? '');

        // Pas de bouton « tout fusionner » sur les suggestions sémantiques.
        $section = substr($body, (int) strpos($body, 'id="t1-suggestions-card"'), (int) strpos($body, 'id="merge-card"') - (int) strpos($body, 'id="t1-suggestions-card"'));
        self::assertStringNotContainsString('Tout fusionner', $section, 'La carte suggestions ne doit PAS offrir de « tout fusionner ».');
    }

    public function test_decide_ignorer_journalise_la_paire_negative(): void
    {
        $this->seedPepsi();

        $r = $this->request('POST', '/admin/compta/inventaire/t1/decide', [
            'source'   => 'Pepsi sans sucre',
            'target'   => 'Pepsi',
            'decision' => 'ignorer',
            'origine'  => 'modele',
            'score'    => '0.7981',
        ], [], $this->rootId);

        self::assertSame(302, (int) ($r['code'] ?? 0), 'La décision doit rediriger (PRG).');
        self::assertStringEndsWith('#suggestions-rapprochement', $this->location($r), 'Le retour doit pointer l\'ancre des suggestions.');
        $flash = $this->lastFlash($r);
        self::assertSame('success', $flash['type'] ?? '');
        self::assertStringContainsString('Paire ignorée', (string) ($flash['message'] ?? ''));

        // UNE seule écriture d'audit, aucune fusion.
        $rows = $this->auditRows(T1Suggestions::ACTION_FEEDBACK);
        self::assertCount(1, $rows, 'La décision doit écrire exactement une ligne t1.pair_feedback.');
        self::assertSame([], $this->auditRows('inventory.merge'), '« Ignorer » ne doit fusionner aucune clé.');
        $row = $rows[0];
        self::assertSame(T1Suggestions::ENTITY_TYPE, (string) $row['entity_type']);
        self::assertSame(
            T1Suggestions::pairEntityId('Pepsi', 'Pepsi sans sucre'),
            (string) $row['entity_id'],
            'entity_id = sha1 de la paire normalisée (symétrique).'
        );
        self::assertSame(
            ['a' => 'Pepsi sans sucre', 'b' => 'Pepsi', 'score' => 0.7981, 'decision' => 'ignorer', 'origine' => 'modele'],
            json_decode((string) $row['details'], true),
            'Le détail JSON doit porter la paire étiquetée (négative ici).'
        );

        // Les données produit sont intactes (la vente unique reste sur « Pepsi »).
        self::assertSame('1', (string) $this->pdo->query("SELECT COUNT(*) FROM sales WHERE product_key = 'Pepsi'")->fetchColumn());

        // Au rechargement, la paire n'est plus suggérée (liste noire).
        $page = $this->request('GET', '/admin/compta/inventaire', [], [], $this->rootId);
        $body = (string) ($page['body'] ?? '');
        self::assertDoesNotMatchRegularExpression('/ressemblance \d{2} ?%/', $body, 'La paire ignorée ne doit plus être suggérée.');
        self::assertStringContainsString('Toutes les suggestions ont été traitées', $body);
    }

    public function test_decide_fusion_fusionne_et_journalise_deux_lignes(): void
    {
        $this->seedPepsi();

        $r = $this->request('POST', '/admin/compta/inventaire/t1/decide', [
            'source'   => 'Pepsi',
            'target'   => 'Pepsi sans sucre',
            'decision' => 'fusion',
            'origine'  => 'residuel',
        ], [], $this->rootId);

        self::assertSame(302, (int) ($r['code'] ?? 0), 'La fusion doit rediriger (PRG).');
        self::assertStringEndsWith('#suggestions-rapprochement', $this->location($r));
        $flash = $this->lastFlash($r);
        self::assertSame('success', $flash['type'] ?? '');
        self::assertStringContainsString('Fusion effectuée', (string) ($flash['message'] ?? ''));
        self::assertStringContainsString('1 vente(s)', (string) ($flash['message'] ?? ''), 'Le récapitulatif doit mentionner la vente déplacée.');

        // Fusion réelle : la vente et le stock suivent la cible (5 + 7 = 12).
        self::assertSame('1', (string) $this->pdo->query("SELECT COUNT(*) FROM sales WHERE product_key = 'Pepsi sans sucre'")->fetchColumn());
        self::assertSame('0', (string) $this->pdo->query("SELECT COUNT(*) FROM sales WHERE product_key = 'Pepsi'")->fetchColumn());
        self::assertSame('12', (string) $this->pdo->query("SELECT stock FROM product_stocks WHERE product_key = 'Pepsi sans sucre'")->fetchColumn());
        self::assertFalse((bool) $this->pdo->query("SELECT 1 FROM product_stocks WHERE product_key = 'Pepsi'")->fetchColumn());

        // Deux lignes d'audit : inventory.merge (parité mergeKeys) puis
        // t1.pair_feedback (paire étiquetée positive).
        $merges = $this->auditRows('inventory.merge');
        self::assertCount(1, $merges, 'La fusion doit être auditée comme inventory.merge.');
        self::assertSame('product_key', (string) $merges[0]['entity_type']);
        self::assertSame('Pepsi sans sucre', (string) $merges[0]['entity_id']);
        $detailsFusion = json_decode((string) $merges[0]['details'], true);
        self::assertSame('Pepsi', $detailsFusion['source']);
        self::assertSame('Pepsi sans sucre', $detailsFusion['target']);
        self::assertSame(1, $detailsFusion['tables']['sales'], 'La vente déplacée est comptée dans le récapitulatif.');
        // T1.5 : toute fusion (manuelle comprise) archive un snapshot
        // pré-fusion par clé dans le details du même audit.
        self::assertArrayHasKey('archive', $detailsFusion);
        self::assertSame(5, $detailsFusion['archive']['source']['stock']);
        self::assertSame(7, $detailsFusion['archive']['target']['stock']);

        $feedbacks = $this->auditRows(T1Suggestions::ACTION_FEEDBACK);
        self::assertCount(1, $feedbacks);
        $fb = $feedbacks[0];
        self::assertSame(
            T1Suggestions::pairEntityId('Pepsi', 'Pepsi sans sucre'),
            (string) $fb['entity_id'],
            'Même identifiant de paire que côté assemblage (liste noire).'
        );
        self::assertSame(
            ['a' => 'Pepsi', 'b' => 'Pepsi sans sucre', 'score' => null, 'decision' => 'fusion', 'origine' => 'residuel'],
            json_decode((string) $fb['details'], true),
            'Paire étiquetée positive, score absent car non posté.'
        );

        // Au rechargement, la paire a disparu (clé source morte).
        $page = $this->request('GET', '/admin/compta/inventaire', [], [], $this->rootId);
        self::assertDoesNotMatchRegularExpression('/ressemblance \d{2} ?%/', (string) ($page['body'] ?? ''));
    }

    public function test_decide_invalide_n_ecrit_rien(): void
    {
        $this->seedPepsi();

        $r = $this->request('POST', '/admin/compta/inventaire/t1/decide', [
            'source'   => 'Pepsi',
            'target'   => 'Pepsi sans sucre',
            'decision' => 'peut-etre',
            'origine'  => 'modele',
            'score'    => '0.7981',
        ], [], $this->rootId);

        self::assertSame(302, (int) ($r['code'] ?? 0));
        $flash = $this->lastFlash($r);
        self::assertSame('error', $flash['type'] ?? '', 'Une décision inconnue doit être refusée.');

        // Aucune écriture : ni audit, ni fusion (ventes intactes).
        self::assertSame([], $this->auditRows(T1Suggestions::ACTION_FEEDBACK));
        self::assertSame([], $this->auditRows('inventory.merge'));
        self::assertSame('1', (string) $this->pdo->query("SELECT COUNT(*) FROM sales WHERE product_key = 'Pepsi'")->fetchColumn());
    }
}
