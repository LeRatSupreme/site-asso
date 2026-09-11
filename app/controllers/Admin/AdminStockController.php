<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Compta\ComptaCalc;
use App\Models\InventoryCount;
use App\Models\ProductCost;
use App\Models\ProductStock;
use App\Models\Purchase;
use App\Models\Sale;

/**
 * Suivi des achats réels et des inventaires.
 *
 * Achats : ce qui a été réellement commandé (alimente le stock théorique).
 * Inventaire : comptage physique comparé au théorique (dernier comptage +
 * achats − ventes) pour détecter pertes et casses. Réservé aux rôles
 * ADMIN et TRESORERIE (voir guardCompta()).
 */
final class AdminStockController extends AdminBaseController
{
    // -----------------------------------------------------------------
    //  Achats
    // -----------------------------------------------------------------

    public function purchases(): void
    {
        $user = $this->guardCompta();

        $period = ComptaCalc::resolvePeriod($_GET['period'] ?? null, $_GET['from'] ?? null, $_GET['to'] ?? null);
        $rows = Purchase::between($period['from'], $period['to'], 200);

        // Agrégats de la période, calculés sur les lignes affichées :
        // quantité totale, ventilation par fournisseur et par produit.
        $qtyTotal = 0;
        $bySupplier = [];
        $byProduct = [];
        foreach ($rows as $r) {
            $supplier = trim((string) ($r['supplier'] ?? ''));
            $supplier = $supplier !== '' ? $supplier : '—';
            $key = (string) $r['product_key'];
            $qty = (int) $r['quantity'];
            $lineTotal = (float) $r['total_ttc'];

            $qtyTotal += $qty;
            $bySupplier[$supplier]['lines'] = ($bySupplier[$supplier]['lines'] ?? 0) + 1;
            $bySupplier[$supplier]['qty'] = ($bySupplier[$supplier]['qty'] ?? 0) + $qty;
            $bySupplier[$supplier]['total'] = ($bySupplier[$supplier]['total'] ?? 0.0) + $lineTotal;

            $byProduct[$key]['qty'] = ($byProduct[$key]['qty'] ?? 0) + $qty;
            $byProduct[$key]['total'] = ($byProduct[$key]['total'] ?? 0.0) + $lineTotal;
        }
        uasort($bySupplier, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);
        uasort($byProduct, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        $topSupplierName = $bySupplier === [] ? null : (string) array_key_first($bySupplier);
        $topSupplierTotal = $topSupplierName !== null ? (float) $bySupplier[$topSupplierName]['total'] : 0.0;

        $this->renderAdmin('admin/compta/purchases', [
            'title'           => 'Achats & stock',
            'user'            => $user,
            'rows'            => $rows,
            'products'        => Sale::distinctProducts(),
            'period'          => $period,
            'periodOptions'   => ComptaCalc::PERIOD_OPTIONS,
            'total'           => Purchase::totalBetween($period['from'], $period['to']),
            'count'           => count($rows),
            'qtyTotal'        => $qtyTotal,
            'bySupplier'      => $bySupplier,
            'byProduct'       => $byProduct,
            'topSupplierName' => $topSupplierName,
            'topSupplierTotal'=> $topSupplierTotal,
        ]);
    }

    public function savePurchase(): void
    {
        $user = $this->guardCompta();

        $purchasedAt = trim((string) ($_POST['purchased_at'] ?? ''));
        if ($purchasedAt === '') {
            $purchasedAt = date('Y-m-d');
        }
        $productKey = trim((string) ($_POST['product_key'] ?? ''));
        $quantity = (int) ($_POST['quantity'] ?? 0);
        $unitCost = parseFrenchFloat((string) ($_POST['unit_cost'] ?? ''));
        $supplier = trim((string) ($_POST['supplier'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($productKey === '' || $quantity < 1 || $unitCost <= 0.0) {
            $this->setFlash('error', 'Produit, quantité et coût unitaire requis.');
            redirect(url('/admin/compta/achats'));
        }

        $id = Purchase::create([
            'purchased_at' => $purchasedAt,
            'product_key'  => $productKey,
            'quantity'     => $quantity,
            'unit_cost'    => $unitCost,
            'supplier'     => $supplier,
            'notes'        => $notes,
            'created_by'   => $user['id'] ?? null,
        ]);

        // Option (cochée par défaut) : l'achat crée un nouveau lot de coût
        // de revient à ce prix — chaque achat à un prix différent ouvre un
        // nouveau lot daté, le bénéfice suit les vrais coûts d'achat.
        $lotNote = '';
        if (isset($_POST['update_cost'])) {
            $lotId = ProductCost::create([
                'product_key' => $productKey,
                'cost_price'  => $unitCost,
                'valid_from'  => $purchasedAt,
                'supplier'    => $supplier,
            ]);
            if ($lotId !== '') {
                $this->audit('compta.cost.auto_from_purchase', 'product_cost', $lotId, [
                    'product_key'    => $productKey,
                    'cost_price'     => $unitCost,
                    'from_purchase'  => $id,
                ]);
                $lotNote = sprintf(' Nouveau lot de coût : %s /unité.', formatPrice($unitCost));
            }
        }

        $this->audit('compta.purchase.create', 'purchase', $id, [
            'product_key' => $productKey,
            'quantity'    => $quantity,
            'unit_cost'   => $unitCost,
        ]);

        $this->setFlash('success', 'Achat enregistré — stock mis à jour.' . $lotNote);
        redirect(url('/admin/compta/achats'));
    }

    public function deletePurchase(string $id): void
    {
        $user = $this->guardCompta();

        Purchase::delete($id);

        $this->audit('compta.purchase.delete', 'purchase', $id);
        $this->setFlash('success', 'Achat supprimé.');
        redirect(url('/admin/compta/achats'));
    }

    // -----------------------------------------------------------------
    //  Inventaire
    // -----------------------------------------------------------------

    public function inventory(): void
    {
        $user = $this->guardCompta();

        $lastCounts = InventoryCount::lastCountsMap();
        $theoretical = InventoryCount::theoreticalStocksMap();
        $stockMap = ProductStock::allMap();

        $rows = [];
        foreach (Sale::distinctProducts() as $key) {
            $last = $lastCounts[$key] ?? null;
            $rows[] = [
                'key'         => $key,
                'stock'       => $stockMap[$key] ?? null,
                'counted_at'  => $last !== null ? $last['at'] : null,
                'counted_qty' => $last !== null ? $last['qty'] : null,
                'gap'         => $last !== null ? $last['gap'] : null,
                'theoretical' => $theoretical[$key] ?? null,
            ];
        }

        // Produits déjà comptés en premier (du plus récemment compté au plus
        // ancien), puis les produits jamais comptés par ordre alphabétique.
        usort($rows, static function (array $a, array $b): int {
            if ($a['counted_at'] !== null && $b['counted_at'] !== null) {
                return strcmp((string) $b['counted_at'], (string) $a['counted_at']);
            }
            if ($a['counted_at'] !== null) {
                return -1;
            }
            if ($b['counted_at'] !== null) {
                return 1;
            }

            return strcmp((string) $a['key'], (string) $b['key']);
        });

        $this->renderAdmin('admin/compta/inventory', [
            'title'   => 'Inventaire',
            'user'    => $user,
            'rows'    => $rows,
            'history' => InventoryCount::history(50),
            'gaps'    => InventoryCount::recentGaps(30),
        ]);
    }

    public function saveCount(): void
    {
        $user = $this->guardCompta();

        $counts = $_POST['count'] ?? [];
        if (!is_array($counts)) {
            $counts = [];
        }

        $done = 0;
        $gaps = 0;

        foreach ($counts as $key => $value) {
            $productKey = trim((string) $key);
            if ($productKey === '' || trim((string) $value) === '') {
                continue;
            }

            $counted = (int) $value;
            if ($counted < 0) {
                continue;
            }

            // L'écart doit être calculé AVANT le record : chaque comptage
            // devient la nouvelle référence du stock théorique.
            $theoretical = InventoryCount::theoreticalStock($productKey);
            $gap = $counted - ($theoretical ?? 0);

            InventoryCount::record($productKey, $counted, null, $user['id'] ?? null);

            $done++;
            if ($gap !== 0) {
                $gaps++;
            }
        }

        if ($done === 0) {
            $this->setFlash('error', 'Aucun comptage saisi.');
            redirect(url('/admin/compta/inventaire'));
        }

        $this->audit('compta.inventory.count', 'inventory_count', null, [
            'products' => $done,
            'gaps'     => $gaps,
        ]);

        $this->setFlash(
            'success',
            sprintf('%d produit(s) compté(s) — %d écart(s) détecté(s).', $done, $gaps)
        );
        redirect(url('/admin/compta/inventaire'));
    }
}
