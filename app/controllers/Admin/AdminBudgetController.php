<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Compta\ComptaCalc;
use App\Models\Budget;
use App\Models\Expense;
use App\Models\Purchase;
use App\Models\Sale;

/**
 * Budgets prévisionnels vs réalisé : objectif de CA et enveloppes de
 * dépenses par mois. Réservé aux rôles ADMIN et TRESORERIE.
 *
 * La consultation agrège les mois couverts par la période « Période » ;
 * l'édition (formulaire de save) cible toujours le DERNIER mois couvert.
 */
final class AdminBudgetController extends AdminBaseController
{
    // -----------------------------------------------------------------
    //  Prévu vs réalisé
    // -----------------------------------------------------------------

    public function index(): void
    {
        $user = $this->guardCompta();

        $period = ComptaCalc::resolvePeriod($_GET['period'] ?? null, $_GET['from'] ?? null, $_GET['to'] ?? null);

        // Mois couverts par la période (plafonnés aux 24 derniers mois) :
        // du 1er jour du mois de départ à celui du mois d'arrivée. Sans
        // bornes (« Tout ») → 24 derniers mois.
        $today = new \DateTimeImmutable('today');
        $start = ($period['from'] !== null
            ? new \DateTimeImmutable($period['from'])
            : $today->modify('first day of this month')->modify('-23 months'))
            ->modify('first day of this month');
        $end = ($period['to'] !== null
            ? new \DateTimeImmutable($period['to'])
            : $today)
            ->modify('first day of this month');

        $months = [];
        $cur = $start;
        while ($cur <= $end) {
            $months[] = [(int) $cur->format('Y'), (int) $cur->format('n')];
            $cur = $cur->modify('+1 month');
        }
        if (count($months) > 24) {
            // Période trop large : on garde les 24 derniers mois.
            $months = array_slice($months, -24);
        }

        // Agrégation en PHP des budgets et des réalisés sur ces mois.
        // L'enveloppe MATIERE se nourrit des ACHATS de stock (page Achats
        // & stock) : c'est la vraie sortie d'argent « courses cafétéria »,
        // en plus d'éventuelles dépenses MATIERE saisies manuellement.
        $plannedByCat = [];
        $realizedCa = 0.0;
        $realizedExpenses = [];
        $purchasesByYear = [];
        foreach ($months as [$y, $m]) {
            foreach (Budget::forMonth($y, $m) as $cat => $planned) {
                $plannedByCat[$cat] = ($plannedByCat[$cat] ?? 0.0) + $planned;
            }
            $realizedCa += (float) Sale::monthAggregates($y, $m)['ca'];
            foreach (Expense::byCategory($y, $m) as $c) {
                $realizedExpenses[$c['category']] = ($realizedExpenses[$c['category']] ?? 0.0) + (float) $c['ttc'];
            }

            if (!isset($purchasesByYear[$y])) {
                $purchasesByYear[$y] = [];
                foreach (Purchase::monthlyTotals($y) as $p) {
                    $purchasesByYear[$y][(int) $p['m']] = (float) $p['ttc'];
                }
            }
            $realizedExpenses['MATIERE'] = ($realizedExpenses['MATIERE'] ?? 0.0)
                + ($purchasesByYear[$y][$m] ?? 0.0);
        }

        // Le formulaire édite le dernier mois couvert par la période.
        [$lastY, $lastM] = $months[count($months) - 1];
        $editMonth = [
            'year'  => $lastY,
            'month' => $lastM,
            'value' => sprintf('%04d-%02d', $lastY, $lastM),
        ];

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
                'planned'  => (float) ($plannedByCat['CA'] ?? 0),
                'realized' => $realizedCa,
            ],
        ];
        foreach (Expense::CATEGORIES as $cat) {
            $rows[] = [
                'key'      => $cat,
                'label'    => $labels[$cat] ?? $cat,
                'planned'  => (float) ($plannedByCat[$cat] ?? 0),
                'realized' => (float) ($realizedExpenses[$cat] ?? 0),
            ];
        }

        $totals = ['planned' => 0.0, 'realized' => 0.0];
        foreach ($rows as $r) {
            $totals['planned'] += $r['planned'];
            $totals['realized'] += $r['realized'];
        }

        $this->renderAdmin('admin/compta/budgets', [
            'title'         => 'Budgets',
            'user'          => $user,
            'period'        => $period,
            'periodOptions' => ComptaCalc::PERIOD_OPTIONS,
            'editMonth'     => $editMonth,
            'monthsCount'   => count($months),
            'rows'          => $rows,
            'totals'        => $totals,
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
