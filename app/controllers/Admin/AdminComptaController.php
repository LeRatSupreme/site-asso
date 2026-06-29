<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Compta\AliasSuggester;
use App\Core\Compta\ComptaCalc;
use App\Core\Compta\SumUpCsvParser;
use App\Models\ImportBatch;
use App\Models\Product;
use App\Models\ProductAlias;
use App\Models\ProductCost;
use App\Models\ProductStock;
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

        // Liste des produits connus pour l'autocomplétion (anti-fautes de frappe).
        $keys = Sale::distinctProducts();
        foreach (Product::allForAdmin() as $p) {
            $keys[] = (string) ($p['name'] ?? '');
        }
        foreach (ProductCost::all() as $c) {
            $keys[] = (string) ($c['product_key'] ?? '');
        }
        $keys = array_values(array_filter(array_unique($keys)));
        sort($keys, SORT_STRING | SORT_FLAG_CASE);

        // Tous les lots, regroupés par produit.
        $costs = ProductCost::all();
        $lotsByProduct = [];
        foreach ($costs as $c) {
            $k = (string) ($c['product_key'] ?? '—');
            $lotsByProduct[$k][] = $c;
        }

        // Tous les produits vendus (avec catégorie + quantité), pour tout afficher
        // même ceux sans coût saisi.
        $salesProducts = Sale::byProduct((int) date('Y'), null);
        $catByProduct = [];
        $qtyByProduct = [];
        foreach ($salesProducts as $sp) {
            $name = (string) $sp['product_key'];
            $catByProduct[$name] = (string) ($sp['category'] ?? 'Non classé');
            $qtyByProduct[$name] = (int) ($sp['qty'] ?? 0);
        }

        // Construction de la liste d'affichage (produits vendus + produits avec lots).
        $allNames = array_unique(array_merge(array_keys($catByProduct), array_keys($lotsByProduct)));
        $items = [];
        foreach ($allNames as $name) {
            $lots = $lotsByProduct[$name] ?? [];
            // Lot en cours = valid_to NULL le plus récent.
            $current = null;
            foreach ($lots as $l) {
                if (empty($l['valid_to'])) { $current = $l; break; }
            }
            $items[] = [
                'name'        => $name,
                'category'    => $catByProduct[$name] ?? 'Non classé',
                'qty'         => $qtyByProduct[$name] ?? 0,
                'currentCost' => $current ? (float) $current['cost_price'] : null,
                'lotsCount'   => count($lots),
                'lots'        => $lots,
            ];
        }
        usort($items, static function ($a, $b) { return strnatcasecmp($a['name'], $b['name']); });

        $this->renderAdmin('admin/compta/costs', [
            'title'       => 'Coûts de revient',
            'user'        => $user,
            'costs'       => $costs,
            'items'       => $items,
            'categories'  => array_values(array_unique(array_filter($catByProduct))),
            'productKeys' => $keys,
            'form'        => [
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

    public function deleteCost(string $id): void
    {
        $this->guardCompta();

        ProductCost::delete($id);
        $this->audit('compta.cost.delete', 'product_cost', $id);
        $this->setFlash('success', 'Lot supprimé.');
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
    //  Auto-détection de doublons (consolidation des libellés)
    // -----------------------------------------------------------------

    /**
     * Suggère un mapping canonique pour chaque libellé rencontré, en regroupant
     * les variantes (Bueno_white / Bueno, Coca_cherry / Coca cherry...) via une
     * heuristique déterministe. Affiche un tableau éditable avant application.
     */
    public function aliasesAuto(): void
    {
        $user = $this->guardCompta();

        $descriptions = Sale::allDescriptions();

        // Mapping déjà existant (raw -> product_key), pour pré-sélectionner.
        $existing = [];
        foreach (ProductAlias::all() as $alias) {
            $existing[(string) $alias['raw_description']] = (string) $alias['product_key'];
        }

        // Suggestion : on regroupe par clé canonique suggérée.
        $rows = [];
        $groups = [];
        foreach ($descriptions as $d) {
            $raw = (string) ($d['description'] ?? '');
            if ($raw === '') {
                continue;
            }
            $suggested = AliasSuggester::suggest($raw);
            $current = $existing[$raw] ?? $suggested;

            $rows[] = [
                'raw'        => $raw,
                'occurrences'=> (int) ($d['occurrences'] ?? 0),
                'suggested'  => $suggested,
                'canonical'  => $current,
                'already'    => isset($existing[$raw]),
            ];
            $groups[$current][] = $raw;
        }

        // Trie par clé canonique suggérée, puis occurrences décroissantes.
        usort($rows, static function (array $a, array $b): int {
            $cmp = strnatcasecmp($a['canonical'], $b['canonical']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return $b['occurrences'] <=> $a['occurrences'];
        });

        $this->renderAdmin('admin/compta/aliases_auto', [
            'title' => 'Auto-détection des doublons',
            'user'  => $user,
            'rows'  => $rows,
        ]);
    }

    /**
     * Applique le mapping validé (POST) : crée/met à jour chaque alias puis
     * ré-applique le mapping à toutes les ventes existantes.
     */
    public function aliasesApply(): void
    {
        $this->guardCompta();

        $raws = $_POST['raw'] ?? [];
        $keys = $_POST['canonical'] ?? [];
        if (!is_array($raws) || !is_array($keys)) {
            $this->setFlash('error', 'Données invalides.');
            redirect(url('/admin/compta/aliases/auto'));
        }

        $applied = 0;
        $details = [];
        for ($i = 0, $n = max(count($raws), count($keys)); $i < $n; $i++) {
            $raw = trim((string) ($raws[$i] ?? ''));
            $key = trim((string) ($keys[$i] ?? ''));
            if ($raw === '' || $key === '') {
                continue;
            }
            if (ProductAlias::save(['raw_description' => $raw, 'product_key' => $key]) !== '') {
                $applied++;
                $details[] = $raw . ' -> ' . $key;
            }
        }

        $updated = Sale::reapplyAliases();

        $this->audit('compta.alias.apply', 'product_alias', null, [
            'applied'      => $applied,
            'sales_updated' => $updated,
        ]);

        $this->setFlash(
            'success',
            sprintf(
                '%d alias enregistré(s) — %d vente(s) mise(s) à jour.',
                $applied,
                $updated
            )
        );
        redirect(url('/admin/compta/aliases'));
    }

    // -----------------------------------------------------------------
    //  Réapprovisionnement
    // -----------------------------------------------------------------

    public function reorder(): void
    {
        $user = $this->guardCompta();

        // Période cible d'approvisionnement, exprimée en JOURS D'OUVERTURE
        // (la cafétéria est ouverte du lundi au vendredi : 5 j/semaine).
        $periods = [
            '1w' => ['label' => '1 semaine',  'days' => 5],   // 5 j d'ouverture
            '2w' => ['label' => '2 semaines', 'days' => 10],
            '1m' => ['label' => '1 mois',     'days' => 22],  // ≈ 22 j ouvrés
            '2m' => ['label' => '2 mois',     'days' => 43],
            '3m' => ['label' => '3 mois',     'days' => 65],
        ];
        $periodKey = $_GET['period'] ?? '1m';
        if (!isset($periods[$periodKey])) {
            $periodKey = '1m';
        }
        $targetDays = $periods[$periodKey]['days'];

        $data = $this->reorderData($targetDays);

        $this->renderAdmin('admin/compta/reorder', [
            'title'        => 'Réapprovisionnement',
            'user'         => $user,
            'rows'         => $data['rows'],
            'alerts'       => $data['alerts'],
            'periods'      => $periods,
            'currentPeriod'=> $periodKey,
            'targetDays'   => $targetDays,
        ]);
    }

    /**
     * Enregistre les stocks saisis sur la page Réappro (table product_stocks).
     */
    public function saveStocks(): void
    {
        $this->guardCompta();

        $stocks = $_POST['stocks'] ?? [];
        if (!is_array($stocks)) {
            $stocks = [];
        }

        $count = 0;
        foreach ($stocks as $key => $value) {
            $productKey = trim((string) $key);
            if ($productKey === '') {
                continue;
            }
            $value = trim((string) $value);
            if ($value === '') {
                // Champ vide : on n'écrase pas (stock laissé inconnu).
                continue;
            }
            ProductStock::set($productKey, (int) $value);
            $count++;
        }

        $this->audit('compta.reappro.stocks', 'product_stocks', null, ['count' => $count]);
        $this->setFlash('success', sprintf('%d stock(s) mis à jour.', $count));

        $period = (string) ($_POST['period'] ?? '1m');
        redirect(url('/admin/compta/reappro?period=' . rawurlencode($period)));
    }

    /**
     * Calcule l'analyse de réapprovisionnement.
     *
     * Basée sur TOUTES les ventes importées (table sales) : chaque produit
     * présent dans les rapports SumUp est listé, avec sa consommation moyenne
     * (moyenne mobile 3 mois) par jour / semaine / mois et la quantité à
     * commander pour couvrir la période cible. Le stock n'est connu que pour
     * les produits présents dans la table cafétéria (sinon : « — »).
     *
     * @return array{rows:list<array<string,mixed>>, alerts:int}
     */
    private function reorderData(int $targetDays): array
    {
        $consumption = Sale::consumptionByProductKey(3); // tous les produits des ventes
        $products    = Product::allForAdmin();
        $stocksInput = ProductStock::allMap();           // stocks saisis sur Réappro

        // Stock + catégorie par nom de produit (depuis la table cafétéria).
        $stockByName = [];
        $catByName   = [];
        foreach ($products as $p) {
            $name = (string) ($p['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $stockByName[$name] = (int) ($p['stock'] ?? 0);
            $catByName[$name]   = $p['category_name'] ?? '—';
        }

        $rows = [];
        $alerts = 0;
        $seen = [];

        // 1) Tous les produits présents dans les ventes (CSV).
        foreach ($consumption as $key => $data) {
            $key = (string) $key;
            if ($key === '') {
                continue;
            }
            $seen[$key] = true;

            $monthly  = $data['monthly'] ?? [];
            $avgMonth = ComptaCalc::movingAverage($monthly, 3);
            // Jours d'ouverture : CAF ouvert lun-ven (5 j/sem) ≈ 21,77 j/mois.
            // Les ventes n'ayant lieu qu'en semaine, la conso mensuelle est
            // répartie sur ces jours d'ouverture (et non sur 30 j calendaires).
            $openDaysPerMonth = 21.77;
            $avgDay   = $avgMonth / $openDaysPerMonth;   // par jour d'ouverture
            $avgWeek  = $avgDay * 5.0;                   // par semaine d'ouverture (lun-ven)

            // Priorité du stock : valeur saisie sur Réappro > stock cafétéria > inconnu.
            if (array_key_exists($key, $stocksInput)) {
                $stock = $stocksInput[$key];
            } elseif (array_key_exists($key, $stockByName)) {
                $stock = $stockByName[$key];
            } else {
                $stock = null;
            }

            $autonomy = ($stock !== null && $avgDay > 0)
                ? (int) floor($stock / $avgDay)
                : null;

            $need   = (int) ceil($avgDay * $targetDays);
            $toOrder = max(0, $need - ($stock ?? 0));

            // État : à définir (stock inconnu), à racheter (stock <= 0 ou
            // autonomie < 7 j), ou OK.
            if ($stock === null) {
                $state = 'unknown';
            } elseif ($stock <= 0 || ($autonomy !== null && $autonomy < 7)) {
                $state = 'reorder';
            } else {
                $state = 'ok';
            }
            $isAlert = ($state === 'reorder');
            if ($isAlert) {
                $alerts++;
            }

            $rows[] = [
                'name'      => $key,
                'category'  => $catByName[$key] ?? (string) ($data['category'] ?? '—'),
                'stock'     => $stock,        // null = inconnu
                'avg_day'   => $avgDay,
                'avg_week'  => $avgWeek,
                'avg_month' => $avgMonth,
                'autonomy'  => $autonomy,
                'need'      => $need,
                'to_order'  => $toOrder,
                'state'     => $state,
                'is_alert'  => $isAlert,
            ];
        }

        // 2) Produits cafétéria sans ventes enregistrées (stock mais conso 0).
        foreach ($stockByName as $name => $stk) {
            if (isset($seen[$name])) {
                continue;
            }
            // Stock saisi prioritaire si présent.
            $finalStock = array_key_exists($name, $stocksInput) ? $stocksInput[$name] : $stk;
            $state = ($finalStock !== null && $finalStock <= 0) ? 'reorder' : 'ok';
            if ($state === 'reorder') {
                $alerts++;
            }
            $rows[] = [
                'name'      => $name,
                'category'  => $catByName[$name] ?? '—',
                'stock'     => $finalStock,
                'avg_day'   => 0.0,
                'avg_week'  => 0.0,
                'avg_month' => 0.0,
                'autonomy'  => null,
                'need'      => 0,
                'to_order'  => 0,
                'state'     => $state,
                'is_alert'  => $state === 'reorder',
            ];
        }

        // Tri : quantité à commander décroissante (les plus urgents d'abord).
        usort($rows, static function (array $a, array $b): int {
            if ($b['to_order'] !== $a['to_order']) {
                return $b['to_order'] <=> $a['to_order'];
            }
            $aa = $a['autonomy'] ?? PHP_INT_MAX;
            $bb = $b['autonomy'] ?? PHP_INT_MAX;

            return $aa <=> $bb;
        });

        return ['rows' => $rows, 'alerts' => $alerts];
    }
}
