<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Page /admin/compta/budgets + entrée « À faire » du dashboard compta —
 * chantier P1-3 « Budget visible et éditable simplement » :
 *
 *   1. ?month=YYYY-MM édite CE mois-là (champs = budget réel du mois
 *      entier), même quand la période comparée se termine ailleurs — et le
 *      POST /save enregistré depuis ce formulaire écrit bien ce mois ;
 *   2. sans paramètre : comportement historique strictement inchangé
 *      (formulaire = dernier mois couvert, champs au prorata de la
 *      période) ;
 *   3. mois invalide (2026-13, texte) → rejet (redirection, paramètre
 *      abandonné) ;
 *   4. mini-grille annuelle en LECTURE SEULE : 12 mois × prévu vs réalisé,
 *      aucun formulaire ni route d'écriture ajoutés ;
 *   5. entrée « À faire » du dashboard : « Aucun budget pour le mois
 *      courant » si vide / « MATIERE à X % de l'enveloppe » dès 85 % ;
 *   6. bouton « Pré-remplir avec le réalisé récent » : les suggestions
 *      sont la MOYENNE du réalisé des 3 mois calendaires précédant le
 *      mois édité (CA + dépenses par catégorie, achats de stock comptés
 *      dans MATIERE), arrondie à l'euro, calculées côté contrôleur et
 *      transportées en data-suggest — RIEN n'est pré-rempli dans les
 *      champs ni enregistré sans le POST « Enregistrer le budget ».
 *
 * Les dates de test sont relatives à aujourd'hui (mois précédent = mois
 * édité, mois encore avant = période « ailleurs ») : robustes au calendrier
 * de exécution, y compris au passage d'année.
 *
 * Base `aeic_test` requise (les tests sont sautés sinon).
 */
final class BudgetPageTest extends IntegrationTestCase
{
    private string $rootId = 'u_budget_root';

    /** Premier jour du mois précédent (mois édité dans les scénarios). */
    private \DateTimeImmutable $prevMonth;

    /** Premier jour du mois encore avant (période comparée « ailleurs »). */
    private \DateTimeImmutable $prevPrevMonth;

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
            'budgets',
            'users',
        ]);
        $this->seedUser($this->rootId, 'budget-root@exemple.fr', 'Password123456', 'SUPERADMIN');

        // Depuis le 1er du mois courant : « -1 month » / « -2 months » sont
        // sans ambiguïté (jour fixe à 1, pas de piège du 31).
        $this->prevMonth = (new \DateTimeImmutable('today'))
            ->modify('first day of this month')
            ->modify('-1 month');
        $this->prevPrevMonth = (new \DateTimeImmutable('today'))
            ->modify('first day of this month')
            ->modify('-2 months');
    }

    // -----------------------------------------------------------------
    //  1. ?month= choisit le mois édité
    // -----------------------------------------------------------------

    public function test_param_month_edite_le_mois_choisi_meme_hors_periode(): void
    {
        $ym = $this->prevMonth->format('Y-m');
        $this->insertBudget('b_p1_ca', $this->prevMonth, 'CA', '1000.00');
        $this->insertBudget('b_p1_mat', $this->prevMonth, 'MATIERE', '200.00');

        // Période « ailleurs » : un seul jour au milieu du mois encore avant.
        $elsewhere = $this->prevPrevMonth->format('Y-m-15');
        $r = $this->request(
            'GET',
            '/admin/compta/budgets?period=custom&from=' . $elsewhere . '&to=' . $elsewhere . '&month=' . $ym,
            [],
            [],
            $this->rootId
        );
        self::assertSame(200, (int) ($r['code'] ?? 0), 'La page budgets doit répondre avec ?month=.');
        $body = (string) ($r['body'] ?? '');

        // Le formulaire enregistre bien le mois choisi, pas le dernier mois
        // de la période (qui serait le mois « ailleurs »).
        self::assertStringContainsString(
            'name="month" value="' . $ym . '"',
            $body,
            'Le champ caché du formulaire doit cibler le mois du paramètre ?month=.'
        );

        // Les champs montrent le budget RÉEL du mois choisi (1 000 € de CA),
        // pas le prorata de la période d'un jour (qui serait ~33 €).
        self::assertStringContainsString('value="1 000,00"', $body, 'Le champ CA doit montrer le budget complet du mois édité.');
        self::assertStringContainsString('value="200,00"', $body, 'Le champ MATIERE doit montrer le budget complet du mois édité.');
        self::assertStringContainsString('(choisi dans « Mois édité »)', $body);
    }

    public function test_le_formulaire_d_un_mois_choisi_enregistre_ce_mois(): void
    {
        $ym = $this->prevMonth->format('Y-m');
        $r = $this->request(
            'POST',
            '/admin/compta/budgets/save',
            ['month' => $ym, 'planned' => ['CA' => '777,50']],
            [],
            $this->rootId
        );
        self::assertStringContainsString(
            '/admin/compta/budgets?month=' . $ym,
            $this->location($r),
            'Après enregistrement, on revient sur le mois édité.'
        );

        $stmt = $this->pdo->prepare('SELECT planned_amount FROM budgets WHERE year = ? AND month = ? AND category = ?');
        $stmt->execute([(int) $this->prevMonth->format('Y'), (int) $this->prevMonth->format('n'), 'CA']);
        $row = $stmt->fetch();

        self::assertNotFalse($row, 'La ligne budget du mois choisi doit exister en base.');
        self::assertEqualsWithDelta(777.50, (float) $row['planned_amount'], 0.001);
    }

    // -----------------------------------------------------------------
    //  2. Sans paramètre : comportement historique inchangé
    // -----------------------------------------------------------------

    public function test_sans_param_dernier_mois_couvert_et_prorata_inchanges(): void
    {
        $ym = $this->prevMonth->format('Y-m');
        $this->insertBudget('b_p2_ca', $this->prevMonth, 'CA', '300.00');

        // Période d'UN jour au milieu du mois précédent : sans ?month, le
        // formulaire cible ce mois (dernier couvert) et affiche le « prévu »
        // au prorata (300 € ÷ jours du mois) — comportement historique.
        $mid = $this->prevMonth->format('Y-m-15');
        $r = $this->request(
            'GET',
            '/admin/compta/budgets?period=custom&from=' . $mid . '&to=' . $mid,
            [],
            [],
            $this->rootId
        );
        self::assertSame(200, (int) ($r['code'] ?? 0));
        $body = (string) ($r['body'] ?? '');

        self::assertStringContainsString(
            'name="month" value="' . $ym . '"',
            $body,
            'Sans ?month, le formulaire cible toujours le dernier mois couvert.'
        );

        $days = (int) $this->prevMonth->format('t');
        $expectedProrata = number_format(300 / $days, 2, ',', ' ');
        self::assertStringContainsString(
            'value="' . $expectedProrata . '"',
            $body,
            sprintf('Sans ?month, le champ reste au prorata de la période (300 € × 1/%d).', $days)
        );
        self::assertStringContainsString('(dernier mois de la période)', $body);
    }

    // -----------------------------------------------------------------
    //  3. Validation stricte du format
    // -----------------------------------------------------------------

    public function test_mois_invalide_rejete(): void
    {
        foreach (['2026-13', 'nimporte', '2026-1', '2026-11-05'] as $bad) {
            $r = $this->request('GET', '/admin/compta/budgets?month=' . urlencode($bad), [], [], $this->rootId);
            $loc = $this->location($r);

            self::assertNotSame('', $loc, sprintf('Un mois invalide (%s) doit être rejeté par redirection.', $bad));
            self::assertStringContainsString('/admin/compta/budgets', $loc);
            self::assertStringNotContainsString('month=', $loc, 'La redirection abandonne le mois invalide.');
        }
    }

    // -----------------------------------------------------------------
    //  4. Mini-grille annuelle lecture seule
    // -----------------------------------------------------------------

    public function test_grille_annuelle_lecture_seule(): void
    {
        $ym = $this->prevMonth->format('Y-m');
        $this->insertBudget('b_g_ca', $this->prevMonth, 'CA', '500.00');
        $this->insertSale('s_budget_g', 'TBUDG1', $this->prevMonth->format('Y-m-15 12:00:00'), '120.00');

        $r = $this->request('GET', '/admin/compta/budgets?month=' . $ym, [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0));
        $body = (string) ($r['body'] ?? '');

        // Les 12 mois de l'année du mois édité, en clair.
        foreach (['Janvier', 'Juin', 'Décembre'] as $label) {
            self::assertStringContainsString($label, $body, sprintf('Le mois %s doit figurer dans la grille annuelle.', $label));
        }

        // Réutilise les sources existantes : prévu = Budget du mois (500 €),
        // réalisé = ventes du mois (120 €).
        self::assertStringContainsString('500,00 €', $body, 'Le CA prévu du mois seedé doit apparaître.');
        self::assertStringContainsString('120,00 €', $body, 'Le CA réalisé du mois seedé doit apparaître.');

        // Lecture seule : dans la section annuelle, aucun formulaire ni
        // champ de saisie — juste des liens de navigation ?month=.
        $pos = strpos($body, 'Année ' . $this->prevMonth->format('Y'));
        self::assertNotFalse($pos, 'La carte « Année … » doit être rendue.');
        $annualSection = substr($body, $pos);
        self::assertSame(0, substr_count($annualSection, '<form'), 'La grille annuelle ne doit contenir aucun formulaire.');
        self::assertSame(0, substr_count($annualSection, '<input'), 'La grille annuelle ne doit contenir aucun champ de saisie.');

        // 12 liens de mois (un par ligne de la grille).
        self::assertGreaterThanOrEqual(
            12,
            substr_count($body, 'month=' . $this->prevMonth->format('Y') . '-'),
            'Chaque mois de la grille doit être un lien vers ?month=YYYY-MM.'
        );
    }

    // -----------------------------------------------------------------
    //  5. Entrée « À faire » du dashboard compta
    // -----------------------------------------------------------------

    public function test_dashboard_entree_aucun_budget_pour_le_mois_courant(): void
    {
        // Aucune ligne budgets (table réinitialisée) pour le mois courant.
        $r = $this->request('GET', '/admin/compta', [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0));
        $body = (string) ($r['body'] ?? '');

        self::assertStringContainsString('Aucun budget pour le mois courant', $body, 'L\'entrée budget du bloc « À faire » est absente.');
        self::assertStringContainsString('/admin/compta/budgets?month=', $body, 'L\'entrée doit mener à la page budgets du mois courant.');
    }

    public function test_dashboard_entree_enveloppe_presque_epuisee(): void
    {
        // Enveloppe MATIERE de 100 € pour le mois courant, déjà 90 € de
        // dépenses MATIERE → « MATIERE à 90 % de l'enveloppe ».
        $this->insertBudgetCurrentMonth('b_d_mat', 'MATIERE', '100.00');
        $this->insertExpense('exp_budget_1', date('Y-m-d'), 'MATIERE', 'Courses', '90.00');

        $r = $this->request('GET', '/admin/compta', [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0));
        $body = (string) ($r['body'] ?? '');

        self::assertStringContainsString('MATIERE à 90 %', $body, 'L\'enveloppe à 90 % doit être signalée.');
        self::assertStringContainsString('enveloppe', $body);
        self::assertStringNotContainsString('Aucun budget pour le mois courant', $body, 'Un budget existe : le rappel de saisie ne doit pas s\'afficher.');
    }

    public function test_dashboard_aucune_entree_budget_quand_tout_va_bien(): void
    {
        // Enveloppe MATIERE de 100 € seulement entamée à 50 € : ni rappel
        // (le budget existe) ni alerte (sous le seuil de 85 %).
        $this->insertBudgetCurrentMonth('b_d_ok', 'MATIERE', '100.00');
        $this->insertExpense('exp_budget_2', date('Y-m-d'), 'MATIERE', 'Courses', '50.00');

        $r = $this->request('GET', '/admin/compta', [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0));
        $body = (string) ($r['body'] ?? '');

        self::assertStringNotContainsString('Aucun budget pour le mois courant', $body);
        self::assertStringNotContainsString("l&#39;enveloppe", $body, 'Sous 85 % de l\'enveloppe : aucune alerte.');
    }

    // -----------------------------------------------------------------
    //  6. Suggestions « Pré-remplir avec le réalisé récent » (P2-3)
    // -----------------------------------------------------------------

    /**
     * Mois édité = M-1 ; fenêtre de suggestions = M-2, M-3, M-4.
     *
     * @return list<\DateTimeImmutable> [M-4, M-3, M-2] (premiers jours).
     */
    private function fenetreSuggestions(): array
    {
        $m2 = $this->prevPrevMonth;

        return [$m2->modify('-2 months'), $m2->modify('-1 month'), $m2];
    }

    public function test_suggestions_moyenne_des_3_mois_precedents(): void
    {
        [$m4, $m3, $m2] = $this->fenetreSuggestions();

        // CA réalisé : 900 + 1000 + 1100 = 3000 → moyenne 1 000 €.
        $this->insertSale('s_sug_a', 'TSUGA', $m2->format('Y-m-10 10:00:00'), '900.00');
        $this->insertSale('s_sug_b', 'TSUGB', $m3->format('Y-m-15 10:00:00'), '1000.00');
        $this->insertSale('s_sug_c', 'TSUGC', $m4->format('Y-m-20 10:00:00'), '1100.00');

        // MATIERE : dépenses 100 + 150 + 200 = 450, achats de stock
        // 100 × 3 = 300 → (450 + 300) / 3 = 250 €.
        $this->insertExpense('exp_sug_a', $m2->format('Y-m-05'), 'MATIERE', 'Courses', '100.00');
        $this->insertExpense('exp_sug_b', $m3->format('Y-m-05'), 'MATIERE', 'Courses', '150.00');
        $this->insertExpense('exp_sug_c', $m4->format('Y-m-05'), 'MATIERE', 'Courses', '200.00');
        $this->insertPurchase('p_sug_a', $m2->format('Y-m-06'), 'PSUG1', '100.00');
        $this->insertPurchase('p_sug_b', $m3->format('Y-m-06'), 'PSUG1', '100.00');
        $this->insertPurchase('p_sug_c', $m4->format('Y-m-06'), 'PSUG1', '100.00');

        // FRAIS : 30 + 40 + 42 = 112 → 112/3 = 37,33 → arrondi à l'euro : 37 €.
        $this->insertExpense('exp_sug_d', $m2->format('Y-m-07'), 'FRAIS', 'Banque', '30.00');
        $this->insertExpense('exp_sug_e', $m3->format('Y-m-07'), 'FRAIS', 'Banque', '40.00');
        $this->insertExpense('exp_sug_f', $m4->format('Y-m-07'), 'FRAIS', 'Banque', '42.00');

        // MATERIEL : un seul mois alimenté (60 €) → moyenne 20 €.
        $this->insertExpense('exp_sug_g', $m3->format('Y-m-08'), 'MATERIEL', 'Petit matos', '60.00');
        // EVENEMENT et DIVERS : aucun réalisé → suggestion vide.

        $r = $this->request('GET', '/admin/compta/budgets?month=' . $this->prevMonth->format('Y-m'), [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0));
        $body = (string) ($r['body'] ?? '');

        // Les suggestions attendues (moyenne des 3 mois précédents, arrondie
        // à l'euro, déjà formatée à la française) sont dans data-suggest.
        self::assertStringContainsString('data-suggest="1 000,00"', $body, 'Suggestion CA = moyenne des CA des 3 mois précédents.');
        self::assertStringContainsString('data-suggest="250,00"', $body, 'Suggestion MATIERE = dépenses + achats de stock, moyennés.');
        self::assertStringContainsString('data-suggest="37,00"', $body, 'Suggestion FRAIS arrondie à l’euro (112/3 = 37,33 → 37).');
        self::assertStringContainsString('data-suggest="20,00"', $body, 'Suggestion MATERIEL = 60 € étalés sur 3 mois.');
        self::assertStringContainsString('data-suggest=""', $body, 'Catégorie sans réalisé sur la fenêtre → suggestion vide.');

        // RIEN n'est pré-rempli dans les champs eux-mêmes : sans clic (côté
        // client) ni validation, le formulaire reste vide (aucun budget
        // seedé pour le mois édité).
        self::assertStringNotContainsString('value="1 000,00"', $body, 'La suggestion ne doit PAS remplir le champ sans clic.');

        // Indication des mois utilisés : fenêtre M-4 → M-2, en toutes
        // lettres (abréviations françaises des mois extrémités).
        self::assertStringContainsString('moyenne réalisée', $body);
        self::assertStringContainsString(self::abbrMois($m4), $body, 'Le 1er mois de la fenêtre doit être indiqué.');
        self::assertStringContainsString(self::abbrMois($m2), $body, 'Le dernier mois de la fenêtre doit être indiqué.');
    }

    public function test_suggestion_sans_clic_rien_n_est_ecrit(): void
    {
        $ym = $this->prevMonth->format('Y-m');
        $this->insertBudget('b_sug_keep', $this->prevMonth, 'CA', '555.00');
        $this->insertSale('s_sug_side', 'TSUGSIDE', $this->prevPrevMonth->format('Y-m-12 12:00:00'), '300.00');

        $r = $this->request('GET', '/admin/compta/budgets?month=' . $ym, [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0));
        $body = (string) ($r['body'] ?? '');

        // Le bouton est un simple button (jamais un submit) : il ne peut
        // pas déclencher le POST /save.
        self::assertStringContainsString(
            '<button type="button" class="btn btn-ghost" id="budget-suggest"',
            $body,
            'Le bouton de pré-remplissage ne doit pas soumettre le formulaire.'
        );

        // Le champ garde le budget EXISTANT (555 €), pas la suggestion
        // (300 €) : le GET ne touche ni l'affichage des valeurs ni la base.
        self::assertStringContainsString('value="555,00"', $body);

        // Aucune écriture : la ligne budget est intacte, aucune ligne
        // ajoutée (la suggestion n'a créé ni budgets ni quoi que ce soit).
        $row = $this->pdo->query('SELECT COUNT(*) AS n, COALESCE(SUM(planned_amount), 0) AS total FROM budgets')->fetch();
        self::assertSame(1, (int) $row['n'], 'Aucune ligne budget ne doit être créée par un simple GET.');
        self::assertEqualsWithDelta(555.0, (float) $row['total'], 0.001, 'Le budget existant doit être inchangé.');
    }

    // -----------------------------------------------------------------
    //  Helpers
    // -----------------------------------------------------------------

    /**
     * Insère une ligne de budget pour le premier jour de mois donné
     * (objet date « premier jour du mois »).
     */
    private function insertBudget(string $id, \DateTimeImmutable $firstOfMonth, string $category, string $planned): void
    {
        $this->pdo->prepare(
            'INSERT INTO budgets (id, year, month, category, planned_amount) VALUES (?,?,?,?,?)'
        )->execute([$id, (int) $firstOfMonth->format('Y'), (int) $firstOfMonth->format('n'), $category, $planned]);
    }

    /**
     * Insère une ligne de budget pour le MOIS COURANT.
     */
    private function insertBudgetCurrentMonth(string $id, string $category, string $planned): void
    {
        $this->pdo->prepare(
            'INSERT INTO budgets (id, year, month, category, planned_amount) VALUES (?,?,?,?,?)'
        )->execute([$id, (int) date('Y'), (int) date('n'), $category, $planned]);
    }

    /**
     * Insère une vente directement en base (SumUp simulé).
     */
    private function insertSale(string $id, string $ref, string $soldAt, string $priceTtc): void
    {
        $this->pdo->prepare(
            'INSERT INTO sales (id, transaction_ref, sold_at, payment_method, payment_raw, quantity, description, price_ttc, is_custom_amount, category, imported_at)
             VALUES (?,?,?,?,?,1,?,?,0,?,?)'
        )->execute([$id, $ref, $soldAt, 'CARTE', 'Visa - Débit', $ref, $priceTtc, null, date('Y-m-d H:i:s')]);
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
     * Insère un achat de stock directement en base (TTC seulement, entré
     * en stock : compté par Purchase::statsBetween dans l'enveloppe
     * MATIERE de la page budgets).
     */
    private function insertPurchase(string $id, string $purchasedAt, string $productKey, string $totalTtc): void
    {
        $this->pdo->prepare(
            'INSERT INTO purchases (id, purchased_at, product_key, quantity, total_ttc)
             VALUES (?,?,?,1,?)'
        )->execute([$id, $purchasedAt, $productKey, $totalTtc]);
    }

    /**
     * Abréviation française d'un mois (même convention que la page :
     * « oct. », « déc. »…), pour vérifier l'indication des mois utilisés.
     */
    private static function abbrMois(\DateTimeImmutable $mois): string
    {
        $abbr = [
            1 => 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin',
            'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.',
        ];

        return $abbr[(int) $mois->format('n')];
    }
}
