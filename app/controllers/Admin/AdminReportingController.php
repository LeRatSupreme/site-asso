<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Expense;
use App\Models\Sale;

/**
 * Rapport annuel : vue 12 mois (CA, bénéfice, dépenses, résultat net) avec
 * comparaison N-1, synthèse TVA et statistiques de paniers.
 *
 * Réservé aux rôles ADMIN et TRESORERIE (voir guardCompta()).
 */
final class AdminReportingController extends AdminBaseController
{
    public function annual(): void
    {
        $user = $this->guardCompta();

        // Année demandée via l'URL (>= 2020), sinon année courante.
        $currentYear = (int) date('Y');
        $year = $currentYear;
        if (isset($_GET['year']) && preg_match('/^\d{4}$/', (string) $_GET['year']) && (int) $_GET['year'] >= 2020) {
            $year = (int) $_GET['year'];
        }

        // Années disponibles : années présentes dans les ventes + année courante.
        $years = array_values(array_unique(array_merge(Sale::distinctYears(), [$currentYear])));
        rsort($years);

        // Ventes de l'année, indexées par n° de mois.
        $salesByMonth = [];
        foreach (Sale::byMonth($year) as $r) {
            $salesByMonth[(int) $r['m']] = [
                'ca'     => (float) $r['ca'],
                'profit' => (float) $r['profit'],
                'qty'    => (int) $r['qty'],
            ];
        }

        // Dépenses de l'année, indexées par n° de mois.
        $expensesByMonth = [];
        foreach (Expense::monthlyTotals($year) as $r) {
            $expensesByMonth[(int) $r['m']] = (float) $r['ttc'];
        }

        // CA de l'année précédente, indexé par n° de mois (comparaison N-1).
        $salesPrevByMonth = [];
        foreach (Sale::byMonth($year - 1) as $r) {
            $salesPrevByMonth[(int) $r['m']] = (float) $r['ca'];
        }

        // 12 lignes mensuelles + totaux.
        $rows = [];
        $totals = ['ca' => 0.0, 'profit' => 0.0, 'expenses' => 0.0, 'net' => 0.0, 'caPrev' => 0.0];

        for ($m = 1; $m <= 12; $m++) {
            $ca = $salesByMonth[$m]['ca'] ?? 0.0;
            $profit = $salesByMonth[$m]['profit'] ?? 0.0;
            $expenses = $expensesByMonth[$m] ?? 0.0;
            $caPrev = $salesPrevByMonth[$m] ?? 0.0;

            $rows[] = [
                'm'        => $m,
                'ca'       => $ca,
                'profit'   => $profit,
                'margin'   => $ca > 0 ? round($profit / $ca * 100, 1) : null,
                'expenses' => $expenses,
                'net'      => $profit - $expenses,
                'caPrev'   => $caPrev,
                'delta'    => $caPrev > 0 ? round(($ca - $caPrev) / $caPrev * 100, 1) : null,
            ];

            $totals['ca'] += $ca;
            $totals['profit'] += $profit;
            $totals['expenses'] += $expenses;
            $totals['net'] += $profit - $expenses;
            $totals['caPrev'] += $caPrev;
        }

        $vat = Sale::vatByRate($year, null);
        $baskets = Sale::basketStats($year, null);

        // Export CSV ?
        if (isset($_GET['export']) && $_GET['export'] === 'csv') {
            $this->exportAnnualCsv($rows, $totals, $year);

            return;
        }

        $this->renderAdmin('admin/compta/annual', [
            'title'   => sprintf('Rapport annuel %d', $year),
            'user'    => $user,
            'year'    => $year,
            'years'   => $years,
            'rows'    => $rows,
            'totals'  => $totals,
            'vat'     => $vat,
            'baskets' => $baskets,
        ]);
    }

    /**
     * Stream le rapport annuel en CSV (termine par exit, ne retourne jamais).
     *
     * @param list<array<string,mixed>> $rows
     * @param array{ca:float,profit:float,expenses:float,net:float,caPrev:float} $totals
     */
    private function exportAnnualCsv(array $rows, array $totals, int $year): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sprintf('rapport_annuel_aeic_%d.csv', $year) . '"');
        $out = fopen('php://output', 'w');
        fprintf($out, "\xEF\xBB\xBF"); // BOM UTF-8 pour Excel
        fputcsv($out, ['Mois', 'CA TTC', 'Bénéfice', 'Marge %', 'Dépenses TTC', 'Résultat net', 'CA N-1', 'Évolution %']);
        foreach ($rows as $r) {
            fputcsv($out, [
                sprintf('%02d/%04d', (int) $r['m'], $year),
                sprintf('%.2f', (float) $r['ca']),
                sprintf('%.2f', (float) $r['profit']),
                $r['margin'] === null ? '' : sprintf('%.1f', (float) $r['margin']),
                sprintf('%.2f', (float) $r['expenses']),
                sprintf('%.2f', (float) $r['net']),
                sprintf('%.2f', (float) $r['caPrev']),
                $r['delta'] === null ? '' : sprintf('%.1f', (float) $r['delta']),
            ]);
        }
        fputcsv($out, [
            'TOTAL',
            sprintf('%.2f', $totals['ca']),
            sprintf('%.2f', $totals['profit']),
            $totals['ca'] > 0 ? sprintf('%.1f', round($totals['profit'] / $totals['ca'] * 100, 1)) : '',
            sprintf('%.2f', $totals['expenses']),
            sprintf('%.2f', $totals['net']),
            sprintf('%.2f', $totals['caPrev']),
            $totals['caPrev'] > 0 ? sprintf('%.1f', round(($totals['ca'] - $totals['caPrev']) / $totals['caPrev'] * 100, 1)) : '',
        ]);
        fclose($out);
        exit;
    }
}
