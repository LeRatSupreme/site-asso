<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Compta\CashLedger;
use App\Core\Compta\InvoiceScan;
use App\Core\Compta\ProductAutoSync;
use App\Core\Compta\PurchaseSaver;
use App\Core\Compta\ReceiptStorage;
use App\Core\Compta\StockPublic;
use App\Core\Compta\SumUpCsvParser;
use App\Core\Controller;
use App\Models\Analytics;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\InventoryCount;
use App\Models\ProductDiscontinued;
use App\Models\Setting;
use App\Models\Sale;

/**
 * Comptage « kiosque » par lien secret (même jeton que Réappro/Liste) :
 * hub avec deux saisies — comptage de caisse et comptage inventaire à
 * l'aveugle — ainsi que la saisie express des dépenses depuis le livre
 * comptable (jeton admin). Mêmes mécaniques serveur que les pages admin
 * (écarts calculés côté serveur, historique complet), sans connexion : le
 * jeton secret EST l'authentification. Toutes les traces sont marquées
 * « kiosque » (created_by / audit).
 */
final class KioskComptageController extends Controller
{
    private function tokenOk(string $token): bool
    {
        $expected = trim((string) Setting::get('reappro_kiosk_token', ''));
        $given    = trim($token);

        return $expected !== '' && $given !== '' && hash_equals($expected, $given);
    }

    /**
     * Jeton ADMIN (données financières complètes) — totalement indépendant
     * du jeton membres : la partie admin du kiosque est détachée et
     * révocable séparément.
     */
    private function adminTokenOk(string $token): bool
    {
        $expected = trim((string) Setting::get('admin_kiosk_token', ''));
        $given    = trim($token);

        return $expected !== '' && $given !== '' && hash_equals($expected, $given);
    }

    /**
     * Pages outils (caisse, inventaire, liste) : accessibles avec l'un OU
     * l'autre jeton — l'URL porte le contexte et les liens de retour
     * conservent l'espace (membres ou admin) d'origine.
     */
    private function anyTokenOk(string $token): bool
    {
        return $this->tokenOk($token) || $this->adminTokenOk($token);
    }

    /**
     * URL du hub correspondant au jeton fourni : jeton admin → hub admin,
     * jeton membres → hub membres. Les liens « retour » des pages outils
     * restent ainsi dans l'espace d'origine.
     */
    private function hubUrlFor(string $token): string
    {
        $admin = trim((string) Setting::get('admin_kiosk_token', ''));

        return $admin !== '' && hash_equals($admin, trim($token))
            ? url('/kiosque/admin/' . trim($token))
            : url('/kiosque/comptage/' . trim($token));
    }

    private function deny(): void
    {
        http_response_code(403);
        echo '<h1>Erreur 403 — Lien invalide ou révoqué.</h1>';
    }

    /**
     * Identité OBLIGATOIRE du membre kiosque : prénom, nom et rôle (ex.
     * « vice-trésorier »), saisis via la pastille profil. Les trois parties
     * sont exigées — sans elles, tout enregistrement est refusé. La trace
     * générée ressemble à « Jean Dupont (vice-trésorier) ».
     */
    private function whoFromPost(): string
    {
        $p = trim(strip_tags((string) ($_POST['who_prenom'] ?? '')));
        $n = trim(strip_tags((string) ($_POST['who_nom'] ?? '')));
        $a = trim(strip_tags((string) ($_POST['who_alias'] ?? '')));

        if ($p === '' || $n === '' || $a === '') {
            return '';
        }

        return mb_substr($p . ' ' . $n . ' (' . $a . ')', 0, 200);
    }

    /**
     * Hub : les deux comptages accessibles.
     */
    public function hub(string $token): void
    {
        if (!$this->tokenOk($token)) {
            $this->deny();

            return;
        }

        $this->renderKiosk('admin/compta/kiosk-comptage', [
            'title' => 'Comptage',
            'token' => $token,
        ]);
    }

    /**
     * Récap du jour (partie ADMIN, lecture seule) : CA, bénéfice, ventes de
     * la journée en cours (heure de Paris) — mêmes calculs que le dashboard
     * analytics (montants personnalisés inclus dans le CA, exclus du
     * bénéfice). Auto-actualisé par la vue via jourData().
     * Accès par le JETON ADMIN (partie détachée des membres).
     */
    public function jour(string $token): void
    {
        if (!$this->adminTokenOk($token)) {
            $this->deny();

            return;
        }

        $stats = $this->jourStats();
        $this->renderKiosk('admin/compta/kiosk-jour', [
            'title' => 'Récap du jour',
            'token' => $token,
            'stats' => $stats,
        ]);
    }

    /** Données du récap du jour (JSON) — consommées par l'auto-refresh. */
    public function jourData(string $token): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->adminTokenOk($token)) {
            http_response_code(403);
            echo '{"ok":false}';

            return;
        }

        echo json_encode(['ok' => true] + $this->jourStats());
    }

    /**
     * Livre comptable (partie ADMIN, adaptée téléphone) : Date | Objet |
     * Débit | Crédit, lignes « ticket », équilibrage = solde de trésorerie
     * (+ bénéfice net affiché séparément) — et onglet « Saisir une
     * dépense » (express, 30 s, enregistré par ledgerDepenseSave()).
     */
    public function ledger(string $token): void
    {
        if (!$this->adminTokenOk($token)) {
            $this->deny();

            return;
        }

        $ledger = new \App\Controllers\Admin\AdminLedgerController();
        [$from, $to, $preset] = $ledger->periodFromRequest();
        $entries = $ledger->buildEntries($from, $to);

        $this->renderKiosk('admin/ledger/index', [
            'title'          => 'Livre comptable',
            'token'          => $token,
            'kiosk'          => true,
            'entries'        => $entries,
            'from'           => $from,
            'to'             => $to,
            'preset'         => $preset,
            'totalDebit'     => $entries['total_debit'],
            'totalCredit'    => $entries['total_credit'],
            'balance'        => $entries['balance'],
            'recentExpenses' => Expense::recent(8),
            // Suggestions produit (datalist) pour les achats créés depuis
            // le scan — même fusion que la version admin du livre.
            'purchaseProductKeys' => \App\Controllers\Admin\AdminLedgerController::purchaseProductKeys(),
        ]);
    }

    /**
     * Saisie express d'une dépense depuis le livre comptable kiosque :
     * créer une trace immédiate (la ligne apparaît aussitôt dans le livre)
     * pour bien la compléter plus tard côté admin. Photo du ticket
     * optionnelle, catégorie forcée à DIVERS, identité obligatoire
     * (pastille profil du layout kiosk). Pas de CSRF : le jeton admin EST
     * l'authentification, comme les autres POST kiosque.
     */
    public function ledgerDepenseSave(string $token): void
    {
        if (!$this->adminTokenOk($token)) {
            $this->deny();

            return;
        }

        $back = url('/kiosque/admin/ledger/' . $token);

        // Identité obligatoire (mêmes mécaniques que le comptage caisse).
        $who = $this->whoFromPost();
        if ($who === '') {
            $this->setFlash('error', 'Indique qui tu es (prénom, nom et rôle) via la pastille en haut à droite avant d\'enregistrer.');
            redirect($back);
        }

        $label = trim((string) ($_POST['label'] ?? ''));
        $amount = parseFrenchFloat((string) ($_POST['amount'] ?? ''));
        if ($label === '' || $amount <= 0) {
            $this->setFlash('error', 'Libellé requis, avec un montant (> 0).');
            redirect($back);
        }

        // Base du montant (« ttc » par défaut — les tickets indiquent le TTC).
        $basis = ($_POST['amount_basis'] ?? '') === 'ht' ? 'ht' : 'ttc';
        $vatAmountInput = parseFrenchFloat((string) ($_POST['vat_amount'] ?? ''));
        $vatRaw = trim((string) ($_POST['vat_rate'] ?? ''));
        $rate = $vatRaw !== '' ? parseFrenchFloat($vatRaw) : null;
        if ($rate !== null && !in_array($rate, [20.0, 10.0, 5.5, 2.1, 0.0], true)) {
            $rate = null;
        }

        // HT/TTC/TVA : exactement la même logique que l'admin (méthode partagée).
        ['amount_ht' => $amountHt, 'amount_ttc' => $amountTtc, 'vat' => $vat] = Expense::computeAmounts(
            $amount,
            $basis,
            $vatAmountInput > 0 ? $vatAmountInput : null,
            $rate
        );

        // Photo du ticket optionnelle (mêmes règles de validation que l'admin).
        try {
            $receiptPath = ReceiptStorage::store();
        } catch (\RuntimeException $e) {
            $this->setFlash('error', $e->getMessage());
            redirect($back);
        }

        // Catégorie validée comme côté admin ; le formulaire kiosque n'en
        // propose pas (DIVERS), mais on reste robustes à un POST forgé.
        $category = strtoupper(trim((string) ($_POST['category'] ?? '')));
        if (!in_array($category, Expense::CATEGORIES, true)) {
            $category = 'DIVERS';
        }

        // Détail des produits éventuellement scannés (sérialisé par le JS
        // du pavé « Détail des produits » : « Détail tickets : … ») —
        // texte borné à 5 000 caractères.
        $notes = mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 5000);

        // Date valide sinon aujourd'hui (saisie téléphone indulgente).
        $spentAt = trim((string) ($_POST['spent_at'] ?? ''));
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $spentAt);
        if ($d === false || $d->format('Y-m-d') !== $spentAt) {
            $spentAt = date('Y-m-d');
        }

        $id = Expense::create([
            'spent_at'       => $spentAt,
            'category'       => $category,
            'label'          => $label,
            'amount_ttc'     => $amountTtc,
            'amount_ht'      => $amountHt,
            'vat'            => $vat,
            'invoice_number' => (string) ($_POST['invoice_number'] ?? ''),
            'notes'          => $notes,
            'receipt_path'   => $receiptPath,
            'created_by'     => 'kiosque — ' . $who,
        ]);

        if ($id === '') {
            $this->setFlash('error', 'Libellé requis, avec un montant (> 0).');
            redirect($back);
        }

        AuditLog::log('compta.expense.create', null, 'expense', $id, [
            'label'      => $label,
            'category'   => $category,
            'basis'      => $basis,
            'vat_rate'   => $rate,
            'amount_ht'  => $amountHt,
            'amount_ttc' => $amountTtc,
            'vat'        => $vat,
            'receipt'    => $receiptPath,
            'via'        => 'kiosque',
            'who'        => $who,
        ]);

        $this->setFlash('success', 'Dépense enregistrée.');
        redirect($back);
    }

    /**
     * Scan d'un ticket depuis le livre comptable KIOSQUE (JSON) : même
     * contrat EXACT que les scans admin (AdminBaseController::handleInvoiceScan,
     * factorisé dans InvoiceScan::run) — texte collé (200 000 caractères
     * max) OU upload jpg/jpeg/png/webp/pdf ≤ 10 Mo (extension ET MIME
     * réels vérifiés), texte extrait par OCR puis interprété par
     * InvoiceParser. Réponse 200 {ok,source,text,invoice} ; erreurs 400 /
     * 413 / 500 sous forme {ok:false,error}. Pas de CSRF : le jeton
     * admin EST l'authentification. Lecture seule (rien n'est enregistré).
     * Audit « kiosque.expense.scan » via AuditLog (sans user : pas de
     * session en kiosque, trace marquée « via: kiosque »).
     */
    public function ledgerScan(string $token): void
    {
        if (!$this->adminTokenOk($token)) {
            $this->deny();

            return;
        }

        $res = InvoiceScan::run();
        if (($res['ok'] ?? false) !== true) {
            $this->json(['ok' => false, 'error' => (string) $res['error']], (int) $res['status']);
        }

        $invoice = $res['invoice'];

        // Même base de journal que les scans admin (source, taille,
        // sha256, lignes extraites, fournisseur, facture) + marque kiosque.
        $audit = $res['audit'];
        $audit['lignes_extraites'] = count($invoice['lines']);
        $audit['fournisseur'] = $invoice['supplier'];
        $audit['facture'] = $invoice['invoice_number'];
        $audit['via'] = 'kiosque';
        AuditLog::log('kiosque.expense.scan', null, 'expense', null, $audit);

        $this->json([
            'ok'      => true,
            'source'  => $res['source'],
            'text'    => $res['text'],
            'invoice' => $invoice,
        ]);
    }

    /**
     * Création d'ACHATS en lot depuis le livre comptable KIOSQUE (JSON,
     * contrat as_json=1 implicite) : lit les MÊMES champs que
     * /admin/compta/achats/save-bulk (purchased_at, supplier,
     * invoice_number, amount_basis, update_cost, product_key[],
     * quantity[], total_amount[], vat_rate scalaire OU tableau par ligne,
     * no_stock[N]) et délègue au service partagé PurchaseSaver::save —
     * mêmes validations, mêmes messages, mêmes effets (achats + lots de
     * coût + synchro carte + cache public). Réponse {"ok":true,
     * "inserted":N,"message":"…"} (200) ou {"ok":false,"error":"…"}
     * (400). Pas de CSRF : le jeton admin EST l'authentification. Audit
     * « kiosque.purchase.create_bulk » via AuditLog (sans user : pas de
     * session en kiosque ; identité de la pastille absente du POST fetch,
     * trace marquée « via: kiosque »).
     */
    public function ledgerPurchasesSave(string $token): void
    {
        if (!$this->adminTokenOk($token)) {
            $this->deny();

            return;
        }

        $purchasedAt = trim((string) ($_POST['purchased_at'] ?? ''));
        if ($purchasedAt === '') {
            $purchasedAt = date('Y-m-d');
        }
        $supplier = trim((string) ($_POST['supplier'] ?? ''));
        $invoiceNumber = trim((string) ($_POST['invoice_number'] ?? ''));
        $basis = (string) ($_POST['amount_basis'] ?? 'ht') === 'ttc' ? 'ttc' : 'ht';
        $updateCost = isset($_POST['update_cost']);

        // Taux de TVA : scalaire (en-tête unique) OU tableau vat_rate[]
        // par ligne (facture à TVA mixte) — mêmes règles que la page Achats.
        $vatInput = $_POST['vat_rate'] ?? '';
        $vatRaw = is_array($vatInput) ? '' : trim((string) $vatInput);
        $vatPerLine = is_array($vatInput);

        $keys = is_array($_POST['product_key'] ?? null) ? $_POST['product_key'] : [];
        $quantities = is_array($_POST['quantity'] ?? null) ? $_POST['quantity'] : [];
        $amounts = is_array($_POST['total_amount'] ?? null) ? $_POST['total_amount'] : [];
        $noStockFlags = is_array($_POST['no_stock'] ?? null) ? $_POST['no_stock'] : [];

        $count = max(count($keys), count($quantities), count($amounts));
        $lines = [];
        for ($i = 0; $i < $count; $i++) {
            $lines[] = [
                'key'       => trim((string) ($keys[$i] ?? '')),
                'qty'       => (string) ($quantities[$i] ?? ''),
                'total_raw' => trim((string) ($amounts[$i] ?? '')),
                'vat_raw'   => $vatPerLine ? (string) ($vatInput[$i] ?? '') : '',
                'no_stock'  => (($noStockFlags[$i] ?? null) === '1' || ($noStockFlags[$i] ?? null) === 1),
            ];
        }

        // Identité de la pastille profil si présente (le POST fetch du JS
        // ne l'embarque pas : le layout kiosk n'injecte que dans les
        // <form>) — optionnelle ici, obligatoire pour la dépense.
        $who = $this->whoFromPost();

        $res = PurchaseSaver::save([
            'purchased_at'   => $purchasedAt,
            'supplier'       => $supplier,
            'invoice_number' => $invoiceNumber,
            'amount_basis'   => $basis,
            'update_cost'    => $updateCost,
            'vat_raw'        => $vatRaw,
            'vat_per_line'   => $vatPerLine,
            'created_by'     => $who !== '' ? 'kiosque — ' . $who : 'kiosque',
        ], $lines);

        if ($res['inserted'] === 0) {
            $this->json(['ok' => false, 'error' => $res['message']], 400);
        }

        AuditLog::log('kiosque.purchase.create_bulk', null, 'purchase', null, [
            'inserted'       => $res['inserted'],
            'products'       => $res['products'],
            'ignored'        => count($res['errors']),
            'vat_rate'       => $vatRaw === '' ? null : parseFrenchFloat($vatRaw),
            'vat_per_line'   => $vatPerLine,
            'amount_basis'   => $basis,
            'update_cost'    => $updateCost,
            'no_stock'       => $res['no_stock'],
            'supplier'       => $supplier,
            'invoice_number' => $invoiceNumber !== '' ? $invoiceNumber : null,
            'purchased_at'   => $purchasedAt,
            'via'            => 'kiosque',
            'who'            => $who !== '' ? $who : null,
        ]);

        $this->json(['ok' => true, 'inserted' => $res['inserted'], 'message' => $res['message']]);
    }

    /**
     * HUB ADMIN kiosque : menu de tuiles avec données financières
     * complètes — totalement détaché du hub membres (jeton dédié).
     */
    public function adminHub(string $token): void
    {
        if (!$this->adminTokenOk($token)) {
            $this->deny();

            return;
        }

        $today = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris')))->format('Y-m-d');
        $week = \App\Core\Compta\ComptaCalc::last7DaysWindow();
        $month = \App\Core\Compta\ComptaCalc::monthToDateWindow();
        $aggToday = Sale::aggregatesBetween($today, $today);
        $aggWeek = Sale::aggregatesBetween($week['from'], $week['to']);
        $aggMonth = Sale::aggregatesBetween($month['from'], $month['to']);

        $this->renderKiosk('admin/compta/kiosk-admin-hub', [
            'title'   => 'Kiosque admin',
            'token'   => $token,
            'jour'    => ['ca' => round($aggToday['ca'], 2), 'profit' => round($aggToday['profit'], 2)],
            'semaine' => ['ca' => round($aggWeek['ca'], 2), 'profit' => round($aggWeek['profit'], 2)],
            'mois'    => ['ca' => round($aggMonth['ca'], 2), 'profit' => round($aggMonth['profit'], 2)],
        ]);
    }

    /** Durées proposées par le menu « Période analysée ». */
    private const PERIODE_OPTIONS = ['7j', '14j', '30j', 'mois'];

    /**
     * Récap de période unifié (admin) : une seule page pour 7 / 14 / 30
     * derniers jours et le mois en cours — menu « Période analysée » avec
     * bornes affichées et nombre de jours analysés (week-end inclus).
     * Détail jour par jour sur toute durée ; comparaison mois précédent
     * quand la période est le mois en cours.
     */
    public function periode(string $token): void
    {
        if (!$this->adminTokenOk($token)) {
            $this->deny();

            return;
        }

        $paris = new \DateTimeZone('Europe/Paris');
        $p = (string) ($_GET['p'] ?? '7j');
        if (!in_array($p, self::PERIODE_OPTIONS, true)) {
            $p = '7j';
        }

        $today = new \DateTimeImmutable('today', $paris);
        $isMois = $p === 'mois';
        $prevCa = null;
        $prevLabel = null;
        if ($isMois) {
            $window = \App\Core\Compta\ComptaCalc::monthToDateWindow();
            $from = new \DateTimeImmutable($window['from'], $paris);
            $to = new \DateTimeImmutable($window['to'], $paris);
            $prevFrom = $from->modify('-1 month');
            $prevTo = $from->modify('-1 day');
            $prev = Sale::aggregatesBetween($prevFrom->format('Y-m-d'), $prevTo->format('Y-m-d'));
            $prevCa = round($prev['ca'], 2);
            $prevLabel = $prevFrom->format('m/Y');
        } else {
            $days = (int) substr($p, 0, -1);
            $from = $today->modify('-' . ($days - 1) . ' days');
            $to = $today;
        }

        $stats = $this->periodeStats($from->format('Y-m-d'), $to->format('Y-m-d'), true);
        $stats['prev_ca'] = $prevCa;
        $stats['prev_label'] = $prevLabel;

        // Jours CALENDaires couverts (week-end inclus), comme sur le réappro.
        $calDays = (int) $from->diff($to)->format('%a') + 1;

        $this->renderKiosk('admin/compta/kiosk-admin-periode', [
            'title'      => 'Période analysée',
            'token'      => $token,
            'p'          => $p,
            'stats'      => $stats,
            'calDays'    => $calDays,
            'rangeLabel' => 'du ' . $from->format('d/m') . ' au ' . $to->format('d/m'),
        ]);
    }

    /**
     * Ancienne page « 7 derniers jours » : redirigée vers la période
     * unifiée (les vieux liens et favoris continuent de marcher).
     */
    public function semaine(string $token): void
    {
        if (!$this->adminTokenOk($token)) {
            $this->deny();

            return;
        }

        redirect(url('/kiosque/admin/periode/' . rawurlencode($token) . '?p=7j'));
    }

    /**
     * Ancienne page « mois en cours » : redirigée vers la période unifiée.
     */
    public function mois(string $token): void
    {
        if (!$this->adminTokenOk($token)) {
            $this->deny();

            return;
        }

        redirect(url('/kiosque/admin/periode/' . rawurlencode($token) . '?p=mois'));
    }

    /**
     * Agrégats d'une période (bornes incluses) avec option détail
     * jour par jour.
     *
     * @return array<string,mixed>
     */
    private function periodeStats(string $from, string $to, bool $perDay): array
    {
        $agg = Sale::aggregatesBetween($from, $to);
        $split = Sale::paymentSplitBetween($from, $to);
        $top = Sale::topProductsBetween($from, $to, 10);
        $tx = Sale::transactionsBetween($from, $to);

        $days = [];
        if ($perDay) {
            $start = new \DateTimeImmutable($from);
            $end = new \DateTimeImmutable($to);
            $jours = ['lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'];
            for ($cur = $start; $cur <= $end; $cur = $cur->modify('+1 day')) {
                $day = $cur->format('Y-m-d');
                $a = Sale::aggregatesBetween($day, $day);
                $days[] = [
                    'label'  => $jours[(int) $cur->format('N') - 1] . ' ' . $cur->format('d/m'),
                    'ca'     => round($a['ca'], 2),
                    'profit' => round($a['profit'], 2),
                    'qty'    => $a['qty'],
                ];
            }
        }

        return [
            'from'         => $from,
            'to'           => $to,
            'ca'           => round($agg['ca'], 2),
            'profit'       => round($agg['profit'], 2),
            'qty'          => $agg['qty'],
            'transactions' => $tx,
            'liquide'      => round((float) ($split['LIQUIDE'] ?? 0), 2),
            'carte'        => round((float) ($split['CARTE'] ?? 0), 2),
            'top'          => $top,
            'days'         => $days,
            'computed_at'  => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('H:i:s'),
        ];
    }

    /**
     * Récap du jour version MEMBRES (partagée sur le hub) : uniquement le
     * CA et les produits vendus — ni bénéfice, ni paiements, ni CA/produit.
     */
    public function jourMembre(string $token): void
    {
        if (!$this->tokenOk($token)) {
            $this->deny();

            return;
        }

        $this->renderKiosk('admin/compta/kiosk-jour-membre', [
            'title' => 'Ventes du jour',
            'token' => $token,
            'stats' => $this->jourMembreStats(),
        ]);
    }

    /** Données du récap membres (JSON) — consommées par l'auto-refresh. */
    public function jourMembreData(string $token): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->tokenOk($token)) {
            http_response_code(403);
            echo '{"ok":false}';

            return;
        }

        echo json_encode(['ok' => true] + $this->jourMembreStats());
    }

    /**
     * Agrégats du jour (Europe/Paris, bornes 00:00 → 23:59 locales) +
     * enrichissements : panier moyen, comparaison avec hier, transactions
     * par moyen de paiement, semaine en cours et CA par heure du jour.
     * 7 requêtes légères (toutes indexées sur la date), pas plus.
     *
     * @return array<string,mixed>
     */
    private function jourStats(): array
    {
        $paris = new \DateTimeZone('Europe/Paris');
        $todayDt = new \DateTimeImmutable('today', $paris);
        $today = $todayDt->format('Y-m-d');
        $yesterday = $todayDt->modify('-1 day')->format('Y-m-d');

        $agg = Sale::aggregatesBetween($today, $today);
        $detail = Sale::paymentSplitDetailBetween($today, $today);
        $top = Sale::topProductsBetween($today, $today, 10);
        $tx = Sale::transactionsBetween($today, $today);
        $aggYesterday = Sale::aggregatesBetween($yesterday, $yesterday);
        $week = \App\Core\Compta\ComptaCalc::last7DaysWindow($todayDt);
        $aggWeek = Sale::aggregatesBetween($week['from'], $week['to']);

        // CA par heure du jour : salesByDayHour() regroupe par jour de
        // SEMAINE (0 = lundi) — sur une requête today→today, seule la
        // ligne du jour courant est remplie. On garde uniquement les
        // heures avec CA > 0, déjà triées par heure croissante (0→23).
        $dayIndex = (int) $todayDt->format('N') - 1;
        $hoursMatrix = Analytics::salesByDayHour($today, $today);
        $todayHours = $hoursMatrix[$dayIndex] ?? array_fill(0, 24, 0.0);
        $hours = [];
        foreach ($todayHours as $h => $ca) {
            if ((float) $ca > 0) {
                $hours[] = ['h' => (int) $h, 'ca' => round((float) $ca, 2)];
            }
        }

        return [
            'date'         => $today,
            'ca'           => round($agg['ca'], 2),
            'profit'       => round($agg['profit'], 2),
            'qty'          => $agg['qty'],
            'ca_products'  => round($agg['ca_products'], 2),
            'transactions' => $tx,
            'liquide'      => round((float) ($detail['LIQUIDE']['ca'] ?? 0), 2),
            'carte'        => round((float) ($detail['CARTE']['ca'] ?? 0), 2),
            'liquide_tx'   => (int) ($detail['LIQUIDE']['tx'] ?? 0),
            'carte_tx'     => (int) ($detail['CARTE']['tx'] ?? 0),
            'yesterday_ca' => round($aggYesterday['ca'], 2),
            'week'         => [
                'from'   => $week['from'],
                'to'     => $week['to'],
                'ca'     => round($aggWeek['ca'], 2),
                'profit' => round($aggWeek['profit'], 2),
            ],
            'hours'        => $hours,
            'top'          => $top,
            'computed_at'  => (new \DateTimeImmutable('now', $paris))->format('H:i:s'),
        ];
    }

    /**
     * Version restreinte pour les membres : CA global, produits vendus
     * (quantités) et CA de chaque produit — sans bénéfice ni paiements.
     *
     * @return array<string,mixed>
     */
    private function jourMembreStats(): array
    {
        $today = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris')))->format('Y-m-d');

        $agg = Sale::aggregatesBetween($today, $today);
        $top = array_map(
            static fn (array $t): array => [
                'label' => (string) $t['label'],
                'qty'   => (int) $t['qty'],
                'ca'    => round((float) $t['ca'], 2),
            ],
            Sale::topProductsBetween($today, $today, 500)
        );

        return [
            'date'        => $today,
            'ca'          => round($agg['ca'], 2),
            'qty'         => $agg['qty'],
            'top'         => $top,
            'computed_at' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('H:i:s'),
        ];
    }

    /**
     * Comptage de caisse (à l'aveugle : le théorique n'est pas affiché).
     */
    public function caisse(string $token): void
    {
        if (!$this->anyTokenOk($token)) {
            $this->deny();

            return;
        }

        $this->renderKiosk('admin/compta/kiosk-caisse', [
            'title'  => 'Comptage caisse',
            'token'  => $token,
            'hubUrl' => $this->hubUrlFor($token),
        ]);
    }

    /**
     * Enregistre le comptage de caisse saisi en kiosque.
     */
    public function caisseSave(string $token): void
    {
        if (!$this->anyTokenOk($token)) {
            $this->deny();

            return;
        }

        $back = url('/kiosque/comptage/caisse/' . $token);

        $counted = parseFrenchFloat((string) ($_POST['counted'] ?? ''));
        if ($counted < 0) {
            $this->setFlash('error', 'Montant compté invalide.');
            redirect($back);
        }

        $who = $this->whoFromPost();
        if ($who === '') {
            $this->setFlash('error', 'Indique qui tu es (prénom, nom et rôle) via la pastille en haut à droite avant d\'enregistrer.');
            redirect($back);
        }

        $label = trim((string) ($_POST['label'] ?? ''));
        $res   = CashLedger::recordCount($counted, $label, $who, null);
        AuditLog::log('cash.count', null, 'cash', $res['count_id'], [
            'counted'     => $counted,
            'theoretical' => round($counted - $res['ecart'], 2),
            'ecart'       => $res['ecart'],
            'via'         => 'kiosque',
            'who'         => $who,
        ]);

        if ($res['ecart'] < 0) {
            $this->setFlash('error', sprintf('Manquant de %s constaté — caisse réalignée.', formatPrice(abs($res['ecart']))));
        } elseif ($res['ecart'] > 0) {
            $this->setFlash('success', sprintf('Surplus de %s constaté — caisse réalignée.', formatPrice($res['ecart'])));
        } else {
            $this->setFlash('success', 'Comptage exact : aucun écart.');
        }

        redirect($back);
    }

    /**
     * Comptage inventaire « à l'aveugle » (théoriques non affichés,
     * produits en pause listés sans saisie).
     */
    public function inventaire(string $token): void
    {
        if (!$this->anyTokenOk($token)) {
            $this->deny();

            return;
        }

        $pausedKeys = array_flip(ProductDiscontinued::keys());
        $parser = new SumUpCsvParser();

        // Catégorie de chaque clé produit (la plus fréquente dans les ventes)
        // pour trier la liste par rayon.
        $catByKey = [];
        try {
            $catRows = InventoryCount::connection()->query(
                "SELECT COALESCE(NULLIF(TRIM(product_key), ''), TRIM(description)) AS k,
                        MAX(COALESCE(category, '')) AS cat
                 FROM sales
                 WHERE category IS NOT NULL AND category <> ''
                 GROUP BY k"
            )->fetchAll();
            foreach ($catRows as $row) {
                $catByKey[strtolower(trim((string) $row['k']))] = trim((string) $row['cat']);
            }
        } catch (\Throwable $e) {
            // Pas de catégories disponibles : tri alphabétique simple.
            error_log('[kiosque] catégories indisponibles : ' . $e->getMessage());
        }

        $active = [];
        $paused = [];
        foreach (Sale::distinctProducts() as $key) {
            $k = trim((string) $key);
            // Artefact SumUp (« custom amount », « montant personnalisé ») :
            // jamais un produit à compter.
            if ($k === '' || $parser->isCustomAmount($k)) {
                continue;
            }
            $cat = $catByKey[strtolower($k)] ?? 'Divers';
            if ($cat === '') {
                $cat = 'Divers';
            }
            if (isset($pausedKeys[$k])) {
                $paused[] = ['key' => $k, 'cat' => $cat, 'paused' => true];
            } else {
                $active[] = ['key' => $k, 'cat' => $cat, 'paused' => false];
            }
        }
        $byCat = static fn(array $a, array $b): int =>
            strcasecmp($a['cat'], $b['cat']) ?: strcasecmp($a['key'], $b['key']);
        usort($active, $byCat);
        usort($paused, $byCat);

        $this->renderKiosk('admin/compta/kiosk-inventaire', [
            'title'  => 'Comptage inventaire',
            'token'  => $token,
            'hubUrl' => $this->hubUrlFor($token),
            'active' => $active,
            'paused' => $paused,
        ]);
    }

    /**
     * Enregistre le comptage inventaire saisi en kiosque : même mécanique
     * que les pages admin (théorique côté serveur, écarts historisés,
     * synchro carte non bloquante). Les clés en pause sont ignorées.
     */
    public function inventaireSave(string $token): void
    {
        if (!$this->anyTokenOk($token)) {
            $this->deny();

            return;
        }

        $back = url('/kiosque/comptage/inventaire/' . $token);

        $counts = $_POST['count'] ?? [];
        if (!is_array($counts)) {
            $counts = [];
        }

        $pausedKeys = array_flip(ProductDiscontinued::keys());
        $who = $this->whoFromPost();
        if ($who === '') {
            $this->setFlash('error', 'Indique qui tu es (prénom, nom et rôle) via la pastille en haut à droite avant d\'enregistrer.');
            redirect($back);
        }
        $done = 0;
        $gaps = 0;

        foreach ($counts as $key => $value) {
            $productKey = trim((string) $key);
            if ($productKey === '' || trim((string) $value) === '' || isset($pausedKeys[$productKey])) {
                continue;
            }

            $counted = (int) $value;
            if ($counted < 0) {
                continue;
            }

            InventoryCount::record($productKey, $counted, null, $who);
            $done++;
        }

        if ($done === 0) {
            $this->setFlash('error', 'Aucun comptage saisi.');
            redirect($back);
        }

        AuditLog::log('compta.inventory.count', null, 'inventory_count', null, [
            'products' => $done,
            'via'      => 'kiosque',
            'who'      => $who,
        ]);

        try {
            ProductAutoSync::sync();
        } catch (\Throwable) {
            // Jamais bloquant.
        }
        StockPublic::invalidate();

        $this->setFlash('success', sprintf('%d produit(s) compté(s).', $done));

        redirect($back);
    }

    /**
     * Met en pause / réactive un produit DEPUIS le kiosque (réponse JSON,
     * consommée par la page de comptage qui déplace la ligne sans recharger).
     * Mêmes mécaniques que l'admin : drapeau product_discontinued, aucune
     * suppression de données, traçabilité (updated_by = identité kiosque).
     */
    public function inventairePause(string $token): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!$this->anyTokenOk($token)) {
            http_response_code(403);
            echo '{"ok":false,"error":"lien invalide"}';

            return;
        }

        $key   = trim((string) ($_POST['key'] ?? ''));
        $state = ($_POST['state'] ?? '') === 'resume' ? 'resume' : 'pause';
        $who   = $this->whoFromPost();

        if ($who === '') {
            http_response_code(422);
            echo '{"ok":false,"error":"identite requise (prenom, nom, role)"}';

            return;
        }

        $parser = new SumUpCsvParser();
        if ($key === '' || $parser->isCustomAmount($key)) {
            http_response_code(422);
            echo '{"ok":false,"error":"produit invalide"}';

            return;
        }

        // La clé doit exister (produit déjà vendu) ou être déjà en pause :
        // impossible de créer une entrée parasite via le kiosque.
        $known = ProductDiscontinued::isDiscontinued($key);
        if (!$known) {
            foreach (Sale::distinctProducts() as $k) {
                if (trim((string) $k) === $key) {
                    $known = true;
                    break;
                }
            }
        }
        if (!$known) {
            http_response_code(422);
            echo '{"ok":false,"error":"produit inconnu"}';

            return;
        }

        if ($state === 'pause') {
            ProductDiscontinued::mark($key, $who);
        } else {
            ProductDiscontinued::resume($key);
        }

        AuditLog::log(
            $state === 'pause' ? 'compta.product.pause' : 'compta.product.resume',
            null,
            'product_discontinued',
            $key,
            ['via' => 'kiosque', 'who' => $who]
        );

        try {
            ProductAutoSync::sync();
        } catch (\Throwable) {
            // Jamais bloquant.
        }
        StockPublic::invalidate();

        echo json_encode(['ok' => true, 'key' => $key, 'paused' => $state === 'pause']);
    }
}
