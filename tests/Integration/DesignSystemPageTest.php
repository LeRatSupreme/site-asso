<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Page de démonstration du design system (/admin/design-system).
 *
 * Contrat de rendu (lot 1) : document HTML autonome portant class="ds"
 * sur <html>, un <h2> par composant avec les libellés officiels, aucun
 * style en ligne, aucun bloc <style>, aucun dégradé, aucun emoji et
 * aucun jargon/code interne visible (WAPE, codes de règles).
 *
 * Base `aeic_test` requise (les tests sont sautés sinon).
 */
final class DesignSystemPageTest extends IntegrationTestCase
{
    public function test_la_page_est_rendue_avec_toutes_les_sections(): void
    {
        $this->requireDatabase();
        $this->reset(['users']);
        $this->seedUser('u_ds_root', 'ds-root@exemple.fr', 'Password123456', 'SUPERADMIN');

        $r = $this->request('GET', '/admin/design-system', [], [], 'u_ds_root');
        self::assertSame(200, (int) ($r['code'] ?? 0), 'La page design system doit répondre 200 pour un admin.');

        $body = (string) ($r['body'] ?? '');

        // Coquille du document autonome.
        self::assertStringContainsString('lang="fr"', $body);
        self::assertStringContainsString('class="ds"', $body);
        self::assertStringContainsString('<title>Design system', $body);

        // Un <h2> par composant, libellés exacts.
        foreach ([
            "Fil d'Ariane",
            'Carte KPI',
            "Badge d'état",
            "Bandeau d'alerte",
            'Boutons',
            'Formulaire',
            'Onglets',
            'Menu déroulant',
            'Tableau',
            'Ligne extensible',
            'Info-bulle',
            'État vide',
            'Squelette de chargement',
            'Fenêtre de confirmation',
        ] as $libelle) {
            self::assertStringContainsString($libelle, $body, "Section « $libelle » absente de la page de démonstration.");
        }
    }

    public function test_la_page_ne_contient_rien_dinterdit(): void
    {
        $this->requireDatabase();
        $this->reset(['users']);
        $this->seedUser('u_ds_root2', 'ds-root2@exemple.fr', 'Password123456', 'SUPERADMIN');

        $r = $this->request('GET', '/admin/design-system', [], [], 'u_ds_root2');
        self::assertSame(200, (int) ($r['code'] ?? 0));

        $body = (string) ($r['body'] ?? '');

        // Aucun style en ligne, aucun bloc <style>, aucun dégradé.
        self::assertStringNotContainsString('style="', $body, 'Aucun attribut style= ne doit apparaître.');
        self::assertStringNotContainsString('<style', $body, 'Aucun bloc <style> ne doit apparaître.');
        self::assertStringNotContainsString('linear-gradient', $body);
        self::assertStringNotContainsString('radial-gradient', $body);

        // Aucun emoji (échantillon suffisant).
        self::assertStringNotContainsString('💡', $body);
        self::assertStringNotContainsString('⭐', $body);
        self::assertStringNotContainsString('🚀', $body);

        // Aucun jargon ni code de règle interne.
        self::assertStringNotContainsString('WAPE', $body);
        self::assertStringNotContainsString('R1', $body);
        self::assertStringNotContainsString('R4', $body);
    }

    public function test_acces_reserve_aux_roles_admin(): void
    {
        $this->requireDatabase();
        $this->reset(['users']);
        $this->seedUser('u_ds_eleve', 'ds-eleve@exemple.fr', 'Password123456', 'ELEVE');

        // Invité : redirection vers la connexion.
        $r = $this->request('GET', '/admin/design-system');
        self::assertSame(302, (int) ($r['code'] ?? 0), 'Un invité doit être redirigé vers la connexion.');
        self::assertStringContainsString('/login', $this->location($r));

        // Élève : refus (redirection avec message, jamais la page).
        $r = $this->request('GET', '/admin/design-system', [], [], 'u_ds_eleve');
        $code = (int) ($r['code'] ?? 0);
        self::assertNotSame(200, $code, 'Un élève ne doit pas voir la page.');
        self::assertStringNotContainsString('<title>Design system', (string) ($r['body'] ?? ''));
    }
}
