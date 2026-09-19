<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Compta\ComptaCalc;
use App\Models\Expense;

/**
 * Journal des dépenses de l'association (charges hors coût d'achat matière).
 * Réservé aux rôles ADMIN et TRESORERIE. Le résultat net du dashboard =
 * bénéfice cafétéria − ces dépenses.
 */
final class AdminExpenseController extends AdminBaseController
{
    // -----------------------------------------------------------------
    //  Journal des dépenses
    // -----------------------------------------------------------------

    public function index(): void
    {
        $user = $this->guardCompta();

        $period = ComptaCalc::resolvePeriod($_GET['period'] ?? null, $_GET['from'] ?? null, $_GET['to'] ?? null);

        $this->renderAdmin('admin/compta/expenses', [
            'title'         => 'Dépenses',
            'user'          => $user,
            'period'        => $period,
            'periodOptions' => ComptaCalc::PERIOD_OPTIONS,
            'expenses'      => Expense::between($period['from'], $period['to']),
            'agg'           => Expense::aggregatesBetween($period['from'], $period['to']),
            'byCategory'    => Expense::byCategoryBetween($period['from'], $period['to']),
        ]);
    }

    // -----------------------------------------------------------------
    //  Ajout / suppression
    // -----------------------------------------------------------------

    public function save(): void
    {
        $user = $this->guardCompta();

        $label = trim((string) ($_POST['label'] ?? ''));
        $amountTtc = parseFrenchFloat((string) ($_POST['amount_ttc'] ?? ''));

        if ($label === '' || $amountTtc <= 0) {
            $this->setFlash('error', 'Libellé et montant TTC (> 0) requis.');
            redirect(url('/admin/compta/depenses'));
        }

        $category = strtoupper(trim((string) ($_POST['category'] ?? '')));
        if (!in_array($category, Expense::CATEGORIES, true)) {
            $category = 'DIVERS';
        }

        // TVA : si un taux est choisi, HT et TVA sont déduites du TTC
        // (round 2) — plus besoin de saisir les montants à la main.
        // Aucun taux : écriture sans détail TVA (null).
        $amountHt = null;
        $vat = null;
        $vatRaw = trim((string) ($_POST['vat_rate'] ?? ''));
        if ($vatRaw !== '') {
            $rate = parseFrenchFloat($vatRaw);
            if (in_array($rate, [20.0, 10.0, 5.5, 2.1, 0.0], true)) {
                $amountHt = round($amountTtc / (1 + $rate / 100), 2);
                $vat = round($amountTtc - $amountHt, 2);
            }
        }

        $id = Expense::create([
            'spent_at'   => (string) ($_POST['spent_at'] ?? '') !== '' ? (string) $_POST['spent_at'] : date('Y-m-d'),
            'category'   => $category,
            'label'      => $label,
            'amount_ttc' => $amountTtc,
            'amount_ht'  => $amountHt,
            'vat'        => $vat,
            'supplier'   => (string) ($_POST['supplier'] ?? ''),
            'notes'      => (string) ($_POST['notes'] ?? ''),
            'created_by' => (string) ($user['id'] ?? ''),
        ]);

        if ($id === '') {
            $this->setFlash('error', 'Libellé et montant TTC (> 0) requis.');
            redirect(url('/admin/compta/depenses'));
        }

        $this->audit('compta.expense.create', 'expense', $id, [
            'label'      => $label,
            'category'   => $category,
            'amount_ttc' => $amountTtc,
        ]);
        $this->setFlash('success', 'Dépense enregistrée.');
        redirect(url('/admin/compta/depenses'));
    }

    public function delete(string $id): void
    {
        $this->guardCompta();

        Expense::delete($id);
        $this->audit('compta.expense.delete', 'expense', $id);
        $this->setFlash('success', 'Dépense supprimée.');
        redirect(url('/admin/compta/depenses'));
    }
}
