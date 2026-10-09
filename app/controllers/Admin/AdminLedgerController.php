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
 * dépense/achat une seconde ligne « ticket » (les achats du même jour chez
 * le même fournisseur sont fusionnés en une écriture au total du jour,
 * référencés par leur n° de facture) et les ventes récapitulées en une
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
            'entries' => $entries,
            'from'    => $from,
            'to'      => $to,
            'preset'  => $preset,
            'totalDebit'  => $entries['total_debit'],
            'totalCredit' => $entries['total_credit'],
            'balance'     => $entries['balance'],
            'recentExpenses' => Expense::recent(8),
            // Suggestions produit (datalist) pour l'enregistrement d'un
            // achat depuis le livre — même fusion que la liste de picking
            // de la page Achats, en version simplifiée.
            'purchaseProductKeys' => $this->purchaseProductKeys(),
        ]);
    }

    /**
     * Clés produits proposées à l'autocomplétion des achats saisis depuis
     * le livre comptable : produits vendus (SumUp) + noms de la carte
     * admin, fusion dédupliquée puis triée naturellement (même esprit que
     * la liste de picking de la page Achats — vue purchases.php).
     *
     * @return list<string>
     */
    private function purchaseProductKeys(): array
    {
        $keys = [];
        foreach (Sale::distinctProducts() as $key) {
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
     * jour + fournisseur, avec le gros total du jour), dépenses (débits,
     * avec ligne « ticket ») et ventes (crédits, récapitulées en une
     * unique ligne de clôture placée en fin de livre, dont le « ticket »
     * détaille la répartition liquide/carte).
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

        // ── Débits : ACHATS (une ligne par jour + fournisseur) ──────────
        // Tous les reçus d'un même jour chez le même fournisseur sont
        // fusionnés en une écriture unique portant le total : les reçus
        // individuels restent listés en lignes « ticket », référencés par
        // le n° de facture quand il est renseigné (sinon n° interne).
        $purchaseGroups = [];
        foreach (Purchase::between($from, $to, 500) as $p) {
            $date = (string) $p['purchased_at'];
            $supplier = trim((string) ($p['supplier'] ?? '') !== '' ? $p['supplier'] : (string) $p['product_key']);
            $key = $date . '|' . $supplier;
            if (!isset($purchaseGroups[$key])) {
                $purchaseGroups[$key] = [
                    'date'     => $date,
                    'supplier' => $supplier,
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
