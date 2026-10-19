<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Compta\ComptaCalc;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
/**
 * Livre comptable (groupe Système) : retranscription prête à recopier dans
 * le livret papier — Date | Objet | Débit | Crédit, avec pour chaque
 * dépense/achat une seconde ligne « ticket » (les achats du même jour
 * chez le même fournisseur portant le même n° de facture/ticket sont
 * fusionnés en une écriture au total du jour, référencés par leur n°
 * de facture ; deux factures distinctes du même jour chez le même
 * fournisseur font deux écritures) et les ventes récapitulées en une
 * unique ligne de clôture en fin de livre. L'« équilibrage » de pied de
 * page est un SOLDE DE TRÉSORERIE (encaissements − décaissements de la
 * période), PAS le bénéfice : le bénéfice net (coût des produits vendus
 * déduit, montants personnalisés exclus) est calculé séparément
 * (Sale::aggregatesBetween()['profit']) et affiché sur sa propre ligne.
 */
final class AdminLedgerController extends AdminBaseController
{
    public function index(): void
    {
        $this->guardSystemOrPage('ledger');

        [$from, $to, $preset] = $this->periodFromRequest();
        $entries = $this->buildEntries($from, $to);

        $this->renderAdmin('admin/ledger/index', [
            'title'   => 'Livre comptable',
            'user'    => Auth::user() ?? [],
            // Onglets .compta-tab stylés par compta.css : le layout admin
            // ne la charge que sur /compta ou sur ce drapeau, et
            // /admin/ledger n'est ni l'un ni l'autre.
            'loadComptaCss' => true,
            'entries' => $entries,
            'from'    => $from,
            'to'      => $to,
            'preset'  => $preset,
            'totalDebit'  => $entries['total_debit'],
            'totalCredit' => $entries['total_credit'],
            'balance'     => $entries['balance'],
            // Vignette « Derniers tickets & dépenses » : liste FUSIONNÉE et
            // chronologique (dépenses ET groupes d'achats mélangés, du plus
            // récent au plus ancien — même ordre que le livre). Les anciennes
            // variables séparées (recentExpenses / recentPurchases, 8 + 8)
            // cachaient les saisies au-delà des 8 premières et rangeaient
            // chaque type dans son ordre : une dépense datée d'un ancien
            // jour n'apparaissait jamais en haut, la vignette semblait
            // figée alors que le livre, lui, montrait tout.
            'recentItems' => self::recentItems(20),
            // Suggestions produit (datalist) pour l'enregistrement d'un
            // achat depuis le livre — même fusion que la liste de picking
            // de la page Achats, en version simplifiée. Méthode statique
            // publique : réutilisée telle quelle par le livre kiosque
            // (KioskComptageController::ledger).
            'purchaseProductKeys' => self::purchaseProductKeys(),
        ]);
    }

    /**
     * Clés produits proposées à l'autocomplétion des achats saisis depuis
     * le livre comptable : produits vendus (SumUp) + noms de la carte
     * admin, fusion dédupliquée puis triée naturellement (même esprit que
     * la liste de picking de la page Achats — vue purchases.php).
     * Statique publique : partagée avec la version kiosque du livre.
     *
     * @return list<string>
     */
    public static function purchaseProductKeys(): array
    {
        $keys = [];        foreach (Sale::distinctProducts() as $key) {
            $key = trim((string) $key);
            if ($key !== '') {
                $keys[] = $key;
            }
        }
        foreach (Product::allForAdmin() as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name !== '') {
                $keys[] = $name;
            }
        }

        $keys = array_values(array_unique($keys));
        usort($keys, 'strnatcasecmp');

        return $keys;
    }

    /**
     * Référence de regroupement d'un achat dans le livre comptable et la
     * liste « Derniers tickets » : le fournisseur quand il est renseigné,
     * sinon le n° de facture/ticket partagé (une course scannée sans
     * fournisseur reste fusionnée en une écriture), sinon la clé produit
     * (repli historique). Méthode pure (aucun accès POST/DB) : testée
     * unitairement.
     */
    public static function purchaseGroupRef(string $supplier, string $invoice, string $productKey): string
    {
        $supplier = trim($supplier);
        if ($supplier !== '') {
            return $supplier;
        }

        $invoice = trim($invoice);
        if ($invoice !== '') {
            return $invoice;
        }

        return trim($productKey);
    }

    /**
     * Clé de regroupement complète d'un achat (livre comptable et liste
     * « Derniers tickets ») : jour + référence (purchaseGroupRef) + n° de
     * facture NORMALISÉ (minuscules, espaces bornes retirés ; '' si
     * absent). Deux factures distinctes saisies le même jour chez le
     * même fournisseur donnent donc deux écritures — chacune avec son
     * total et son justificatif. Méthode pure (aucun accès POST/DB) :
     * testée unitairement.
     */
    public static function purchaseGroupKey(string $date, string $supplier, string $invoice, string $productKey): string
    {
        $invoiceNorm = strtolower(trim($invoice));

        return $date . '|' . self::purchaseGroupRef($supplier, $invoice, $productKey) . '|' . $invoiceNorm;
    }

    /**
     * Dernières courses enregistrées en ACHATS (page Achats ou livre
     * comptable), groupées comme dans le livre : même jour + même
     * fournisseur + même n° de facture normalisé (une course saisie sans
     * fournisseur reste fusionnée par n° de facture ; deux factures
     * distinctes du même jour chez le même fournisseur font deux
     * groupes) — voir purchaseGroupKey(). Rend par groupe : total TTC,
     * n° de facture (« invoice », alias « invoice_number »), justificatif
     * du scan conservé (premier trouvé dans le groupe, aussi listé avec
     * les autres dans « receipts ») et nombre de lignes. Statique
     * publique : partagée avec la version kiosque du livre.
     *
     * @return list<array<string,mixed>>
     */
    public static function recentPurchaseGroups(int $limit = 20): array
    {
        $groups = [];
        try {
            foreach (Purchase::recent(200) as $p) {
                $date = (string) $p['purchased_at'];
                $invoiceRaw = (string) ($p['invoice_number'] ?? '');
                $ref = self::purchaseGroupRef(
                    (string) ($p['supplier'] ?? ''),
                    $invoiceRaw,
                    (string) ($p['product_key'] ?? '')
                );
                $key = self::purchaseGroupKey(
                    $date,
                    (string) ($p['supplier'] ?? ''),
                    $invoiceRaw,
                    (string) ($p['product_key'] ?? '')
                );
                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'date'          => $date,
                        'supplier'      => $ref,
                        'total_ttc'     => 0.0,
                        'invoice'       => null,
                        'invoice_number' => null,
                        'receipt_path'  => null,
                        'receipts'      => [],
                        'lines'         => 0,
                        // Dernière saisie du groupe (max des created_at de
                        // ses lignes) : clé de tri secondaire de la liste
                        // fusionnée recentItems().
                        'created_at'    => (string) ($p['created_at'] ?? ''),
                    ];
                }
                $groups[$key]['total_ttc'] += (float) $p['total_ttc'];
                $groups[$key]['lines']++;
                $createdAt = (string) ($p['created_at'] ?? '');
                if ($createdAt > $groups[$key]['created_at']) {
                    $groups[$key]['created_at'] = $createdAt;
                }
                $invoice = trim($invoiceRaw);
                if ($invoice !== '' && $groups[$key]['invoice'] === null) {
                    $groups[$key]['invoice'] = $invoice;
                    $groups[$key]['invoice_number'] = $invoice;
                }
                $receipt = trim((string) ($p['receipt_path'] ?? ''));
                if ($receipt !== '') {
                    if ($groups[$key]['receipt_path'] === null) {
                        $groups[$key]['receipt_path'] = $receipt;
                    }
                    if (!in_array($receipt, $groups[$key]['receipts'], true)) {
                        $groups[$key]['receipts'][] = $receipt;
                    }
                }
            }
        } catch (\Throwable) {
            return [];
        }

        // La requête est déjà DESC (plus récent d'abord) : l'ordre
        // d'insertion des groupes suit.
        return array_slice(array_values($groups), 0, max(1, $limit));
    }

    /**
     * Vignette « Derniers tickets & dépenses » : dépenses et groupes
     * d'achats FUSIONNÉS en une seule liste chronologique, de la plus
     * récente à la plus ancienne (même lecture que le livre) — à date
     * égale, la saisie la plus récente d'abord (created_at DESC).
     *
     * Chaque élément porte « type » : 'purchase' (groupe d'achats,
     * structure de recentPurchaseGroups()) ou 'expense' (dépense,
     * structure de Expense::recent()). Une dépense saisie aujourd'hui
     * mais datée d'un ancien jour apparaît donc à SA place dans la
     * liste, visible, au lieu d'être cachée sous les achats.
     *
     * Statique publique : partagée avec la version kiosque du livre.
     *
     * @return list<array<string,mixed>>
     */
    public static function recentItems(int $limit = 20): array
    {
        $limit = max(1, $limit);

        $items = [];
        foreach (self::recentPurchaseGroups($limit * 2) as $g) {
            $g['type'] = 'purchase';
            $items[] = $g;
        }
        foreach (Expense::recent($limit * 2) as $e) {
            $e['type'] = 'expense';
            $items[] = $e;
        }

        usort($items, static function (array $a, array $b): int {
            $dateA = (string) ($a['type'] === 'purchase' ? $a['date'] : $a['spent_at']);
            $dateB = (string) ($b['type'] === 'purchase' ? $b['date'] : $b['spent_at']);
            if ($dateA !== $dateB) {
                return strcmp($dateB, $dateA);
            }

            return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        });

        return array_slice($items, 0, $limit);
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    public function periodFromRequest(): array
    {
        $period = ComptaCalc::resolvePeriod(
            $_GET['period'] ?? null,
            $_GET['du'] ?? null,
            $_GET['au'] ?? null
        );

        return [$period['from'], $period['to'], $period['preset']];
    }

    /**
     * Assemble les écritures du livre : achats (une seule ligne par
     * jour + fournisseur, sinon par jour + n° de facture partagé, avec
     * le gros total du jour), dépenses (débits, avec ligne « ticket »)
     * et ventes (crédits, récapitulées en une unique ligne de clôture
     * placée en fin de livre, dont le « ticket » détaille la répartition
     * liquide/carte).
     *
     * « balance » est un SOLDE DE TRÉSORERIE : ventes TTC − (achats +
     * dépenses) TTC de la période. Ce n'est pas le bénéfice — les achats
     * incluent du stock pas encore vendu, les ventes du stock acheté
     * avant. Le bénéfice net (coût des produits vendus déduit) est
     * renvoyé séparément dans « sales_profit ».
     *
     * @return array{rows:list<array<string,mixed>>, total_debit:float, total_credit:float, balance:float, sales_profit:float}
     */
    public function buildEntries(string $from, string $to): array
    {
        $rows = [];

        // ── Débits : ACHATS (une ligne par jour + référence + facture) ──
        // Tous les reçus d'un même jour chez le même fournisseur portant
        // le même n° de facture/ticket normalisé sont fusionnés en une
        // écriture unique portant le total (voir purchaseGroupKey()) ;
        // deux factures distinctes du même jour chez le même fournisseur
        // font deux écritures. Les reçus individuels restent listés en
        // lignes « ticket », référencés par le n° de facture quand il est
        // renseigné (sinon n° interne).
        $purchaseGroups = [];
        foreach (Purchase::between($from, $to, 500) as $p) {
            $date = (string) $p['purchased_at'];
            $key = self::purchaseGroupKey(
                $date,
                (string) ($p['supplier'] ?? ''),
                (string) ($p['invoice_number'] ?? ''),
                (string) ($p['product_key'] ?? '')
            );
            if (!isset($purchaseGroups[$key])) {
                $purchaseGroups[$key] = [
                    'date'     => $date,
                    'supplier' => self::purchaseGroupRef(
                        (string) ($p['supplier'] ?? ''),
                        (string) ($p['invoice_number'] ?? ''),
                        (string) ($p['product_key'] ?? '')
                    ),
                    'total'    => 0.0,
                    'purchases' => [],
                ];
            }
            $purchaseGroups[$key]['total'] += (float) $p['total_ttc'];
            $purchaseGroups[$key]['purchases'][] = $p;
        }
        foreach ($purchaseGroups as $g) {
            // Reçus du plus ancien au plus récent (la requête est DESC).
            $ps = array_reverse($g['purchases']);
            // Référence de chaque reçu : n° de facture partagé si toute la
            // course en a un seul (cas standard), sinon référence par ligne.
            $invoices = [];
            foreach ($ps as $p) {
                $inv = trim((string) ($p['invoice_number'] ?? ''));
                if ($inv !== '' && !in_array($inv, $invoices, true)) {
                    $invoices[] = $inv;
                }
            }
            $tickets = [];
            if (count($invoices) === 1) {
                $tickets[] = 'facture ' . $invoices[0];
                foreach ($ps as $p) {
                    $tickets[] = (string) $p['product_key'] . ' ×' . (int) $p['quantity'];
                }
            } else {
                foreach ($ps as $p) {
                    $inv = trim((string) ($p['invoice_number'] ?? ''));
                    $ref = $inv !== '' ? 'facture ' . $inv : '#' . substr((string) $p['id'], -8);
                    $tickets[] = $ref . ' · ' . (string) $p['product_key'] . ' ×' . (int) $p['quantity'];
                }
            }
            $rows[] = [
                'date'  => $g['date'],
                'kind'  => 'purchase',
                'label' => 'Achat — ' . $g['supplier'],
                'debit' => round($g['total'], 2),
                'credit' => 0.0,
                'ticket' => implode("\n", $tickets),
                // Ids des lignes d'achats du groupe (même regroupement que
                // l'affichage, cf. purchaseGroupKey()) : clé ADDITIONNELLE
                // pour la suppression groupée depuis le livre (admin) —
                // aucun calcul n'en dépend.
                'ids' => array_map(
                    static fn (array $p): string => (string) $p['id'],
                    $g['purchases']
                ),
            ];
        }

        // ── Débits : DÉPENSES (avec n° de facture / ticket si saisi) ────
        foreach (Expense::between($from, $to) as $e) {
            $invoice = trim((string) ($e['invoice_number'] ?? ''));
            $ticketParts = ['#' . substr((string) $e['id'], -8)];
            if ($invoice !== '') {
                $ticketParts[] = 'facture ' . $invoice;
            }
            $rows[] = [
                'date'  => (string) $e['spent_at'],
                'kind'  => 'expense',
                'label' => 'Dépense — ' . trim((string) $e['label']) . ' (' . (string) $e['category'] . ')',
                'debit' => (float) $e['amount_ttc'],
                'credit' => 0.0,
                'ticket' => $this->ticketLine('', $ticketParts),
                // Id de la dépense : clé ADDITIONNELLE pour la suppression
                // depuis le livre (admin) — aucun calcul n'en dépend.
                'id' => (string) $e['id'],
            ];
        }

        // ── Crédits : VENTES (récapitulées en une seule ligne) ──────────
        // Le total de la période est totalisé ici, la ligne de clôture
        // est ajoutée après le tri, tout en fin de livre.
        $salesCa = 0.0;
        $salesTx = 0;
        foreach (Sale::dailyRevenueBetween($from, $to) as $s) {
            $salesCa += (float) $s['ca'];
            $salesTx += (int) $s['tx'];
        }

        // Tri chronologique ; à date égale, les débits avant les crédits.
        usort($rows, static function (array $a, array $b): int {
            if ($a['date'] !== $b['date']) {
                return strcmp($a['date'], $b['date']);
            }

            return strcmp($b['kind'], $a['kind']);
        });

        // Ligne de clôture des ventes, en toute fin de livre. Le « ticket »
        // détaille la répartition du CA entre liquide et carte (montant +
        // nombre de transactions par moyen) : la somme des deux tombe sur
        // le crédit de la ligne.
        if ($salesTx > 0) {
            $split = Sale::paymentSplitDetailBetween($from, $to);
            $rows[] = [
                'date'  => $to,
                'kind'  => 'sales',
                'label' => 'Ventes cafétéria (' . $salesTx . ' transaction(s))',
                'debit' => 0.0,
                'credit' => round($salesCa, 2),
                'ticket' => 'Liquide : ' . formatPrice(round((float) $split['LIQUIDE']['ca'], 2))
                    . ' — ' . (int) $split['LIQUIDE']['tx'] . ' transaction(s)'
                    . "\n"
                    . 'Carte : ' . formatPrice(round((float) $split['CARTE']['ca'], 2))
                    . ' — ' . (int) $split['CARTE']['tx'] . ' transaction(s)',
            ];
        }

        $totalDebit = 0.0;
        $totalCredit = 0.0;
        foreach ($rows as $r) {
            $totalDebit += (float) $r['debit'];
            $totalCredit += (float) $r['credit'];
        }

        return [
            'rows' => $rows,
            'total_debit' => round($totalDebit, 2),
            'total_credit' => round($totalCredit, 2),
            // Solde de trésorerie (encaissements − décaissements), pas le
            // bénéfice : voir docblock de la classe.
            'balance' => round($totalCredit - $totalDebit, 2),
            // Bénéfice net de la période (coût des produits vendus déduit,
            // montants personnalisés exclus) — distinct du solde ci-dessus.
            'sales_profit' => round((float) Sale::aggregatesBetween($from, $to)['profit'], 2),
        ];
    }

    /**
     * Ligne « ticket » : préfixe non vide + parties join par « · ».
     *
     * @param list<string> $parts
     */
    private function ticketLine(string $prefix, array $parts): string
    {
        $parts = array_values(array_filter($parts, static fn (string $s): bool => trim($s) !== ''));
        if ($parts === []) {
            return trim($prefix) !== '' ? trim($prefix) : '';
        }

        return trim($prefix . ' ' . implode(' · ', $parts));
    }
}
