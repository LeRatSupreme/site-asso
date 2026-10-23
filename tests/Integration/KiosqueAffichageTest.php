<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Lot 5 — présentation unifiée Réappro / Liste de courses, version
 * bénévole SANS chiffre financier (docs/ui-audit.md §8) :
 *
 *  - l'encart « Base de calcul » (ventes analysées · couverture visée ·
 *    source du stock) est présent à l'identique sur les 4 rendus
 *    (admin liste, admin réappro, kiosque liste, kiosque réappro) ;
 *  - jeton kiosque « membres » (= bénévole) : AUCUN chiffre financier sur
 *    la liste et le réappro kiosque (ni « € », ni « Coût ») ; produit,
 *    stock restant, à commander et packs restent visibles ;
 *  - jeton kiosque « admin » (bureau sur téléphone) : tout est visible ;
 *  - le bouton « copier le lien kiosque » reste en place côté admin
 *    (régression interdite par la mission).
 *
 * Jetons forgés directement en settings (même mécanique que les tests
 * kiosque existants : ComptaConsistencyTest, AnalyticsBuildPageTest).
 *
 * Base `aeic_test` requise (les tests sont sautés sinon).
 */
final class KiosqueAffichageTest extends IntegrationTestCase
{
    private string $rootId       = 'u_kiosq_aff_root';
    private string $tokenMembres = 'jetonmembres-affichage';
    private string $tokenAdmin   = 'jetonadmin-affichage';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->reset([
            'sales',
            'inventory_counts',
            'product_costs',
            'product_discontinued',
            'product_infinite',
            'product_packs',
            'users',
        ]);
        $this->seedUser($this->rootId, 'kiosq-aff-root@exemple.fr', 'Password123456', 'SUPERADMIN');

        // Jetons kiosque « membres » et « admin » posés en settings.
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (id, `key`, value) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );
        $stmt->execute(['set_kiosq_aff_m', 'reappro_kiosk_token', $this->tokenMembres]);
        $stmt->execute(['set_kiosq_aff_a', 'admin_kiosk_token', $this->tokenAdmin]);

        // Un produit commandable avec un coût connu : la liste a une tuile
        // et le panier compta affiche un montant en euros.
        for ($i = 1; $i <= 3; $i++) {
            $this->pdo->prepare(
                'INSERT INTO sales (id, transaction_ref, sold_at, payment_method, payment_raw, quantity, description, price_ttc, is_custom_amount, category)
                 VALUES (?,?,?,?,?,1,?,?,0,?)'
            )->execute([
                's_kiosq_aff_' . $i,
                'TKAFF000' . $i,
                date('Y-m-d ') . sprintf('09:0%d:00', $i),
                'CARTE',
                'Visa - Débit',
                'colakiosqaff',
                '1.50',
                'Boissons',
            ]);
        }
        $this->pdo->prepare(
            'INSERT INTO inventory_counts (id, counted_at, product_key, counted_qty, theoretical_qty, gap, created_by)
             VALUES (?,?,?,?,?,?,?)'
        )->execute(['inv_kiosq_aff', '2026-01-01 08:00:00', 'colakiosqaff', 0, 0, 0, 'test']);
        $this->pdo->prepare(
            'INSERT INTO product_costs (id, product_key, cost_price, valid_from) VALUES (?,?,?,?)'
        )->execute(['pc_kiosq_aff', 'colakiosqaff', '1.500', '2026-01-01']);
        $this->pdo->prepare(
            'INSERT INTO product_packs (product_key, pack_size, updated_by) VALUES (?,?,?)'
        )->execute(['colakiosqaff', 12, 'test']);
    }

    public function test_encart_base_de_calcul_sur_les_quatre_rendus(): void
    {
        $fragments = [
            'Base de calcul',
            'Ventes analysées',
            'Couverture visée',
            'stock théorique (comptages, achats, ventes, pertes)',
        ];

        $rendus = [
            'admin liste'     => ['/admin/compta/liste', $this->rootId],
            'admin réappro'   => ['/admin/compta/reappro', $this->rootId],
            'kiosque liste'   => ['/kiosque/liste/' . $this->tokenMembres, ''],
            'kiosque réappro' => ['/kiosque/reappro/' . $this->tokenMembres, ''],
        ];

        foreach ($rendus as $nom => [$path, $userId]) {
            $r    = $this->request('GET', $path, [], [], $userId);
            $body = (string) ($r['body'] ?? '');

            self::assertSame(200, (int) ($r['code'] ?? 0), sprintf('%s : la page doit répondre.', $nom));
            foreach ($fragments as $fragment) {
                self::assertStringContainsString(
                    $fragment,
                    $body,
                    sprintf('%s : l\'encart « Base de calcul » doit être complet.', $nom)
                );
            }
        }
    }

    public function test_kiosque_jeton_membres_sans_chiffre_financier(): void
    {
        // Liste kiosque : tuile produit visible, aucun montant.
        $rl    = $this->request('GET', '/kiosque/liste/' . $this->tokenMembres);
        $bodyL = (string) ($rl['body'] ?? '');
        self::assertSame(200, (int) ($rl['code'] ?? 0));
        self::assertStringContainsString('data-key="colakiosqaff"', $bodyL, 'La tuile du produit reste visible pour un membre.');
        foreach (['€', 'Coût'] as $interdit) {
            self::assertStringNotContainsString($interdit, $bodyL, sprintf('Liste kiosque membres : « %s » ne doit pas apparaître.', $interdit));
        }

        // Réappro kiosque (lecture seule) : produit, stock restant,
        // à commander et packs visibles ; aucun montant.
        $rr    = $this->request('GET', '/kiosque/reappro/' . $this->tokenMembres);
        $bodyR = (string) ($rr['body'] ?? '');
        self::assertSame(200, (int) ($rr['code'] ?? 0));
        self::assertStringContainsString('data-name="colakiosqaff"', $bodyR, 'La ligne du produit reste visible pour un membre.');
        self::assertStringContainsString('Stock restant', $bodyR, 'La colonne « stock restant » reste visible (vocabulaire unifié).');
        self::assertStringContainsString('À commander', $bodyR, 'La colonne « à commander » reste visible.');
        self::assertStringContainsString('Pack', $bodyR, 'La colonne « packs » reste visible.');
        foreach (['€', 'Coût'] as $interdit) {
            self::assertStringNotContainsString($interdit, $bodyR, sprintf('Réappro kiosque membres : « %s » ne doit pas apparaître.', $interdit));
        }
    }

    public function test_kiosque_jeton_admin_voit_les_couts(): void
    {
        $rl    = $this->request('GET', '/kiosque/liste/' . $this->tokenAdmin);
        $bodyL = (string) ($rl['body'] ?? '');
        self::assertSame(200, (int) ($rl['code'] ?? 0));
        self::assertStringContainsString('€', $bodyL, 'Liste kiosque admin : le coût estimé du panier est visible.');

        $rr    = $this->request('GET', '/kiosque/reappro/' . $this->tokenAdmin);
        $bodyR = (string) ($rr['body'] ?? '');
        self::assertSame(200, (int) ($rr['code'] ?? 0));
        self::assertStringContainsString('€', $bodyR, 'Réappro kiosque admin : les coûts sont visibles.');
        self::assertStringContainsString('Coût ligne', $bodyR, 'Réappro kiosque admin : la colonne « Coût ligne » est visible.');
        self::assertStringContainsString('Coût estimé du panier', $bodyR, 'Réappro kiosque admin : le KPI panier est visible.');
    }

    /**
     * Lot suivi (docs/refonte-limites.md §16) : les encarts « accès
     * téléphone » des pages Réappro et Liste sont désormais MASQUÉS —
     * le jeton ne figure plus dans le HTML initial, la révélation passe
     * par le POST CSRF audité (partial _kiosk_link.php). La copie et la
     * régénération restent proposées.
     */
    public function test_bouton_copier_lien_kiosque_conserve(): void
    {
        $rr    = $this->request('GET', '/admin/compta/reappro', [], [], $this->rootId);
        $bodyR = (string) ($rr['body'] ?? '');
        self::assertSame(200, (int) ($rr['code'] ?? 0));
        self::assertStringContainsString('Copier', $bodyR, 'Le bouton « Copier » du Réappro doit rester.');
        self::assertStringContainsString('Afficher le lien', $bodyR, 'La révélation à la demande remplace le lien en clair.');
        self::assertStringContainsString('data-kl-path="/kiosque/reappro/"', $bodyR, 'L\'encart porte le chemin kiosque (pas le jeton).');
        self::assertStringNotContainsString($this->tokenMembres, $bodyR, 'Le jeton membres ne doit plus apparaître dans le HTML du Réappro.');
        self::assertStringContainsString('/admin/compta/reappro/kiosk/regenerate', $bodyR, 'La régénération du jeton reste proposée.');

        $rl    = $this->request('GET', '/admin/compta/liste', [], [], $this->rootId);
        $bodyL = (string) ($rl['body'] ?? '');
        self::assertSame(200, (int) ($rl['code'] ?? 0));
        self::assertStringContainsString('Copier', $bodyL, 'Le bouton « Copier » de la liste doit rester.');
        self::assertStringContainsString('data-kl-path="/kiosque/liste/"', $bodyL, 'L\'encart liste porte le chemin kiosque (pas le jeton).');
        self::assertStringNotContainsString($this->tokenMembres, $bodyL, 'Le jeton membres ne doit plus apparaître dans le HTML de la liste.');
    }
}
