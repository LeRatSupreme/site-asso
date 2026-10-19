<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Vérification fonctionnelle des pages retouchées :
 *  - « Opérations » : bloc unique « Pertes / Inventaire » (onglet de
 *    niveau 1 partagé, sous-onglets de niveau 2, actifs au bon endroit) ;
 *  - « Livre comptable » : vignette « Derniers tickets & dépenses » en
 *    liste FUSIONNÉE — une dépense et un achat datés d'anciens jours
 *    sont tous les deux visibles (le bug d'origine : cachés sous les
 *    achats ou au-delà des 8 premiers).
 *
 * Base `aeic_test` requise (les tests sont sautés sinon) — même
 * mécanique que AdminPagesSmokeTest : vraies requêtes simulées via le
 * runner, utilisateur racine injecté.
 */
final class OperationsLedgerPagesTest extends IntegrationTestCase
{
    private string $rootId = 'u_ops_ledger_root';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->reset(['users', 'expenses', 'purchases']);
        $this->seedUser($this->rootId, 'ops-ledger@exemple.fr', 'Password123456', 'SUPERADMIN');
    }

    /**
     * Page Opérations · Pertes : l'onglet fusionné « Pertes /
     * Inventaire » est actif, les deux sous-onglets sont rendus, seul
     * « Pertes » porte la classe active.
     */
    public function test_page_pertes_bloc_fusionne_sous_onglets(): void
    {
        $r = $this->request('GET', '/admin/compta/pertes', [], [], $this->rootId);
        $body = (string) ($r['body'] ?? '');

        self::assertSame(200, (int) ($r['code'] ?? 0));
        self::assertStringContainsString('Pertes / Inventaire', $body, 'Libellé de l\'onglet fusionné rendu.');
        self::assertStringContainsString(
            'aria-label="Pertes / Inventaire"',
            $body,
            'La sous-navigation de niveau 2 est rendue.'
        );
        self::assertStringContainsString(
            'href="https://example.test/admin/compta/inventaire-tablette"',
            $body,
            'Sous-onglet « Inventaire » présent.'
        );
        self::assertStringNotContainsString(
            'is-active" href="https://example.test/admin/compta/inventaire-tablette"',
            $body,
            'Sur la page Pertes, le sous-onglet Inventaire est inactif.'
        );
        self::assertStringContainsString(
            'op-tab is-active" href="https://example.test/admin/compta/pertes"',
            $body,
            'Onglet de niveau 1 actif (et sous-onglet Pertes actif).'
        );
    }

    /**
     * Page Opérations · Inventaire tablette : même onglet fusionné
     * actif, sous-onglet « Inventaire » actif cette fois.
     */
    public function test_page_inventaire_sous_onglet_actif(): void
    {
        $r = $this->request('GET', '/admin/compta/inventaire-tablette', [], [], $this->rootId);
        $body = (string) ($r['body'] ?? '');

        self::assertSame(200, (int) ($r['code'] ?? 0));
        self::assertStringContainsString('Pertes / Inventaire', $body);
        self::assertStringContainsString('aria-label="Pertes / Inventaire"', $body);
        self::assertStringContainsString(
            'op-tab is-active" href="https://example.test/admin/compta/inventaire-tablette"',
            $body,
            'Sous-onglet « Inventaire » actif sur sa page.'
        );
        self::assertStringContainsString(
            'op-tab is-active" href="https://example.test/admin/compta/pertes"',
            $body,
            'Onglet de niveau 1 « Pertes / Inventaire » actif (il pointe sur /pertes).'
        );
    }

    /**
     * Livre comptable : une dépense ET un achat datés d'anciens jours
     * apparaissent tous les deux dans la vignette fusionnée (et dans le
     * livre) — plus aucune liste séparée qui cache les saisies.
     */
    public function test_livre_vignette_fusionne_affiche_depense_et_achat(): void
    {
        // Dépense saisie « aujourd'hui » mais datée d'un ancien jour +
        // achat encore plus ancien : la vignette doit montrer les deux.
        $this->pdo->prepare(
            "INSERT INTO expenses (id, spent_at, category, label, amount_ttc, created_at)
             VALUES ('expense_fusion_1', '2026-10-13', 'DIVERS', 'Gobelets fusion test', 9.90, '2026-10-19 10:00:00')"
        )->execute();
        $this->pdo->prepare(
            "INSERT INTO purchases (id, purchased_at, supplier, invoice_number, product_key, quantity, total_ttc, created_at)
             VALUES ('purchase_fusion_1', '2026-10-12', 'METRO TEST', '159-99999999', 'Coca fusion test', 6, 12.30, '2026-10-12 08:00:00')"
        )->execute();

        $r = $this->request('GET', '/admin/ledger', [], [], $this->rootId);
        $body = (string) ($r['body'] ?? '');

        self::assertSame(200, (int) ($r['code'] ?? 0));

        // Vignette « Derniers tickets & dépenses » rendue.
        self::assertStringContainsString('Derniers tickets &amp; dépenses', $body);
        self::assertStringContainsString('lg-recent-table', $body);

        // La dépense ET l'achat sont visibles (liste fusionnée : plus de
        // bloc séparé qui repousse les dépenses hors champ).
        self::assertStringContainsString('Gobelets fusion test', $body, 'Dépense visible dans la vignette.');
        self::assertStringContainsString('Achat — METRO TEST', $body, 'Groupe d\'achats visible dans la vignette.');

        // …et le livre les contient aussi (période par défaut 30 jours).
        self::assertStringContainsString('Dépense — Gobelets fusion test', $body);
        self::assertStringContainsString('Coca fusion test', $body, 'Ligne ticket du livre.');
    }

    /**
     * Livre comptable sans aucune saisie : la vignette affiche l'état
     * vide proprement (pas d'onglet cassé, pas de tableau fantôme).
     */
    public function test_livre_vignette_vide_coherente(): void
    {
        $r = $this->request('GET', '/admin/ledger', [], [], $this->rootId);
        $body = (string) ($r['body'] ?? '');

        self::assertSame(200, (int) ($r['code'] ?? 0));
        self::assertStringContainsString('Aucune dépense ni achat enregistré', $body);
    }
}
