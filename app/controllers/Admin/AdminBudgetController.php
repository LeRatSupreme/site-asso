<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Budget;
use App\Models\Expense;
use App\Models\Sale;

/**
 * Budgets prévisionnels vs réalisé : objectif de CA et enveloppes de
 * dépenses par mois. Réservé aux rôles ADMIN et TRESORERIE.
 */
final class AdminBudgetController extends AdminBaseController
{
    /**
     * Résout le mois demandé (GET « YYYY-MM »), sinon mois calendaire courant.
     *
     * @return array{year:int,month:int,value:string}
     */
    private function resolveMonth(?string $param): array
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', (string) $param, $m)) {
            return ['year' => (int) $m[1], 'month' => (int) $m[2], 'value' => $param];
        }

        $now = new \DateTimeImmutable('first day of this month');

        return ['year' => (int) $now->format('Y'), 'month' => (int) $now->format('n'), 'value' => $now->format('Y-m')];
    }

    /**
     * Les 12 derniers mois glissants (du mois courant à mois courant − 11),
     * du plus récent au plus ancien — sans requête SQL.
     *
     * @return list<array{value:string,label:string}>
     */
    private function recentMonths(int $year, int $month): array
    {
        $current = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));

        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $d = $current->modify('-' . $i . ' months');
            $months[] = [
                'value' => $d->format('Y-m'),
                'label' => $d->format('m/Y'),
            ];
        }

        return $months;
    }

    // -----------------------------------------------------------------
    //  Prévu vs réalisé
    // -----------------------------------------------------------------

    public function index(): void
    {
        $user = $this->guardCompta();

        $month = $this->resolveMonth($_GET['month'] ?? null);

        $months = $this->recentMonths((int) (new \DateTimeImmutable())->format('Y'), (int) (new \DateTimeImmutable())->format('n'));
        if (!in_array($month['value'], array_column($months, 'value'), true)) {
            // Mois demandé hors fenêtre glissante : on le propose quand même.
            array_unshift($months, ['value' => $month['value'], 'label' => $month['value']]);
        }

        $budget = Budget::forMonth($month['year'], $month['month']);
        $realizedCa = (float) Sale::monthAggregates($month['year'], $month['month'])['ca'];

        $realizedExpenses = [];
        foreach (Expense::byCategory($month['year'], $month['month']) as $c) {
            $realizedExpenses[$c['category']] = (float) $c['ttc'];
        }

        $labels = [
            'CA'        => "Chiffre d'affaires",
            'MATIERE'   => 'Matière (cafétéria)',
            'MATERIEL'  => 'Matériel',
            'EVENEMENT' => 'Événements',
            'FRAIS'     => 'Frais (bancaires, abonnements)',
            'DIVERS'    => 'Divers',
        ];

        $rows = [
            [
                'key'      => 'CA',
                'label'    => $labels['CA'],
                'planned'  => (float) ($budget['CA'] ?? 0),
                'realized' => $realizedCa,
            ],
        ];
        foreach (Expense::CATEGORIES as $cat) {
            $rows[] = [
                'key'      => $cat,
                'label'    => $labels[$cat] ?? $cat,
                'planned'  => (float) ($budget[$cat] ?? 0),
                'realized' => (float) ($realizedExpenses[$cat] ?? 0),
            ];
        }

        $totals = ['planned' => 0.0, 'realized' => 0.0];
        foreach ($rows as $r) {
            $totals['planned'] += $r['planned'];
            $totals['realized'] += $r['realized'];
        }

        $this->renderAdmin('admin/compta/budgets', [
            'title'  => 'Budgets',
            'user'   => $user,
            'month'  => $month,
            'months' => $months,
            'rows'   => $rows,
            'totals' => $totals,
        ]);
    }

    // -----------------------------------------------------------------
    //  Enregistrement des enveloppes
    // -----------------------------------------------------------------

    public function save(): void
    {
        $this->guardCompta();

        $monthParam = (string) ($_POST['month'] ?? '');
        if (!preg_match('/^(\d{4})-(\d{2})$/', $monthParam, $m)) {
            $this->setFlash('error', 'Mois invalide.');
            redirect(url('/admin/compta/budgets'));
        }

        $year = (int) $m[1];
        $month = (int) $m[2];

        $planned = is_array($_POST['planned'] ?? null) ? $_POST['planned'] : [];
        $allowedKeys = array_merge(['CA'], Expense::CATEGORIES);

        $count = 0;
        foreach ($planned as $key => $value) {
            $key = trim((string) $key);
            if ($key === '' || !in_array($key, $allowedKeys, true)) {
                continue;
            }
            Budget::upsert($year, $month, $key, parseFrenchFloat((string) $value));
            $count++;
        }

        $this->audit('compta.budget.save', 'budget', $monthParam, [
            'month' => $monthParam,
            'lines' => $count,
        ]);
        $this->setFlash('success', sprintf('%d ligne(s) de budget enregistrée(s).', $count));
        redirect(url('/admin/compta/budgets?month=' . $monthParam));
    }
}
