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
 * septembre).
 *
 * Mois ÉDITÉ : sans paramètre, le formulaire cible le DERNIER mois couvert
 * (comportement historique, inchangé) ; avec ?month=YYYY-MM il cible ce
 * mois-là (validation stricte du format) et les champs affichent le budget
 * RÉEL du mois (pas le prorata de la période). Le paramètre ne touche ni
 * la période comparée ni le prorata : il ne choisit que le mois enregistré.
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

        // Mois édité explicite (?month=YYYY-MM) : validation stricte —
        // format réel (mois 01-12) et année qu'un budget peut accepter
        // (Budget::upsert ignore les années < 2020 : les refuser ici évite
        // d'éditer un mois silencièrement non enregistrable).
        $monthParam = trim((string) ($_GET['month'] ?? ''));
        $editFromParam = null;
        if ($monthParam !== '') {
            if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $monthParam, $mm) === 1 && (int) $mm[1] >= 2020) {
                $editFromParam = [(int) $mm[1], (int) $mm[2]];
            } else {
                $this->setFlash('error', 'Mois invalide (format attendu : AAAA-MM).');
                redirect(url('/admin/compta/budgets'));
            }
        }

        // Bornes journalières réelles du « réalisé ». Sans bornes (« Tout »),
        // on borne à la fenêtre des mois couverts pour que prévu et réalisé
        // comparent la même durée.
        $today = new \DateTimeImmutable('today');
        $windowStart = ($period['from'] !== null
            ? new \DateTimeImmutable($period['from'])
            : $today->modify('first day of this month')->modify('-23 months'))
            ->modify('first day of this month');
        $fromDay = $period['from'] ?? $windowStart->format('Y-m-d');
        $toDay = $period['to'] ?? $today->format('Y-m-d');

        // Mois couverts (helper partagé, testé dans BudgetProrataTest).
        $months = ComptaCalc::monthsCoveredBy($fromDay, $toDay);

        // Prévu : budgets des mois couverts, ajustés au prorata du nombre
        // de jours de la période qui tombent dans chaque mois (budget
        // mensuel × jours couverts ÷ jours du mois).
        $plannedByCat = [];
        foreach ($months as [$y, $m]) {
            $factor = ComptaCalc::monthBudgetFactor(sprintf('%04d-%02d-01', $y, $m), $fromDay, $toDay);
            if ($factor <= 0.0) {
                continue;
            }
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

        // Le formulaire édite le mois choisi (?month=) ou, sans paramètre,
        // le dernier mois couvert par la période (comportement historique).
        [$editY, $editM] = $editFromParam ?? $months[count($months) - 1];
        $editMonth = [
            'year'   => $editY,
            'month'  => $editM,
            'value'  => sprintf('%04d-%02d', $editY, $editM),
            'choisi' => $editFromParam !== null,
        ];

        // Valeurs pré-remplies du formulaire : avec ?month= on édite le mois
        // CHOISI → les champs montrent son budget réel (mois entier) ; sans
        // paramètre, comportement inchangé (le « prévu » au prorata de la
        // période, comme la colonne de comparaison).
        $editPlanned = $editFromParam !== null ? Budget::forMonth($editY, $editM) : null;

        // Mini-grille annuelle (LECTURE SEULE) : 12 mois × prévu vs réalisé
        // pour l'année du mois édité. Zéro recalcul parallèle — mêmes sources
        // que la comparaison ci-dessus (Budget::forMonth, Sale::caBetween,
        // dépenses + achats comptés dans MATIERE), mois calendaire par mois
        // calendaire (prévu = budget du mois entier, sans prorata).
        $moisFr = [
            1 => 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin',
            'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre',
        ];
        $annual = [];
        for ($m = 1; $m <= 12; $m++) {
            $first = sprintf('%04d-%02d-01', $editY, $m);
            $last = (new \DateTimeImmutable($first))->format('Y-m-t');
            $monthBudgets = Budget::forMonth($editY, $m);
            $expPlanned = 0.0;
            foreach ($monthBudgets as $cat => $planned) {
                if ($cat !== 'CA') {
                    $expPlanned += (float) $planned;
                }
            }
            $annual[] = [
                'value'        => sprintf('%04d-%02d', $editY, $m),
                'label'        => $moisFr[$m],
                'ca_planned'   => (float) ($monthBudgets['CA'] ?? 0),
                'ca_realized'  => Sale::caBetween($first, $last),
                'exp_planned'  => $expPlanned,
                'exp_realized' => (float) Expense::aggregatesBetween($first, $last)['ttc']
                    + (float) Purchase::statsBetween($first, $last)['ttc'],
            ];
        }

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
            'title'             => 'Budgets',
            'user'              => $user,
            'period'            => $period,
            'periodOptions'     => ComptaCalc::PERIOD_OPTIONS,
            'editMonth'         => $editMonth,
            'editPlanned'       => $editPlanned,
            'currentMonthValue' => date('Y-m'),
            'annual'            => $annual,
            'rangeLabel'        => $rangeLabel,
            'rows'              => $rows,
            'totals'            => $totals,
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
