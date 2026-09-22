<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Compta\ComptaCalc;
use App\Core\Compta\ProductAutoSync;
use App\Core\Compta\StockPublic;
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
    // -----------------------------------------------------------------
    //  Journal des pertes
    // -----------------------------------------------------------------

    public function index(): void
    {
        $user = $this->guardCompta();

        $period = ComptaCalc::resolvePeriod($_GET['period'] ?? null, $_GET['from'] ?? null, $_GET['to'] ?? null);

        // Journal enrichi : coût unitaire du lot applicable à la date de
        // perte + valeur de la ligne (quantité × coût).
        $rows = [];
        $aggQty = 0;
        $aggValue = 0.0;
        foreach (Loss::between($period['from'], $period['to']) as $r) {
            $unitCost = (float) (ProductCost::costAt((string) $r['product_key'], (string) $r['lost_at']) ?? 0);
            $r['unit_cost'] = $unitCost;
            $r['value'] = (int) $r['quantity'] * $unitCost;
            $rows[] = $r;

            $aggQty += (int) $r['quantity'];
            $aggValue += (float) $r['value'];
        }

        $this->renderAdmin('admin/compta/pertes', [
            'title'         => 'Pertes',
            'user'          => $user,
            'period'        => $period,
            'periodOptions' => ComptaCalc::PERIOD_OPTIONS,
            'rows'          => $rows,
            'agg'           => ['qty' => $aggQty, 'value' => $aggValue],
            'byReason'      => Loss::byReasonBetween($period['from'], $period['to']),
            'value30'       => Loss::valueForDays(30),
            'products'      => Sale::distinctProducts(),
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

        // La perte déduit le théorique : la carte publique suit.
        StockPublic::invalidate();

        $this->audit('compta.loss.create', 'loss', $id, [
            'product_key' => $productKey,
            'quantity'    => $quantity,
            'reason'      => $reason,
        ]);

        // Synchro automatique de la carte pour ce produit : une perte est
        // parfois la première trace d'un produit jamais vendu ni compté.
        // Jamais bloquant.
        $sync = ['created' => []];
        try {
            $sync = ProductAutoSync::ensureKeys([$productKey]);
        } catch (\Throwable) {
            // Les pertes ne doivent jamais casser à cause de la synchro carte.
        }

        $flash = 'Perte enregistrée (déduite du stock théorique).';
        if ($sync['created'] !== []) {
            $flash .= ' Carte mise à jour automatiquement : ' . implode(', ', $sync['created']) . '.';
        }
        $this->setFlash('success', $flash);
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
