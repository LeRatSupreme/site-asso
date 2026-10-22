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
