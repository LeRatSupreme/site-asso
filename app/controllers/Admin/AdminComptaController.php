<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Compta\ComptaCalc;
use App\Core\Compta\SumUpCsvParser;
use App\Models\ImportBatch;
use App\Models\Product;
use App\Models\ProductAlias;
use App\Models\ProductCost;
use App\Models\Sale;
use App\Models\SaleAdjustment;

/**
 * Module Comptabilité & gestion des achats (Cafétéria).
 *
 * Réservé aux rôles ADMIN et TRESORERIE (voir guardCompta()).
 *
 * Flux : import CSV SumUp -> table `sales` (dédupliquée) -> mapping aliases
 * -> coûts de revient par lot daté -> calculs (CA, bénéfices, marge) ->
 * analyses de réapprovisionnement.
 */
final class AdminComptaController extends AdminBaseController
{
    /** Mois disponibles dans les ventes (pour les sélecteurs). */
    private function availableMonths(): array
    {
        try {
            $rows = Sale::class;
            $stmt = \db()->query(
                'SELECT DISTINCT YEAR(sold_at) AS y, MONTH(sold_at) AS m
                 FROM sales ORDER BY y DESC, m DESC'
            );
            $months = [];
            foreach ($stmt->fetchAll() as $r) {
                $months[] = [
                    'value' => sprintf('%04d-%02d', (int) $r['y'], (int) $r['m']),
                    'label' => sprintf('%02d/%04d', (int) $r['m'], (int) $r['y']),
                ];
            }

            return $months;
        } catch (\Throwable) {
            return [];
        }
    }

    private function resolveMonth(?string $param): array
    {
        $now = new \DateTimeImmutable('first day of this month');
        if (preg_match('/^(\d{4})-(\d{2})$/', (string) $param, $m)) {
            return ['year' => (int) $m[1], 'month' => (int) $m[2], 'value' => $param];
        }

        return ['year' => (int) $now->format('Y'), 'month' => (int) $now->format('n'), 'value' => $now->format('Y-m')];
    }

    // -----------------------------------------------------------------
    //  Dashboard
    // -----------------------------------------------------------------

    public function dashboard(): void
    {
        $user = $this->guardCompta();

        $month = $this->resolveMonth($_GET['month'] ?? null);
        $agg = Sale::monthAggregates($month['year'], $month['month']);
        $split = Sale::paymentSplit($month['year'], $month['month']);
        $top = Sale::topProducts($month['year'], $month['month']);
        $byCategory = Sale::byCategory($month['year'], $month['month']);

        $reorderAlerts = $this->reorderData()['alerts'];

        $this->renderAdmin('admin/compta/dashboard', [
            'title'        => 'Comptabilité',
            'user'         => $user,
            'month'        => $month,
            'months'       => $this->availableMonths(),
            'agg'          => $agg,
            'split'        => $split,
            'top'          => $top,
            'byCategory'   => $byCategory,
            'reorderAlerts'=> $reorderAlerts,
            'margin'       => ComptaCalc::marginPercent($agg['profit'], $agg['ca_products']),
        ]);
    }

    // -----------------------------------------------------------------
    //  Import CSV
    // -----------------------------------------------------------------

    public function importForm(): void
    {
        $user = $this->guardCompta();

        $this->renderAdmin('admin/compta/import', [
            'title'   => 'Importer un rapport SumUp',
            'user'    => $user,
            'batches' => ImportBatch::all(),
        ]);
    }

    public function import(): void
    {
        $user = $this->guardCompta();

        if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
            $this->setFlash('error', 'Aucun fichier reçu ou erreur d\'upload.');
            redirect(url('/admin/compta/import'));
        }

        $filename = (string) $_FILES['csv']['name'];
        $content = (string) file_get_contents((string) $_FILES['csv']['tmp_name']);

        // Validation minimale : on s'attend à du CSV texte contenant "Prix (TTC)".
        if ($content === '' || stripos($content, 'Prix (TTC)') === false) {
            $this->setFlash('error', 'Ce fichier ne ressemble pas à un rapport SumUp (colonne « Prix (TTC) » absente).');
            redirect(url('/admin/compta/import'));
        }

        $parser = new SumUpCsvParser();
        $parsed = $parser->parse($content, [ProductAlias::class, 'resolve']);

        // On écarte les lignes dont la date n'a pas pu être parsée
        // (colonne sold_at NOT NULL en base).
        $rows = array_values(array_filter(
            $parsed['rows'],
            static fn ($r): bool => !empty($r['sold_at'])
        ));
        $parsed['rows'] = $rows;

        // Création du lot d'import (avant insertion effective).
        $batchId = ImportBatch::create([
            'filename'      => $filename,
            'period_start'  => $parsed['meta']['period_start'],
            'period_end'    => $parsed['meta']['period_end'],
            'rows_total'    => $parsed['meta']['total'],
            'imported_by'   => $user['id'] ?? null,
        ]);

        $result = Sale::importBatch($batchId, $parsed['rows']);

        ImportBatch::finalize($batchId, $result['inserted'], $result['skipped']);

        $this->audit('compta.import', 'import_batch', $batchId, [
            'filename' => $filename,
            'inserted' => $result['inserted'],
            'skipped'  => $result['skipped'],
        ]);

        $unmapped = count(Sale::unmappedDescriptions());

        $this->setFlash(
            'success',
            sprintf(
                'Import terminé : %d nouvelle(s) ligne(s), %d ignorée(s) (doublons). %d libellé(s) à classer.',
                $result['inserted'],
                $result['skipped'],
                $unmapped
            )
        );
        redirect(url('/admin/compta/import'));
    }

    // -----------------------------------------------------------------
    //  Journal des ventes
    // -----------------------------------------------------------------

    public function sales(): void
    {
        $user = $this->guardCompta();

        $month = $this->resolveMonth($_GET['month'] ?? null);
        $category = isset($_GET['category']) && $_GET['category'] !== '' ? (string) $_GET['category'] : null;
        $product = isset($_GET['product']) && $_GET['product'] !== '' ? (string) $_GET['product'] : null;
        $payment = isset($_GET['payment']) && $_GET['payment'] !== '' ? (string) $_GET['payment'] : null;
        $allMonths = isset($_GET['month']) && $_GET['month'] === 'all';

        // Export CSV ?
        if (isset($_GET['export']) && $_GET['export'] === 'csv') {
            $this->exportSalesCsv(
                $allMonths ? null : $month['year'],
                $allMonths ? null : $month['month'],
                $category,
                $product,
                $payment
            );

            return;
        }

        $rows = Sale::journal(
            $allMonths ? null : $month['year'],
            $allMonths ? null : $month['month'],
            $category,
            $product,
            $payment,
            2000
        );

        $this->renderAdmin('admin/compta/sales', [
            'title'      => 'Journal des ventes',
            'user'       => $user,
            'rows'       => $rows,
            'months'     => $this->availableMonths(),
            'categories' => Sale::distinctCategories(),
            'products'   => Sale::distinctProducts(),
            'filters' => [
                'month'    => $allMonths ? 'all' : $month['value'],
                'category' => $category ?? '',
                'product'  => $product ?? '',
                'payment'  => $payment ?? '',
            ],
        ]);
    }

    private function exportSalesCsv(?int $year, ?int $month, ?string $category, ?string $product, ?string $payment): void
    {
        $rows = Sale::journal($year, $month, $category, $product, $payment, 100000);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="ventes_aeic.csv"');
        $out = fopen('php://output', 'w');
        fprintf($out, "\xEF\xBB\xBF"); // BOM UTF-8 pour Excel
        fputcsv($out, ['Date', 'Réf. transaction', 'Paiement', 'Description', 'Produit', 'Catégorie', 'Qté', 'Prix TTC', 'Coût', 'Bénéfice', 'Montant perso']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['sold_at'],
                $r['transaction_ref'],
                $r['payment_method'],
                $r['description'],
                $r['product_key'],
                $r['category'],
                $r['quantity'],
                $r['price_ttc'],
                $r['cost_price'],
                $r['profit'],
                $r['is_custom_amount'] ? 'oui' : 'non',
            ]);
        }
        fclose($out);
        exit;
    }

    // -----------------------------------------------------------------
    //  Produits (bénéfice)
    // -----------------------------------------------------------------

    public function products(): void
    {
        $user = $this->guardCompta();

        $month = $this->resolveMonth($_GET['month'] ?? null);
        $allMonths = isset($_GET['month']) && $_GET['month'] === 'all';

        // « Toute l'année » : on agrège sur l'année courante (mois = null).
        if ($allMonths) {
            $rows = Sale::byProduct((int) date('Y'), null);
        } else {
            $rows = Sale::byProduct($month['year'], $month['month']);
        }

        $this->renderAdmin('admin/compta/products', [
            'title'  => 'Bénéfice par produit',
            'user'   => $user,
            'rows'   => $rows,
            'month'  => $month,
            'months' => $this->availableMonths(),
            'all'    => $allMonths,
        ]);
    }

    // -----------------------------------------------------------------
    //  Catégories
    // -----------------------------------------------------------------

    public function categories(): void
    {
        $user = $this->guardCompta();

        $month = $this->resolveMonth($_GET['month'] ?? null);
        $allMonths = isset($_GET['month']) && $_GET['month'] === 'all';

        $rows = Sale::byCategory(
            $allMonths ? null : $month['year'],
            $allMonths ? null : $month['month']
        );

        $this->renderAdmin('admin/compta/categories', [
            'title'  => 'Bénéfice par catégorie',
            'user'   => $user,
            'rows'   => $rows,
            'month'  => $month,
            'months' => $this->availableMonths(),
            'all'    => $allMonths,
        ]);
    }

    // -----------------------------------------------------------------
    //  Coûts de revient
    // -----------------------------------------------------------------

    public function costs(): void
    {
        $user = $this->guardCompta();

        $this->renderAdmin('admin/compta/costs', [
            'title' => 'Coûts de revient',
            'user'  => $user,
            'costs' => ProductCost::all(),
            'form'  => [
                'product_key' => $_GET['product_key'] ?? '',
                'cost_price'  => '',
                'valid_from'  => date('Y-m-d'),
                'supplier'    => '',
                'notes'       => '',
            ],
        ]);
    }

    public function saveCost(): void
    {
        $user = $this->guardCompta();

        $data = $_POST;
        $data['cost_price'] = parseFrenchFloat((string) ($data['cost_price'] ?? '0'));

        $id = ProductCost::create($data);
        if ($id === '') {
            $this->setFlash('error', 'Produit et date de début requis.');
        } else {
            $this->audit('compta.cost.create', 'product_cost', $id, [
                'product_key' => $data['product_key'] ?? null,
                'cost_price'  => $data['cost_price'],
            ]);
            $this->setFlash('success', 'Lot de coût enregistré.');
        }

        redirect(url('/admin/compta/couts'));
    }

    public function closeCost(string $id): void
    {
        $this->guardCompta();

        ProductCost::close($id);
        $this->audit('compta.cost.close', 'product_cost', $id);
        $this->setFlash('success', 'Lot clôturé.');
        redirect(url('/admin/compta/couts'));
    }

    // -----------------------------------------------------------------
    //  Aliases (mapping libellés)
    // -----------------------------------------------------------------

    public function aliases(): void
    {
        $user = $this->guardCompta();

        $this->renderAdmin('admin/compta/aliases', [
            'title'    => 'Mapping des libellés',
            'user'     => $user,
            'aliases'  => ProductAlias::all(),
            'unmapped' => Sale::unmappedDescriptions(),
        ]);
    }

    public function saveAlias(): void
    {
        $this->guardCompta();

        $data = $_POST;
        if (ProductAlias::save($data) === '') {
            $this->setFlash('error', 'Libellé et produit canonique requis.');
        } else {
            $this->audit('compta.alias.save', 'product_alias', null, [
                'raw' => $data['raw_description'] ?? null,
            ]);
            $this->setFlash('success', 'Libellé rattaché au produit.');
        }

        redirect(url('/admin/compta/aliases'));
    }

    public function deleteAlias(string $id): void
    {
        $this->guardCompta();

        ProductAlias::deleteRow($id);
        $this->audit('compta.alias.delete', 'product_alias', $id);
        $this->setFlash('success', 'Alias supprimé.');
        redirect(url('/admin/compta/aliases'));
    }

    // -----------------------------------------------------------------
    //  Réapprovisionnement
    // -----------------------------------------------------------------

    public function reorder(): void
    {
        $user = $this->guardCompta();

        $data = $this->reorderData();

        $this->renderAdmin('admin/compta/reorder', [
            'title'  => 'Réapprovisionnement',
            'user'   => $user,
            'rows'   => $data['rows'],
            'alerts' => $data['alerts'],
        ]);
    }

    /**
     * Calcule l'analyse de réapprovisionnement.
     *
     * Pour chaque produit cafétéria (table products), on rapproche le stock
     * actuel de la consommation moyenne (moyenne mobile 3 mois lue depuis
     * sales, rapprochée par nom = product_key).
     *
     * @return array{rows:list<array<string,mixed>>, alerts:int}
     */
    private function reorderData(): array
    {
        $products = Product::allForAdmin();
        $consumption = Sale::consumptionByProductKey(3);

        $rows = [];
        $alerts = 0;

        foreach ($products as $p) {
            $key = (string) ($p['name'] ?? '');
            if ($key === '') {
                continue;
            }

            $monthlyData = $consumption[$key] ?? null;
            $monthly = $monthlyData['monthly'] ?? [];
            $avg = ComptaCalc::movingAverage($monthly, 3);
            $stock = (int) ($p['stock'] ?? 0);
            $autonomy = ComptaCalc::autonomyDays($stock, $avg);
            $suggested = ComptaCalc::suggestedReorder($avg, 1, $stock);

            $isAlert = ($autonomy !== null && $autonomy < 7) || $stock <= 0;
            if ($isAlert) {
                $alerts++;
            }

            $rows[] = [
                'name'        => $key,
                'category'    => $p['category_name'] ?? '—',
                'stock'       => $stock,
                'avg_month'   => $avg,
                'autonomy'    => $autonomy,
                'suggested'   => $suggested,
                'is_alert'    => $isAlert,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $aa = $a['autonomy'] ?? PHP_INT_MAX;
            $bb = $b['autonomy'] ?? PHP_INT_MAX;

            return $aa <=> $bb;
        });

        return ['rows' => $rows, 'alerts' => $alerts];
    }
}
