<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Compta\CardFees;
use App\Core\Compta\CashLedger;
use App\Models\AuditLog;
use App\Models\CashCount;
use App\Models\CashMovement;
use App\Models\Expense;

/**
 * Caisses — traçabilité du liquide (groupe « Système » ou attribution
 * individuelle, voir guardSystemOrPage()).
 *
 * Solde théorique = ventes LIQUIDE (calculées en direct depuis `sales`)
 * + mouvements manuels (fond de caisse, dépôts banque, ajustements).
 * Un comptage physique révèle les écarts (vol potentiel) et réaligne
 * le théorique ; tout est historisé, rien n'est supprimable.
 */
final class AdminCashController extends AdminBaseController
{
    public function index(): void
    {
        $this->guardSystemOrPage('cash');

        $this->renderAdmin('admin/caisses/index', [
            'title'       => 'Caisses',
            'balance'     => CashLedger::balance(),
            'salesTotal'  => CashLedger::salesTotal(),
            'cardTotal'   => CashLedger::cardSalesTotal(),
            'cardFee'     => CardFees::estimatedTotal(),
            'cardNet'     => CardFees::estimatedNet(),
            'feeRate'     => CardFees::formattedRate(),
            'movements'   => CashMovement::recent(50),
            'counts'      => CashCount::recent(50),
            'ecarts'      => CashCount::recentEcarts(30),
        ]);
    }

    /**
     * Dépôt à la banque (sortie de liquide).
     *
     * Chaque dépôt génère automatiquement une dépense de frais bancaires
     * (CashLedger::DEPOSIT_FEE, catégorie « FRAIS ») : la banque prélève
     * ces frais sur le compte, jamais dans la caisse — le mouvement DEPOT
     * sort donc uniquement le montant déposé.
     */
    public function deposit(): void
    {
        $this->guardSystemOrPage('cash');

        $amount = parseFrenchFloat((string) ($_POST['amount'] ?? ''));
        if ($amount <= 0) {
            $this->setFlash('error', 'Montant du dépôt (> 0) requis.');
            redirect(url('/admin/caisses'));
        }

        $date = datetime_selects_value();
        if (!$date['ok']) {
            $this->setFlash('error', 'Date invalide.');
            redirect(url('/admin/caisses'));
        }

        $label = trim((string) ($_POST['label'] ?? ''));
        $by = (string) Auth::user()['email'];
        $fee = CashLedger::DEPOSIT_FEE;

        // Dépôt + frais bancaires : les deux écritures sont créées
        // atomiquement. Sans date saisie (valeur null = « maintenant »),
        // la dépense de frais est datée du jour.
        $pdo = CashCount::connection();
        $pdo->beginTransaction();
        try {
            $id = CashLedger::recordDeposit($amount, $label, $by, $date['value']);
            $feeExpenseId = Expense::create([
                'spent_at'   => $date['value'] !== null ? substr((string) $date['value'], 0, 10) : date('Y-m-d'),
                'category'   => 'FRAIS',
                'label'      => 'Frais de dépôt banque' . ($label !== '' ? ' — ' . $label : ''),
                'amount_ttc' => $fee,
                'created_by' => $by,
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        AuditLog::log('cash.depot', Auth::id(), 'cash', $id, [
            'amount'         => $amount,
            'label'          => $label,
            'fee'            => $fee,
            'fee_expense_id' => $feeExpenseId !== '' ? $feeExpenseId : null,
        ]);

        $this->setFlash('success', sprintf(
            'Dépôt de %s enregistré — frais bancaires de %s ajoutés aux dépenses (net crédité en banque ≈ %s).',
            formatPrice($amount),
            formatPrice($fee),
            formatPrice(max(0.0, round($amount - $fee, 2)))
        ));
        redirect(url('/admin/caisses'));
    }

    /**
     * Comptage physique : fige l'écart et réaligne le théorique.
     */
    public function count(): void
    {
        $this->guardSystemOrPage('cash');

        $counted = parseFrenchFloat((string) ($_POST['counted'] ?? ''));
        if ($counted < 0) {
            $this->setFlash('error', 'Montant compté invalide.');
            redirect(url('/admin/caisses'));
        }

        $date = datetime_selects_value();
        if (!$date['ok']) {
            $this->setFlash('error', 'Date invalide.');
            redirect(url('/admin/caisses'));
        }

        $label = trim((string) ($_POST['label'] ?? ''));
        $res = CashLedger::recordCount($counted, $label, (string) Auth::user()['email'], $date['value']);
        AuditLog::log('cash.count', Auth::id(), 'cash', $res['count_id'], [
            'counted'     => $counted,
            'theoretical' => round($counted - $res['ecart'], 2),
            'ecart'       => $res['ecart'],
        ]);

        if ($res['ecart'] < 0) {
            $this->setFlash('error', sprintf('Manquant de %s constaté — caisse réalignée.', formatPrice(abs($res['ecart']))));
        } elseif ($res['ecart'] > 0) {
            $this->setFlash('success', sprintf('Surplus de %s constaté — caisse réalignée.', formatPrice($res['ecart'])));
        } else {
            $this->setFlash('success', 'Comptage exact : aucun écart.');
        }

        redirect(url('/admin/caisses'));
    }

    /**
     * Fond de caisse (entrée de liquide initial / remise en caisse).
     */
    public function fund(): void
    {
        $this->guardSystemOrPage('cash');

        $amount = parseFrenchFloat((string) ($_POST['amount'] ?? ''));
        if ($amount <= 0) {
            $this->setFlash('error', 'Montant du fond de caisse (> 0) requis.');
            redirect(url('/admin/caisses'));
        }

        $date = datetime_selects_value();
        if (!$date['ok']) {
            $this->setFlash('error', 'Date invalide.');
            redirect(url('/admin/caisses'));
        }

        $label = trim((string) ($_POST['label'] ?? '')) ?: 'Fond de caisse';
        $id = CashLedger::recordFund($amount, $label, (string) Auth::user()['email'], $date['value']);
        AuditLog::log('cash.fond', Auth::id(), 'cash', $id, ['amount' => $amount, 'label' => $label]);

        $this->setFlash('success', sprintf('Fond de caisse de %s enregistré.', formatPrice($amount)));
        redirect(url('/admin/caisses'));
    }
}
