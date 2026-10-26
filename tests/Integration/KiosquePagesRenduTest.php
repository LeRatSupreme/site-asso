<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Kiosque;
use App\Models\Model;

/**
 * Couverture de rendu des pages kiosque restées sans test dédié
 * (docs/refonte-limites.md §13) : hub admin, récap du jour (admin),
 * période analysée (admin), ventes du jour (membres) et dépôt à la
 * banque (admin) — plus la Vue Président (tuile additive : une page,
 * 3 chiffres, rien d'autre).
 *
 * Modèle : KiosqueAffichageTest — jetons forgés directement en settings,
 * ventes du jour posées pour que les récaps aient du contenu.
 *
 * Contrat vérifié :
 *  - chaque page répond 200 avec son titre, sans marqueur d'erreur PHP ;
 *  - le récap membres n'affiche NI bénéfice, NI moyen de paiement (la
 *    version membres ne montre que le CA et les produits vendus) ;
 *  - un jeton invalide reçoit 403 sur toutes les pages (le jeton EST
 *    l'authentification) ;
 *  - la Vue Président affiche EXACTEMENT 3 blocs KPI (ventes du mois,
 *    bénéfice du mois, stock restant) et aucun autre chiffre — elle est
 *    refusée ENTIÈRE (403) sans l'option « benefice » du kiosque.
 *
 * Base `aeic_test` requise (les tests sont sautés sinon).
 */
final class KiosquePagesRenduTest extends IntegrationTestCase
{
    private string $tokenMembres = 'jetonmembres-rendu4';
    private string $tokenAdmin   = 'jetonadmin-rendu4';

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
            'kiosques',
        ]);

        // Les kiosques personnalisés sont créés en process : le modèle est
        // branché sur la même base que les requêtes HTTP (même mécanique
        // que KiosquesPersonnalisesTest).
        Model::setTestPdo($this->pdo);

        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (id, `key`, value) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );
        $stmt->execute(['set_kiosq_r4_m', 'reappro_kiosk_token', $this->tokenMembres]);
        $stmt->execute(['set_kiosq_r4_a', 'admin_kiosk_token', $this->tokenAdmin]);

        for ($i = 1; $i <= 3; $i++) {
            $this->pdo->prepare(
                'INSERT INTO sales (id, transaction_ref, sold_at, payment_method, payment_raw, quantity, description, price_ttc, is_custom_amount, category)
                 VALUES (?,?,?,?,?,1,?,?,0,?)'
            )->execute([
                's_kiosq_r4_' . $i,
                'TKR4000' . $i,
                date('Y-m-d ') . sprintf('10:0%d:00', $i),
                'CARTE',
                'Visa - Débit',
                'colakiosqr4',
                '1.50',
                'Boissons',
            ]);
        }

        // Stock du jour pour la Vue Président : comptage de 20 unités à
        // midi (APRÈS les ventes de 10 h : théorique = 20) au dernier coût
        // connu de 0,50 € (lot ouvert hier, couvrant tout aujourd'hui).
        $this->pdo->prepare(
            "INSERT INTO inventory_counts (id, counted_at, product_key, counted_qty, theoretical_qty, gap, created_by)
             VALUES ('inv_kiosq_r4_1', ?, 'colakiosqr4', 20, 20, 0, 'test')"
        )->execute([date('Y-m-d 12:00:00')]);
        $this->pdo->prepare(
            "INSERT INTO product_costs (id, product_key, cost_price, valid_from)
             VALUES ('pc_kiosq_r4_1', 'colakiosqr4', 0.500, ?)"
        )->execute([date('Y-m-d', strtotime('-1 day'))]);
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
        parent::tearDown();
    }

    /**
     * Les pages se rendent avec leur titre, sans erreur PHP.
     */
    public function test_les_pages_se_rendent(): void
    {
        $pages = [
            'hub admin'       => ['/kiosque/admin/' . $this->tokenAdmin, 'Kiosque admin'],
            'récap du jour'   => ['/kiosque/comptage/jour/' . $this->tokenAdmin, 'Récap du jour'],
            'période'         => ['/kiosque/admin/periode/' . $this->tokenAdmin, 'Période analysée'],
            'ventes du jour'  => ['/kiosque/comptage/jour-membre/' . $this->tokenMembres, 'Ventes du jour'],
            'dépôt banque'    => ['/kiosque/admin/caisse/depot/' . $this->tokenAdmin, 'Dépôt à la banque'],
        ];

        foreach ($pages as $nom => [$path, $titre]) {
            $r    = $this->request('GET', $path);
            $body = (string) ($r['body'] ?? '');

            self::assertSame(200, (int) ($r['code'] ?? 0), sprintf('%s : la page doit répondre.', $nom));
            self::assertStringContainsString($titre, $body, sprintf('%s : le titre de la page est rendu.', $nom));
            self::assertNull($this->bodyError($body), sprintf('%s : la page doit se rendre sans erreur PHP.', $nom));
        }
    }

    /**
     * Le récap membres reste sans donnée financière sensible : ni
     * bénéfice, ni détail des moyens de paiement — seul le CA et les
     * produits vendus y figurent.
     */
    public function test_recap_membres_sans_benefice_ni_paiements(): void
    {
        $r    = $this->request('GET', '/kiosque/comptage/jour-membre/' . $this->tokenMembres);
        $body = (string) ($r['body'] ?? '');

        self::assertSame(200, (int) ($r['code'] ?? 0));
        self::assertStringContainsString('colakiosqr4', $body, 'Le produit vendu du jour apparaît dans le récap membres.');
        foreach (['Bénéfice', 'Espèces', 'Carte bancaire'] as $interdit) {
            self::assertStringNotContainsString($interdit, $body, sprintf('Récap membres : « %s » ne doit pas apparaître.', $interdit));
        }
    }

    /**
     * Le récap admin (jeton admin) montre AUSSI le bénéfice : la page
     * complète reste la version par jeton détaché.
     */
    public function test_recap_admin_avec_benefice(): void
    {
        $r    = $this->request('GET', '/kiosque/comptage/jour/' . $this->tokenAdmin);
        $body = (string) ($r['body'] ?? '');

        self::assertSame(200, (int) ($r['code'] ?? 0));
        self::assertStringContainsString('Bénéfice', $body, 'Le récap du jour admin affiche le bénéfice.');
    }

    /**
     * Jeton invalide : 403 sur les quatre pages — le jeton EST
     * l'authentification, aucun contenu n'est servi.
     */
    public function test_jetons_invalides_refuses(): void
    {
        $paths = [
            '/kiosque/admin/jetonnementinvalide',
            '/kiosque/comptage/jour/jetonnementinvalide',
            '/kiosque/admin/periode/jetonnementinvalide',
            '/kiosque/comptage/jour-membre/jetonnementinvalide',
            '/kiosque/admin/caisse/depot/jetonnementinvalide',
            '/kiosque/admin/president/jetonnementinvalide',
        ];

        foreach ($paths as $path) {
            $r = $this->request('GET', $path);

            self::assertSame(403, (int) ($r['code'] ?? 0), sprintf('%s : jeton invalide → 403.', $path));
            self::assertStringNotContainsString('Récap', (string) ($r['body'] ?? ''), 'Aucun contenu kiosque n\'est servi sans jeton valide.');
        }
    }

    /**
     * Vue Président (jeton admin legacy : benefice + couts) : la page
     * affiche EXACTEMENT 3 blocs KPI — ventes du mois (3 × 1,50 € = 4,50 €),
     * bénéfice du mois, stock restant (20 unités ≈ 10,00 € au dernier
     * coût) — et AUCUN autre chiffre : ni marge %, ni paiements, ni top
     * produits, ni transactions.
     */
    public function test_vue_president_trois_kpi_rien_de_plus(): void
    {
        $r    = $this->request('GET', '/kiosque/admin/president/' . $this->tokenAdmin);
        $body = (string) ($r['body'] ?? '');

        self::assertSame(200, (int) ($r['code'] ?? 0), 'La Vue Président doit répondre 200 au jeton admin.');
        self::assertStringContainsString('Vue Président', $body, 'Le titre de la page est rendu.');
        self::assertNull($this->bodyError($body), 'La Vue Président doit se rendre sans erreur PHP.');

        // EXACTEMENT 3 blocs KPI : ca, benefice, stock — pas un de plus.
        self::assertSame(3, substr_count($body, 'data-kpi="'), 'La Vue Président affiche exactement 3 blocs KPI.');
        foreach (['ca', 'benefice', 'stock'] as $kpi) {
            self::assertStringContainsString('data-kpi="' . $kpi . '"', $body, sprintf('Le bloc KPI « %s » est rendu.', $kpi));
        }

        // Les 3 chiffres attendus : CA du mois, stock unités, valeur € du
        // stock (option « couts » du jeton admin).
        self::assertStringContainsString('4,50 €', $body, 'CA du mois : 3 ventes à 1,50 €.');
        self::assertStringContainsString('20', $body, 'Stock restant : 20 unités du comptage de midi.');
        self::assertStringContainsString('10,00 €', $body, 'Valeur du stock au dernier coût : 20 × 0,50 € (option couts).');

        // Aucun autre chiffre ni jargon : marge, paiements, top produits,
        // transactions et panier sont interdits sur cette page.
        foreach (['Marge', 'Liquide', 'Carte', 'Top produits', 'transactions', 'panier', 'Période analysée'] as $interdit) {
            self::assertStringNotContainsString($interdit, $body, sprintf('Vue Président : « %s » ne doit pas apparaître.', $interdit));
        }
    }

    /**
     * Vue Président sans l'option « benefice » : refus ENTIÈR (403),
     * même si la tuile est cochée — le bénéfice du mois est un pilier de
     * la page, rien n'est servi partiellement. Un kiosque ANCIEN (options
     * NULL, comportement historique : bénéfice visible) y accède.
     */
    public function test_vue_president_sans_option_benefice_refusee(): void
    {
        // Kiosque personnalisé avec la tuile cochée mais SANS « benefice ».
        $id = Kiosque::create('Président sans bénéfice', ['president'], 'test', ['paiements']);
        $k  = Kiosque::find($id);
        self::assertIsArray($k);

        $r = $this->request('GET', '/kiosque/admin/president/' . $k['token']);
        self::assertSame(403, (int) ($r['code'] ?? 0), 'Sans l\'option « benefice », la page entière est refusée.');
        self::assertStringNotContainsString('data-kpi=', (string) ($r['body'] ?? ''), 'Aucun KPI n\'est servi sans l\'option.');

        // Kiosque ANCIEN (options NULL : bénéfice visible par défaut) : accès.
        $idAncien = Kiosque::create('Président ancien', ['president'], 'test');
        $ancien   = Kiosque::find($idAncien);
        self::assertIsArray($ancien);
        self::assertNull($ancien['options']);

        $r2 = $this->request('GET', '/kiosque/admin/president/' . $ancien['token']);
        self::assertSame(200, (int) ($r2['code'] ?? 0), 'Kiosque ancien (options NULL) : bénéfice visible, page servie.');

        // Jeton MEMBRES legacy : ni la tuile ni l'option → 403.
        $r3 = $this->request('GET', '/kiosque/admin/president/' . $this->tokenMembres);
        self::assertSame(403, (int) ($r3['code'] ?? 0), 'Le jeton membres n\'ouvre pas la Vue Président.');
    }

    /**
     * Détecte les marqueurs d'erreur dans un corps de page rendu.
     */
    private function bodyError(string $body): ?string
    {
        $marqueurs = ['Erreur 500', 'Fatal error', 'Parse error', 'Warning:', 'Deprecated:', 'Undefined variable', 'Undefined array key'];

        foreach ($marqueurs as $marqueur) {
            if (str_contains($body, $marqueur)) {
                return $marqueur;
            }
        }

        return null;
    }
}
