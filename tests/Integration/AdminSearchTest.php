<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Recherche globale (/admin/search?q=) :
 *   - groupe « Produits » pour tout le bureau (catalogue, libellés des
 *     ventes, alias de mapping) ;
 *   - groupe « Ventes » visible SEULEMENT pour les rôles compta ;
 *   - groupe « Membres » visible SEULEMENT pour les lecteurs de
 *     /admin/users (Fondateur/Système ou page « users » attribuée) ;
 *   - état vide « Aucun résultat pour "…" ».
 *
 * Requêtes LIKE paramétrées, échappement e() systématique : le corps de
 * page ne doit jamais renvoyer le SQL ni la saisie brute non échappée.
 *
 * Base `aeic_test` requise (les tests sont sautés sinon).
 */
final class AdminSearchTest extends IntegrationTestCase
{
    private string $rootId = 'u_search_root';
    private string $commId = 'u_search_comm';
    private string $tresorId = 'u_search_tresor';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->reset([
            'product_categories',
            'products',
            'product_aliases',
            'sales',
            'import_batches',
            'users',
        ]);
        $this->seedUser($this->rootId, 'search-root@exemple.fr', 'Password123456', 'SUPERADMIN');
        $this->seedUser($this->commId, 'search-comm@exemple.fr', 'Password123456', 'COMMUNICATION');
        $this->seedUser($this->tresorId, 'search-tresor@exemple.fr', 'Password123456', 'TRESORERIE');
        $this->seedUser('u_search_coco', 'coco@exemple.fr', 'Password123456', 'ELEVE');

        // Catalogue cafétéria.
        $this->pdo->prepare(
            'INSERT INTO products (id, name, price) VALUES (?,?,?)'
        )->execute(['prod_coca', 'Coca cerise 33cl', '1.50']);

        // Ventes importées (mêmes colonnes que le harnais DashboardPageTest).
        $this->insertSale('s_search_1', 'TSEAR1', date('Y-m-d H:i:s', strtotime('-2 days')), '1.50', 'CARTE', 'coca cherry 33cl');
        $this->insertSale('s_search_2', 'TSEAR2', date('Y-m-d H:i:s', strtotime('-1 day')), '2.00', 'LIQUIDE', 'jus orange');

        // Alias de mapping (libellé SumUp -> produit canonique).
        $this->pdo->prepare(
            "INSERT INTO product_aliases (id, raw_description, product_key, kind, created_at)
             VALUES ('alias_search_1', 'coca_cherry', 'coca cerise', 'label', NOW())"
        )->execute();
    }

    private function insertSale(
        string $id,
        string $ref,
        string $soldAt,
        string $priceTtc,
        string $payment = 'CARTE',
        ?string $description = null
    ): void {
        $this->pdo->prepare(
            'INSERT INTO sales (id, transaction_ref, sold_at, payment_method, payment_raw, quantity, description, price_ttc, is_custom_amount, category, imported_at)
             VALUES (?,?,?,?,?,1,?,?,0,?,?)'
        )->execute([$id, $ref, $soldAt, $payment, $payment === 'CARTE' ? 'Visa - Débit' : 'Cash', $description ?? $ref, $priceTtc, null, date('Y-m-d H:i:s')]);
    }

    public function test_produit_trouve_pour_tout_le_bureau(): void
    {
        $r = $this->request('GET', '/admin/search?q=coca', [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0));
        $body = (string) ($r['body'] ?? '');

        self::assertStringContainsString('<h2 class="card-title">Produits</h2>', $body, 'Le groupe « Produits » est absent.');
        // Catalogue, libellé des ventes et alias remontent.
        self::assertStringContainsString('Coca cerise 33cl', $body, 'Le produit du catalogue est absent.');
        self::assertStringContainsString('coca cherry 33cl', $body, 'Le libellé des ventes est absent.');
        self::assertStringContainsString('coca_cherry', $body, 'L\'alias de mapping est absent.');

        // Lien vers la page compta des produits (rôle compta).
        self::assertStringContainsString('/admin/compta/produits?q=', $body, 'Le lien vers la page produits est absent.');
    }

    public function test_ventes_visibles_seulement_pour_compta(): void
    {
        // SUPERADMIN (compta) : le groupe Ventes apparaît avec date et montant.
        $r = $this->request('GET', '/admin/search?q=coca', [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0));
        $body = (string) ($r['body'] ?? '');
        self::assertStringContainsString('<h2 class="card-title">Ventes</h2>', $body, 'Le groupe « Ventes » devrait apparaître pour un rôle compta.');
        self::assertStringContainsString('1,50 €', $body, 'Le montant de la vente est absent.');

        // COMMUNICATION (pas compta) : ni ventes, ni montants.
        $r2 = $this->request('GET', '/admin/search?q=coca', [], [], $this->commId);
        self::assertSame(200, (int) ($r2['code'] ?? 0));
        $body2 = (string) ($r2['body'] ?? '');
        self::assertStringNotContainsString('<h2 class="card-title">Ventes</h2>', $body2, 'Le groupe « Ventes » ne doit pas apparaître hors compta.');
        self::assertSame(0, substr_count($body2, '€'), 'Aucun montant ne doit fuiter hors rôle compta.');
        // Le groupe Produits reste disponible.
        self::assertStringContainsString('coca cherry 33cl', $body2, 'Le groupe « Produits » doit rester visible pour tout le bureau.');
    }

    public function test_membres_visibles_seulement_pour_le_systeme(): void
    {
        // Fondateur : groupe Membres (il voit déjà /admin/users).
        $r = $this->request('GET', '/admin/search?q=coco', [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0));
        $body = (string) ($r['body'] ?? '');
        self::assertStringContainsString('<h2 class="card-title">Membres</h2>', $body, 'Le groupe « Membres » devrait apparaître pour le Fondateur.');
        self::assertStringContainsString('coco@exemple.fr', $body, 'Le membre trouvé est absent.');

        // Trésorerie (compta mais pas Système) : pas de groupe Membres.
        $r2 = $this->request('GET', '/admin/search?q=coco', [], [], $this->tresorId);
        self::assertSame(200, (int) ($r2['code'] ?? 0));
        $body2 = (string) ($r2['body'] ?? '');
        self::assertStringNotContainsString('<h2 class="card-title">Membres</h2>', $body2, 'Le groupe « Membres » ne doit pas apparaître sans droit Utilisateurs.');
        self::assertStringNotContainsString('coco@exemple.fr', $body2, 'Un e-mail de membre ne doit pas fuiter hors Système.');

        // Communication (ni compta ni Système) : ni ventes ni membres.
        $r3 = $this->request('GET', '/admin/search?q=coco', [], [], $this->commId);
        self::assertSame(200, (int) ($r3['code'] ?? 0));
        $body3 = (string) ($r3['body'] ?? '');
        self::assertStringNotContainsString('<h2 class="card-title">Membres</h2>', $body3, 'Le groupe « Membres » ne doit pas apparaître pour Communication.');
        self::assertStringNotContainsString('<h2 class="card-title">Ventes</h2>', $body3, 'Le groupe « Ventes » ne doit pas apparaître pour Communication.');
    }

    public function test_aucun_resultat_etat_vide(): void
    {
        $r = $this->request('GET', '/admin/search?q=zzzzabsent', [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0));
        $body = (string) ($r['body'] ?? '');

        self::assertStringContainsString('Aucun résultat pour', $body, 'L\'état vide est absent.');
        self::assertStringContainsString('zzzzabsent', $body, 'La requête recherchée doit être rappelée dans l\'état vide.');
    }

    public function test_requete_vide_page_sobre(): void
    {
        $r = $this->request('GET', '/admin/search', [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0));
        $body = (string) ($r['body'] ?? '');

        self::assertStringContainsString('Recherche', $body, 'Le titre « Recherche » est absent.');
        self::assertStringNotContainsString('Aucun résultat pour', $body, 'Pas d\'état vide sans requête.');
    }

    public function test_acces_refuse_aux_eleves(): void
    {
        $eleveId = 'u_search_eleve';
        $this->seedUser($eleveId, 'search-eleve@exemple.fr', 'Password123456', 'ELEVE');

        $r = $this->request('GET', '/admin/search?q=coca', [], [], $eleveId);
        self::assertSame(
            403,
            (int) ($r['code'] ?? 0),
            'Un élève doit être refusé (403) sur la recherche admin.'
        );
    }
}
