<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Fusion des pages « Bénéfice par produit » et « Bénéfice par catégorie »
 * en une page unique /admin/compta/benefice :
 *   1. La page unifiée expose les DEUX jeux de données selon l'onglet
 *      demandé (?vue=produits|categories, liste blanche, défaut produits).
 *   2. Les anciennes URLs /admin/compta/produits et /admin/compta/categories
 *      redirigent en 301 vers la page unifiée avec le bon onglet et la
 *      période conservée (bookmarks / liens recherche admin : zéro 404).
 *   3. La garde d'accès est inchangée : visiteur non connecté → /login
 *      (page unifiée comme anciennes URLs).
 *
 * Base `aeic_test` requise (les tests sont sautés sinon).
 */
final class ComptaBeneficeFusionTest extends IntegrationTestCase
{
    private string $rootId = 'u_benef_root';

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->requireDatabase();
        $this->reset(['sales', 'product_costs', 'users']);
        $this->seedUser($this->rootId, 'benef-root@exemple.fr', 'Password123456', 'SUPERADMIN');

        // Boissons : 100,00 € de CA pour 50,00 € de coût → marge 50,0 %.
        // Snacks : 100,00 € sans coût → marge 100,0 %. Totaux (onglet
        // catégories) : CA 200,00 €, bénéfice 150,00 €, marge 75,0 %,
        // chaque catégorie 50,0 % du CA.
        $this->insertSale('s_ben_b', 'TBENB1', '2026-07-05 10:00:00', '100.00', 'café', 'Boissons');
        $this->insertSale('s_ben_s', 'TBENS1', '2026-07-06 10:00:00', '100.00', 'thé', 'Snacks');
        $this->pdo->prepare(
            'INSERT INTO product_costs (id, product_key, cost_price, valid_from) VALUES (?,?,?,?)'
        )->execute(['pc_ben_cafe', 'café', '50.000', '2026-01-01']);
    }

    /**
     * La page unifiée rend le bloc « produits » par défaut et sur
     * ?vue=produits : marges calculées par le point d'assemblage unique.
     */
    public function test_page_unifiee_benefice_rend_le_bloc_produits_par_defaut(): void
    {
        $url = '/admin/compta/benefice?period=custom&from=2026-07-01&to=2026-07-31';

        foreach ([$url, $url . '&vue=produits'] as $u) {
            $r = $this->request('GET', $u, [], [], $this->rootId);
            self::assertSame(200, (int) ($r['code'] ?? 0), 'La page Bénéfice unifiée (onglet produits) doit répondre : ' . $u);
            $body = (string) ($r['body'] ?? '');
            // Marqueur structurel du bloc produits (barre de recherche/table
            // « pk-* ») : le libellé seul ne suffit pas, la sidebar le porte aussi.
            self::assertStringContainsString('id="pk-search"', $body, "L'onglet produits doit rendre le bloc produits : $u");
            self::assertStringContainsString('50,0 %', $body, "La marge produit (clé margin de beneficeData) est absente : $u");
            self::assertStringNotContainsString('Marge globale', $body, "L'onglet produits ne doit pas rendre le bloc catégories : $u");
        }
    }

    /**
     * ?vue=categories bascule sur le bloc « catégories » (totaux, marge
     * globale et parts du CA venus du contrôleur).
     */
    public function test_page_unifiee_benefice_bascule_sur_le_bloc_categories(): void
    {
        $r = $this->request(
            'GET',
            '/admin/compta/benefice?vue=categories&period=custom&from=2026-07-01&to=2026-07-31',
            [],
            [],
            $this->rootId
        );
        self::assertSame(200, (int) ($r['code'] ?? 0), 'La page Bénéfice unifiée (onglet catégories) doit répondre.');
        $body = (string) ($r['body'] ?? '');
        // Marqueurs structurels du bloc catégories (cartes + totaux), le
        // libellé seul étant aussi porté par la sidebar.
        self::assertStringContainsString('Marge globale', $body, "L'onglet catégories doit rendre le bloc catégories.");
        self::assertStringContainsString('200,00 €', $body, 'Le CA total (clé totaux) est erroné.');
        self::assertStringContainsString('75,0 %', $body, 'La marge globale (clé totaux) est absente ou erronée.');
        self::assertStringContainsString('50,0 % du CA total', $body, 'La part de chaque catégorie (clé share) est absente ou erronée.');
        self::assertStringNotContainsString('id="pk-search"', $body, "L'onglet catégories ne doit pas rendre le bloc produits.");
    }

    /**
     * La bascule ?vue= est contrôlée : toute valeur hors liste blanche
     * retombe sur l'onglet défaut (produits), jamais sur une erreur.
     */
    public function test_parametre_vue_hors_liste_blanche_retombe_sur_produits(): void
    {
        $r = $this->request(
            'GET',
            '/admin/compta/benefice?vue=inconnue&period=custom&from=2026-07-01&to=2026-07-31',
            [],
            [],
            $this->rootId
        );
        self::assertSame(200, (int) ($r['code'] ?? 0), 'Une valeur ?vue= inconnue ne doit pas casser la page.');
        self::assertStringContainsString(
            'id="pk-search"',
            (string) ($r['body'] ?? ''),
            '?vue= hors liste blanche doit retomber sur l\'onglet défaut (produits).'
        );
    }

    /**
     * Zéro 404 : les anciennes URLs redirigent en 301 vers la page unifiée
     * avec le bon onglet, en conservant la période demandée.
     */
    public function test_anciennes_urls_redirigent_en_301_vers_la_page_unifiee(): void
    {
        $rp = $this->request(
            'GET',
            '/admin/compta/produits?period=custom&from=2026-07-01&to=2026-07-31',
            [],
            [],
            $this->rootId
        );
        self::assertSame(301, (int) ($rp['code'] ?? 0), 'L\'ancienne URL produits doit répondre 301.');
        $locP = $this->location($rp);
        self::assertStringContainsString('/admin/compta/benefice', $locP, 'La redirection produits ne pointe pas vers la page unifiée.');
        self::assertStringContainsString('vue=produits', $locP, 'La redirection produits doit cibler l\'onglet produits.');
        self::assertStringContainsString('from=2026-07-01', $locP, 'La période (from) doit survivre à la redirection.');
        self::assertStringContainsString('to=2026-07-31', $locP, 'La période (to) doit survivre à la redirection.');

        $rc = $this->request(
            'GET',
            '/admin/compta/categories?period=custom&from=2026-07-01&to=2026-07-31',
            [],
            [],
            $this->rootId
        );
        self::assertSame(301, (int) ($rc['code'] ?? 0), 'L\'ancienne URL catégories doit répondre 301.');
        $locC = $this->location($rc);
        self::assertStringContainsString('/admin/compta/benefice', $locC, 'La redirection catégories ne pointe pas vers la page unifiée.');
        self::assertStringContainsString('vue=categories', $locC, 'La redirection catégories doit cibler l\'onglet catégories.');

        // La cible de la redirection rend bien la page unifiée (chaînage).
        $r = $this->request('GET', parse_url($locC, PHP_URL_PATH) . '?' . parse_url($locC, PHP_URL_QUERY), [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0), 'La cible de la redirection 301 doit répondre 200.');
    }

    /**
     * La recherche admin lien deep (`?q=`) survit : le paramètre est
     * conservé par la redirection produits → bénéfice.
     */
    public function test_redirection_produits_conserve_le_parametre_de_recherche(): void
    {
        $r = $this->request('GET', '/admin/compta/produits?q=caf%C3%A9', [], [], $this->rootId);
        self::assertSame(301, (int) ($r['code'] ?? 0));
        self::assertStringContainsString(
            'q=caf%C3%A9',
            $this->location($r),
            'Le paramètre de recherche ?q= doit être conservé par la redirection.'
        );
    }

    /**
     * Garde d'accès inchangée : sans session, la page unifiée et les
     * anciennes URLs renvoient vers /login (jamais de données exposées).
     */
    public function test_garde_acces_inchangee_sans_session(): void
    {
        foreach ([
            '/admin/compta/benefice',
            '/admin/compta/produits',
            '/admin/compta/categories',
        ] as $url) {
            $r = $this->request('GET', $url);
            self::assertStringContainsString('/login', $this->location($r), "L'accès non authentifié doit renvoyer au login : $url");
            self::assertStringNotContainsString('200,00', (string) ($r['body'] ?? ''), "Aucun chiffre ne doit fuiter sans session : $url");
        }
    }

    /**
     * Insère une vente directement en base (SumUp simulé).
     */
    private function insertSale(string $id, string $ref, string $soldAt, string $priceTtc, ?string $description = null, ?string $category = null): void
    {
        $this->pdo->prepare(
            'INSERT INTO sales (id, transaction_ref, sold_at, payment_method, payment_raw, quantity, description, price_ttc, is_custom_amount, category)
             VALUES (?,?,?,?,?,1,?,?,0,?)'
        )->execute([$id, $ref, $soldAt, 'CARTE', 'Visa - Débit', $description ?? $ref, $priceTtc, $category]);
    }
}
