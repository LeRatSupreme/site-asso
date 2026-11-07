<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\Admin\AdminLedgerController;
use App\Controllers\KioskComptageController;

/**
 * Fusion « Saisir une dépense » × « Achats » — backend (invariant :
 * UN ticket = UNE écriture, aucun POST ne crée à la fois une expense
 * et des purchases) :
 *
 *  (a) volet facture à lignes (page unifiée /admin/compta/achats) :
 *      POST /admin/compta/achats/save-bulk 2 lignes → 2 purchases,
 *      0 expense ;
 *  (b) volet frais divers : POST /admin/compta/depenses/save → 1
 *      expense, 0 purchase ;
 *  (c) livre comptable lecture stricte (contrôleur) : plus de données
 *      de saisie d'achats injectées par le contrôleur, surface d'écriture
 *      retirée (méthode + endpoint kiosque) ;
 *  (d) POST forgé /kiosque/admin/ledger/purchases/{token} → 404, aucune
 *      écriture purchases ;
 *  (e) non-régression dépôt banque kiosque : frais bancaires FRAIS
 *      auto-créés, aucun achat ;
 *  + saisie express kiosque D5 : dépense seule toujours fonctionnelle.
 *
 * Base `aeic_test` requise (les tests sont sautés sinon).
 */
final class ComptaSaisieUnifieeTest extends IntegrationTestCase
{
    private string $rootId     = 'u_saisie_unifiee_root';
    private string $tokenAdmin = 'jeton-saisie-unifiee-admin';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->reset([
            'users',
            'settings',
            'expenses',
            'purchases',
            'product_costs',
            'product_stocks',
            'sales',
            'cash_movements',
            'audit_logs',
        ]);
        $this->seedUser($this->rootId, 'saisie-unifiee@exemple.fr', 'Password123456', 'SUPERADMIN');

        // Jeton admin kiosque (accès ledger + depot).
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (id, `key`, value) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );
        $stmt->execute(['set_su_a', 'admin_kiosk_token', $this->tokenAdmin]);
    }

    /** Marqueurs d'erreur PHP dans un corps de page rendu. */
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

    /** (a) Volet « Facture à lignes » de la page unifiée : 2 lignes → 2 purchases, 0 expense. */
    public function test_volet_a_facture_deux_lignes_cree_deux_achats_et_aucune_depense(): void
    {
        $r = $this->request('POST', '/admin/compta/achats/save-bulk', [
            'purchased_at'   => '2026-11-05',
            'supplier'       => 'METRO',
            'invoice_number' => '0/0(087)0099/TEST1',
            'amount_basis'   => 'ttc',
            'vat_rate'       => '',
            'product_key'    => ['Coca saisie unifiée', 'Fanta saisie unifiée'],
            'quantity'       => [6, 4],
            'total_amount'   => ['12,60', '8,40'],
        ], [], $this->rootId);

        // PRG : redirection vers la page unifiée.
        self::assertSame(302, (int) ($r['code'] ?? 0), 'La saisie standard redirige (PRG).');
        self::assertStringContainsString('/admin/compta/achats', $this->location($r));

        // Deux achats, zéro dépense : le volet facture n'écrit QUE purchases.
        $purchases = $this->pdo->query(
            "SELECT product_key, quantity, total_ttc, supplier, invoice_number
             FROM purchases ORDER BY product_key"
        )->fetchAll();
        self::assertCount(2, $purchases, 'Deux lignes de facture → deux achats.');
        self::assertSame('Coca saisie unifiée', (string) $purchases[0]['product_key']);
        self::assertSame(6, (int) $purchases[0]['quantity']);
        self::assertSame(12.60, (float) $purchases[0]['total_ttc']);
        self::assertSame('Fanta saisie unifiée', (string) $purchases[1]['product_key']);
        self::assertSame(8.40, (float) $purchases[1]['total_ttc']);
        self::assertSame('0/0(087)0099/TEST1', (string) $purchases[0]['invoice_number']);

        self::assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn(),
            'Invariant : une facture à lignes ne crée JAMAIS de dépense.'
        );
    }

    /** (b) Volet « Frais divers » : 1 expense, 0 purchase. */
    public function test_volet_b_frais_divers_cree_une_depense_et_aucun_achat(): void
    {
        $r = $this->request('POST', '/admin/compta/depenses/save', [
            'return_to'    => '/admin/compta/achats',
            'spent_at'     => '2026-11-05',
            'category'     => 'DIVERS',
            'label'        => 'Frais divers test fusion',
            'amount'       => '36,60',
            'amount_basis' => 'ttc',
        ], [], $this->rootId);

        self::assertSame(302, (int) ($r['code'] ?? 0), 'La saisie d\'une dépense redirige (PRG).');
        self::assertStringContainsString('/admin/compta/achats', $this->location($r), 'Retour vers la page unifiée.');

        $expenses = $this->pdo->query(
            'SELECT label, category, amount_ttc FROM expenses'
        )->fetchAll();
        self::assertCount(1, $expenses, 'Une dépense créée.');
        self::assertSame('Frais divers test fusion', (string) $expenses[0]['label']);
        self::assertSame('DIVERS', (string) $expenses[0]['category']);
        self::assertSame(36.60, (float) $expenses[0]['amount_ttc']);

        self::assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM purchases')->fetchColumn(),
            'Invariant : des frais divers ne créent JAMAIS d\'achat.'
        );
    }

    /**
     * (c) Livre comptable lecture stricte — côté CONTRÔLEUR : la surface
     * de saisie d'achats du livre est retirée (plus de suggestions produits
     * injectées, plus de méthode d'écriture), et les deux versions de la
     * page (admin + kiosque) se rendent proprement. La purge du panneau
     * dans la vue relève du frontend.
     */
    public function test_livre_lecture_stricte_controleur_sans_surface_de_saisie(): void
    {
        // Le contrôleur du livre n'expose plus les suggestions produits du
        // panneau saisie, ni le kiosque l'endpoint d'achats en lot.
        self::assertFalse(
            method_exists(AdminLedgerController::class, 'purchaseProductKeys'),
            'AdminLedgerController ne doit plus fournir les clés produits du panneau saisie.'
        );
        self::assertFalse(
            method_exists(KioskComptageController::class, 'ledgerPurchasesSave'),
            'L\'endpoint d\'achats en lot kiosque doit être supprimé du contrôleur.'
        );

        // Page admin : rendue propre, sans liste de produits injectée
        // (le payload datalist du panneau saisie, s'il reste rendu par la
        // vue en transition, est vide).
        $r = $this->request('GET', '/admin/ledger', [], [], $this->rootId);
        $body = (string) ($r['body'] ?? '');
        self::assertSame(200, (int) ($r['code'] ?? 0), 'Le livre admin répond 200.');
        self::assertNull($this->bodyError($body), 'Le livre admin se rend sans erreur PHP.');
        self::assertSame(
            0,
            preg_match('/data-purchase-products="\[[^]]/', $body),
            'Aucune suggestion produit injectée par le contrôleur du livre.'
        );

        // Page kiosque (vue partagée) : idem.
        $rk = $this->request('GET', '/kiosque/admin/ledger/' . $this->tokenAdmin);
        $bodyK = (string) ($rk['body'] ?? '');
        self::assertSame(200, (int) ($rk['code'] ?? 0), 'Le livre kiosque répond 200.');
        self::assertNull($this->bodyError($bodyK), 'Le livre kiosque se rend sans erreur PHP.');
        self::assertSame(
            0,
            preg_match('/data-purchase-products="\[[^]]/', $bodyK),
            'Aucune suggestion produit injectée côté kiosque.'
        );
    }

    /**
     * (d) POST forgé vers l'ancien endpoint d'achats kiosque : 404 (route
     * supprimée), avec un jeton valide comme invalide — et AUCUNE écriture
     * purchases, même avec une charge utile complète.
     *
     * @return array<string,mixed> Charge utile d'achat complète (2 lignes).
     */
    private function purchasePayload(): array
    {
        return [
            'purchased_at'   => '2026-11-05',
            'supplier'       => 'METRO',
            'invoice_number' => '0/0(087)0099/FORGE',
            'amount_basis'   => 'ttc',
            'product_key'    => ['Coca forgé', 'Fanta forgé'],
            'quantity'       => [6, 4],
            'total_amount'   => ['12,60', '8,40'],
            'vat_rate'       => ['5.5', '5.5'],
            'receipt_path'   => 'uploads/receipts/forge.pdf',
        ];
    }

    public function test_post_forge_kiosque_ledger_purchases_jetons_tous_404(): void
    {
        // Jeton ADMIN valide (l'ancienne garde aurait laissé passer).
        $r = $this->request('POST', '/kiosque/admin/ledger/purchases/' . $this->tokenAdmin, $this->purchasePayload());
        self::assertSame(404, (int) ($r['code'] ?? 0), 'POST forgé avec jeton valide : route supprimée → 404.');

        // Jeton inconnu.
        $r2 = $this->request('POST', '/kiosque/admin/ledger/purchases/jeton-nimporte', $this->purchasePayload());
        self::assertSame(404, (int) ($r2['code'] ?? 0), 'POST avec jeton inconnu : 404.');

        // Aucune écriture possible depuis un jeton kiosque.
        self::assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM purchases')->fetchColumn(),
            'Aucun achat ne doit pouvoir être créé depuis un jeton kiosque.'
        );
        self::assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn()
        );
    }

    /** (e) Non-régression dépôt banque kiosque : mouvement DEPOT + dépense FRAIS auto, zéro achat. */
    public function test_depot_banque_kiosque_cree_les_frais_bancaires(): void
    {
        $r = $this->request('POST', '/kiosque/admin/caisse/depot/' . $this->tokenAdmin, [
            'who_prenom' => 'Jean',
            'who_nom'    => 'Test',
            'who_alias'  => 'trésorier',
            'amount'     => '250,00',
            'label'      => 'Dépôt test fusion',
        ]);
        self::assertSame(302, (int) ($r['code'] ?? 0), 'Le dépôt redirige après enregistrement.');

        // Frais bancaires auto-créés (catégorie FRAIS — lus par la
        // trésorerie via Expense::depositFeesTotal) : seule dépense.
        $expenses = $this->pdo->query('SELECT category, label, amount_ttc FROM expenses')->fetchAll();
        self::assertCount(1, $expenses, 'Le dépôt crée exactement la dépense de frais bancaires.');
        self::assertSame('FRAIS', (string) $expenses[0]['category'], 'Les frais de dépôt restent en catégorie FRAIS.');
        self::assertStringContainsString('Frais de dépôt banque', (string) $expenses[0]['label']);
        self::assertSame(3.50, (float) $expenses[0]['amount_ttc']);

        // Mouvement DEPOT (sortie de liquide) créé.
        $depot = $this->pdo->query("SELECT type, amount FROM cash_movements WHERE type = 'DEPOT'")->fetchAll();
        self::assertCount(1, $depot, 'Un mouvement DEPOT est créé.');
        self::assertSame(-250.00, (float) $depot[0]['amount']);

        // Invariant : un dépôt n'écrit jamais dans purchases.
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM purchases')->fetchColumn());
    }

    /** (D5) Saisie express kiosque : dépense seule toujours fonctionnelle. */
    public function test_kiosque_saisie_express_depense_seule_fonctionne(): void
    {
        $r = $this->request('POST', '/kiosque/admin/ledger/depense/' . $this->tokenAdmin, [
            'who_prenom'   => 'Marie',
            'who_nom'      => 'Test',
            'who_alias'    => 'trésorier',
            'spent_at'     => '2026-11-05',
            'label'        => 'Gobelets express kiosque',
            'amount'       => '9,90',
            'amount_basis' => 'ttc',
            'category'     => 'DIVERS',
        ]);
        self::assertSame(302, (int) ($r['code'] ?? 0), 'La saisie express redirige après enregistrement.');

        $expenses = $this->pdo->query(
            'SELECT label, category, amount_ttc, created_by FROM expenses'
        )->fetchAll();
        self::assertCount(1, $expenses, 'Une dépense créée par la saisie express.');
        self::assertSame('Gobelets express kiosque', (string) $expenses[0]['label']);
        self::assertSame('DIVERS', (string) $expenses[0]['category']);
        self::assertSame(9.90, (float) $expenses[0]['amount_ttc']);
        self::assertStringContainsString('kiosque — Marie Test', (string) $expenses[0]['created_by'], 'Trace kiosque conservée.');

        self::assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM purchases')->fetchColumn(),
            'La saisie express kiosque n\'écrit QUE la dépense (jamais d\'achats).'
        );
    }
}
