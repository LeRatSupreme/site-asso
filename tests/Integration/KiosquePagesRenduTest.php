<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Couverture de rendu des 4 pages kiosque restées sans test dédié
 * (docs/refonte-limites.md §13) : hub admin, récap du jour (admin),
 * période analysée (admin) et ventes du jour (membres).
 *
 * Modèle : KiosqueAffichageTest — jetons forgés directement en settings,
 * ventes du jour posées pour que les récaps aient du contenu.
 *
 * Contrat vérifié :
 *  - chaque page répond 200 avec son titre, sans marqueur d'erreur PHP ;
 *  - le récap membres n'affiche NI bénéfice, NI moyen de paiement (la
 *    version membres ne montre que le CA et les produits vendus) ;
 *  - un jeton invalide reçoit 403 sur les quatre pages (le jeton EST
 *    l'authentification).
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
        ]);

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
    }

    /**
     * Les quatre pages se rendent avec leur titre, sans erreur PHP.
     */
    public function test_les_quatre_pages_se_rendent(): void
    {
        $pages = [
            'hub admin'       => ['/kiosque/admin/' . $this->tokenAdmin, 'Kiosque admin'],
            'récap du jour'   => ['/kiosque/comptage/jour/' . $this->tokenAdmin, 'Récap du jour'],
            'période'         => ['/kiosque/admin/periode/' . $this->tokenAdmin, 'Période analysée'],
            'ventes du jour'  => ['/kiosque/comptage/jour-membre/' . $this->tokenMembres, 'Ventes du jour'],
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
        ];

        foreach ($paths as $path) {
            $r = $this->request('GET', $path);

            self::assertSame(403, (int) ($r['code'] ?? 0), sprintf('%s : jeton invalide → 403.', $path));
            self::assertStringNotContainsString('Récap', (string) ($r['body'] ?? ''), 'Aucun contenu kiosque n\'est servi sans jeton valide.');
        }
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
