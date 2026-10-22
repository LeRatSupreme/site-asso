<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Auth;
use App\Core\Permissions;

/**
 * Accueil /admin « À faire aujourd'hui » — trois variantes selon le rôle :
 *  1. Rôles compta (SUPERADMIN/PRESIDENT) : verdict + les 4 cartes KPI
 *     (libellés du moteur Dashboard\Cartes) + bloc « À faire » + sections
 *     repliées (niveau 2) + liens experts (niveau 3). La carte « Journal
 *     d'audit » a quitté l'accueil (page dédiée /admin/audit).
 *  2. BENEVOL : page outil minimaliste — trois tuiles-liens, AUCUN chiffre
 *     financier, AUCUN compteur.
 *  3. Autres rôles du bureau (COMMUNICATION) : accueil non financier —
 *     compteurs + liens rapides, sans aucun €.
 *
 * Base `aeic_test` requise (les tests sont sautés sinon).
 */
final class AdminHomePageTest extends IntegrationTestCase
{
    private string $rootId = 'u_home_root';
    private string $commId = 'u_home_comm';
    private string $benevolId = 'u_home_benevol';
    private string $presidentId = 'u_home_president';

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->requireDatabase();
        // Mêmes tables que DashboardPageTest : le payload Dashboard\Data
        // lit les ventes, dépenses, comptages, coûts et imports.
        $this->reset([
            'sale_adjustments',
            'sales',
            'import_batches',
            'expenses',
            'cash_movements',
            'cash_counts',
            'inventory_counts',
            'inventory_tablet_sessions',
            'inventory_tablet_lines',
            'product_costs',
            'product_discontinued',
            'product_zero_since',
            'product_infinite',
            'product_packs',
            'product_aliases',
            'purchases',
            'losses',
            // Le test « sans carte journal d'audit » y insère une entrée :
            // le reset garantit l'indépendance vis-à-vis de l'ordre des
            // tests (executionOrder=random) et des résidus d'autres suites.
            'audit_logs',
            'users',
        ]);
        $this->seedUser($this->rootId, 'home-root@exemple.fr', 'Password123456', 'SUPERADMIN');
        $this->seedUser($this->commId, 'home-comm@exemple.fr', 'Password123456', 'COMMUNICATION');
        $this->seedUser($this->presidentId, 'home-president@exemple.fr', 'Password123456', 'PRESIDENT');
    }

    public function test_accueil_compta_affiche_verdict_kpi_et_a_faire(): void
    {
        $r = $this->request('GET', '/admin', [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0), 'L\'accueil admin doit répondre pour un SUPERADMIN.');
        $body = (string) ($r['body'] ?? '');

        // Titre de la page (l'apostrophe est échappée par e()).
        self::assertStringContainsString('faire aujourd', $body, 'Le titre « À faire aujourd\'hui » est absent.');

        // Les 4 cartes KPI (libellés EXACTS du moteur Cartes).
        self::assertStringContainsString('Argent encaissé', $body, 'La carte « Argent encaissé » est absente.');
        self::assertStringContainsString('gagne sur les ventes', $body, 'La carte « Ce qu\'on gagne sur les ventes » est absente.');
        self::assertStringContainsString('reste après les dépenses', $body, 'La carte « Ce qui reste après les dépenses » est absente.');
        self::assertStringContainsString('Articles vendus', $body, 'La carte « Articles vendus » est absente.');

        // Verdict (fenêtre par défaut = semaine en cours → neutre « en cours »).
        self::assertStringContainsString('Période en cours', $body, 'Le verdict (neutre sur période en cours) est absent.');

        // Bloc « À faire » avec liens d'action.
        self::assertStringContainsString('À faire', $body, 'Le bloc « À faire » est absent.');

        // Niveau 2 : sections repliées (partiaux partagés avec /admin/compta).
        self::assertStringContainsString('<details', $body, 'Les sections repliées (niveau 2) sont absentes.');
        self::assertStringContainsString('Carte et liquide', $body, 'La section « Carte et liquide » est absente.');
        self::assertStringContainsString('Top produits', $body, 'La section « Top produits » est absente.');

        // Niveau 3 : liens vers les outils experts.
        self::assertStringContainsString('/admin/analytics', $body, 'Le lien vers les Analyses est absent.');
        self::assertStringContainsString('/admin/compta/annuel', $body, 'Le lien vers le Bilan annuel est absent.');
        self::assertStringContainsString('/admin/compta/ventes', $body, 'Le lien vers le Journal des ventes est absent.');
    }

    public function test_accueil_compta_sans_carte_journal_audit(): void
    {
        // La carte « Journal d'audit » déménage vers /admin/audit : la liste
        // des dernières entrées ne doit plus être rendue sur l'accueil.
        // (Le libellé du lien de menu, lui, peut rester dans la sidebar.)
        $this->pdo->prepare(
            'INSERT INTO audit_logs (id, user_id, action, created_at) VALUES (?,?,?,CURRENT_TIMESTAMP)'
        )->execute(['aud_home_1', $this->rootId, 'user.login']);

        $r = $this->request('GET', '/admin', [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0));
        $body = (string) ($r['body'] ?? '');

        self::assertStringNotContainsString(
            'audit-list',
            $body,
            'La liste du journal d\'audit (ancienne carte) ne doit plus apparaître sur l\'accueil.'
        );
        self::assertStringNotContainsString(
            'user.login',
            $body,
            'Les entrées d\'audit ne doivent plus être affichées sur l\'accueil.'
        );
    }

    public function test_president_recoit_la_variante_compta(): void
    {
        // Le Président a le module compta : il voit la variante financière.
        $r = $this->request('GET', '/admin', [], [], $this->presidentId);
        self::assertSame(200, (int) ($r['code'] ?? 0));
        $body = (string) ($r['body'] ?? '');

        self::assertStringContainsString('Argent encaissé', $body, 'Le Président doit voir la variante compta de l\'accueil.');
    }

    public function test_accueil_communication_sans_euro(): void
    {
        $r = $this->request('GET', '/admin', [], [], $this->commId);
        self::assertSame(200, (int) ($r['code'] ?? 0));
        $body = (string) ($r['body'] ?? '');

        // Compteurs existants + liens rapides.
        self::assertStringContainsString('Membres actifs', $body, 'Le compteur « Membres actifs » est absent.');
        self::assertStringContainsString('Événements publiés', $body, 'Le compteur « Événements publiés » est absent.');
        self::assertStringContainsString('Vos raccourcis', $body, 'Les liens rapides sont absents.');

        // AUCUN contenu financier.
        self::assertSame(0, substr_count($body, '€'), 'L\'accueil non financier ne doit contenir aucun €.');
        self::assertStringNotContainsString('Argent encaissé', $body, 'Aucune carte compta ne doit apparaître.');
    }

    public function test_accueil_benevol_outils_sans_chiffres(): void
    {
        // Le rôle BENEVOL est créé en parallèle (lot 2 rôles). Si la
        // constante n'existe pas encore (ou si le rôle n'a pas encore
        // accès à l'espace admin), on saute avec un commentaire clair :
        // l'intégrateur réactivera ce test dès que le rôle est fusionné.
        if (!defined('App\\Core\\Auth::ROLE_BENEVOL')) {
            self::markTestSkipped(
                'Rôle BENEVOL absent d\'Auth (créé en parallèle par le lot rôles) : '
                . 'réactiver ce test dès la fusion.'
            );
        }
        if (!in_array(Auth::ROLE_BENEVOL, Permissions::adminRoles(), true)) {
            self::markTestSkipped(
                'Le rôle BENEVOL n\'est pas encore admis dans l\'espace admin '
                . '(Permissions::adminRoles) : réactiver ce test dès la fusion.'
            );
        }

        $benevolId = 'u_home_benevol';
        $this->seedUser($benevolId, 'home-benevol@exemple.fr', 'Password123456', Auth::ROLE_BENEVOL);

        $r = $this->request('GET', '/admin', [], [], $benevolId);
        self::assertSame(200, (int) ($r['code'] ?? 0), 'L\'accueil doit répondre pour un BENEVOL.');
        $body = (string) ($r['body'] ?? '');

        // Trois tuiles-liens outils.
        self::assertStringContainsString('Comptage caisse', $body, 'La tuile « Comptage caisse » est absente.');
        self::assertStringContainsString('Comptage inventaire', $body, 'La tuile « Comptage inventaire » est absente.');
        self::assertStringContainsString('/admin/wiki', $body, 'La tuile « Aide & wiki » est absente.');

        // AUCUN chiffre, AUCUN €, AUCUN compteur.
        self::assertSame(0, substr_count($body, '€'), 'L\'accueil BENEVOL ne doit contenir aucun €.');
        self::assertStringNotContainsString('Argent encaissé', $body, 'Aucune carte compta ne doit apparaître.');
        self::assertStringNotContainsString('Membres actifs', $body, 'Aucun compteur ne doit apparaître.');
    }
}
