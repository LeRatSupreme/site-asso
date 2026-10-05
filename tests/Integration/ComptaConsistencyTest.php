<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Cohérence inter-pages de la comptabilité : les mêmes données doivent
 * produire les mêmes chiffres partout (bilan annuel, analytics, dashboard
 * sumup, dépenses) et chaque écriture doit se répercuter sur toutes les
 * pages concernées.
 *
 * Scénarios :
 *   1. L'export CSV du bilan annuel reflète exactement les ventes et
 *      dépenses saisies en base (par mois et en total, résultat net
 *      inclus) — c'est LA page de référence du bureau.
 *   2. La page Analytics, filtrée sur la même période personnalisée,
 *      affiche le même CA que le bilan.
 *   3. Un dépôt à la banque génère bien : le mouvement de caisse (−
 *      montant déposé), la dépense de frais bancaires (3,50 €) ET la
 *      Majoration des dépenses dans le bilan annuel.
 *   4. La page Inventaire valorise le stock (Σ stock théorique × coût du
 *      lot en cours) et signale les produits sans coût saisi.
 *   5. Cycle de vie automatique : pause auto + stock → reprise ; en vente
 *      à stock 0 depuis ≥ 7 jours → pause ; pauses manuelles intouchées.
 *   6. Le drapeau « stock infini » (Réappro) exclut un produit « à
 *      compter » de la commande.
 *
 * Base `aeic_test` requise (les tests sont sautés sinon).
 */
final class ComptaConsistencyTest extends IntegrationTestCase
{
    private string $rootId = 'u_compta_root';

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->requireDatabase();
        $this->reset([
            'sale_adjustments',
            'sales',
            'import_batches',
            'expenses',
            'cash_movements',
            'cash_counts',
            'inventory_counts',
            'product_costs',
            'product_discontinued',
            'product_zero_since',
            'product_infinite',
            'product_packs',
            'users',
        ]);
        $this->seedUser($this->rootId, 'compta-root@exemple.fr', 'Password123456', 'SUPERADMIN');

        // Ventes : juin = 25,00 € (2 lignes), septembre = 100,00 € (1 ligne).
        $this->insertSale('s_cons_j1', 'TCONS001', '2026-06-06 10:00:00', '10.00');
        $this->insertSale('s_cons_j2', 'TCONS002', '2026-06-20 12:00:00', '15.00');
        $this->insertSale('s_cons_s1', 'TCONS003', '2026-09-15 12:30:00', '100.00');

        // Dépenses : juin 5,00 € · septembre 12,50 + 3,50 (frais) = 16,00 €.
        $this->insertExpense('exp_cons_j1', '2026-06-10', 'MATIERE', 'Gobelets', '5.00');
        $this->insertExpense('exp_cons_s1', '2026-09-10', 'MATIERE', 'Cafetière', '12.50');
        $this->insertExpense('exp_cons_s2', '2026-09-12', 'FRAIS', 'Frais bancaires', '3.50');
    }

    public function test_inventaire_affiche_la_valeur_du_stock(): void
    {
        // Stock : « café » compté 10 u. avec un coût de 2,50 € (lot en cours)
        // → 25,00 € valorisés. « thé » compté 4 u. sans coût saisi → compté
        // dans les unités mais signalé « sans coût », hors total.
        $this->insertCount('inv_cons_1', '2026-09-20 10:00:00', 'café', 10);
        $this->insertCount('inv_cons_2', '2026-09-20 10:05:00', 'thé', 4);

        $this->pdo->prepare(
            'INSERT INTO product_costs (id, product_key, cost_price, valid_from) VALUES (?,?,?,?)'
        )->execute(['pc_cons_1', 'café', '2.500', '2026-01-01']);

        $r = $this->request('GET', '/admin/compta/inventaire', [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0), 'La page Inventaire doit répondre.');
        $body = (string) ($r['body'] ?? '');

        self::assertStringContainsString('Valeur du stock', $body, 'Le bloc « Valeur du stock » est absent de l\'Inventaire.');
        self::assertStringContainsString('25,00', $body, 'La valeur du stock ne correspond pas à Σ (stock × coût du lot en cours).');
        self::assertStringContainsString('14 u.', $body, 'Le total d\'unités en stock est erroné (10 + 4 attendus).');
        // Le libellé « coût manquant » est éclaté sur plusieurs balises HTML :
        // on vérifie ses fragments plutôt qu'une phrase continue.
        self::assertStringContainsString('Coût manquant', $body, 'Le bloc « Coût manquant » est absent.');
        self::assertStringContainsString('sans coût saisi', $body, 'Les produits sans coût saisi ne sont pas signalés.');
    }

    public function test_cycle_automatique_pause_et_reprise_selon_stock(): void
    {
        // A) Pause AUTOMATIQUE + stock reconstitué → remise en vente.
        $this->insertCount('inv_auto_1', '2026-01-01 10:00:00', 'bonbon', 12);
        $this->markPaused('bonbon', 'auto');

        // B) Produit en vente à stock 0 depuis 8 jours → mise en pause auto.
        $this->insertCount('inv_auto_2', '2026-01-01 10:05:00', 'chips', 0);
        $this->markZeroSince('chips', 8);

        // C) Produit en vente à stock 0 récent (1 jour) → suivi seulement.
        $this->insertCount('inv_auto_3', '2026-01-01 10:10:00', 'fanta', 0);
        $this->markZeroSince('fanta', 1);

        // D) Pause MANUELLE (saisonnière) avec stock → jamais reprise ici.
        $this->insertCount('inv_auto_4', '2026-01-01 10:15:00', 'redbull_ete', 30);
        $this->markPaused('redbull_ete', 'u_manuel');

        $r = $this->request('GET', '/admin/compta/inventaire', [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0), 'La page Inventaire doit répondre.');
        $body = (string) ($r['body'] ?? '');
        self::assertStringContainsString('Automatique', $body, 'Le bandeau du cycle automatique est absent.');

        // A) « bonbon » repris : drapeau levé.
        self::assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM product_discontinued WHERE product_key = 'bonbon'")->fetchColumn(),
            'Une pause automatique avec du stock doit être levée.'
        );

        // B) « chips » mis en pause automatiquement, suivi à zéro nettoyé.
        self::assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM product_discontinued WHERE product_key = 'chips' AND updated_by = 'auto'")->fetchColumn(),
            'Un produit à stock 0 depuis 8 jours doit être mis en pause automatiquement.'
        );
        self::assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM product_zero_since WHERE product_key = 'chips'")->fetchColumn(),
            'Le suivi « à zéro depuis » doit être nettoyé après la pause.'
        );

        // C) « fanta » : seulement suivi, pas encore 7 jours.
        self::assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM product_discontinued WHERE product_key = 'fanta'")->fetchColumn(),
            'Un produit à stock 0 depuis moins de 7 jours ne doit pas être mis en pause.'
        );
        self::assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM product_zero_since WHERE product_key = 'fanta'")->fetchColumn(),
            'Le début du stock à zéro doit être suivi.'
        );

        // D) « redbull_ete » : pause manuelle conservée malgré le stock.
        self::assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM product_discontinued WHERE product_key = 'redbull_ete' AND updated_by = 'u_manuel'")->fetchColumn(),
            'Une pause manuelle (saisonnière) ne doit jamais être levée automatiquement.'
        );
    }

    public function test_reappro_marquage_stock_infini_exclut_de_la_commande(): void
    {
        // Vente du jour d'un produit jamais compté : « à compter », besoin
        // complet proposé par défaut.
        $this->insertSale('s_inf_1', 'TINF001', date('Y-m-d 12:00:00'), '2.00', 'siropinfini');

        $r1 = $this->request('GET', '/admin/compta/reappro', [], [], $this->rootId);
        self::assertSame(200, (int) ($r1['code'] ?? 0));
        $body1 = (string) ($r1['body'] ?? '');
        self::assertMatchesRegularExpression(
            '/data-name="siropinfini"[^>]*data-state="unknown"/',
            $body1,
            'Le produit sans comptage doit être « à compter » (unknown) avant marquage.'
        );

        // Marquage « stock infini » depuis la ligne du tableau.
        $r2 = $this->request('POST', '/admin/compta/reappro/infinite', ['product_key' => 'siropinfini'], [], $this->rootId);
        $flash = $r2['session']['_flash'] ?? [];
        $last  = is_array($flash) && $flash !== [] ? end($flash) : null;
        self::assertSame('success', $last['type'] ?? '', 'La bascule doit produire un flash de succès.');
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM product_infinite WHERE product_key = 'siropinfini'")->fetchColumn());

        $r3 = $this->request('GET', '/admin/compta/reappro', [], [], $this->rootId);
        $body3 = (string) ($r3['body'] ?? '');
        self::assertMatchesRegularExpression(
            '/data-name="siropinfini"[^>]*data-state="ok"/',
            $body3,
            'Le produit « stock infini » doit passer en état OK.'
        );
        self::assertMatchesRegularExpression(
            '/data-name="siropinfini"[^>]*data-toorder="0"/',
            $body3,
            'Le produit « stock infini » ne doit jamais être proposé à la commande.'
        );
        self::assertStringContainsString('Stock infini (marqué manuellement)', $body3);
    }

    public function test_reappro_arrondit_au_pack_dachat(): void
    {
        // 3 ventes de « cola » aujourd'hui, jamais compté : besoin brut 2
        // (3 ventes / 14 jours × 7 jours d'horizon, arrondi au-dessus).
        for ($i = 1; $i <= 3; $i++) {
            $this->insertSale('s_pack_' . $i, 'TPACK0' . $i, date('Y-m-d ') . sprintf('12:0%d:00', $i), '1.50', 'cola');
        }

        // Sans pack : à commander = besoin brut (2).
        $r1 = $this->request('GET', '/admin/compta/reappro', [], [], $this->rootId);
        self::assertMatchesRegularExpression(
            '/data-name="cola"[^>]*data-toorder="2"/',
            (string) ($r1['body'] ?? ''),
            'Sans pack, « à commander » doit être le besoin brut.'
        );

        // Pack de 12 défini en base : 2 → arrondi à 12, avec l'info « besoin ».
        $this->pdo->prepare('INSERT INTO product_packs (product_key, pack_size, updated_by) VALUES (?,?,?)')
            ->execute(['cola', 12, 'test']);
        $r2 = $this->request('GET', '/admin/compta/reappro', [], [], $this->rootId);
        $body2 = (string) ($r2['body'] ?? '');
        self::assertMatchesRegularExpression(
            '/data-name="cola"[^>]*data-toorder="12"/',
            $body2,
            'La commande doit être arrondie au pack de 12.'
        );
        self::assertStringContainsString('besoin 2', $body2, 'L\'info « besoin brut · pack » doit être affichée.');

        // Modification via le formulaire : pack de 6 → 2 arrondi à 6.
        $this->request('POST', '/admin/compta/reappro/packs', [
            'pack_keys'  => ['cola'],
            'pack_sizes' => ['6'],
        ], [], $this->rootId);
        self::assertSame(
            6,
            (int) $this->pdo->query("SELECT pack_size FROM product_packs WHERE product_key = 'cola'")->fetchColumn(),
            'Le pack édité doit être enregistré.'
        );
        $r4 = $this->request('GET', '/admin/compta/reappro', [], [], $this->rootId);
        self::assertMatchesRegularExpression(
            '/data-name="cola"[^>]*data-toorder="6"/',
            (string) ($r4['body'] ?? ''),
            'Le pack de 6 doit arrondir 2 → 6.'
        );

        // Pack vidé : retour à la commande à l'unité (besoin brut 2).
        $this->request('POST', '/admin/compta/reappro/packs', [
            'pack_keys'  => ['cola'],
            'pack_sizes' => [''],
        ], [], $this->rootId);
        self::assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM product_packs WHERE product_key = 'cola'")->fetchColumn(),
            'Un pack vidé doit être supprimé (retour à l\'unité).'
        );
    }

    public function test_liste_courses_uniquement_a_acheter_avec_packs(): void
    {
        // cola : compté 0 puis 3 ventes du jour (théorique −3) → à racheter,
        // besoin 2 + reconstitution 3 = 5 → pack 12 → commander 12.
        for ($i = 1; $i <= 3; $i++) {
            $this->insertSale('s_lst_' . $i, 'TLST0' . $i, date('Y-m-d ') . sprintf('09:0%d:00', $i), '1.50', 'cola');
        }
        $this->insertCount('inv_lst_0', '2026-01-01 08:00:00', 'cola', 0);
        $this->pdo->prepare('INSERT INTO product_packs (product_key, pack_size, updated_by) VALUES (?,?,?)')
            ->execute(['cola', 12, 'test']);

        // misterfreeze : jamais compté (« à compter ») → PAS dans la liste.
        for ($i = 1; $i <= 2; $i++) {
            $this->insertSale('s_lst_mf' . $i, 'TLSTM' . $i, date('Y-m-d ') . sprintf('10:0%d:00', $i), '2.00', 'misterfreeze');
        }

        // eau : gros stock compté → rien à acheter, absent de la liste.
        $this->insertCount('inv_lst_1', '2026-01-01 09:00:00', 'eau', 100);

        // chips : compté 0 mais AUCUNE vente → pas dans la liste (produit
        // sans consommation, ex. saisonnier écoulé).
        $this->insertCount('inv_lst_2', '2026-01-01 09:05:00', 'chips', 0);

        $r = $this->request('GET', '/admin/compta/liste', [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0), 'La page Liste de courses doit répondre.');
        $body = (string) ($r['body'] ?? '');
        self::assertStringContainsString('Liste de courses', $body);
        self::assertStringContainsString('cola', $body, 'Le produit à racheter (cola) doit figurer dans la liste.');
        self::assertStringNotContainsString('chips', $body, 'Un produit sans aucune vente ne doit pas apparaître dans la liste.');
        self::assertStringNotContainsString('misterfreeze', $body, 'Un produit « à compter » ne doit pas apparaître dans la liste.');
        self::assertStringNotContainsString('>eau<', $body, 'Un produit avec du stock ne doit pas apparaître dans la liste.');

        // Kiosque sans session : 200 lecture seule, sans navigation.
        $token = (string) $this->pdo->query(
            "SELECT value FROM settings WHERE `key` = 'reappro_kiosk_token'"
        )->fetchColumn();
        self::assertNotSame('', $token, 'Le jeton kiosque doit être généré par la page liste.');

        $rk = $this->request('GET', '/kiosque/liste/' . $token);
        self::assertSame(200, (int) ($rk['code'] ?? 0), 'La liste kiosque doit fonctionner sans connexion.');
        $bodyK = (string) ($rk['body'] ?? '');
        self::assertStringContainsString('cola', $bodyK);
        self::assertStringNotContainsString('admin-sidebar', $bodyK, 'Aucune navigation en kiosque.');

        // Mauvais jeton : 403.
        self::assertSame(
            403,
            (int) ($this->request('GET', '/kiosque/liste/mauvais-jeton')['code'] ?? 0),
            'Un jeton invalide doit être refusé (403).'
        );
    }

    public function test_kiosque_comptage_caisse_et_inventaire(): void
    {
        // Jeton kiosque inséré directement (même clé que les pages kiosque).
        $this->pdo->prepare(
            "INSERT INTO settings (id, `key`, value) VALUES ('set_kiosk_t', 'reappro_kiosk_token', 'jetoncomptage123')
             ON DUPLICATE KEY UPDATE value = 'jetoncomptage123'"
        )->execute();
        $token = 'jetoncomptage123';

        // Hub : les deux tuiles de comptage.
        $rh = $this->request('GET', '/kiosque/comptage/' . $token);
        self::assertSame(200, (int) ($rh['code'] ?? 0), 'Le hub kiosque comptage doit répondre.');
        $bodyH = (string) ($rh['body'] ?? '');
        self::assertStringContainsString('Comptage caisse', $bodyH);
        self::assertStringContainsString('Comptage inventaire', $bodyH);
        self::assertStringNotContainsString('admin-sidebar', $bodyH, 'Aucune navigation en kiosque.');

        // Les pages admin Comptage affichent les liens kiosque à copier.
        $ra = $this->request('GET', '/admin/compta/caisse', [], [], $this->rootId);
        self::assertSame(200, (int) ($ra['code'] ?? 0));
        self::assertStringContainsString('/kiosque/comptage/caisse/', (string) ($ra['body'] ?? ''), 'La page Comptage caisse doit afficher le lien kiosque.');
        $rb = $this->request('GET', '/admin/compta/inventaire/comptage', [], [], $this->rootId);
        self::assertSame(200, (int) ($rb['code'] ?? 0));
        self::assertStringContainsString('/kiosque/comptage/inventaire/', (string) ($rb['body'] ?? ''), 'La page Comptage inventaire doit afficher le lien kiosque.');

        // Comptage caisse : formulaire accessible sans session…
        $rc = $this->request('GET', '/kiosque/comptage/caisse/' . $token);
        self::assertSame(200, (int) ($rc['code'] ?? 0));
        self::assertStringContainsString('Montant compté', (string) ($rc['body'] ?? ''));

        // … et enregistrement réel (comptage à l'aveugle, trace kiosque).
        $this->request('POST', '/kiosque/comptage/caisse/' . $token, [
            'counted' => '10,50',
            'label'   => 'test kiosque',
        ]);
        self::assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM cash_counts WHERE created_by = 'kiosque' AND counted_amount = 10.5")->fetchColumn(),
            'Le comptage de caisse kiosque doit être enregistré.'
        );

        // Comptage inventaire : le produit vendu apparaît (à l'aveugle)…
        $this->insertSale('s_kt_1', 'TKIOSK1', date('Y-m-d 08:00:00'), '1.00', 'colakiosque');
        $ri = $this->request('GET', '/kiosque/comptage/inventaire/' . $token);
        self::assertSame(200, (int) ($ri['code'] ?? 0));
        self::assertStringContainsString('colakiosque', (string) ($ri['body'] ?? ''));

        // … et l'enregistrement crée bien le comptage (théorique calculé côté serveur).
        $this->request('POST', '/kiosque/comptage/inventaire/save/' . $token, [
            'count' => ['colakiosque' => '7'],
        ]);
        self::assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM inventory_counts WHERE product_key = 'colakiosque' AND counted_qty = 7 AND created_by = 'kiosque'")->fetchColumn(),
            'Le comptage inventaire kiosque doit être enregistré.'
        );

        // Mauvais jeton : 403 sur le hub et sur les POST.
        self::assertSame(403, (int) ($this->request('GET', '/kiosque/comptage/mauvais-jeton')['code'] ?? 0));
        self::assertSame(403, (int) ($this->request('POST', '/kiosque/comptage/caisse/mauvais-jeton', ['counted' => '5'])['code'] ?? 0));
    }

    public function test_acces_kiosque_reappro_sans_connexion_par_jeton(): void
    {
        // Le jeton est généré paresseusement à la première visite connectée.
        $r1 = $this->request('GET', '/admin/compta/reappro', [], [], $this->rootId);
        self::assertSame(200, (int) ($r1['code'] ?? 0));

        $token = (string) $this->pdo->query(
            "SELECT value FROM settings WHERE `key` = 'reappro_kiosk_token'"
        )->fetchColumn();
        self::assertNotSame('', $token, 'Le jeton kiosque doit être généré automatiquement.');

        // Lien kiosque SANS session : la page s'affiche en lecture seule.
        $r2 = $this->request('GET', '/kiosque/reappro/' . $token);
        self::assertSame(200, (int) ($r2['code'] ?? 0), 'Le lien kiosque doit fonctionner sans connexion.');
        $body = (string) ($r2['body'] ?? '');
        self::assertStringContainsString('Réapprovisionnement', $body);
        self::assertStringNotContainsString(
            '/admin/compta/reappro/infinite',
            $body,
            'Le mode kiosque doit être en lecture seule (pas de bouton stock ∞).'
        );
        self::assertStringNotContainsString(
            'Accès téléphone',
            $body,
            'Pas de bloc « accès téléphone » imbriqué en mode kiosque.'
        );
        // Layout kiosque : aucune navigation admin visible — que la page.
        self::assertStringNotContainsString('admin-sidebar', $body, 'Le menu latéral ne doit pas apparaître en kiosque.');
        self::assertStringNotContainsString('admin-toggle', $body, 'Le bouton menu admin ne doit pas apparaître en kiosque.');
        self::assertStringNotContainsString('Déconnexion', $body, 'Aucun lien de déconnexion en kiosque.');
        self::assertStringNotContainsString('Voir le site', $body, 'Aucun lien externe en kiosque.');

        // Mauvais jeton : 403.
        $r3 = $this->request('GET', '/kiosque/reappro/mauvais-jeton');
        self::assertSame(403, (int) ($r3['code'] ?? 0), 'Un jeton invalide doit être refusé (403).');

        // Régénération (connecté) : l'ancien lien est révoqué.
        $r4 = $this->request('POST', '/admin/compta/reappro/kiosk/regenerate', [], [], $this->rootId);
        self::assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM settings WHERE `key` = 'reappro_kiosk_token'")->fetchColumn()
        );

        $r5 = $this->request('GET', '/kiosque/reappro/' . $token);
        self::assertSame(403, (int) ($r5['code'] ?? 0), 'L\'ancien lien doit être révoqué après régénération.');
    }

    public function test_bilan_annuel_csv_conforme_aux_donnees_saisies(): void
    {
        $rows = $this->annualCsvRows(2026);

        // Juin : CA 25,00 · bénéfice = CA (aucun coût de revient saisi) ·
        // dépenses 5,00 · net 20,00.
        self::assertSame(
            ['06/2026', '25.00', '25.00', '100.0', '5.00', '20.00', '0.00', ''],
            array_values($rows['06/2026'] ?? []),
            'Ligne juin du bilan annuel incohérente avec les données en base.'
        );

        // Septembre : CA 100,00 · dépenses 16,00 (12,50 + 3,50) · net 84,00.
        self::assertSame(
            ['09/2026', '100.00', '100.00', '100.0', '16.00', '84.00', '0.00', ''],
            array_values($rows['09/2026'] ?? []),
            'Ligne septembre du bilan annuel incohérente avec les données en base.'
        );

        // Totaux : CA 125,00 · dépenses 21,00 · net 104,00.
        $total = $rows['TOTAL'] ?? [];
        self::assertSame('125.00', $total[1] ?? '', 'CA total du bilan incohérent.');
        self::assertSame('21.00', $total[4] ?? '', 'Dépenses totales du bilan incohérentes.');
        self::assertSame('104.00', $total[5] ?? '', 'Résultat net total incohérent.');
    }

    public function test_analytics_et_bilan_html_affichent_les_memes_chiffres(): void
    {
        // Bilan HTML : la ligne juin existe et affiche bien le CA de juin.
        $html = $this->request('GET', '/admin/compta/annuel?year=2026', [], [], $this->rootId);
        self::assertSame(200, (int) ($html['code'] ?? 0), 'Le bilan annuel (HTML) doit répondre.');
        self::assertStringContainsString('06/2026', (string) ($html['body'] ?? ''));
        self::assertStringContainsString('25,00', (string) ($html['body'] ?? ''));

        // Analytics filtré sur la même fenêtre (juin 2026) : même CA affiché.
        $analytics = $this->request(
            'GET',
            '/admin/analytics?period=custom&from=2026-06-01&to=2026-06-30',
            [],
            [],
            $this->rootId
        );
        self::assertSame(200, (int) ($analytics['code'] ?? 0), 'Analytics doit répondre sur une période personnalisée.');
        self::assertStringContainsString('25,00', (string) ($analytics['body'] ?? ''), 'Analytics n\'affiche pas le CA de la période.');

        // Le dashboard SumUp (même source « sales ») répond aussi avec les données.
        $sumup = $this->request('GET', '/admin/sumup', [], [], $this->rootId);
        self::assertSame(200, (int) ($sumup['code'] ?? 0), 'Le dashboard SumUp doit répondre.');
    }

    public function test_depot_banque_genere_frais_repercutes_dans_le_bilan(): void
    {
        // Dépôt de 50,50 € via la page Caisses.
        $r = $this->request('POST', '/admin/caisses/depot', ['amount' => '50,50'], [], $this->rootId);

        // Flash de succès mentionnant les frais.
        $flash = $r['session']['_flash'] ?? [];
        $last  = is_array($flash) && $flash !== [] ? end($flash) : null;
        self::assertNotNull($last, 'Aucun flash après le dépôt.');
        self::assertSame('success', $last['type'] ?? '');
        self::assertStringContainsString('frais bancaires', (string) ($last['message'] ?? ''));

        // Caisse : un mouvement DEPOT du montant exact.
        self::assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM cash_movements WHERE type = 'DEPOT' AND amount = -50.50")->fetchColumn(),
            'Le mouvement de caisse du dépôt est absent ou erroné.'
        );

        // Dépenses : la ligne de frais bancaires (3,50 €) est créée.
        self::assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM expenses WHERE category = 'FRAIS' AND amount_ttc = 3.50 AND label LIKE 'Frais de dépôt banque%'")->fetchColumn(),
            'La dépense de frais bancaires (3,50 €) n\'a pas été créée.'
        );

        // Le bilan annuel de l'année courante intègre les 3,50 € de frais.
        $year    = (int) date('Y');
        $rows    = $this->annualCsvRows($year);
        $attendu = $year === 2026 ? '24.50' : '3.50'; // 21,00 € de dépenses 2026 + 3,50 € de frais.
        self::assertSame(
            $attendu,
            $rows['TOTAL'][4] ?? '',
            'Les frais de dépôt ne se répercutent pas dans les dépenses du bilan annuel.'
        );
    }

    // -----------------------------------------------------------------
    //  Helpers
    // -----------------------------------------------------------------

    /**
     * Insère une vente directement en base (SumUp simulé).
     */
    private function insertSale(string $id, string $ref, string $soldAt, string $priceTtc, ?string $description = null): void
    {
        $this->pdo->prepare(
            'INSERT INTO sales (id, transaction_ref, sold_at, payment_method, payment_raw, quantity, description, price_ttc, is_custom_amount)
             VALUES (?,?,?,?,?,1,?,?,0)'
        )->execute([$id, $ref, $soldAt, 'CARTE', 'Visa - Débit', $description ?? $ref, $priceTtc]);
    }

    /**
     * Insère une dépense directement en base.
     */
    private function insertExpense(string $id, string $spentAt, string $category, string $label, string $amountTtc): void
    {
        $this->pdo->prepare(
            'INSERT INTO expenses (id, spent_at, category, label, amount_ttc, created_by)
             VALUES (?,?,?,?,?,?)'
        )->execute([$id, $spentAt, $category, $label, $amountTtc, 'test@aeic.fr']);
    }

    /**
     * Insère un comptage inventaire (théorique = compté, aucun mouvement).
     */
    private function insertCount(string $id, string $countedAt, string $productKey, int $qty): void
    {
        $this->pdo->prepare(
            'INSERT INTO inventory_counts (id, counted_at, product_key, counted_qty, theoretical_qty, gap, created_by)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$id, $countedAt, $productKey, $qty, $qty, 0, 'test']);
    }

    /**
     * Pose un drapeau « plus en vente » avec l'auteur donné
     * (« auto » = pause automatique du système, sinon pause manuelle).
     */
    private function markPaused(string $productKey, string $updatedBy): void
    {
        $this->pdo->prepare(
            'INSERT INTO product_discontinued (product_key, updated_by, updated_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE updated_by = VALUES(updated_by), updated_at = NOW()'
        )->execute([$productKey, $updatedBy]);
    }

    /**
     * Note le produit « à zéro » depuis N jours (table product_zero_since).
     */
    private function markZeroSince(string $productKey, int $daysAgo): void
    {
        $this->pdo->prepare(
            'INSERT INTO product_zero_since (product_key, zero_since) VALUES (?, ?)'
        )->execute([$productKey, date('Y-m-d H:i:s', time() - $daysAgo * 86400)]);
    }

    /**
     * Export CSV du bilan annuel, parsé en lignes indexées par leur
     * première colonne (« 06/2026 », « TOTAL »…).
     *
     * @return array<string,list<string>>
     */
    private function annualCsvRows(int $year): array
    {
        $r = $this->request('GET', '/admin/compta/annuel?export=csv&year=' . $year, [], [], $this->rootId);
        self::assertSame(
            200,
            (int) ($r['code'] ?? 0),
            sprintf('L\'export CSV du bilan annuel %d doit répondre.', $year)
        );

        $body = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($r['body'] ?? ''));
        $lines = preg_split('/\r?\n/', trim((string) $body)) ?: [];

        $rows = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $cells = str_getcsv($line);
            if ($cells === [] || $cells[0] === '') {
                continue;
            }
            $rows[$cells[0]] = $cells;
        }

        self::assertArrayHasKey('TOTAL', $rows, 'Ligne TOTAL absente de l\'export CSV du bilan.');

        return $rows;
    }
}
