<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Loss;
use App\Models\ProductCost;
use App\Models\Sale;

/**
 * Journal des pertes (casse, vol, périmé, offert). Chaque perte est
 * valorisée au coût de revient et déduite du stock théorique de
 * l'inventaire. Réservé aux rôles ADMIN et TRESORERIE.
 */
final class AdminLossController extends AdminBaseController
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

    /**
     * Les 12 derniers mois glissants (du mois courant à mois courant − 11),
     * du plus récent au plus ancien — sans requête SQL.
     *
     * @return list<array{value:string,label:string}>
     */
    private function recentMonths(int $year, int $month): array
    {
        $current = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));

        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $d = $current->modify('-' . $i . ' months');
            $months[] = [
                'value' => $d->format('Y-m'),
                'label' => $d->format('m/Y'),
            ];
        }

        return $months;
    }

    // -----------------------------------------------------------------
    //  Journal des pertes
    // -----------------------------------------------------------------

    public function index(): void
    {
        $user = $this->guardCompta();

        $month = $this->resolveMonth($_GET['month'] ?? null);

        $months = $this->recentMonths((int) (new \DateTimeImmutable())->format('Y'), (int) (new \DateTimeImmutable())->format('n'));
        if (!in_array($month['value'], array_column($months, 'value'), true)) {
            // Mois demandé hors fenêtre glissante : on le propose quand même.
            array_unshift($months, ['value' => $month['value'], 'label' => $month['value']]);
        }

        // Journal enrichi : coût unitaire du lot applicable à la date de
        // perte + valeur de la ligne (quantité × coût).
        $rows = [];
        $aggQty = 0;
        $aggValue = 0.0;
        foreach (Loss::forPeriod($month['year'], $month['month']) as $r) {
            $unitCost = (float) (ProductCost::costAt((string) $r['product_key'], (string) $r['lost_at']) ?? 0);
            $r['unit_cost'] = $unitCost;
            $r['value'] = (int) $r['quantity'] * $unitCost;
            $rows[] = $r;

            $aggQty += (int) $r['quantity'];
            $aggValue += (float) $r['value'];
        }

        $this->renderAdmin('admin/compta/pertes', [
            'title'    => 'Pertes',
            'user'     => $user,
            'month'    => $month,
            'months'   => $months,
            'rows'     => $rows,
            'agg'      => ['qty' => $aggQty, 'value' => $aggValue],
            'byReason' => Loss::byReason($month['year'], $month['month']),
            'value30'  => Loss::valueForDays(30),
            'products' => Sale::distinctProducts(),
        ]);
    }

    public function save(): void
    {
        $user = $this->guardCompta();

        $lostAt = trim((string) ($_POST['lost_at'] ?? ''));
        if ($lostAt === '') {
            $lostAt = date('Y-m-d');
        }
        $productKey = trim((string) ($_POST['product_key'] ?? ''));
        $quantity = max(1, (int) ($_POST['quantity'] ?? 0));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $note = trim((string) ($_POST['note'] ?? ''));

        if ($productKey === '' || $quantity < 1) {
            $this->setFlash('error', 'Produit et quantité requis.');
            redirect(url('/admin/compta/pertes'));
        }

        $id = Loss::create([
            'lost_at'     => $lostAt,
            'product_key' => $productKey,
            'quantity'    => $quantity,
            'reason'      => $reason,
            'note'        => $note,
            'created_by'  => $user['id'] ?? null,
        ]);

        $this->audit('compta.loss.create', 'loss', $id, [
            'product_key' => $productKey,
            'quantity'    => $quantity,
            'reason'      => $reason,
        ]);

        $this->setFlash('success', 'Perte enregistrée (déduite du stock théorique).');
        redirect(url('/admin/compta/pertes'));
    }

    public function delete(string $id): void
    {
        $user = $this->guardCompta();

        Loss::delete($id);

        $this->audit('compta.loss.delete', 'loss', $id);
        $this->setFlash('success', 'Perte supprimée.');
        redirect(url('/admin/compta/pertes'));
    }
}
