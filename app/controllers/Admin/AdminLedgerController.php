<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Compta\ComptaCalc;
use App\Models\Expense;
use App\Models\Purchase;
use App\Models\Sale;

/**
 * Livre comptable (groupe Système) : retranscription prête à recopier dans
 * le livret papier — Date | Objet | Débit | Crédit, avec pour chaque
 * dépense/achat une seconde ligne « ticket », et l'équilibrage final
 * (bénéfice ou déficit) en pied de page.
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
        ]);
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
     * Assemble les écritures du livre : ventes (crédits, une ligne par
     * jour), achats et dépenses (débits, avec ligne « ticket »).
     *
     * @return array{rows:list<array<string,mixed>>, total_debit:float, total_credit:float, balance:float}
     */
    public function buildEntries(string $from, string $to): array
    {
        $rows = [];

        // ── Débits : ACHATS (groupés par reçu = date + fournisseur) ─────
        foreach (Purchase::between($from, $to, 500) as $p) {
            $rows[] = [
                'date'  => (string) $p['purchased_at'],
                'kind'  => 'purchase',
                'label' => 'Achat — ' . trim((string) ($p['supplier'] ?? '') !== '' ? $p['supplier'] : (string) $p['product_key']),
                'debit' => (float) $p['total_ttc'],
                'credit' => 0.0,
                'ticket' => $this->ticketLine('#' . substr((string) $p['id'], -8), [
                    (string) ($p['supplier'] ?? ''),
                    (string) $p['product_key'] . ' ×' . (int) $p['quantity'],
                ]),
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

        // ── Crédits : VENTES (une ligne par jour) ───────────────────────
        foreach (Sale::dailyRevenueBetween($from, $to) as $s) {
            $rows[] = [
                'date'  => $s['d'],
                'kind'  => 'sales',
                'label' => 'Ventes cafétéria (' . $s['tx'] . ' transaction(s))',
                'debit' => 0.0,
                'credit' => round($s['ca'], 2),
                'ticket' => '',
            ];
        }

        // Tri chronologique ; à date égale, les débits avant les crédits.
        usort($rows, static function (array $a, array $b): int {
            if ($a['date'] !== $b['date']) {
                return strcmp($a['date'], $b['date']);
            }

            return strcmp($b['kind'], $a['kind']);
        });

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
            'balance' => round($totalCredit - $totalDebit, 2),
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
