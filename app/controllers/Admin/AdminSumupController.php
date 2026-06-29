<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Sale;

/**
 * Mini « dashboard SumUp ».
 *
 * Aucun appel réseau à l'API SumUp : on se base sur les ventes déjà importées
 * dans la comptabilité (table `sales`) via l'import de rapports SumUp.
 *
 * Accès : rôles ADMIN et TRESORERIE (mêmes droits que la comptabilité).
 */
final class AdminSumupController extends AdminBaseController
{
    public function index(): void
    {
        $user = $this->guardCompta();

        $now = new \DateTimeImmutable('first day of this month');
        $year = (int) $now->format('Y');
        $month = (int) $now->format('n');

        // Répartition du mois courant par moyen de paiement (CARTE / LIQUIDE).
        $split = Sale::paymentSplit($year, $month);

        // Nombre de transactions du mois.
        $agg = Sale::monthAggregates($year, $month);

        // Transactions du mois courant (total encaissé hors montants personnalisés).
        $cardTotal = (float) ($split['CARTE'] ?? 0.0);
        $cashTotal = (float) ($split['LIQUIDE'] ?? 0.0);

        $this->renderAdmin('admin/sumup/index', [
            'title'      => 'SumUp',
            'user'       => $user,
            'year'       => $year,
            'month'      => $month,
            'monthLabel' => $now->format('m/Y'),
            'cardTotal'  => $cardTotal,
            'cashTotal'  => $cashTotal,
            'txCount'    => $agg['qty'],
            'caTotal'    => $agg['ca'],
        ]);
    }
}
