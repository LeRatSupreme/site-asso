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
 * dépenses. Réservé aux rôles ADMIN et TRESORERIE.
 *
 * Le « réalisé » suit EXACTEMENT la période sélectionnée (bornes
 * journalières incluses) ; le « prévu » somme les budgets des mois
 * couverts, ajustés au prorata des jours de la période dans chaque mois
 * (une période d'1 jour sur septembre compare donc à 1/30 du budget de
 * septembre). L'édition (formulaire de save) cible le DERNIER mois couvert.
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

        // Bornes journalières réelles du « réalisé ». Sans bornes (« Tout »),
        // on borne à la fenêtre des mois couverts pour que prévu et réalisé
        // comparent la même durée.
        $fromDay = $period['from'] ?? $start->format('Y-m-d');
        $toDay = $period['to'] ?? $today->format('Y-m-d');
        $periodStart = new \DateTimeImmutable($fromDay);
        $periodEnd = new \DateTimeImmutable($toDay);

        // Prévu : budgets des mois couverts, ajustés au prorata du nombre
        // de jours de la période qui tombent dans chaque mois (budget
        // mensuel × jours couverts ÷ jours du mois).
        $plannedByCat = [];
        foreach ($months as [$y, $m]) {
            $monthStart = \DateTimeImmutable::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', $y, $m));
            $monthEnd = $monthStart->modify('last day of this month');
            $ovStart = $monthStart > $periodStart ? $monthStart : $periodStart;
            $ovEnd = $monthEnd < $periodEnd ? $monthEnd : $periodEnd;
            if ($ovEnd < $ovStart) {
                continue;
            }
            $factor = ((float) $ovStart->diff($ovEnd)->days + 1) / (float) $monthStart->format('t');
            foreach (Budget::forMonth($y, $m) as $cat => $planned) {
                $plannedByCat[$cat] = ($plannedByCat[$cat] ?? 0.0) + $planned * $factor;
            }
        }

        // Réalisé : exactement la période choisie (bornes incluses).
        // L'enveloppe MATIERE se nourrit des ACHATS de stock (page Achats
        // & stock) : c'est la vraie sortie d'argent « courses cafétéria »,
        // en plus d'éventuelles dépenses MATIERE saisies manuellement.
        $realizedCa = Sale::caBetween($fromDay, $toDay);
        $realizedExpenses = [];
        foreach (Expense::byCategoryBetween($fromDay, $toDay) as $c) {
            $realizedExpenses[(string) $c['category']] = (float) $c['ttc'];
        }
        $realizedExpenses['MATIERE'] = ($realizedExpenses['MATIERE'] ?? 0.0)
            + (float) Purchase::statsBetween($fromDay, $toDay)['ttc'];

        // Le formulaire édite le dernier mois couvert par la période.
        [$lastY, $lastM] = $months[count($months) - 1];
        $editMonth = [
            'year'  => $lastY,
            'month' => $lastM,
            'value' => sprintf('%04d-%02d', $lastY, $lastM),
        ];

        $rangeLabel = $fromDay === $toDay
            ? 'le ' . formatDate($fromDay, 'd/m/Y')
            : 'du ' . formatDate($fromDay, 'd/m/Y') . ' au ' . formatDate($toDay, 'd/m/Y');

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
            'rangeLabel'    => $rangeLabel,
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
