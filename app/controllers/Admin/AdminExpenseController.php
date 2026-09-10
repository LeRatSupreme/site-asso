<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Expense;

/**
 * Journal des dépenses de l'association (charges hors coût d'achat matière).
 * Réservé aux rôles ADMIN et TRESORERIE. Le résultat net du dashboard =
 * bénéfice cafétéria − ces dépenses.
 */
final class AdminExpenseController extends AdminBaseController
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

    // -----------------------------------------------------------------
    //  Journal des dépenses
    // -----------------------------------------------------------------

    public function index(): void
    {
        $user = $this->guardCompta();

        $month = $this->resolveMonth($_GET['month'] ?? null);

        $months = Expense::monthsWithExpenses();
        if ($months === []) {
            $months = [['value' => $month['value'], 'label' => $month['value']]];
        } elseif (!in_array($month['value'], array_column($months, 'value'), true)) {
            // Le mois affiché doit rester sélectionnable dans le sélecteur.
            array_unshift($months, ['value' => $month['value'], 'label' => $month['value']]);
        }

        $this->renderAdmin('admin/compta/expenses', [
            'title'      => 'Dépenses',
            'user'       => $user,
            'month'      => $month,
            'months'     => $months,
            'expenses'   => Expense::forPeriod($month['year'], $month['month']),
            'agg'        => Expense::aggregates($month['year'], $month['month']),
            'byCategory' => Expense::byCategory($month['year'], $month['month']),
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

        $id = Expense::create([
            'spent_at'   => (string) ($_POST['spent_at'] ?? '') !== '' ? (string) $_POST['spent_at'] : date('Y-m-d'),
            'category'   => $category,
            'label'      => $label,
            'amount_ttc' => $amountTtc,
            'amount_ht'  => parseFrenchFloat((string) ($_POST['amount_ht'] ?? '')),
            'vat'        => parseFrenchFloat((string) ($_POST['vat'] ?? '')),
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
