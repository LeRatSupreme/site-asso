<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Compta\ComptaCalc;
use App\Core\Compta\ReceiptStorage;
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

        // Page fusionnée « Opérations » (voir AdminStockController::purchases).
        $this->renderAdmin('admin/compta/operations', [
            'title'         => 'Opérations · Dépenses',
            'section'       => 'depenses',
            'user'          => $user,
            'period'        => $period,
            'periodOptions' => ComptaCalc::PERIOD_OPTIONS,
            'expenses'      => Expense::between($period['from'], $period['to']),
            'agg'           => Expense::aggregatesBetween($period['from'], $period['to']),
            'byCategory'    => Expense::byCategoryBetween($period['from'], $period['to']),
            'vatStats'      => Expense::vatBetween($period['from'], $period['to']),
        ]);
    }

    // -----------------------------------------------------------------
    //  Ajout / suppression
    // -----------------------------------------------------------------

    /**
     * Valide et déplace le justificatif envoyé avec la dépense (règles
     * partagées avec le kiosque, voir ReceiptStorage). Renvoie le chemin
     * relatif (« uploads/receipts/xx.ext ») ou null si aucun fichier ;
     * arrête la requête (flash + redirect) en cas d'erreur.
     *
     * @param string $back Chemin de retour (cf. returnTo()).
     */
    private function storeReceipt(string $back): ?string
    {
        try {
            return ReceiptStorage::store();
        } catch (\RuntimeException $e) {
            $this->setFlash('error', $e->getMessage());
            redirect(url($back));
        }
    }

    /**
     * Chemin de retour après enregistrement : par défaut le journal des
     * dépenses, ou la page d'appel (ex. livre comptable) via le champ
     * « return_to ». Seuls les chemins locaux sous /admin/ sont acceptés —
     * jamais une URL arbitraire (open redirect).
     */
    private function returnTo(): string
    {
        $returnTo = (string) ($_POST['return_to'] ?? '');
        if (str_starts_with($returnTo, '/admin/') && !str_starts_with($returnTo, '//')) {
            return $returnTo;
        }

        return '/admin/compta/depenses';
    }

    public function save(): void
    {
        $user = $this->guardCompta();
        $back = $this->returnTo();

        $label = trim((string) ($_POST['label'] ?? ''));
        // Saisie souple : un seul montant, dans une base explicite.
        // « amount_basis » = « ttc » (défaut — les tickets indiquent le TTC)
        // ou « ht ». Le HT/TTC manquant est déduit du montant de TVA saisi
        // (prioritaire) ou du taux choisi.
        $amount = parseFrenchFloat((string) ($_POST['amount'] ?? ''));
        $basis = (string) ($_POST['amount_basis'] ?? '');
        $amountHtInput = parseFrenchFloat((string) ($_POST['amount_ht'] ?? ''));
        $amountTtcInput = parseFrenchFloat((string) ($_POST['amount_ttc'] ?? ''));
        if ($basis !== 'ht' && $basis !== 'ttc') {
            // Compatibilité ancien formulaire (champs HT/TTC séparés) :
            // le TTC fourni fait foi, sinon le HT.
            if ($amountTtcInput > 0) {
                $basis = 'ttc';
                $amount = $amountTtcInput;
            } elseif ($amountHtInput > 0) {
                $basis = 'ht';
                $amount = $amountHtInput;
            } else {
                $basis = 'ttc';
            }
        }

        $vatAmountInput = parseFrenchFloat((string) ($_POST['vat_amount'] ?? ''));
        $vatRaw = trim((string) ($_POST['vat_rate'] ?? ''));
        $rate = $vatRaw !== '' ? parseFrenchFloat($vatRaw) : null;
        if ($rate !== null && !in_array($rate, [20.0, 10.0, 5.5, 2.1, 0.0], true)) {
            $rate = null;
        }

        if ($label === '' || $amount <= 0) {
            $this->setFlash('error', 'Libellé requis, avec un montant (> 0).');
            redirect(url($back));
        }

        // HT/TTC/TVA déduits du montant unique : montant de TVA saisi
        // prioritaire, sinon taux choisi, sinon la base fait foi (TVA = 0) —
        // logique partagée avec le kiosque (Expense::computeAmounts()).
        ['amount_ht' => $amountHt, 'amount_ttc' => $amountTtc, 'vat' => $vat] = Expense::computeAmounts(
            $amount,
            $basis,
            $vatAmountInput > 0 ? $vatAmountInput : null,
            $rate
        );

        $receiptPath = $this->storeReceipt($back);

        $category = strtoupper(trim((string) ($_POST['category'] ?? '')));
        if (!in_array($category, Expense::CATEGORIES, true)) {
            $category = 'DIVERS';
        }

        $id = Expense::create([
            'spent_at'   => (string) ($_POST['spent_at'] ?? '') !== '' ? (string) $_POST['spent_at'] : date('Y-m-d'),
            'category'   => $category,
            'label'      => $label,
            'amount_ttc' => $amountTtc,
            'amount_ht'  => $amountHt,
            'vat'        => $vat,
            'invoice_number' => (string) ($_POST['invoice_number'] ?? ''),
            'notes'      => (string) ($_POST['notes'] ?? ''),
            'receipt_path' => $receiptPath,
            'created_by' => (string) ($user['id'] ?? ''),
        ]);

        if ($id === '') {
            $this->setFlash('error', 'Libellé requis, avec un montant (> 0).');
            redirect(url($back));
        }

        $this->audit('compta.expense.create', 'expense', $id, [
            'label'      => $label,
            'category'   => $category,
            'basis'      => $basis,
            'vat_rate'   => $rate,
            'amount_ht'  => $amountHt,
            'amount_ttc' => $amountTtc,
            'vat'        => $vat,
            'receipt'    => $receiptPath,
        ]);
        $this->setFlash('success', 'Dépense enregistrée.');
        redirect(url($back));
    }

    public function delete(string $id): void
    {
        $this->guardCompta();

        // Supprime aussi le justificatif associé (chemin contenu dans le
        // dossier receipts uniquement, jamais un chemin arbitraire).
        $expense = Expense::find($id);
        if ($expense !== null && !empty($expense['receipt_path'])) {
            $file = realpath(AEIC_PUBLIC . '/assets/' . ltrim((string) $expense['receipt_path'], '/'));
            $base = realpath(AEIC_PUBLIC . '/assets/uploads/receipts');
            if ($file !== false && $base !== false && str_starts_with($file, $base)) {
                @unlink($file);
            }
        }

        Expense::delete($id);
        $this->audit('compta.expense.delete', 'expense', $id);
        $this->setFlash('success', 'Dépense supprimée.');
        redirect(url('/admin/compta/depenses'));
    }
}
