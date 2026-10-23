<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Rôles PRESIDENT (lecture seule) et BENEVOL (bénévole de caisse) —
 * parcours réels de l'espace admin sur la base de test.
 *
 * Contrat vérifié :
 *  - BENEVOL : accueil /admin sans AUCUN chiffre financier (ni « € », ni
 *    « Bénéfice », ni « Argent encaissé », ni les libellés du menu compta),
 *    refusé sur les pages module (compta, analytics), admis sur les deux
 *    pages de comptage (guardAdminArea) ;
 *  - BENEVOL (lot 5) : la liste de courses rejoint ses pages, rendue sans
 *    AUCUN chiffre financier (ni « € », ni « Coût », ni « Total estimé »),
 *    avec l'entrée « Liste de courses » dans le menu Accueil ; un rôle
 *    compta voit les coûts sur la même page ;
 *  - BENEVOL (lot 6E) : parcours complet page après page (accueil, comptage
 *    de caisse, comptage inventaire, liste de courses) — chaque étape
 *    répond 200 et n'affiche NI « € », NI « Bénéfice », NI « Marge »
 *    (les libellés neutres de comptage restent bien sûr autorisés) ;
 *  - PRESIDENT : lecture générale (accueil, compta), jamais le groupe
 *    Système (settings), et toute écriture POST /admin/* reçue en 403 par
 *    le routeur — le contrôleur n'est jamais atteint (l'objectif hebdo ne
 *    doit pas bouger) ;
 *  - EVENEMENTS : rôle désormais câblé (events + content), la page
 *    /admin/events s'affiche.
 *
 * Note sur les refus : un rôle du bureau auquel une page est interdite est
 * renvoyé chez lui par Middleware::forbidden() (redirection douce vers
 * /admin avec message flash — convention du projet, jamais de page d'erreur
 * abrupte). Le test accepte donc le 403 brut OU la redirection douce, et
 * vérifie dans les deux cas que la page demandée n'est PAS servie.
 *
 * Base `aeic_test_r` requise (les tests sont sautés sinon).
 */
final class RolesMenuTest extends IntegrationTestCase
{
    private string $presidentId  = 'u_roles_president';
    private string $benevolId    = 'u_roles_benevol';
    private string $evenementsId = 'u_roles_evenements';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->reset(['users']);
        $this->seedUser($this->presidentId, 'roles-president@exemple.fr', 'Password123456', 'PRESIDENT');
        $this->seedUser($this->benevolId, 'roles-benevol@exemple.fr', 'Password123456', 'BENEVOL');
        $this->seedUser($this->evenementsId, 'roles-evenements@exemple.fr', 'Password123456', 'EVENEMENTS');
    }

    // -----------------------------------------------------------------
    //  BENEVOL : aucun chiffre financier
    // -----------------------------------------------------------------

    public function test_benevol_accueil_sans_aucun_chiffre_financier(): void
    {
        $r    = $this->request('GET', '/admin', [], [], $this->benevolId);
        $code = (int) ($r['code'] ?? 0);
        $body = (string) ($r['body'] ?? '');

        self::assertSame(200, $code, 'Un bénévole de caisse accède à l\'accueil admin.');

        // Ni chiffre financier, ni libellé de menu compta : le rôle ne doit
        // même pas voir un lien vers une page qui lui est interdite.
        foreach (['€', 'Bénéfice', 'Argent encaissé', 'Journal des ventes', 'Analyses', 'Réappro'] as $interdit) {
            self::assertStringNotContainsString($interdit, $body, sprintf('« %s » ne doit pas apparaître pour un bénévole.', $interdit));
        }

        // Ses deux pages de comptage restent visibles depuis l'accueil.
        self::assertStringContainsString('/admin/compta/caisse', $body);
        self::assertStringContainsString('/admin/compta/inventaire/comptage', $body);
    }

    public function test_benevol_refuse_sur_les_pages_compta_et_analytics(): void
    {
        foreach (['/admin/compta', '/admin/analytics'] as $path) {
            $r = $this->request('GET', $path, [], [], $this->benevolId);

            $this->assertRefuse($r, $path, 'Un bénévole n\'a accès à aucun module compta.');
        }
    }

    public function test_benevol_accede_aux_deux_pages_de_comptage(): void
    {
        foreach (['/admin/compta/caisse', '/admin/compta/inventaire/comptage'] as $path) {
            $r    = $this->request('GET', $path, [], [], $this->benevolId);
            $code = (int) ($r['code'] ?? 0);
            $body = (string) ($r['body'] ?? '');

            self::assertSame(200, $code, sprintf('Le comptage fait partie des pages du bénévole : %s.', $path));
            self::assertNull($this->bodyError($body), sprintf('La page %s doit se rendre sans erreur PHP.', $path));
        }
    }

    /**
     * Lot 6E : parcours complet du bénévole de caisse, dans l'ordre où il
     * enchaîne les pages (mission : « test par rôle »). Chaque étape répond
     * 200 et AUCUNE n'affiche de chiffre financier ni de vocabulaire de
     * marge : ni « € », ni « Bénéfice », ni « Marge » — les libellés neutres
     * (« Comptage », « Montant compté »…) restent bien sûr affichés.
     */
    public function test_benevol_parcours_complet_sans_aucun_chiffre_financier(): void
    {
        $this->seedProduitListe();

        $parcours = [
            '/admin'                            => 'accueil outils',
            '/admin/compta/caisse'              => 'comptage de caisse',
            '/admin/compta/inventaire/comptage' => 'comptage inventaire',
            '/admin/compta/liste'               => 'liste de courses',
        ];

        foreach ($parcours as $path => $etape) {
            $r    = $this->request('GET', $path, [], [], $this->benevolId);
            $code = (int) ($r['code'] ?? 0);
            $body = (string) ($r['body'] ?? '');

            self::assertSame(200, $code, sprintf('Le bénévole enchaîne son parcours : étape « %s » (%s).', $etape, $path));
            self::assertNull($this->bodyError($body), sprintf('L\'étape « %s » (%s) doit se rendre sans erreur PHP.', $etape, $path));

            foreach (['€', 'Bénéfice', 'Marge'] as $interdit) {
                self::assertStringNotContainsString(
                    $interdit,
                    $body,
                    sprintf('« %s » ne doit apparaître sur aucune page du bénévole (étape « %s »).', $interdit, $etape)
                );
            }
        }

        // La dernière étape reste utile : la liste montre bien les produits
        // à acheter (sans aucun montant).
        $rl   = $this->request('GET', '/admin/compta/liste', [], [], $this->benevolId);
        $body = (string) ($rl['body'] ?? '');
        self::assertStringContainsString('data-key="colalistetest"', $body, 'La tuile du produit à acheter reste visible au bout du parcours.');
    }

    /**
     * Lot 5 : la liste de courses rejoint les pages du bénévole, SANS
     * aucun chiffre financier (mission : « le bénévole ne voit AUCUN
     * chiffre financier »). La page ne montre que produit, stock restant,
     * quantité à acheter, case à cocher ; le menu Accueil propose l'entrée.
     */
    public function test_benevol_liste_de_courses_sans_chiffre_financier(): void
    {
        $this->seedProduitListe();

        $r    = $this->request('GET', '/admin/compta/liste', [], [], $this->benevolId);
        $code = (int) ($r['code'] ?? 0);
        $body = (string) ($r['body'] ?? '');

        self::assertSame(200, $code, 'La liste de courses est accessible au bénévole (lot 5).');

        foreach (['€', 'Coût', 'Total estimé'] as $interdit) {
            self::assertStringNotContainsString($interdit, $body, sprintf('« %s » ne doit pas apparaître pour un bénévole sur la liste.', $interdit));
        }

        // Contenu utile conservé : produit, stock restant, à commander,
        // base de calcul (sans montant) et entrée de menu.
        self::assertStringContainsString('Liste de courses', $body);
        self::assertStringContainsString('data-key="colalistetest"', $body, 'La tuile du produit à acheter reste visible.');
        self::assertStringContainsString('stock restant', $body, 'Le stock restant reste affiché (vocabulaire unifié).');
        self::assertStringContainsString('Base de calcul', $body, 'L\'encart « Base de calcul » est affiché, sans montant.');
        self::assertStringContainsString('/admin/compta/liste', $body, 'L\'entrée « Liste de courses » doit figurer dans le menu du bénévole.');

        // Cases partagées : le bénévole coche via le MÊME endpoint que la
        // compta (mécanisme inchangé, garde élargie à son rôle).
        $rc    = $this->request('POST', '/admin/compta/liste/check', [
            'key'        => 'colalistetest',
            'state'      => '1',
            'who_prenom' => 'Ben',
            'who_nom'    => 'Volontaire',
            'who_alias'  => 'bénévole',
        ], [], $this->benevolId);
        $check = json_decode((string) ($rc['body'] ?? ''), true);
        self::assertSame(200, (int) ($rc['code'] ?? 0), 'Le POST de coche du bénévole est accepté.');
        self::assertTrue(is_array($check) && ($check['ok'] ?? false), 'Le bénévole coche via le même endpoint que la compta.');
        $ru    = $this->request('POST', '/admin/compta/liste/check', [
            'key'        => 'colalistetest',
            'state'      => '0',
            'who_prenom' => 'Ben',
            'who_nom'    => 'Volontaire',
            'who_alias'  => 'bénévole',
        ], [], $this->benevolId);
        $uncheck = json_decode((string) ($ru['body'] ?? ''), true);
        self::assertTrue(is_array($uncheck) && ($uncheck['ok'] ?? false), 'Le décochage du bénévole est accepté.');
    }

    /**
     * Miroir du test précédent : un rôle compta (module ouvert) voit les
     * coûts sur la MÊME page — le masquage est bien par rôle, pas global.
     */
    public function test_role_compta_voit_les_couts_sur_la_liste(): void
    {
        $this->seedProduitListe();
        $tresorierId = 'u_roles_tresorerie';
        $this->seedUser($tresorierId, 'roles-tresorerie@exemple.fr', 'Password123456', 'TRESORERIE');

        $r    = $this->request('GET', '/admin/compta/liste', [], [], $tresorierId);
        $code = (int) ($r['code'] ?? 0);
        $body = (string) ($r['body'] ?? '');

        self::assertSame(200, $code, 'Un rôle compta accède à la liste de courses.');
        self::assertStringContainsString('€', $body, 'Un rôle compta voit le coût estimé du panier sur la liste.');
        self::assertStringContainsString('Base de calcul', $body, 'L\'encart « Base de calcul » est aussi présent pour un rôle compta.');
    }

    // -----------------------------------------------------------------
    //  PRESIDENT : lecture générale, écriture impossible
    // -----------------------------------------------------------------

    public function test_president_lit_l_accueil_et_la_compta(): void
    {
        foreach (['/admin', '/admin/compta/ventes'] as $path) {
            $r    = $this->request('GET', $path, [], [], $this->presidentId);
            $code = (int) ($r['code'] ?? 0);
            $body = (string) ($r['body'] ?? '');

            self::assertSame(200, $code, sprintf('Le président lit %s (lecture générale).', $path));
            self::assertNull($this->bodyError($body), sprintf('La page %s doit se rendre sans erreur PHP.', $path));
        }
    }

    public function test_president_refuse_le_groupe_systeme(): void
    {
        // Utilisateurs et Paramètres restent réservés au Fondateur/ADMIN
        // listé — le président n'y accède jamais.
        foreach (['/admin/settings', '/admin/users'] as $path) {
            $r = $this->request('GET', $path, [], [], $this->presidentId);

            $this->assertRefuse($r, $path, 'Le président n\'accède pas au groupe Système.');
        }
    }

    public function test_president_post_admin_bloque_par_le_routeur(): void
    {
        $avant = $this->goalSetting();

        $r    = $this->request('POST', '/admin/analytics/objectif', ['goal' => '999'], [], $this->presidentId);
        $code = (int) ($r['code'] ?? 0);
        $body = (string) ($r['body'] ?? '');

        // 403 AVANT tout contrôleur : message sobre du routeur, la page
        // d'objectif n'est jamais atteinte.
        self::assertSame(403, $code, 'Toute écriture POST /admin/* d\'un président reçoit un 403 du routeur.');
        self::assertStringContainsString('Consultation seule', $body);
        self::assertFalse(isset($r['session']['_flash']), 'Aucun flash de succès : le contrôleur n\'a pas tourné.');

        // L'objectif hebdomadaire n'a pas bougé (aucune écriture en base).
        self::assertSame($avant, $this->goalSetting(), 'Le POST bloqué ne doit rien écrire en base.');
    }

    /**
     * Lot suivi (docs/refonte-limites.md §4) : le layout pose le drapeau
     * « lecture seule » pour le président — classe body is-readonly (les
     * boutons des formulaires POST sont masqués en CSS) et bandeau
     * explicite. Aucun autre rôle ne porte le drapeau.
     */
    public function test_president_drapeau_lecture_seule_dans_le_layout(): void
    {
        $r    = $this->request('GET', '/admin/compta/ventes', [], [], $this->presidentId);
        $body = (string) ($r['body'] ?? '');

        self::assertSame(200, (int) ($r['code'] ?? 0), 'Le président lit le journal des ventes.');
        self::assertStringContainsString('admin-body is-readonly', $body, 'La classe body is-readonly est posée pour le président.');
        self::assertStringContainsString('Mode lecture seule', $body, 'Le bandeau explicatif est affiché.');

        $tresorierId = 'u_roles_tresorerie';
        $this->seedUser($tresorierId, 'roles-tresorerie@exemple.fr', 'Password123456', 'TRESORERIE');
        $r2    = $this->request('GET', '/admin/compta/ventes', [], [], $tresorierId);
        $body2 = (string) ($r2['body'] ?? '');

        self::assertSame(200, (int) ($r2['code'] ?? 0), 'Miroir : un rôle compta accède à la même page.');
        self::assertStringNotContainsString('is-readonly', $body2, 'Aucun autre rôle ne porte le drapeau lecture seule.');
        self::assertStringNotContainsString('Mode lecture seule', $body2, 'Pas de bandeau lecture seule pour un rôle qui écrit.');
    }

    // -----------------------------------------------------------------
    //  EVENEMENTS : rôle câblé (events + content)
    // -----------------------------------------------------------------

    public function test_evenements_accede_a_la_gestion_des_events(): void
    {
        $r    = $this->request('GET', '/admin/events', [], [], $this->evenementsId);
        $code = (int) ($r['code'] ?? 0);

        self::assertSame(200, $code, 'Le rôle EVENEMENTS (events + content) ouvre la page Événements.');

        // Mais pas la compta : module non attribué à ce rôle.
        $rCompta = $this->request('GET', '/admin/compta', [], [], $this->evenementsId);
        $this->assertRefuse($rCompta, '/admin/compta', 'Le rôle EVENEMENTS n\'a pas la compta.');
    }

    // -----------------------------------------------------------------
    //  Helpers
    // -----------------------------------------------------------------

    /**
     * Donne à la liste de courses un produit commandable avec un coût
     * connu : ventes du jour, comptage 0 (théorique 0) et coût de revient
     * saisi — la tuile apparaît et le panier compta affiche un montant
     * en euros. Les tables produit sont vidées pour isoler chaque test.
     */
    private function seedProduitListe(): void
    {
        $this->reset([
            'sales',
            'inventory_counts',
            'product_costs',
            'product_discontinued',
            'product_infinite',
            'product_packs',
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $this->pdo->prepare(
                'INSERT INTO sales (id, transaction_ref, sold_at, payment_method, payment_raw, quantity, description, price_ttc, is_custom_amount, category)
                 VALUES (?,?,?,?,?,1,?,?,0,?)'
            )->execute([
                's_roles_lst_' . $i,
                'TROLST00' . $i,
                date('Y-m-d ') . sprintf('08:0%d:00', $i),
                'CARTE',
                'Visa - Débit',
                'colalistetest',
                '1.50',
                'Boissons',
            ]);
        }

        $this->pdo->prepare(
            'INSERT INTO inventory_counts (id, counted_at, product_key, counted_qty, theoretical_qty, gap, created_by)
             VALUES (?,?,?,?,?,?,?)'
        )->execute(['inv_roles_lst', '2026-01-01 08:00:00', 'colalistetest', 0, 0, 0, 'test']);
        $this->pdo->prepare(
            'INSERT INTO product_costs (id, product_key, cost_price, valid_from) VALUES (?,?,?,?)'
        )->execute(['pc_roles_lst', 'colalistetest', '1.500', '2026-01-01']);
        $this->pdo->prepare(
            'INSERT INTO product_packs (product_key, pack_size, updated_by) VALUES (?,?,?)'
        )->execute(['colalistetest', 12, 'test']);
    }

    /**
     * Vérifie un refus d'accès : 403 brut (élève, routeur) ou redirection
     * douce du bureau vers /admin avec message « Accès refusé » — dans les
     * deux cas la page demandée n'est pas servie.
     *
     * @param array<string,mixed> $r
     */
    private function assertRefuse(array $r, string $path, string $message): void
    {
        $code     = (int) ($r['code'] ?? 0);
        $location = $this->location($r);
        $flash    = $r['session']['_flash'][0]['message'] ?? '';

        if ($code === 403) {
            self::assertStringNotContainsString('Erreur 500', (string) ($r['body'] ?? ''));

            return;
        }

        self::assertSame(302, $code, $message . sprintf(' (GET %s : ni 403, ni redirection douce — code %d.)', $path, $code));
        self::assertStringContainsString('/admin', $location, 'Le bureau est renvoyé vers son accueil.');
        self::assertStringNotContainsString($path, $location, 'La page refusée ne doit pas être l\'objectif de la redirection.');
        self::assertStringContainsString('Accès refusé', (string) $flash, 'La redirection douce porte un message explicite.');
    }

    /**
     * Valeur courante de l'objectif hebdomadaire (settings), null si absente.
     */
    private function goalSetting(): ?string
    {
        $stmt = $this->pdo->prepare("SELECT value FROM settings WHERE `key` = 'analytics_weekly_goal' LIMIT 1");
        $stmt->execute();
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
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
