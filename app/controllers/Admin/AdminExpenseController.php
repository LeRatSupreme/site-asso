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
            'vatStats'      => Expense::vatBetween($period['from'], $period['to']),
        ]);
    }

    // -----------------------------------------------------------------
    //  Ajout / suppression
    // -----------------------------------------------------------------

    // Justificatifs : PDF ou image, 5 Mo max, MIME réel vérifié (comme les
    // médias). Stockés sous /assets/uploads/receipts/ avec un nom aléatoire.
    private const RECEIPT_MAX_SIZE = 5 * 1024 * 1024;
    private const RECEIPT_ALLOWED = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
    ];

    /**
     * Valide et déplace le justificatif envoyé avec la dépense.
     * Renvoie le chemin relatif (« uploads/receipts/xx.ext ») ou null si
     * aucun fichier ; arrête la requête (flash + redirect) en cas d'erreur.
     */
    private function storeReceipt(): ?string
    {
        $receipt = $_FILES['receipt'] ?? null;
        if (!is_array($receipt) || (int) ($receipt['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ((int) ($receipt['error'] ?? 1) !== UPLOAD_ERR_OK) {
            $this->setFlash('error', 'Échec de l\'envoi du justificatif (erreur ' . (int) ($receipt['error'] ?? 0) . ').');
            redirect(url('/admin/compta/depenses'));
        }

        if ((int) ($receipt['size'] ?? 0) > self::RECEIPT_MAX_SIZE) {
            $this->setFlash('error', 'Justificatif trop volumineux (5 Mo maximum).');
            redirect(url('/admin/compta/depenses'));
        }

        $ext = strtolower(pathinfo((string) ($receipt['name'] ?? ''), PATHINFO_EXTENSION));
        if (!isset(self::RECEIPT_ALLOWED[$ext])) {
            $this->setFlash('error', 'Justificatif : formats acceptés PDF, JPG, PNG ou WEBP.');
            redirect(url('/admin/compta/depenses'));
        }

        // Validation MIME réelle : l'extension déclarée n'est jamais une preuve.
        $detected = null;
        if (function_exists('mime_content_type')) {
            $detected = mime_content_type((string) $receipt['tmp_name']);
        } elseif (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, (string) $receipt['tmp_name']);
                finfo_close($finfo);
            }
        }
        if ($detected !== self::RECEIPT_ALLOWED[$ext]) {
            $this->setFlash('error', 'Le contenu du justificatif ne correspond pas à son extension.');
            redirect(url('/admin/compta/depenses'));
        }

        $dir = AEIC_PUBLIC . '/assets/uploads/receipts';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $name = bin2hex(random_bytes(12)) . '.' . $ext;
        if (!move_uploaded_file((string) $receipt['tmp_name'], $dir . '/' . $name)) {
            $this->setFlash('error', 'Échec de l\'enregistrement du justificatif.');
            redirect(url('/admin/compta/depenses'));
        }

        return 'uploads/receipts/' . $name;
    }

    public function save(): void
    {
        $user = $this->guardCompta();

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
            redirect(url('/admin/compta/depenses'));
        }

        $amountHt = null;
        $amountTtc = null;
        $vat = null;

        if ($basis === 'ttc') {
            // TTC fait foi.
            $amountTtc = round($amount, 2);
            if ($vatAmountInput > 0) {
                $vat = round($vatAmountInput, 2);
                $amountHt = round($amountTtc - $vat, 2);
            } elseif ($rate !== null && $rate > 0) {
                $amountHt = round($amountTtc / (1 + $rate / 100), 2);
                $vat = round($amountTtc - $amountHt, 2);
            } else {
                $amountHt = $amountTtc;
                $vat = 0.0;
            }
        } else {
            // HT fait foi.
            $amountHt = round($amount, 2);
            if ($vatAmountInput > 0) {
                $vat = round($vatAmountInput, 2);
                $amountTtc = round($amountHt + $vat, 2);
            } elseif ($rate !== null && $rate > 0) {
                $vat = round($amountHt * $rate / 100, 2);
                $amountTtc = round($amountHt + $vat, 2);
            } else {
                $amountTtc = $amountHt;
                $vat = 0.0;
            }
        }

        $receiptPath = $this->storeReceipt();

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
            redirect(url('/admin/compta/depenses'));
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
        redirect(url('/admin/compta/depenses'));
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
