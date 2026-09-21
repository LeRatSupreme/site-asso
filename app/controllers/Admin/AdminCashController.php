<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Compta\CashLedger;
use App\Models\AuditLog;
use App\Models\CashCount;
use App\Models\CashMovement;

/**
 * Caisses — traçabilité du liquide (groupe « Système »).
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
        $this->guardSystem();

        $this->renderAdmin('admin/caisses/index', [
            'title'       => 'Caisses',
            'balance'     => CashLedger::balance(),
            'salesTotal'  => CashLedger::salesTotal(),
            'movements'   => CashMovement::recent(50),
            'counts'      => CashCount::recent(50),
            'ecarts'      => CashCount::recentEcarts(30),
        ]);
    }

    /**
     * Dépôt à la banque (sortie de liquide).
     */
    public function deposit(): void
    {
        $this->guardSystem();

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

        $id = CashLedger::recordDeposit($amount, $label, (string) Auth::user()['email'], $date['value']);
        AuditLog::log('cash.depot', Auth::id(), 'cash', $id, ['amount' => $amount, 'label' => $label]);

        $this->setFlash('success', sprintf('Dépôt de %s enregistré.', formatPrice($amount)));
        redirect(url('/admin/caisses'));
    }

    /**
     * Comptage physique : fige l'écart et réaligne le théorique.
     */
    public function count(): void
    {
        $this->guardSystem();

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
            $this->setFlash('error', sprintf('⚠️ Manquant de %s constaté — caisse réalignée.', formatPrice(abs($res['ecart']))));
        } elseif ($res['ecart'] > 0) {
            $this->setFlash('success', sprintf('Surplus de %s constaté — caisse réalignée.', formatPrice($res['ecart'])));
        } else {
            $this->setFlash('success', 'Comptage exact : aucun écart. 👍');
        }

        redirect(url('/admin/caisses'));
    }

    /**
     * Fond de caisse (entrée de liquide initial / remise en caisse).
     */
    public function fund(): void
    {
        $this->guardSystem();

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
