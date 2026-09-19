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
 * Réservé aux rôles ADMIN et TRESORERIE (voir guardCompta()).
 *
 * Inventaire : comptage physique comparé au théorique (dernier comptage +
 * achats − ventes) pour détecter pertes et casses. Réservé à ADMIN
 * (voir guard()), TRESORERIE comprise.
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

        // Quantité totale reçue sur la période (KPI + pied du journal).
        $qtyTotal = 0;
        foreach ($rows as $r) {
            $qtyTotal += (int) $r['quantity'];
        }

        $this->renderAdmin('admin/compta/purchases', [
            'title'         => 'Achats & stock',
            'user'          => $user,
            'rows'          => $rows,
            'products'      => Sale::distinctProducts(),
            'period'        => $period,
            'periodOptions' => ComptaCalc::PERIOD_OPTIONS,
            'total'         => Purchase::totalBetween($period['from'], $period['to']),
            'sums'          => Purchase::sumsBetween($period['from'], $period['to']),
            'count'         => count($rows),
            'qtyTotal'      => $qtyTotal,
        ]);
    }

    /** Taux de TVA autorisés pour les achats. */
    private const VAT_RATES = [20.0, 10.0, 5.5, 2.1, 0.0];

    /**
     * Enregistre une grille d'achats en un seul POST (une ligne par
     * produit — une course entière en une fois). Les champs communs
     * (date, TVA, fournisseur, update_cost) s'appliquent à toutes les
     * lignes ; les lignes totalement vides sont ignorées, les lignes
     * invalides sont signalées dans le flash.
     */
    public function savePurchasesBulk(): void
    {
        $user = $this->guardCompta();

        $purchasedAt = trim((string) ($_POST['purchased_at'] ?? ''));
        if ($purchasedAt === '') {
            $purchasedAt = date('Y-m-d');
        }
        $supplier = trim((string) ($_POST['supplier'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $updateCost = isset($_POST['update_cost']);

        // '' = montants saisis déjà TTC (pas de TVA à calculer), sinon taux en %.
        $vatRaw = trim((string) ($_POST['vat_rate'] ?? ''));

        $keys = is_array($_POST['product_key'] ?? null) ? $_POST['product_key'] : [];
        $quantities = is_array($_POST['quantity'] ?? null) ? $_POST['quantity'] : [];
        $amounts = is_array($_POST['total_amount'] ?? null) ? $_POST['total_amount'] : [];

        $inserted = 0;
        $products = [];
        $errors = [];
        $count = max(count($keys), count($quantities), count($amounts));

        for ($i = 0; $i < $count; $i++) {
            $key = trim((string) ($keys[$i] ?? ''));
            $amountRaw = trim((string) ($amounts[$i] ?? ''));

            // Ligne totalement vide (jamais remplie) : ignorée sans bruit.
            if ($key === '' && $amountRaw === '') {
                continue;
            }

            $reason = $this->createOne([
                'purchased_at' => $purchasedAt,
                'product_key'  => $key,
                'quantity'     => (string) ($quantities[$i] ?? ''),
                'total_amount' => $amountRaw,
                'vat_rate'     => $vatRaw,
                'update_cost'  => $updateCost ? '1' : '',
                'supplier'     => $supplier,
                'notes'        => $notes,
            ], $user);

            if ($reason === '') {
                $inserted++;
                $products[$key] = true;
            } else {
                $errors[] = ($key !== '' ? $key : '(sans nom)') . ' : ' . $reason;
            }
        }

        if ($inserted === 0) {
            $flash = $errors === []
                ? 'Aucun achat saisi.'
                : 'Aucun achat enregistré — ' . implode(' · ', $errors);
            $this->setFlash('error', $flash);
            redirect(url('/admin/compta/achats'));
        }

        // Audit agrégé unique : un seul evénement pour toute la grille
        // (les ids des achats/ lots restent consultables dans le journal).
        $this->audit('compta.purchase.create_bulk', 'purchase', null, [
            'inserted'    => $inserted,
            'products'    => count($products),
            'ignored'     => count($errors),
            'vat_rate'    => $vatRaw === '' ? null : parseFrenchFloat($vatRaw),
            'update_cost' => $updateCost,
            'supplier'    => $supplier,
            'purchased_at' => $purchasedAt,
        ]);

        $flash = sprintf(
            '%d achat%s enregistré%s pour %d produit%s — stock mis à jour.',
            $inserted,
            $inserted > 1 ? 's' : '',
            $inserted > 1 ? 's' : '',
            count($products),
            count($products) > 1 ? 's' : ''
        );
        if ($errors !== []) {
            $flash .= ' Lignes ignorées : ' . implode(' · ', $errors);
        }
        $this->setFlash('success', $flash);
        redirect(url('/admin/compta/achats'));
    }

    /**
     * Crée UN achat (et son lot de coût optionnel) à partir d'un jeu de
     * champs — cœur partagé de la saisie en lot.
     *
     * Sémantique du montant : la saisie fait foi. HT si un taux de TVA
     * est fourni, déjà TTC sinon (vat_rate null — comportement historique).
     *
     * @param array<string,mixed> $data purchased_at, product_key,
     *                                  quantity, total_amount, vat_rate
     *                                  ('' = déjà TTC), update_cost,
     *                                  supplier, notes
     * @param array<string,mixed> $user Utilisateur courant (created_by).
     *
     * @return string '' si l'achat est créé, sinon le motif d'erreur
     *                (affiché ligne par ligne dans le flash).
     */
    private function createOne(array $data, array $user): string
    {
        $purchasedAt = trim((string) ($data['purchased_at'] ?? ''));
        if ($purchasedAt === '') {
            $purchasedAt = date('Y-m-d');
        }
        $productKey = trim((string) ($data['product_key'] ?? ''));
        $quantity = (int) ($data['quantity'] ?? 0);
        $totalAmount = parseFrenchFloat((string) ($data['total_amount'] ?? ''));

        // '' = montant saisi déjà TTC (pas de TVA à calculer), sinon taux en %.
        $vatRaw = trim((string) ($data['vat_rate'] ?? ''));
        $vatRate = null;
        if ($vatRaw !== '') {
            $candidate = parseFrenchFloat($vatRaw);
            if (!in_array($candidate, self::VAT_RATES, true)) {
                return 'Taux de TVA invalide.';
            }
            $vatRate = $candidate;
        }

        if ($productKey === '') {
            return 'produit manquant';
        }
        if ($quantity < 1) {
            return 'quantité invalide';
        }
        if ($totalAmount <= 0.0) {
            return 'montant invalide';
        }

        // Le montant saisi fait foi : HT si un taux est choisi, déjà TTC sinon.
        $totalHt = round($totalAmount, 3);
        // Coût unitaire dérivé (même calcul que Purchase::create) : sert au
        // lot de coût de revient et à l'audit.
        $unitCost = round($totalHt / $quantity, 3);

        $id = Purchase::create([
            'purchased_at' => $purchasedAt,
            'product_key'  => $productKey,
            'quantity'     => $quantity,
            'total_ht'     => $totalHt,
            'vat_rate'     => $vatRate,
            'supplier'     => trim((string) ($data['supplier'] ?? '')),
            'notes'        => trim((string) ($data['notes'] ?? '')),
            'created_by'   => $user['id'] ?? null,
        ]);

        if ($id === '') {
            return 'création impossible';
        }

        // Option (cochée par défaut) : l'achat crée un nouveau lot de coût
        // de revient à ce prix — chaque achat à un prix différent ouvre un
        // nouveau lot daté, le bénéfice suit les vrais coûts d'achat.
        if (!empty($data['update_cost'])) {
            // Coût de revient en TTC pour bénéfices cohérents avec ventes TTC :
            // les prix de vente sont TTC, le coût doit l'être aussi.
            $costTtc = $vatRate === null ? $unitCost : round($unitCost * (1 + $vatRate / 100), 3);
            ProductCost::create([
                'product_key' => $productKey,
                'cost_price'  => $costTtc,
                'valid_from'  => $purchasedAt,
                'supplier'    => trim((string) ($data['supplier'] ?? '')),
                // Lie le lot à l'achat : sa suppression en cascade
                // (deletePurchase) saura exactement quel lot retirer.
                'purchase_id' => $id,
            ]);
        }

        return '';
    }

    /**
     * Supprime un achat avec cascade complète.
     *
     * La logique reste dans le contrôleur (et non dans Purchase::delete)
     * par symétrie avec createOne() : la création du lot lié s'y fait
     * déjà (elle dépend de l'option update_cost du POST) — son inverse
     * vit au même endroit, les modèles restant des primitives. Contre-
     * passe aussi le stock de référence : Purchase::create() avait fait
     * ProductStock::adjust(+qty), la suppression retire la quantité.
     */
    public function deletePurchase(string $id): void
    {
        $this->guardCompta();

        // Lecture AVANT suppression : la contre-passation (stock) et
        // l'audit ont besoin des données de l'achat.
        $purchase = Purchase::find($id);
        if ($purchase === null) {
            $this->setFlash('error', 'Achat introuvable (déjà supprimé ?).');
            redirect(url('/admin/compta/achats'));
        }

        $productKey = (string) $purchase['product_key'];
        $quantity = (int) $purchase['quantity'];

        // Cascade : supprime les lots de coût liés à l'achat ; si l'un
        // d'eux était « en cours », le lot antérieur est réouvert
        // (ProductCost::delete) pour ne pas casser la chaîne de coûts.
        $lotsDeleted = ProductCost::deleteByPurchase($id);

        Purchase::delete($id);

        // Contre-passation du stock de référence : ajusté de +quantity à
        // la création de l'achat.
        ProductStock::adjust($productKey, -$quantity);

        $this->audit('compta.purchase.delete', 'purchase', $id, [
            'product_key'    => $productKey,
            'quantity'       => $quantity,
            'lots_deleted'   => $lotsDeleted,
            'stock_adjusted' => -$quantity,
        ]);

        $flash = sprintf(
            'Achat supprimé — stock de référence ajusté (−%d).',
            $quantity
        );
        if ($lotsDeleted > 0) {
            $flash .= sprintf(' %d lot(s) de coût lié(s) supprimé(s), lot antérieur réouvert.', $lotsDeleted);
        }
        $this->setFlash('success', $flash);
        redirect(url('/admin/compta/achats'));
    }

    // -----------------------------------------------------------------
    //  Inventaire (réservé à ADMIN, voir guard())
    // -----------------------------------------------------------------

    public function inventory(): void
    {
        $user = $this->guard();

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
        $user = $this->guard();

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
