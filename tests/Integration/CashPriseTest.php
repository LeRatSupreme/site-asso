<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\Admin\AdminLedgerController;
use App\Models\Model;

/**
 * Chantier « kiosque : prise d'argent chez soi » — flux COMPLETS :
 *
 *  1. POST kiosque /kiosque/admin/caisse/prise/{token} : identité
 *     obligatoire, mauvais jeton 403, écriture PRISE + coupures
 *     transactionnelle et signée, confirmation avec solde caisse et
 *     solde chez soi, retour (PRISE positif) ;
 *  2. POST admin /admin/caisses/prise (page Caisses) : MÊME écriture
 *     via le même service, aucune logique dupliquée ;
 *  3. Traçabilité du liquide : AdminLedgerController::cashTraceability
 *     (Q1–Q3 de database/queries/traceabilite_liquide.sql) liste les
 *     mouvements avec sous-lignes coupures, soldes chez soi = −Σ(PRISE) ;
 *  4. Le livre n'est PAS impacté : total_debit/total_credit/balance de
 *     buildEntries() strictement inchangés par une prise (une prise
 *     n'est ni recette ni dépense de l'asso) ;
 *  5. Non-régression dépôt banque : dépôt avec coupures cohérentes
 *     attachées, incohérentes refusées sans écriture.
 *
 * Base `aeic_test` requise (les tests sont sautés sinon).
 */
final class CashPriseTest extends IntegrationTestCase
{
    private string $rootId    = 'u_cash_prise_root';
    private string $tokenAdmin = 'jetonadmin-cash-prise';

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->requireDatabase();

        // Migration « prise chez soi » (ENUM PRISE + cash_movement_breakdown),
        // idempotente — commentaires « -- » retirés avant le découpage.
        $sql = (string) file_get_contents(dirname(__DIR__) . '/../database/migrations/2026_cash_prise.sql');
        $sql = (string) preg_replace('/^--.*$/m', '', $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt !== '') {
                try {
                    $pdo->exec($stmt);
                } catch (\Throwable) {
                    // Déjà appliqué : non bloquant.
                }
            }
        }

        $this->reset([
            'users',
            'sales',
            'expenses',
            'purchases',
            'cash_movements',
            'cash_counts',
            'cash_movement_breakdown',
            'audit_logs',
        ]);
        $this->seedUser($this->rootId, 'cash-prise-root@exemple.fr', 'Password123456', 'SUPERADMIN');

        // Jeton admin kiosque (accès depot + prise).
        $pdo->prepare(
            'INSERT INTO settings (id, `key`, value) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)'
        )->execute(['set_cash_prise_a', 'admin_kiosk_token', $this->tokenAdmin]);

        // Les modèles branchés sur la même base que les requêtes HTTP
        // (assemblages de données testés en process).
        Model::setTestPdo($pdo);
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
        parent::tearDown();
    }

    /** Détail 3×20€ + 5×2€ + 12×50c = 76,00 €. */
    private function postCoupures(): array
    {
        return [
            'qty_2000' => '3',
            'qty_200'  => '5',
            'qty_50'   => '12',
        ];
    }

    private function identite(): array
    {
        return ['who_prenom' => 'Jean', 'who_nom' => 'Dupont', 'who_alias' => 'trésorier'];
    }

    private function flash(array $response): ?array
    {
        $flash = $response['session']['_flash'] ?? [];

        return is_array($flash) && $flash !== [] ? end($flash) : null;
    }

    public function test_prise_kiosque_sans_identite_refusee_sans_ecriture(): void
    {
        $r = $this->request('POST', '/kiosque/admin/caisse/prise/' . $this->tokenAdmin, $this->postCoupures());

        $last = $this->flash($r);
        self::assertNotNull($last, 'Un refus doit produire un flash.');
        self::assertSame('error', $last['type'] ?? '');
        self::assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM cash_movements')->fetchColumn(),
            'Aucune prise ne doit être créée sans identité.'
        );
        self::assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM cash_movement_breakdown')->fetchColumn()
        );
    }

    public function test_prise_kiosque_mauvais_jeton_403(): void
    {
        $r = $this->request('POST', '/kiosque/admin/caisse/prise/jetonnementinvalide', $this->postCoupures() + $this->identite());

        self::assertSame(403, (int) ($r['code'] ?? 0));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM cash_movements')->fetchColumn());

        // Le jeton MEMBRES (bénévole) n'a pas non plus la tuile prise.
        $this->pdo->prepare(
            'INSERT INTO settings (id, `key`, value) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)'
        )->execute(['set_cash_prise_m', 'reappro_kiosk_token', 'jetonmembres-cash-prise']);
        $rM = $this->request('POST', '/kiosque/admin/caisse/prise/jetonmembres-cash-prise', $this->postCoupures() + $this->identite());
        self::assertSame(403, (int) ($rM['code'] ?? 0), 'Le jeton membres ne doit pas ouvrir la prise.');
    }

    public function test_prise_kiosque_sans_coupures_refusee(): void
    {
        $r = $this->request('POST', '/kiosque/admin/caisse/prise/' . $this->tokenAdmin, $this->identite());

        $last = $this->flash($r);
        self::assertSame('error', $last['type'] ?? '');
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM cash_movements')->fetchColumn());
    }

    public function test_prise_kiosque_ecrit_mouvement_signe_coupures_et_audit(): void
    {
        // Fond de caisse initial pour un solde lisible.
        $this->pdo->prepare(
            "INSERT INTO cash_movements (id, type, amount, label, created_by) VALUES ('cash_fond_1','FOND',100.00,'fond','test@aeic.fr')"
        )->execute();

        $r = $this->request('POST', '/kiosque/admin/caisse/prise/' . $this->tokenAdmin, $this->postCoupures() + $this->identite() + [
            'label' => 'coffre maison',
        ]);

        $last = $this->flash($r);
        self::assertNotNull($last, 'Aucun flash après la prise.');
        self::assertSame('success', $last['type'] ?? '');
        $msg = (string) ($last['message'] ?? '');
        self::assertStringContainsString('76,00', $msg, 'La confirmation affiche le total pris.');
        self::assertStringContainsString('24,00', $msg, 'La confirmation affiche le solde en caisse restant (100 − 76).');
        self::assertStringContainsString('76,00', $msg, 'La confirmation affiche le solde pris chez soi.');

        // Mouvement PRISE négatif, signé, jamais mélangé avec les ventes.
        $row = $this->pdo->query(
            "SELECT id, type, amount, label, created_by FROM cash_movements WHERE type = 'PRISE'"
        )->fetch();
        self::assertNotFalse($row, 'Le mouvement PRISE est absent.');
        self::assertSame(-76.0, (float) $row['amount']);
        self::assertSame('coffre maison', $row['label']);
        self::assertSame('Jean Dupont (trésorier)', $row['created_by'], 'La prise est signée (qui a pris).');

        // Coupures attachées, total exact en centimes entiers.
        $stmt = $this->pdo->prepare('SELECT unit_cents, qty FROM cash_movement_breakdown WHERE movement_id = ?');
        $stmt->execute([(string) $row['id']]);
        $map = [];
        foreach ($stmt->fetchAll() as $b) {
            $map[(int) $b['unit_cents']] = (int) $b['qty'];
        }
        self::assertSame([50 => 12, 200 => 5, 2000 => 3], $map);

        // Audit dédié, marqué kiosque.
        $audit = $this->pdo->query(
            "SELECT details FROM audit_logs WHERE action = 'cash.prise'"
        )->fetch();
        self::assertNotFalse($audit, 'L\'audit cash.prise est absent.');
        self::assertStringContainsString('"sens":"prise"', (string) $audit['details']);
        self::assertStringContainsString('kiosque', (string) $audit['details']);
        self::assertStringContainsString('3×20€ · 5×2€ · 12×50c', (string) $audit['details']);
    }

    public function test_retour_kiosque_est_une_prise_positive(): void
    {
        $r = $this->request('POST', '/kiosque/admin/caisse/prise/' . $this->tokenAdmin, $this->postCoupures() + $this->identite() + [
            'sens' => 'retour',
        ]);

        $last = $this->flash($r);
        self::assertSame('success', $last['type'] ?? '');

        $row = $this->pdo->query("SELECT amount, label FROM cash_movements WHERE type = 'PRISE'")->fetch();
        self::assertSame(76.0, (float) $row['amount'], 'Un retour est une PRISE POSITIVE (ramené en caisse).');
        self::assertSame('Ramené de chez soi', $row['label'], 'Libellé par défaut du retour.');

        // Solde « chez soi » = −Σ(PRISE) : un retour isolé est négatif.
        $row = $this->pdo->query("SELECT -SUM(amount) AS chez_soi FROM cash_movements WHERE type = 'PRISE'")->fetch();
        self::assertSame(-76.0, (float) $row['chez_soi']);
    }

    public function test_prise_admin_memes_ecritures_que_le_kiosque(): void
    {
        $r = $this->request('POST', '/admin/caisses/prise', $this->postCoupures(), [], $this->rootId);

        $last = $this->flash($r);
        self::assertSame('success', $last['type'] ?? '');

        $row = $this->pdo->query("SELECT type, amount, created_by FROM cash_movements WHERE type = 'PRISE'")->fetch();
        self::assertSame(-76.0, (float) $row['amount']);
        self::assertSame('cash-prise-root@exemple.fr', $row['created_by'], 'Côté admin, la prise est signée par l\'email du compte.');

        $audit = $this->pdo->query("SELECT details FROM audit_logs WHERE action = 'cash.prise'")->fetch();
        self::assertNotFalse($audit);
        self::assertStringNotContainsString('kiosque', (string) $audit['details'], 'Audit admin sans marque via kiosque.');
    }

    public function test_traceabilite_du_livre_liste_les_mouvements_sans_toucher_aux_totaux(): void
    {
        // Une vente, une dépense (le livre a du contenu), une prise, un dépôt.
        $this->pdo->prepare(
            "INSERT INTO sales (id, transaction_ref, sold_at, payment_method, payment_raw, quantity, description, price_ttc, is_custom_amount)
             VALUES ('s_prise_1','TPRISE1', NOW(), 'LIQUIDE', 'Espèces', 1, 'café prise', 2.00, 0)"
        )->execute();
        $this->pdo->prepare(
            "INSERT INTO expenses (id, spent_at, category, label, amount_ttc, created_by)
             VALUES ('exp_prise_1', CURDATE(), 'DIVERS', 'Essuie-tout', 5.00, 'test@aeic.fr')"
        )->execute();

        // Totaux du livre AVANT la prise (le dépôt banque et ses frais
        // bancaires, eux, SONT des écritures du livre : posés avant la
        // mesure pour l'isoler — seule la prise doit être neutre).
        $from = date('Y-m-d', strtotime('-1 day'));
        $to   = date('Y-m-d', strtotime('+1 day'));
        $ledger = new AdminLedgerController();

        // Un dépôt banque AVEC coupures cohérentes (20,00 € = 1×20€).
        $rDep = $this->request('POST', '/kiosque/admin/caisse/depot/' . $this->tokenAdmin, [
            'amount' => '20,00', 'qty_2000' => '1',
        ] + $this->identite());
        self::assertSame('success', $this->flash($rDep)['type'] ?? '');

        $avant = $ledger->buildEntries($from, $to);

        // La prise kiosque (76,00 € en coupures).
        $r = $this->request('POST', '/kiosque/admin/caisse/prise/' . $this->tokenAdmin, $this->postCoupures() + $this->identite());
        self::assertSame('success', $this->flash($r)['type'] ?? '');

        // Totaux du livre APRÈS : strictement inchangés.
        $apres = $ledger->buildEntries($from, $to);
        self::assertSame($avant['total_debit'], $apres['total_debit'], 'Le total débit ne doit pas intégrer les mouvements de caisse.');
        self::assertSame($avant['total_credit'], $apres['total_credit'], 'Le total crédit ne doit pas intégrer les mouvements de caisse.');
        self::assertSame($avant['balance'], $apres['balance'], 'Le solde de trésorerie recopié au livret papier doit rester inchangé.');

        // Section « Traçabilité du liquide » (Q1–Q3) : liste chronologique,
        // sous-ligne coupures, soldes de l'en-tête. L'ordre intra-seconde
        // dépend du tie-break d'id aléatoires : on repère PAR TYPE.
        $trace = AdminLedgerController::cashTraceability($from, $to);
        self::assertCount(2, $trace['movements'], 'FOND absent : PRISE + DEPOT attendus.');
        $byType = [];
        foreach ($trace['movements'] as $mvt) {
            $byType[$mvt['type']] = $mvt;
        }
        $prise = $byType['PRISE'];
        self::assertSame('PRISE', $prise['type']);
        self::assertSame('Prise chez soi', $prise['type_label']);
        self::assertSame(-76.0, $prise['amount']);
        self::assertSame('Jean Dupont (trésorier)', $prise['created_by']);
        self::assertSame('3×20€ · 5×2€ · 12×50c', $prise['coupures'], 'Sous-ligne coupures formatée, grosses coupures d\'abord.');

        $depot = $byType['DEPOT'];
        self::assertSame('DEPOT', $depot['type']);
        self::assertSame('Dépôt banque', $depot['type_label']);
        self::assertSame(-20.0, $depot['amount']);
        self::assertSame('1×20€', $depot['coupures'], 'Le détail des coupures est aussi attaché aux dépôts.');

        self::assertSame(76.0, $trace['pris'], 'En-tête : pris = Σ|PRISE| de la période.');
        self::assertSame(20.0, $trace['depose'], 'En-tête : déposé = Σ|DEPOT| de la période.');
        self::assertSame(76.0, $trace['chez_soi'], 'Solde chez soi = −Σ(PRISE), toutes périodes.');

        // Contrôle de cohérence Q4 : tout détail coupures retombe sur
        // |amount| — résultat attendu VIDE.
        $incoherents = $this->pdo->query(
            'SELECT m.id FROM cash_movements AS m
             LEFT JOIN cash_movement_breakdown AS b ON b.movement_id = m.id
             GROUP BY m.id, m.type, m.amount
             HAVING COUNT(b.movement_id) > 0
                AND ABS(m.amount) * 100 <> COALESCE(SUM(b.unit_cents * b.qty), 0)'
        )->fetchAll();
        self::assertSame([], $incoherents, 'Q4 : un détail coupures diffère du montant du mouvement.');
    }

    public function test_depot_avec_coupures_incoherentes_refuse_sans_ecriture(): void
    {
        // Détail 20,00 € pour un montant saisi 25,00 € : refus kiosque.
        $r = $this->request('POST', '/kiosque/admin/caisse/depot/' . $this->tokenAdmin, [
            'amount' => '25,00', 'qty_2000' => '1',
        ] + $this->identite());

        $last = $this->flash($r);
        self::assertSame('error', $last['type'] ?? '');
        self::assertStringContainsString('coupures', (string) ($last['message'] ?? ''));
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM cash_movements WHERE type = 'DEPOT'")->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn(), 'Pas de frais bancaires non plus.');
    }
}
