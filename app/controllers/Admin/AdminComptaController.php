<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Compta\AliasSuggester;
use App\Core\Compta\CashLedger;
use App\Core\Compta\ComptaCalc;
use App\Core\Compta\CostLotStock;
use App\Core\Compta\Dashboard\Data;
use App\Core\Compta\Kiosk;
use App\Core\Compta\KioskPages;
use App\Core\Compta\ProductAutoSync;
use App\Core\Compta\Reappro\BesoinListe;
use App\Core\Compta\ScanCatalogMatcher;
use App\Core\Compta\StockPublic;
use App\Core\Compta\SumUpCsvParser;
use App\Core\Logger;
use App\Core\Permissions;
use App\Models\ImportBatch;
use App\Models\Kiosque;
use App\Models\Product;
use App\Models\ProductAlias;
use App\Models\ProductCategory;
use App\Models\ProductCost;
use App\Models\ProductDiscontinued;
use App\Models\ProductInfinite;
use App\Models\ProductKeyMerge;
use App\Models\ProductPack;
use App\Models\ReapproDemande;
use App\Models\Sale;
use App\Models\SaleAdjustment;
use App\Models\ScanLabel;
use App\Models\Setting;
use App\Models\ShoppingCheck;

/**
 * Module Comptabilité & gestion des achats (Cafétéria).
 *
 * Réservé aux rôles ADMIN et TRESORERIE (voir guardCompta()) ; la page
 * « Coûts de revient » et ses actions font exception : elles appartiennent
 * au groupe « Système » — réservées au Fondateur et aux ADMIN listés dans
 * SYSTEM_ADMINS, ou aux utilisateurs ayant reçu la page en attribution
 * individuelle (voir guardSystemOrPage()).
 *
 * Flux : import CSV SumUp -> table `sales` (dédupliquée) -> mapping aliases
 * -> coûts de revient par lot daté -> calculs (CA, bénéfices, marge) ->
 * analyses de réapprovisionnement.
 */
final class AdminComptaController extends AdminBaseController
{
    /** Taille maximale d'un CSV importé (5 Mo). */
    private const MAX_CSV_SIZE = 5_242_880;

    /**
     * Encart « Base de calcul » (réappro + liste de courses, admin et
     * kiosque) : source du stock, énoncée à l'identique sur les 4 rendus.
     */
    private const BASE_CALCUL_SOURCE = 'stock théorique (comptages, achats, ventes, pertes)';

    // -----------------------------------------------------------------
    //  Dashboard
    // -----------------------------------------------------------------

    public function dashboard(): void
    {
        $user = $this->guardCompta();

        // Assemblage du payload déporté dans Dashboard\Data (point
        // d'assemblage unique partagé avec l'accueil /admin — règle
        // AGENTS.md). Contrat de la vue inchangé : les mêmes clés sont
        // injectées, avec les mêmes valeurs qu'avant l'extraction.
        $payload = Data::construire(
            isset($_GET['period']) ? (string) $_GET['period'] : null,
            isset($_GET['from']) ? (string) $_GET['from'] : null,
            isset($_GET['to']) ? (string) $_GET['to'] : null
        );

        $this->renderAdmin('admin/compta/dashboard', array_merge([
            'title'        => 'Comptabilité',
            'user'         => $user,
            'periodOptions'=> ComptaCalc::PERIOD_OPTIONS,
        ], $payload));
    }

    /**
     * Seuil du dashboard lu en base (clé « dashboard_* ») : valeur vide ou
     * absente → constante Hyper (jamais 0, qui alerterait à tort).
     */
    private static function seuilSetting(string $key, float $defaut): float
    {
        $raw = trim((string) Setting::get($key, (string) $defaut));

        return $raw !== '' ? (float) $raw : $defaut;
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
            // Charge bornée : la synchro API crée un lot par passage, les
            // 500 derniers suffisent à l'affichage (la vue regroupe à part
            // les passages de synchro et les imports manuels).
            'batches' => ImportBatch::recent(500),
            // Compteur exact (indépendant de la borne ci-dessus).
            'salesTotal' => Sale::count(),
            // Diagnostic de classification des moyens de paiement (frais CB).
            'paymentRaw' => Sale::paymentRawBreakdown(),
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

        // Garde de taille : on refuse les rapports anormalement gros avant
        // toute lecture / parsing en mémoire.
        if ((int) ($_FILES['csv']['size'] ?? 0) > self::MAX_CSV_SIZE) {
            $this->setFlash('error', 'Fichier trop volumineux (5 Mo maximum).');
            redirect(url('/admin/compta/import'));
        }

        $content = (string) file_get_contents((string) $_FILES['csv']['tmp_name']);

        // Validation minimale : on s'attend à du CSV texte contenant "Prix (TTC)".
        if ($content === '' || stripos($content, 'Prix (TTC)') === false) {
            $this->setFlash('error', 'Ce fichier ne ressemble pas à un rapport SumUp (colonne « Prix (TTC) » absente).');
            redirect(url('/admin/compta/import'));
        }

        // ── 1. Anti-réimport : une empreinte déjà connue bloque le fichier
        //       avant tout traitement (aucun lot créé).
        $fileHash = hash('sha256', $content);
        $existing = ImportBatch::findByHash($fileHash);
        if ($existing !== null) {
            $this->setFlash('error', sprintf(
                'Ce fichier a déjà été importé le %s (%s). Import refusé.',
                formatDateTime((string) ($existing['imported_at'] ?? '')),
                (string) ($existing['filename'] ?? 'fichier inconnu')
            ));
            redirect(url('/admin/compta/import'));
        }

        // ── 2. Parsing : lignes valides + invalides classées avec motif.
        $parser = new SumUpCsvParser();
        $parsed = $parser->parse($content, [ProductAlias::class, 'resolve']);

        $rows = $parsed['rows'];
        $invalidCounts = $parsed['meta']['invalid'] ?? [];
        $invalidTotal = array_sum($invalidCounts);

        // Aucune vente exploitable : rien à importer (les dates invalides ou
        // hors plage ont été écartées par le parseur, sold_at est NOT NULL).
        if ($rows === [] || $parsed['meta']['period_start'] === null) {
            $message = 'Aucune vente exploitable dans ce fichier — import refusé.';
            if ($invalidTotal > 0) {
                $message .= sprintf(
                    ' %d ligne(s) invalide(s) : %s.',
                    $invalidTotal,
                    self::invalidSummary($invalidCounts)
                );
            }
            $this->setFlash('error', $message);
            redirect(url('/admin/compta/import'));
        }

        // ── 3. Chevauchement de périodes avec des imports précédents
        //       (avertissement non bloquant : la clé unique écarte de toute
        //       façon les ventes identiques).
        $overlaps = ImportBatch::overlapping(
            (string) $parsed['meta']['period_start'],
            (string) $parsed['meta']['period_end'],
            $fileHash
        );

        // ── 4. Lot + insertion (valides uniquement).
        $batchId = ImportBatch::create([
            'filename'      => $filename,
            'file_hash'     => $fileHash,
            'period_start'  => $parsed['meta']['period_start'],
            'period_end'    => $parsed['meta']['period_end'],
            'rows_total'    => $parsed['meta']['total'],
            'imported_by'   => $user['id'] ?? null,
        ]);

        try {
            $result = Sale::importBatch($batchId, $rows);
        } catch (\Throwable $e) {
            // Lot fantôme : l'insertion a échoué, on supprime le lot fraîchement
            // créé (aucune ligne insérée) pour que son empreinte UNIQUE ne
            // bloque pas à tort le ré-import du même fichier. Nettoyage
            // silencieux : ne jamais masquer l'erreur initiale.
            if ($batchId !== null) {
                try {
                    ImportBatch::delete($batchId);
                } catch (\Throwable) {
                    // Le lot reste éventuellement en base ; l'erreur d'import
                    // d'origine prime sur cet échec de nettoyage.
                }
            }
            $this->audit('compta.import_failed', 'import_batch', $batchId, [
                'filename' => $filename,
                'error'    => $e->getMessage(),
            ]);
            // Message générique affiché : le détail de l'erreur reste dans
            // l'audit et le log applicatif.
            Logger::error('compta: import CSV échoué', [
                'filename' => $filename,
                'msg'      => $e->getMessage(),
            ]);
            $this->setFlash('error', 'Import interrompu, aucune ligne enregistrée : une erreur inattendue est survenue. L\'incident a été journalisé.');
            redirect(url('/admin/compta/import'));
        }

        ImportBatch::finalize($batchId, $result['inserted'], $result['skipped']);

        $this->audit('compta.import', 'import_batch', $batchId, [
            'filename'        => $filename,
            'file_hash'       => $fileHash,
            'inserted'        => $result['inserted'],
            'skipped'         => $result['skipped'],
            'file_duplicates' => $result['file_duplicates'],
            'invalid'         => $invalidTotal,
        ]);

        // ── Synchro automatique de la carte : les nouveaux libellés du lot
        //       (ou tout libellé jamais couvert par une fiche) obtiennent
        //       leur produit. Jamais bloquant : un échec est silencieux.
        $sync = ['created' => []];
        try {
            $sync = ProductAutoSync::sync();
        } catch (\Throwable) {
            // La compta ne doit jamais casser à cause de la synchro carte.
        }

        // ── Synchro des catégories du mapping : les lignes fraîchement
        //       importées portent la catégorie de leur alias. Non bloquant
        //       (la méthode avale elle-même les erreurs SQL).
        Sale::syncAliasCategories();

        // ── Variantes par prix : les lignes fraîchement importées dont
        //       le prix révèle une variante (ex. « cafe » à 0,70 € =
        //       allongé 2 dosettes) reçoivent leur clé canonique. Après
        //       le mapping d'alias, et seulement si l'import a inséré —
        //       non bloquant (la méthode avale elle-même les erreurs SQL).
        if ($result['inserted'] > 0) {
            Sale::applyPriceVariants();
        }

        // Les ventes importées déduisent le théorique : la carte publique suit.
        StockPublic::invalidate();

        // ── Les catégories utilisées par le mapping existent aussi dans la
        //       carte (page Catégories de la cafétéria) : création
        //       silencieuse si absente. Jamais bloquant pour l'import.
        try {
            foreach (ProductAlias::distinctCategories() as $cat) {
                ProductCategory::ensure($cat, $user['id'] ?? null);
            }
        } catch (\Throwable) {
            // La compta ne doit jamais casser à cause de la synchro carte.
        }

        $unmapped = count(Sale::unmappedDescriptions());

        // ── 5. Flash : 100 % doublon (fichier différent mais lignes déjà
        //       en base) ou bilan détaillé.
        if ($result['inserted'] === 0 && $result['file_duplicates'] > 0 && $result['skipped'] > 0) {
            $this->setFlash('warning', 'Aucune nouvelle ligne — ce fichier était déjà importé (ou 100 % doublon).');
            redirect(url('/admin/compta/import'));
        }

        $message = sprintf(
            'Import terminé : %d insérée(s) · %d déjà en base · %d doublon(s) dans le fichier',
            $result['inserted'],
            $result['skipped'],
            $result['file_duplicates']
        );
        if ($invalidTotal > 0) {
            $message .= sprintf(
                ' · %d ligne(s) invalide(s) (%s)',
                $invalidTotal,
                self::invalidSummary($invalidCounts)
            );
        }
        $message .= sprintf(' · %d libellé(s) à classer.', $unmapped);
        if ($sync['created'] !== []) {
            $message .= ' Carte mise à jour automatiquement : ' . implode(', ', $sync['created']) . '.';
        }
        $this->setFlash('success', $message);

        if ($overlaps !== []) {
            $periods = [];
            foreach ($overlaps as $o) {
                $periods[] = (string) ($o['period_start'] ?? '?') . ' → ' . (string) ($o['period_end'] ?? '?');
            }
            $this->setFlash('warning', sprintf(
                'Périodes en chevauchement avec les imports du %s — les ventes identiques ont été ignorées.',
                implode(', ', $periods)
            ));
        }

        redirect(url('/admin/compta/import'));
    }

    /**
     * Résumé lisible des lignes invalides : « 3 sans référence de
     * transaction, 2 prix négatif ».
     *
     * @param array<string,int> $invalidCounts
     */
    private static function invalidSummary(array $invalidCounts): string
    {
        $parts = [];
        foreach ($invalidCounts as $reason => $count) {
            $parts[] = $count . ' ' . $reason;
        }

        return implode(', ', $parts);
    }

    // -----------------------------------------------------------------
    //  Journal des ventes
    // -----------------------------------------------------------------

    public function sales(): void
    {
        $user = $this->guardCompta();

        $period = ComptaCalc::resolvePeriod($_GET['period'] ?? null, $_GET['from'] ?? null, $_GET['to'] ?? null);
        $category = isset($_GET['category']) && $_GET['category'] !== '' ? (string) $_GET['category'] : null;
        $product = isset($_GET['product']) && $_GET['product'] !== '' ? (string) $_GET['product'] : null;
        $payment = isset($_GET['payment']) && $_GET['payment'] !== '' ? (string) $_GET['payment'] : null;

        // Export CSV ?
        if (isset($_GET['export']) && $_GET['export'] === 'csv') {
            $this->exportSalesCsvBetween($period, $category, $product, $payment);

            return;
        }

        $rows = Sale::journalBetween($period['from'], $period['to'], $category, $product, $payment, 500);

        $this->renderAdmin('admin/compta/sales', [
            'title'         => 'Journal des ventes',
            'user'          => $user,
            'rows'          => $rows,
            'period'        => $period,
            'periodOptions' => ComptaCalc::PERIOD_OPTIONS,
            'categories'    => Sale::distinctCategories(),
            'products'      => Sale::distinctProducts(),
            'filters' => [
                'period'   => $period['preset'],
                'from'     => $period['from'] ?? '',
                'to'       => $period['to'] ?? '',
                'category' => $category ?? '',
                'product'  => $product ?? '',
                'payment'  => $payment ?? '',
            ],
        ]);
    }

    /**
     * Export CSV du journal des ventes sur la période résolue, avec les
     * filtres catégorie / produit / paiement éventuels.
     *
     * @param array{preset:string,from:?string,to:?string} $period
     */
    private function exportSalesCsvBetween(array $period, ?string $category, ?string $product, ?string $payment): void
    {
        $rows = Sale::journalBetween($period['from'], $period['to'], $category, $product, $payment, 100000);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="ventes_aeic.csv"');
        $out = fopen('php://output', 'w');
        fprintf($out, "\xEF\xBB\xBF"); // BOM UTF-8 pour Excel
        fputcsv($out, ['Date', 'Réf. transaction', 'Paiement', 'Description', 'Produit', 'Catégorie', 'Qté', 'Prix TTC', 'Coût', 'Bénéfice', 'Montant perso']);
        foreach ($rows as $r) {
            fputcsv($out, [
                utcToParis((string) $r['sold_at']),
                self::csvSafe($r['transaction_ref'] ?? null),
                self::csvSafe($r['payment_method'] ?? null),
                self::csvSafe($r['description'] ?? null),
                self::csvSafe($r['product_key'] ?? null),
                self::csvSafe($r['category'] ?? null),
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

    /**
     * Neutralise l'injection de formules dans les exports CSV/Excel
     * (« CSV injection ») : si la valeur (une fois les espaces initiaux
     * retirés) commence par un caractère interprété comme formule
     * (=, +, -, @, tabulation, CR ou LF), on la préfixe d'une apostrophe
     * simple pour qu'elle soit traitée comme du texte.
     */
    private static function csvSafe(?string $v): string
    {
        if ($v === null || $v === '') {
            return '';
        }

        $trimmed = ltrim($v);
        $first = $trimmed === '' ? '' : $trimmed[0];
        if (in_array($first, ['=', '+', '-', '@', "\t", "\r", "\n"], true)
            || in_array($v[0], ["\t", "\r", "\n"], true)
        ) {
            return "'" . $v;
        }

        return $v;
    }

    // -----------------------------------------------------------------
    //  Produits (bénéfice)
    // -----------------------------------------------------------------

    public function products(): void
    {
        $user = $this->guardCompta();

        $period = ComptaCalc::resolvePeriod($_GET['period'] ?? null, $_GET['from'] ?? null, $_GET['to'] ?? null);
        $rows = Sale::byProductBetween($period['from'], $period['to']);

        // Marge par produit (lot 4B : calcul sorti de la vue), en % —
        // 0.0 si le CA du produit est nul (même règle qu'auparavant).
        foreach ($rows as &$r) {
            $ca = (float) $r['ca'];
            $r['margin'] = $ca > 0 ? round((float) $r['profit'] / $ca * 100, 1) : 0.0;
        }
        unset($r);

        $this->renderAdmin('admin/compta/products', [
            'title'         => 'Bénéfice par produit',
            'user'          => $user,
            'rows'          => $rows,
            'period'        => $period,
            'periodOptions' => ComptaCalc::PERIOD_OPTIONS,
        ]);
    }

    // -----------------------------------------------------------------
    //  Catégories
    // -----------------------------------------------------------------

    public function categories(): void
    {
        $user = $this->guardCompta();

        $period = ComptaCalc::resolvePeriod($_GET['period'] ?? null, $_GET['from'] ?? null, $_GET['to'] ?? null);
        $rows = Sale::byCategoryBetween($period['from'], $period['to']);

        // Totaux, marge par catégorie et part du CA (lot 4B : calculs
        // sortis de la vue — la vue ne lit que des clés prêtes).
        $totaux = ['qty' => 0, 'ca' => 0.0, 'profit' => 0.0, 'margin' => 0.0];
        foreach ($rows as &$r) {
            $ca = (float) $r['ca'];
            $profit = (float) $r['profit'];
            $r['margin'] = $ca > 0 ? round($profit / $ca * 100, 1) : 0.0;
            $totaux['qty'] += (int) $r['qty'];
            $totaux['ca'] += $ca;
            $totaux['profit'] += $profit;
        }
        unset($r);
        $totaux['margin'] = $totaux['ca'] > 0 ? round($totaux['profit'] / $totaux['ca'] * 100, 1) : 0.0;
        foreach ($rows as &$r) {
            $r['share'] = $totaux['ca'] > 0 ? round((float) $r['ca'] / $totaux['ca'] * 100, 1) : 0.0;
        }
        unset($r);

        $this->renderAdmin('admin/compta/categories', [
            'title'         => 'Bénéfice par catégorie',
            'user'          => $user,
            'rows'          => $rows,
            'totaux'        => $totaux,
            'period'        => $period,
            'periodOptions' => ComptaCalc::PERIOD_OPTIONS,
        ]);
    }

    // -----------------------------------------------------------------
    //  Coûts de revient
    // -----------------------------------------------------------------

    public function costs(): void
    {
        $user = $this->guardSystemOrPage('costs');

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
            // Lot en cours = lot applicable à aujourd'hui (même règle « as-of »
            // que les calculs : couvrant, sinon précédent — sinon aucun :
            // coût inconnu avant le premier lot, jamais un lot futur).
            $current = $lots === [] ? null : ComptaCalc::selectCostLot(date('Y-m-d'), $lots);
            $items[] = [
                'name'         => $name,
                'category'     => $catByProduct[$name] ?? 'Non classé',
                'qty'          => $qtyByProduct[$name] ?? 0,
                'currentCost'  => $current ? (float) $current['cost_price'] : null,
                'currentLotId' => $current['id'] ?? null,
                'lotsCount'    => count($lots),
                'lots'         => $lots,
            ];
        }
        usort($items, static function ($a, $b) { return strnatcasecmp($a['name'], $b['name']); });

        // Suivi quantité par lot : allocation + écarts stock (vol/casse)
        // calculés UNE seule fois pour tous les produits ayant au moins un
        // lot suivi (CostLotStock::ecarts). Chaque item reçoit sa ligne de
        // suivi (« stockData ») ; les produits absents de la map n'ont
        // aucun lot suivi — la vue affiche « — ». La map complète part à
        // la vue pour l'encart d'alerte « Écarts stock / coûts ».
        $costStock = CostLotStock::ecarts();
        foreach ($items as &$it) {
            $it['stockData'] = $costStock[$it['name']] ?? null;
        }
        unset($it);

        // Doublons : produits distincts en apparence mais identiques une fois
        // normalisés (« redbull Peach » vs « Redbull Peach »).
        $infoByName = [];
        foreach ($items as $it) {
            $infoByName[$it['name']] = $it;
        }
        $dupes = [];
        foreach (AliasSuggester::groupDuplicates(array_keys($infoByName)) as $members) {
            $enriched = [];
            foreach ($members as $m) {
                $enriched[] = [
                    'name'    => $m,
                    'qty'     => (int) ($infoByName[$m]['qty'] ?? 0),
                    'lots'    => (int) ($infoByName[$m]['lotsCount'] ?? 0),
                    'current' => ($infoByName[$m]['currentCost'] ?? null) !== null,
                ];
            }
            // Survivant suggéré : celui qui a un lot en cours, sinon le plus vendu.
            usort($enriched, static function ($a, $b) {
                return [$b['current'], $b['qty']] <=> [$a['current'], $a['qty']];
            });
            $dupes[] = ['suggested' => $enriched[0]['name'], 'members' => $enriched];
        }

        // Édition d'un lot existant (?edit=ID) : le formulaire latéral se
        // pré-remplit et bascule en mode « mise à jour ».
        $editLot = null;
        $editId = trim((string) ($_GET['edit'] ?? ''));
        if ($editId !== '') {
            foreach ($costs as $c) {
                if ((string) ($c['id'] ?? '') === $editId) {
                    $editLot = $c;
                    break;
                }
            }
        }

        $this->renderAdmin('admin/compta/costs', [
            'title'       => 'Coûts de revient',
            'user'        => $user,
            'costs'       => $costs,
            'items'       => $items,
            'costStock'   => $costStock,
            'categories'  => array_values(array_unique(array_filter($catByProduct))),
            'productKeys' => $keys,
            'dupes'       => $dupes,
            'editLot'     => $editLot,
            'form'        => [
                'product_key'   => (string) ($editLot['product_key'] ?? ($_GET['product_key'] ?? '')),
                'cost_price'    => $editLot !== null ? number_format((float) $editLot['cost_price'], 3, ',', '') : '',
                'valid_from'    => $editLot !== null ? substr((string) $editLot['valid_from'], 0, 10) : date('Y-m-d'),
                'supplier'      => $editLot !== null ? (string) ($editLot['supplier'] ?? '') : '',
                // Quantité achetée : '' si absente/null (pas de suivi).
                'qty_purchased' => ($editLot !== null && $editLot['qty_purchased'] !== null)
                    ? (string) (int) $editLot['qty_purchased']
                    : '',
                'notes'         => '',
            ],
        ]);
    }

    public function saveCost(): void
    {
        $user = $this->guardSystemOrPage('costs');

        $data = $_POST;
        $data['cost_price'] = parseFrenchFloat((string) ($data['cost_price'] ?? '0'));
        $data['qty_purchased'] = self::qtyPurchasedFromPost($data['qty_purchased'] ?? null);

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

    /**
     * Quantité achetée saisie dans un POST (champ facultatif) : absent,
     * vide ou invalide → null (lot non suivi, capacité infinie) ; sinon
     * entier ≥ 0. Une valeur invalide (texte, négatif, décimal) retombe
     * sur « pas de suivi » plutôt que de bloquer l'enregistrement du lot.
     *
     * @param mixed $raw Valeur brute du POST (string|int|null|array).
     */
    private static function qtyPurchasedFromPost(mixed $raw): ?int
    {
        if ($raw === null || is_array($raw)) {
            return null;
        }

        $v = trim((string) $raw);
        if ($v === '') {
            return null;
        }

        $qty = filter_var($v, FILTER_VALIDATE_INT);

        return ($qty === false || $qty < 0) ? null : $qty;
    }

    /**
     * Ajout en lot : un prix par produit en un seul POST (après une
     * course, par exemple). valid_from et fournisseur sont communs ;
     * les lignes vides sont ignorées, un même produit répété ne crée
     * qu'un seul lot (premier prix retenu). La règle « as-of » des
     * lots (clôture du lot précédent) est appliquée par ProductCost.
     */
    public function saveCostsBulk(): void
    {
        $this->guardSystemOrPage('costs');

        $validFrom = trim((string) ($_POST['valid_from'] ?? ''));
        if ($validFrom === '') {
            $validFrom = date('Y-m-d');
        }
        $supplier = trim((string) ($_POST['supplier'] ?? ''));

        $keys = is_array($_POST['product_key'] ?? null) ? $_POST['product_key'] : [];
        $prices = is_array($_POST['cost_price'] ?? null) ? $_POST['cost_price'] : [];
        // Quantités facultatives alignées sur product_key[] (même index).
        $qtys = is_array($_POST['qty_purchased'] ?? null) ? $_POST['qty_purchased'] : [];

        $created = 0;
        $ignored = 0;
        $seen = [];
        $count = max(count($keys), count($prices));

        for ($i = 0; $i < $count; $i++) {
            $key = trim((string) ($keys[$i] ?? ''));
            $priceRaw = trim((string) ($prices[$i] ?? ''));

            // Ligne totalement vide (jamais remplie) : ignorée sans bruit.
            if ($key === '' && $priceRaw === '') {
                continue;
            }

            $price = parseFrenchFloat($priceRaw);
            if ($key === '' || $price <= 0.0 || isset($seen[$key])) {
                $ignored++;
                continue;
            }
            $seen[$key] = true;

            if (ProductCost::create([
                'product_key'   => $key,
                'cost_price'    => $price,
                'valid_from'    => $validFrom,
                'supplier'      => $supplier,
                'qty_purchased' => self::qtyPurchasedFromPost($qtys[$i] ?? null),
            ]) !== '') {
                $created++;
            } else {
                $ignored++;
            }
        }

        if ($created === 0) {
            $this->setFlash('error', 'Aucun lot créé — produit et coût (> 0) requis.');
            redirect(url('/admin/compta/couts'));
        }

        // Audit agrégé unique pour toute la grille.
        $this->audit('compta.cost.create_bulk', 'product_cost', null, [
            'lots'       => $created,
            'ignored'    => $ignored,
            'valid_from' => $validFrom,
            'supplier'   => $supplier,
        ]);

        $flash = sprintf('%d lot%s de coût créé%s.', $created, $created > 1 ? 's' : '', $created > 1 ? 's' : '');
        if ($ignored > 0) {
            $flash .= sprintf(' %d ligne(s) ignorée(s) (produit ou coût manquant).', $ignored);
        }
        $this->setFlash('success', $flash);
        redirect(url('/admin/compta/couts'));
    }

    /**
     * Clôture un lot de coût. Action de la page « Coûts de revient » :
     * groupe « Système » ou attribution individuelle, comme costs() et
     * updateCost().
     */
    public function closeCost(string $id): void
    {
        $this->guardSystemOrPage('costs');

        ProductCost::close($id);
        $this->audit('compta.cost.close', 'product_cost', $id);
        $this->setFlash('success', 'Lot clôturé.');
        redirect(url('/admin/compta/couts'));
    }

    /**
     * Supprime un lot de coût. Action de la page « Coûts de revient » :
     * groupe « Système » ou attribution individuelle, comme costs() et
     * updateCost().
     */
    public function deleteCost(string $id): void
    {
        $this->guardSystemOrPage('costs');

        ProductCost::delete($id);
        $this->audit('compta.cost.delete', 'product_cost', $id);
        $this->setFlash('success', 'Lot supprimé.');
        redirect(url('/admin/compta/couts'));
    }

    public function updateCost(string $id): void
    {
        $this->guardSystemOrPage('costs');

        $data = $_POST;
        $data['cost_price'] = parseFrenchFloat((string) ($data['cost_price'] ?? '0'));

        // Quantité achetée : mise à jour seulement si le champ est présent
        // dans le POST (absent = quantité du lot intacte, compat anciens
        // appels) ; '' = arrêter le suivi (NULL accepté par ProductCost).
        if (array_key_exists('qty_purchased', $_POST)) {
            $data['qty_purchased'] = self::qtyPurchasedFromPost($_POST['qty_purchased'] ?? null);
        }

        if (ProductCost::update($id, $data)) {
            $this->audit('compta.cost.update', 'product_cost', $id, [
                'cost_price'    => $data['cost_price'],
                'qty_purchased' => $data['qty_purchased'] ?? null,
            ]);
            $this->setFlash('success', 'Lot mis à jour.');
        } else {
            $this->setFlash('error', 'Lot introuvable ou date de début invalide.');
        }

        redirect(url('/admin/compta/couts'));
    }

    /**
     * Fusionne un groupe de produits dupliqués vers un survivant choisi.
     *
     * Revalide côté serveur que le survivant fait bien partie d'un groupe de
     * doublons (même clé normalisée), puis délègue chaque paire à
     * ProductKeyMerge::merge() — un seul point d'assemblage qui déplace TOUT
     * de façon transactionnelle : ventes, lots de coûts, achats, pertes,
     * comptages, stocks, packs, lignes tablette, drapeaux et alias (mêmes
     * opérations absorbInto/consolidateFor/ensurePointer qu'avant), et
     * invalide le cache de la carte publique. La fusion partielle d'hier
     * (ventes + coûts + alias seulement) laissait achats, comptages et
     * stocks sous les anciennes clés.
     */
    public function mergeProducts(): void
    {
        $this->guardSystemOrPage('costs');

        $keep = trim((string) ($_POST['keep'] ?? ''));
        if ($keep === '') {
            $this->setFlash('error', 'Produit à conserver manquant.');
            redirect(url('/admin/compta/couts'));
        }

        // Recalcule les groupes sur données fraîches (ne jamais se fier au
        // formulaire affiché, potentiellement périmé).
        $names = array_unique(array_merge(
            Sale::distinctProducts(),
            array_column(ProductCost::all(), 'product_key')
        ));
        $group = null;
        foreach (AliasSuggester::groupDuplicates(array_map('strval', $names)) as $members) {
            if (in_array($keep, $members, true)) {
                $group = $members;
                break;
            }
        }

        if ($group === null) {
            $this->setFlash('error', sprintf('« %s » ne fait partie d\'aucun groupe de doublons.', $keep));
            redirect(url('/admin/compta/couts'));
        }

        $others = array_values(array_filter($group, static fn ($m): bool => $m !== $keep));
        if ($others === []) {
            $this->setFlash('success', 'Rien à fusionner : le groupe ne contient qu\'un produit.');
            redirect(url('/admin/compta/couts'));
        }

        // Délégation complète à ProductKeyMerge::merge : la méthode gère sa
        // propre transaction (pas de rollback manuel ici) et invalide le
        // cache de la carte publique après chaque fusion.
        $totals = [
            'sales'            => 0,
            'costs'            => 0,
            'purchases'        => 0,
            'losses'           => 0,
            'counts'           => 0,
            'stocks'           => 0,
            'aliases'          => 0,
            'aliases_absorbed' => 0,
            'aliases_deduped'  => 0,
        ];

        try {
            foreach ($others as $old) {
                $moved = ProductKeyMerge::merge($old, $keep, Auth::id());
                foreach ($totals as $key => $n) {
                    $totals[$key] = $n + (int) ($moved[$key] ?? 0);
                }
            }
        } catch (\Throwable $e) {
            Logger::error('compta: fusion de produits échouée', [
                'keep' => $keep,
                'old'  => $old ?? null,
                'msg'  => $e->getMessage(),
            ]);
            $this->audit('compta.product.merge_failed', 'product', $keep, ['error' => $e->getMessage()]);
            // Message générique affiché : le détail de l'erreur reste dans
            // l'audit et le log applicatif.
            $this->setFlash('error', 'Fusion annulée : une erreur inattendue est survenue. L\'incident a été journalisé.');
            redirect(url('/admin/compta/couts'));
        }

        $this->audit('compta.product.merge', 'product', $keep, array_merge([
            'merged' => $others,
        ], $totals));
        $this->setFlash(
            'success',
            sprintf(
                'Fusion effectuée vers « %s » : %d vente(s), %d lot(s) de coût, %d achat(s), %d comptage(s), %d ligne(s) de stock, %d alias re-groupé(s).',
                $keep,
                $totals['sales'],
                $totals['costs'],
                $totals['purchases'],
                $totals['counts'],
                $totals['stocks'],
                $totals['aliases']
            )
        );
        redirect(url('/admin/compta/couts'));
    }

    // -----------------------------------------------------------------
    //  Aliases (mapping libellés)
    // -----------------------------------------------------------------

    public function aliases(): void
    {
        $user = $this->guardCompta();

        $this->renderAdmin('admin/compta/aliases', [
            'title'      => 'Mapping des libellés',
            'user'       => $user,
            'aliases'    => ProductAlias::allLabels(),
            'unmapped'   => Sale::unmappedDescriptions(),
            'categories' => self::aliasCategories(),
            'known_keys' => self::knownProductKeys(),
        ]);
    }

    /**
     * Fusionne deux produits clé depuis la page Alias : tout l'historique
     * de la clé source (ventes, achats, pertes, alias, comptages, coûts,
     * stocks, drapeaux, promotions, fiches carte…) est rattaché à la clé
     * cible conservée (ProductKeyMerge::merge, transactionnel).
     *
     * La saisie est libre : on peut fusionner une clé non encore connue
     * (ex. une variante repérée dans un rapport) vers une clé existante.
     */
    public function mergeAliasProducts(): void
    {
        $this->guardCompta();

        $from = trim((string) ($_POST['from'] ?? ''));
        $to = trim((string) ($_POST['to'] ?? ''));

        if ($from === '' || $to === '') {
            $this->setFlash('error', 'Produit à absorber et produit à conserver requis.');
            redirect(url('/admin/compta/aliases'));
        }
        if ($from === $to) {
            $this->setFlash('error', 'Les deux produits sont identiques : rien à fusionner.');
            redirect(url('/admin/compta/aliases'));
        }
        if (mb_strlen($from) > 255 || mb_strlen($to) > 255) {
            $this->setFlash('error', 'Les noms de produits sont limités à 255 caractères.');
            redirect(url('/admin/compta/aliases'));
        }

        try {
            $moved = ProductKeyMerge::merge($from, $to, Auth::id());
        } catch (\InvalidArgumentException $e) {
            // Refus de validation : motif réel dans le log, message
            // générique affiché (les garde-fous du formulaire couvrent
            // déjà les cas courants).
            Logger::warning('aliases: fusion refusée', [
                'from' => $from,
                'to'   => $to,
                'msg'  => $e->getMessage(),
            ]);
            $this->setFlash('error', 'Fusion refusée : la demande est invalide.');
            redirect(url('/admin/compta/aliases'));
        } catch (\Throwable $e) {
            Logger::error('aliases: fusion impossible', [
                'from' => $from,
                'to'   => $to,
                'msg'  => $e->getMessage(),
            ]);
            $this->setFlash('error', 'Fusion impossible : une erreur inattendue est survenue. L\'incident a été journalisé.');
            redirect(url('/admin/compta/aliases'));
        }

        $this->audit('compta.aliases.merge', 'product_key', $to, [
            'from'   => $from,
            'to'     => $to,
            'tables' => $moved,
        ]);

        $labels = [
            'purchases'      => 'achat(s)',
            'losses'         => 'perte(s)',
            'sales'          => 'vente(s)',
            'aliases'        => 'alias',
            'aliases_absorbed' => 'alias obsolète absorbé',
            'aliases_deduped' => 'doublon(s) d\'alias consolidé(s)',
            'counts'         => 'comptage(s)',
            'stocks'         => 'stock additionné',
            'discontinued'   => 'drapeau « plus en vente »',
            'costs'          => 'lots de coûts',
            'promotions'     => 'promotion(s)',
            'tablet_lines'   => 'ligne(s) de relevé tablette',
            'infinite'       => 'drapeau « stock infini »',
            'zero_since'     => 'suivi « à zéro depuis »',
            'packs'          => 'taille de pack',
            'checks'         => 'case(s) de liste de courses',
            'scan_labels'    => 'libellé(s) de scan purgé(s)',
            'order_items'    => 'ligne(s) de commande caisse',
            'fiches'         => 'fiche(s) carte',
        ];
        $parts = [];
        foreach ($moved as $table => $n) {
            if ($n > 0) {
                $parts[] = $n . ' ' . ($labels[$table] ?? $table);
            }
        }

        $this->setFlash(
            'success',
            'Produits fusionnés : « ' . $from . ' » → « ' . $to . ' » ('
            . ($parts === [] ? 'aucune donnée trouvée sur la clé source' : implode(', ', $parts))
            . ').'
        );
        redirect(url('/admin/compta/aliases'));
    }

    /**
     * Clés connues pour le datalist de fusion : noms des fiches carte
     * (products.name), product_key des alias et produits vendus
     * (SumUp). Trié, dédupliqué, sans chaîne vide.
     *
     * @return list<string>
     */
    private static function knownProductKeys(): array
    {
        $all = array_unique(array_merge(
            array_map('strval', array_column(Product::allForAdmin(), 'name')),
            array_map('strval', array_column(ProductAlias::all(), 'product_key')),
            Sale::distinctProducts()
        ), SORT_STRING);

        $out = [];
        foreach ($all as $k) {
            $key = trim($k);
            if ($key !== '') {
                $out[] = $key;
            }
        }
        sort($out, SORT_STRING);

        return $out;
    }

    /**
     * Page dédiée « Ajouter une appellation » : le formulaire seul,
     * pour saisir vite un lien libellé facture/CSV -> nom court
     * (ex. « MONSTER PIPELINE PUNCH » -> « Monster rose »). La liste
     * et l'édition restent dans la page Mapping des libellés.
     *
     * Pré-remplissage : libellés de scans non nommés (file
     * ScanLabel::pending(), règle affinée) PLUS les fiches carte au
     * nom de facture (« OASIS TROPICAL SLIM BTE 33CL »…) — pour ces
     * dernières, l'appellation existante (alias) pré-remplit le nom
     * court quand elle existe déjà.
     */
    public function aliasNew(): void
    {
        $user = $this->guardCompta();

        $this->renderAdmin('admin/compta/alias_new', [
            'title'        => 'Ajouter une appellation',
            'user'         => $user,
            'categories'   => self::aliasCategories(),
            'products'     => Sale::distinctProducts(),
            'pendingRows'  => self::pendingAliasRows(),
            'appellations' => ProductAlias::appellations(),
        ]);
    }

    /**
     * Lignes de pré-remplissage du formulaire « Nouvelles appellations »
     * : union dédupliquée (ScanLabel::mergePendingSources) de la file
     * des libellés scannés (hint 'scan') et des fiches carte dont le
     * nom ressemble à un libellé de facture (hint 'fiche'). Silencieux
     * en cas de panne de base : le formulaire s'affiche avec ses lignes
     * vides.
     *
     * Pour une fiche : `suggested` = produit cible de l'alias existant
     * sur ce nom (exact puis normalisé), et la fiche est EXCLUE si son
     * alias vise déjà la fiche elle-même (rien à faire).
     *
     * @return list<array{label:string, occurrences:int, hint:string, suggested:string, category:string}>
     */
    private static function pendingAliasRows(): array
    {
        $scan = [];
        foreach (ScanLabel::pending() as $row) {
            $label = (string) $row['label'];
            [$key, $cat] = self::aliasSuggestionFor($label);
            $scan[] = [
                'label'       => $label,
                'occurrences' => (int) ($row['occurrences'] ?? 0),
                'suggested'   => $key,
                'category'    => $cat,
            ];
        }

        $fiches = [];
        try {
            foreach (Product::allForAdmin() as $fiche) {
                $name = trim((string) ($fiche['name'] ?? ''));
                if ($name === '' || !ScanCatalogMatcher::looksLikeInvoiceLabel($name)) {
                    continue;
                }
                [$key, $cat] = self::aliasSuggestionFor($name);
                if ($key !== '' && ScanCatalogMatcher::sameKey($key, $name)) {
                    continue; // l'alias vise la fiche elle-même : rien à faire
                }
                $fiches[] = [
                    'label'       => $name,
                    'occurrences' => 0,
                    'suggested'   => $key,
                    'category'    => $cat,
                ];
            }
        } catch (\Throwable) {
            $fiches = [];
        }

        return ScanLabel::mergePendingSources($scan, $fiches);
    }

    /**
     * Appellation existante pour un libellé : [product_key, category]
     * de l'alias (recherche exacte par libellé brut, puis variante
     * d'écriture comparée en clé normalisée). ['',''] si aucun alias.
     *
     * @return array{0:string, 1:string}
     */
    private static function aliasSuggestionFor(string $label): array
    {
        try {
            $found = ProductAlias::findByRaw($label);
            if ($found !== null) {
                return [(string) ($found['product_key'] ?? ''), (string) ($found['category'] ?? '')];
            }
            foreach (ProductAlias::all() as $alias) {
                if (ScanCatalogMatcher::sameKey((string) ($alias['raw_description'] ?? ''), $label)) {
                    return [(string) ($alias['product_key'] ?? ''), (string) ($alias['category'] ?? '')];
                }
            }
        } catch (\Throwable) {
            // Alias illisibles : pas de suggestion, ligne saisissable.
        }

        return ['', ''];
    }

    /**
     * Fusion AUTOMATIQUE d'une fiche carte au nom long dans le produit
     * court : déclenchée quand une fiche `products` porte exactement
     * le libellé de l'appellation (même clé normalisée) ET que le nom
     * court visé est différent — le même mécanisme que la carte
     * « Fusionner deux produits » (ProductKeyMerge::merge) : achats,
     * pertes, ventes, coûts, stock et fiche source absorbés, sans
     * perte. Jamais bloquant : en cas d'échec SQL, journalise et
     * laisse la suite des lignes se traiter (flash d'avertissement
     * partiel côté appelant).
     *
     * @return array{0:int, 1:bool} [fiches fusionnées, échec à signaler]
     */
    private function mergeLongFiche(string $raw, string $productKey): array
    {
        $raw = trim($raw);
        $productKey = trim($productKey);
        if ($raw === '' || $productKey === '' || !ProductKeyMerge::canMerge($raw, $productKey)) {
            return [0, false]; // alias visant la fiche elle-même : exclu
        }

        try {
            foreach (Product::allForAdmin() as $fiche) {
                if (StockPublic::normalizeKey((string) ($fiche['name'] ?? '')) === StockPublic::normalizeKey($raw)) {
                    ProductKeyMerge::merge($raw, $productKey, Auth::id());

                    return [1, false];
                }
            }
        } catch (\Throwable $e) {
            Logger::warning('alias: fusion automatique de la fiche au nom long impossible', [
                'raw' => $raw,
                'key' => $productKey,
                'msg' => $e->getMessage(),
            ]);

            return [0, true];
        }

        return [0, false]; // aucune fiche au nom long : rien à fusionner
    }

    /**
     * Compte rendu des fusions réussies, à AJOUTER au flash de succès
     * de l'enregistrement ('' si rien fusionné).
     */
    private static function mergedFlashSuffix(int $merged): string
    {
        return $merged > 0
            ? ' ' . sprintf('%d fiche(s) au nom long fusionnée(s) dans le produit court.', $merged)
            : '';
    }

    /**
     * Flash d'avertissement quand une auto-fusion a échoué (appelant
     * enchaîné malgré tout) : les appellations restent enregistrées,
     * seule l'absorption de la fiche reste à refaire.
     */
    private function flashMergeFailure(): void
    {
        $this->setFlash(
            'warning',
            'Fusion automatique d\'une fiche au nom long impossible (voir journal) — l\'appellation est enregistrée ; réessaie depuis la carte « Fusionner deux produits ».'
        );
    }

    /**
     * Catégories suggérées des alias : d'abord les catégories
     * existantes de la carte (page Catégories de la cafétéria), puis
     * « Événement », puis les valeurs déjà rencontrées dans les alias
     * et les ventes pour rester compatibles avec l'historique.
     *
     * @return list<string>
     */
    private static function aliasCategories(): array
    {
        $categories = [];
        foreach (ProductCategory::allForAdmin() as $cat) {
            $name = trim((string) ($cat['name'] ?? ''));
            if ($name !== '') {
                $categories[] = $name;
            }
        }

        return array_values(array_unique(array_merge(
            $categories,
            ['Événement'],
            ProductAlias::distinctCategories(),
            Sale::distinctCategories()
        )));
    }

    public function saveAlias(): void
    {
        $this->guardCompta();

        $data = $_POST;
        if (ProductAlias::save($data) === '') {
            $this->setFlash('error', 'Libellé et produit canonique requis.');
        } else {
            $raw = (string) ($data['raw_description'] ?? '');
            $productKey = (string) ($data['product_key'] ?? '');
            // Synchronisation : la catégorie choisie est reportée sur les
            // ventes déjà enregistrées portant ce libellé.
            $categorized = Sale::applyAliasCategory(
                $raw,
                isset($data['category']) ? (string) $data['category'] : null,
                $productKey
            );
            // La catégorie choisie doit aussi exister dans la carte (page
            // Catégories de la cafétéria) : création silencieuse si absente.
            $cat = trim((string) ($data['category'] ?? ''));
            if ($cat !== '') {
                ProductCategory::ensure($cat, Auth::id());
            }
            // Nettoyage automatique : une fiche carte au nom du libellé
            // (« OASIS TROPICAL SLIM BTE 33CL ») est fusionnée dans le
            // produit court (« Oasis Tropical ») — achats, stock, coûts
            // et fiche absorbés, sans perte.
            [$merged, $mergeFailed] = $this->mergeLongFiche($raw, $productKey);
            $this->audit('compta.alias.save', 'product_alias', null, [
                'raw'           => $data['raw_description'] ?? null,
                'sales_updated' => $categorized,
                'merged_fiches' => $merged,
            ]);
            // File « à classer » : le libellé vient d'être résolu.
            ScanLabel::deleteByLabel($raw);
            $this->setFlash(
                'success',
                'Libellé rattaché au produit.'
                . ($categorized > 0 ? sprintf(' %d vente(s) catégorisée(s).', $categorized) : '')
                . self::mergedFlashSuffix($merged)
            );
            if ($mergeFailed) {
                $this->flashMergeFailure();
            }
        }

        // Page dédiée : retour au formulaire (ajout en série), sinon à
        // la liste du Mapping des libellés (comportement historique).
        $back = trim((string) ($_POST['stay'] ?? '')) === '1'
            ? '/admin/compta/aliases/new'
            : '/admin/compta/aliases';
        redirect(url($back));
    }

    /**
     * Enregistrement en série de la page « Ajouter une appellation » :
     * grille multi-lignes (raw_description[], product_key[],
     * category[] en parallèle). Les lignes incomplètes sont ignorées,
     * les autres suivent le même chemin que saveAlias() (catégorie
     * propagée aux ventes, catégorie créée dans la carte, purge de la
     * file des libellés scans) PLUS la fusion automatique de la fiche
     * carte au nom long dans le produit court : enregistrer
     * l'appellation d'un nom long absorbe ses achats/stock/coûts/fiche
     * (ProductKeyMerge::merge, comme la carte « Fusionner ») — un
     * échec est journalisé et n'interrompt pas les lignes suivantes
     * (flash d'avertissement partiel).
     */
    public function saveAliasBulk(): void
    {
        $this->guardCompta();

        $raws = $_POST['raw_description'] ?? [];
        $keys = $_POST['product_key'] ?? [];
        $cats = $_POST['category'] ?? [];
        if (!is_array($raws) || !is_array($keys)) {
            $raws = [];
            $keys = [];
        }
        if (!is_array($cats)) {
            $cats = [];
        }

        $done = 0;
        $merged = 0;
        $mergeFailed = false;
        foreach ($raws as $i => $raw) {
            $raw = trim((string) $raw);
            $productKey = trim((string) ($keys[$i] ?? ''));
            $category = trim((string) ($cats[$i] ?? ''));
            if ($raw === '' || $productKey === '') {
                continue; // ligne vide ou incomplète
            }

            ProductAlias::save([
                'raw_description' => $raw,
                'product_key'     => $productKey,
                'category'        => $category,
                'kind'            => 'appellation',
            ]);
            Sale::applyAliasCategory($raw, $category !== '' ? $category : null, $productKey);
            if ($category !== '') {
                ProductCategory::ensure($category, Auth::id());
            }
            ScanLabel::deleteByLabel($raw);
            // Nettoyage automatique de la fiche au nom long (voir
            // mergeLongFiche) : absorbe achats/stock/coûts/fiche dans
            // le produit court, sans jamais bloquer la série.
            [$lineMerged, $lineFailed] = $this->mergeLongFiche($raw, $productKey);
            $merged += $lineMerged;
            $mergeFailed = $mergeFailed || $lineFailed;
            $done++;
        }

        $this->audit('compta.alias.bulk', 'product_alias', null, [
            'count'         => $done,
            'merged_fiches' => $merged,
        ]);
        $this->setFlash(
            $done > 0 ? 'success' : 'error',
            $done > 0
                ? sprintf('%d appellation(s) enregistrée(s).', $done) . self::mergedFlashSuffix($merged)
                : 'Aucune ligne complète : libellé et nom court sont requis.'
        );
        if ($mergeFailed) {
            $this->flashMergeFailure();
        }

        redirect(url('/admin/compta/aliases/new'));
    }

    /**
     * Enregistrement en grille des catégories (bouton unique du tableau
     * « Alias existants ») : met à jour la catégorie de chaque alias
     * existant, sans toucher au produit canonique ni créer d'alias.
     */
    public function aliasesBulk(): void
    {
        $this->guardCompta();

        $raws = $_POST['bulk_raw'] ?? [];
        $cats = $_POST['bulk_cat'] ?? [];
        if (!is_array($raws) || !is_array($cats)) {
            $this->setFlash('error', 'Données invalides.');
            redirect(url('/admin/compta/aliases'));
        }

        $updated = 0;
        $categorized = 0;
        $n = max(count($raws), count($cats));
        for ($i = 0; $i < $n; $i++) {
            $raw = trim((string) ($raws[$i] ?? ''));
            if ($raw === '') {
                continue;
            }

            // Seuls les alias existants sont modifiés (jamais de création ici).
            $existing = ProductAlias::findByRaw($raw);
            if ($existing === null) {
                continue;
            }

            $newCat = trim((string) ($cats[$i] ?? ''));
            $newCat = $newCat !== '' ? $newCat : null;
            if (($existing['category'] ?? null) === $newCat) {
                continue;
            }

            ProductAlias::save([
                'raw_description' => $raw,
                'product_key'     => (string) $existing['product_key'],
                'category'        => $newCat,
            ]);
            // Synchronisation : les ventes portant ce libellé suivent la
            // catégorie choisie (une catégorie vidée ne les modifie pas).
            $categorized += Sale::applyAliasCategory($raw, $newCat, (string) $existing['product_key']);
            // Une catégorie non vide doit aussi exister dans la carte :
            // création silencieuse si absente.
            if ($newCat !== null) {
                ProductCategory::ensure($newCat, Auth::id());
            }
            $updated++;
        }

        if ($updated > 0) {
            $this->audit('compta.alias.bulk', 'product_alias', null, [
                'updated'       => $updated,
                'sales_updated' => $categorized,
            ]);
            $this->setFlash('success', sprintf('%d catégorie(s) mise(s) à jour — %d vente(s) synchronisée(s).', $updated, $categorized));
        } else {
            $this->setFlash('info', 'Aucun changement à enregistrer.');
        }

        redirect(url('/admin/compta/aliases'));
    }

    public function deleteAlias(string $id): void
    {
        $this->guardCompta();

        ProductAlias::deleteRow($id);
        $this->audit('compta.alias.delete', 'product_alias', $id);
        $this->setFlash('success', 'Alias supprimé.');

        // Suppression depuis la page « Ajouter une appellation » :
        // on y revient (la liste du bas vit sur cette page).
        if (($_POST['back'] ?? '') === 'new') {
            redirect(url('/admin/compta/aliases/new'));
        }
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
            // Toutes les clés produits existantes : datalist du champ
            // « Produit canonique » — choisir une cible EXISTANTE évite
            // les fautes de frappe, première cause de doublons.
            'productKeys' => ProductKeyMerge::allKeys(),
        ]);
    }

    /**
     * Applique le mapping validé (POST) : crée/met à jour chaque alias puis
     * ré-applique le mapping à toutes les ventes existantes. Les clés
     * produits déplacées par le changement de cible d'un alias sont
     * fusionnées (ProductKeyMerge) : coûts, stocks et historiques suivent.
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
            // Préserve la catégorie existante de l'alias (le save est un
            // upsert : sans ce champ, la catégorie serait écrasée à NULL).
            $prev = ProductAlias::findByRaw($raw);
            $category = $prev !== null ? ($prev['category'] ?? null) : null;

            if (ProductAlias::save(['raw_description' => $raw, 'product_key' => $key, 'category' => $category]) !== '') {
                $applied++;
                $details[] = $raw . ' -> ' . $key;
            }
        }

        $result = Sale::reapplyAliases();
        $updated = (int) ($result['updated'] ?? 0);
        $repointed = is_array($result['repointed'] ?? null) ? $result['repointed'] : [];

        $this->audit('compta.alias.apply', 'product_alias', null, [
            'applied'      => $applied,
            'sales_updated' => $updated,
            'keys_repointed' => $repointed,
        ]);

        $flash = sprintf('%d alias enregistré(s) — %d vente(s) mise(s) à jour.', $applied, $updated);
        if ($repointed !== []) {
            $pairs = [];
            foreach ($repointed as $old => $new) {
                $pairs[] = $old . ' → ' . $new;
            }
            $flash .= sprintf(
                ' %d clé(s) renommée(s) — coûts, stocks et historiques suivis (%s).',
                count($repointed),
                implode(', ', $pairs)
            );
        }
        $this->setFlash('success', $flash);
        redirect(url('/admin/compta/aliases'));
    }

    // -----------------------------------------------------------------
    //  Réapprovisionnement
    // -----------------------------------------------------------------

    public function reorder(): void
    {
        $user = $this->guardCompta();

        $this->renderReorderPage(false, $user, null, true);
    }

    /**
     * Accès « kiosque » au Réapprovisionnement par lien secret, SANS
     * connexion : destiné au raccourci écran d'accueil du téléphone.
     * Lecture seule ; jeton révocable via regenerateKioskToken().
     */
    public function kioskReorder(string $token): void
    {
        if (!KioskPages::tokenAllows($token, 'reappro')) {
            http_response_code(403);
            echo '<h1>Erreur 403 — Lien invalide ou révoqué.</h1>';

            return;
        }

        // Jeton « membres » = bénévole : aucun chiffre financier. Jeton
        // « admin » (bureau sur téléphone) : tout est visible. Kiosque
        // personnalisé : la variante (complète ou allégée) est celle
        // cochée par l'admin pour la tuile « reappro » / « reappro-lite ».
        $this->renderReorderPage(true, null, $token, $this->kioskTokenIsAdmin($token, 'reappro'));
    }

    /**
     * Régénère le jeton du lien kiosque : l'ancien lien cesse de fonctionner.
     */
    public function regenerateKioskToken(): void
    {
        $this->guardCompta();

        Setting::set('reappro_kiosk_token', bin2hex(random_bytes(20)));
        $this->audit('compta.reappro.kiosk', 'setting', 'reappro_kiosk_token', ['regenerated' => true]);
        $this->setFlash('success', "Lien téléphone régénéré — l'ancien lien ne fonctionne plus.");

        redirect(url('/admin/compta/reappro'));
    }

    // -----------------------------------------------------------------
    //  Liste de courses (Réappro simplifié)
    // -----------------------------------------------------------------

    /**
     * Liste de courses : uniquement les produits à acheter, avec arrondi
     * aux packs d'achat. Analyse FIXE sur 14 jours (non affichée) ; seule
     * la couverture (« Couvrir pour ») est choisissable.
     *
     * Ouverte au bénévole de caisse (guardComptaOuBenevol) : le flag
     * $showMoney masque TOUT chiffre financier pour les rôles sans le
     * module compta (mission : « le bénévole ne voit AUCUN chiffre
     * financier »).
     */
    public function liste(): void
    {
        $user = $this->guardComptaOuBenevol();

        $this->renderListePage(false, $user, null, Permissions::allows(Auth::role(), Permissions::MODULE_COMPTA));
    }

    /**
     * Accès « kiosque » à la liste de courses par lien secret (même jeton
     * que le Réappro) : lecture seule, sans connexion, sans navigation.
     */
    public function kioskListe(string $token): void
    {
        if (!KioskPages::tokenAllows($token, 'liste')) {
            http_response_code(403);
            echo '<h1>Erreur 403 — Lien invalide ou révoqué.</h1>';

            return;
        }

        // Jeton « membres » = bénévole : aucun chiffre financier. Jeton
        // « admin » (bureau sur téléphone) : tout est visible. Kiosque
        // personnalisé : la variante (complète ou allégée) est celle
        // cochée par l'admin pour la tuile « liste » / « liste-lite ».
        $this->renderListePage(true, null, $token, $this->kioskTokenIsAdmin($token, 'liste'));
    }

    // -----------------------------------------------------------------
    //  Liste de courses : cases cochées partagées (kiosque + admin)
    // -----------------------------------------------------------------

    /**
     * Identité OBLIGATOIRE pour cocher (comme pour tout enregistrement
     * kiosque) : prénom, nom et rôle. Mêmes champs que la pastille profil.
     */
    private function whoStrict(): string
    {
        $p = trim(strip_tags((string) ($_POST['who_prenom'] ?? '')));
        $n = trim(strip_tags((string) ($_POST['who_nom'] ?? '')));
        $a = trim(strip_tags((string) ($_POST['who_alias'] ?? '')));

        if ($p === '' || $n === '' || $a === '') {
            return '';
        }

        return mb_substr($p . ' ' . $n . ' (' . $a . ')', 0, 200);
    }

    /**
     * Variante des pages outils (Réappro/Liste) : pilote le drapeau
     * showMoney. Jetons legacy inchangés — ADMIN : variante complète avec
     * coûts, MEMBRES (bénévole) : variante allégée. Kiosque personnalisé :
     * la variante complète n'est servie que si l'admin a coché la tuile
     * pleine ($pageId, « reappro » ou « liste ») ; la variante lite
     * (« reappro-lite » / « liste-lite ») cochée seule reste sans aucun
     * chiffre financier.
     */
    private function kioskTokenIsAdmin(string $token, string $pageId = 'reappro'): bool
    {
        return KioskPages::tokenIsFullData($token, $pageId);
    }

    /**
     * Hub de retour selon le jeton de la liste : kiosque personnalisé →
     * son propre hub (valable pour les variantes lite comme complètes),
     * admin → hub admin, membres → hub membres. La branche legacy
     * n'atteint kioskTokenIsAdmin qu'avec un jeton legacy, pour lequel
     * l'id de page est sans effet.
     */
    private function listeHubUrl(string $token): string
    {
        try {
            $custom = Kiosque::findByToken(trim($token));
            if ($custom !== null) {
                return url('/kiosque/hub/' . trim($token));
            }
        } catch (\Throwable) {
            // Table kiosques absente (migration non appliquée) : on saute à la logique legacy.
        }

        return $this->kioskTokenIsAdmin(trim($token), 'liste')
            ? url('/kiosque/admin/' . trim($token))
            : url('/kiosque/comptage/' . trim($token));
    }

    private function listeStatePayload(): array
    {
        $checks = [];
        foreach (ShoppingCheck::all() as $key => $c) {
            $checks[$key] = [
                'by' => $c['by'],
                'at' => substr($c['at'], 11, 5),
            ];
        }

        return ['ok' => true, 'checks' => $checks];
    }

    /** État des cases (JSON) — version connectée. */
    public function listeState(): void
    {
        $this->guardComptaOuBenevol();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($this->listeStatePayload());
    }

    /** État des cases (JSON) — version kiosque. */
    public function kioskListeState(string $token): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!KioskPages::tokenAllows($token, 'liste')) {
            http_response_code(403);
            echo '{"ok":false,"error":"lien invalide"}';

            return;
        }
        echo json_encode($this->listeStatePayload());
    }

    /** Cocher / décocher (JSON) — version connectée. */
    public function listeCheck(): void
    {
        $this->guardComptaOuBenevol();
        $this->handleListeCheck();
    }

    /** Cocher / décocher (JSON) — version kiosque. */
    public function kioskListeCheck(string $token): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!KioskPages::tokenAllows($token, 'liste')) {
            http_response_code(403);
            echo '{"ok":false,"error":"lien invalide"}';

            return;
        }
        $this->handleListeCheck();
    }

    private function handleListeCheck(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $key     = trim((string) ($_POST['key'] ?? ''));
        $checked = ($_POST['state'] ?? '1') === '1';
        $who     = $this->whoStrict();

        if ($key === '') {
            http_response_code(422);
            echo '{"ok":false,"error":"produit manquant"}';

            return;
        }
        if ($who === '') {
            http_response_code(422);
            echo '{"ok":false,"error":"identite requise (prenom, nom, role)"}';

            return;
        }

        if ($checked) {
            ShoppingCheck::mark($key, $who);
        } else {
            ShoppingCheck::unmark($key);
        }

        echo json_encode([
            'ok'      => true,
            'key'     => $key,
            'checked' => $checked,
            'by'      => $checked ? $who : '',
            'at'      => date('H:i'),
        ]);
    }

    /**
     * Assemble et rend la liste de courses (connecté OU kiosque).
     *
     * @param array<string,mixed>|null $user Utilisateur connecté (null en kiosque).
     */
    private function renderListePage(bool $kiosk, ?array $user = null, ?string $kioskToken = null, bool $showMoney = true): void
    {
        // Page affichant un lien à jeton (encart kiosque / jeton d'URL) :
        // jamais mémorisable.
        Kiosk::sendNoStore();

        // ---- Demande UNIQUE : le moteur ForecastEngine, via ReapproDemande ----
        // (exigence du 21/10/2026). La liste ne recalculait PLUS la même
        // chose que la carte Réappro Analytics : consommation observée 14 j
        // + stock d'une autre table → deux pages, deux vérités. Désormais
        // demande (prévision 7 j, calibration CA) et stock (théorique
        // inventaire, rapprochement par alias) viennent du MÊME appel —
        // « besoin(N) − stock » seulement, cf. BesoinListe.
        $demande  = ReapproDemande::calculer();
        $produits = is_array($demande['forecast']['produits'] ?? null) ? $demande['forecast']['produits'] : [];

        // Couverture (« Couvrir pour »), défaut 1 semaine. La prévision du
        // moteur couvre 7 jours ouverts : le facteur la met à l'échelle de
        // la couverture choisie (1w = ×1 → besoin == suggestion Réappro).
        $covers = [
            '1w' => ['label' => '1 semaine',  'days' => 7],
            '2w' => ['label' => '2 semaines', 'days' => 14],
            '1m' => ['label' => '1 mois',     'days' => 30],
            '2m' => ['label' => '2 mois',     'days' => 61],
            '3m' => ['label' => '3 mois',     'days' => 91],
        ];
        $coverKey = (string) ($_GET['c'] ?? '1w');
        if (!isset($covers[$coverKey])) {
            $coverKey = '1w';
        }
        $facteur = $covers[$coverKey]['days'] / 7.0;

        // Exclusions partagées avec le Réappro : artefacts SumUp (« Divers »
        // à montant libre), produits « plus en vente » et « stock infini ».
        $parser = new SumUpCsvParser();
        $isArtifact = static fn (string $k): bool => $parser->isCustomAmount($k);
        $discontinued = array_flip(array_map(
            static fn (string $k): string => strtolower(trim($k)),
            ProductDiscontinued::keys()
        ));
        $infinite = array_flip(array_map(
            static fn (string $k): string => strtolower(trim($k)),
            ProductInfinite::keys()
        ));
        $exclu = static function (array $p) use ($isArtifact, $discontinued, $infinite): bool {
            foreach ([(string) $p['label'], (string) $p['key']] as $k) {
                $lk = strtolower(trim($k));
                if ($lk === '' || $isArtifact($k) || isset($discontinued[$lk]) || isset($infinite[$lk])) {
                    return true;
                }
            }

            return false;
        };

        // Packs d'achat (12, 24, 32…) : arrondi au multiple supérieur.
        // Résolution exacte → normalisée → alias sur la clé ET le libellé :
        // les packs sont saisis sous les product_key du Réappro, les lignes
        // moteur portent des libellés de vente parfois différents (casse,
        // accents, alias) — sinon la liste commanderait à l'unité (ex. 5
        // cannettes au lieu d'un pack de 6), régression du 22/10/2026.
        $packs = ProductPack::resolver();

        $items    = []; // à commander (stock connu)
        $aCompter = []; // sans AUCUNE donnée d'inventaire → à compter
        $negatifs = []; // stock théorique négatif → anomalie, recompter
        foreach ($produits as $p) {
            if ($exclu($p)) {
                continue;
            }
            $name = (string) $p['label'];
            $pack = $packs->resolve((string) $p['key'], $name);

            $ligne  = BesoinListe::ligne($p, $facteur, $pack);
            $besoin = $ligne['besoin'];

            if ($ligne['stock'] === null) {
                // Jamais compté, ni achat, ni perte : listé à part — on
                // ne commande pas au doigt mouillé un stock inconnu.
                if ($besoin > 0) {
                    $aCompter[] = ['name' => $name, 'besoin' => $besoin];
                }
                continue;
            }

            if ($ligne['negatif']) {
                $negatifs[] = ['name' => $name, 'stock' => $ligne['stock']];
                // Le produit reste dans la liste : le besoin est calculé
                // SANS le stock négatif (max(0, stock) déduit).
            }

            $toOrder = (int) $ligne['to_order'];
            if ($toOrder <= 0) {
                continue;
            }

            $items[] = [
                'name'       => $name,
                'to_order'   => $toOrder,
                'besoin'     => $besoin,
                'stock'      => $ligne['stock'],
                'prev_week'  => $ligne['prev_semaine'],
                'pack'       => $pack,
                'order_cost' => $p['unitCost'] !== null && $p['unitCost'] > 0.0
                    ? round($toOrder * (float) $p['unitCost'], 2)
                    : null,
                'negatif'    => $ligne['negatif'],
            ];
        }

        // Tri : quantité à commander décroissante (les plus critiques
        // d'abord), puis nom.
        usort($items, static function (array $a, array $b): int {
            if ($a['to_order'] !== $b['to_order']) {
                return $b['to_order'] <=> $a['to_order'];
            }

            return strcasecmp((string) $a['name'], (string) $b['name']);
        });
        usort($aCompter, static fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));

        // Totaux sur les quantités arrondies au pack (= ce qu'on achète).
        $totalUnits  = 0;
        $totalCost   = 0.0;
        $missingCost = 0;
        foreach ($items as $r) {
            $totalUnits += (int) $r['to_order'];
            if ($r['order_cost'] !== null) {
                $totalCost += (float) $r['order_cost'];
            } else {
                $missingCost++;
            }
        }

        // Jeton kiosque (kiosque uniquement) : conservé pour les liens de
        // retour vers le hub d'origine (espace membres OU admin). En mode
        // connecté, le lien « accès téléphone » est masqué et révélé à la
        // demande (partial _kiosk_link.php) : plus aucune URL à jeton
        // n'est assemblée ici.
        $kioskToken = ($kiosk && $kioskToken !== null && trim($kioskToken) !== '')
            ? trim($kioskToken)
            : Kiosk::token();

        $viewData = [
            'title'       => 'Liste de courses',
            'user'        => $user,
            'items'       => $items,
            'covers'      => $covers,
            'coverKey'    => $coverKey,
            'totalUnits'  => $totalUnits,
            'totalCost'   => round($totalCost, 2),
            'missingCost' => $missingCost,
            // Encart « Base de calcul » (identique à la page Réappro) :
            // valeurs factuelles issues du payload ReapproDemande (fw) et
            // de la couverture choisie — jamais recalculées en vue.
            'basis'       => [
                'ventes'      => sprintf('%d derniers jours', (int) ($demande['fw'] ?? 30)),
                'couverture'  => (string) $covers[$coverKey]['label'],
                'source'      => self::BASE_CALCUL_SOURCE,
            ],
            // Faux = masque TOUT chiffre financier (bénévole, jeton membres).
            'showMoney'   => $showMoney,
            // Produits sans aucune donnée d'inventaire : à compter d'abord.
            'aCompter'    => $aCompter,
            // Stocks théoriques négatifs (survente) : anomalies à recompter.
            'negatifs'    => $negatifs,
            'kiosk'       => $kiosk,
            'kioskToken'  => (string) $kioskToken,
            // Retour vers le hub correspondant au jeton (admin ou membres).
            'hubUrl'      => $kiosk ? $this->listeHubUrl((string) $kioskToken) : '',
            // Identité des connected (trace des cases cochées côté admin).
            'adminWho'    => (!$kiosk && $user !== null)
                ? trim(trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? '')) . ' (' . ($user['role'] ?? '') . ')')
                : '',
        ];

        if ($kiosk) {
            $this->renderKiosk('admin/compta/liste', $viewData);

            return;
        }

        $this->renderAdmin('admin/compta/liste', $viewData);
    }

    /**
     * Assemble et rend la page Réapprovisionnement (connecté OU kiosque).
     *
     * @param array<string,mixed>|null $user Utilisateur connecté (null en kiosque).
     */
    private function renderReorderPage(bool $kiosk, ?array $user = null, ?string $kioskToken = null, bool $showMoney = true): void
    {
        // Page affichant le lien kiosque à copier : jamais mémorisable.
        Kiosk::sendNoStore();

        // Lien kiosque : plus jamais assemblé ici en mode connecté — la
        // vue l'affiche masqué et le révèle à la demande (partial
        // _kiosk_link.php + POST /admin/kiosques/reveal audité). Le jeton
        // reste généré paresseusement à la première visite connectée
        // (contrat testé par ComptaConsistencyTest), sans fuiter dans la vue.
        if (!$kiosk) {
            Kiosk::token();
        }

        // Hub de retour selon le jeton entrant (admin ou membres).
        $kioskHubUrl = ($kiosk && $kioskToken !== null && trim($kioskToken) !== '')
            ? $this->listeHubUrl(trim($kioskToken))
            : '';

        // ── Période d'ANALYSE (sur quoi calculer les moyennes) ──────────
        $refOptions = [
            '1d'     => '1 jour',
            'week'   => 'Semaine en cours',
            '14d'    => '14 derniers jours',
            '30d'    => '30 derniers jours',
            'all'    => 'Tout',
            'custom' => 'Personnalisé',
        ];
        // Défaut « 14 derniers jours » : c'est la fenêtre d'analyse du
        // contrat réappro (besoin brut = ceil(ventes / 14 j × horizon),
        // testé par ComptaConsistencyTest::test_reappro_arrondit_au_pack)
        // — la demande moteur de la liste est calibrée pareil. L'option
        // « Semaine en cours » reste choisissable dans refOptions.
        $ref = (string) ($_GET['ref'] ?? '14d');
        if (!array_key_exists($ref, $refOptions)) {
            $ref = '14d';
        }

        $today = date('Y-m-d');
        $fromDay = null;
        $toDay = $today;

        $du = trim((string) ($_GET['du'] ?? ''));
        $au = trim((string) ($_GET['au'] ?? ''));
        $duOk = preg_match('/^\d{4}-\d{2}-\d{2}$/', $du) === 1;
        $auOk = preg_match('/^\d{4}-\d{2}-\d{2}$/', $au) === 1;

        switch ($ref) {
            case '1d':  $fromDay = $today; break;
            case 'week': $fromDay = ComptaCalc::currentWeekWindow()['from']; break;
            case '14d': $fromDay = date('Y-m-d', strtotime('-13 days')); break;
            case '30d': $fromDay = date('Y-m-d', strtotime('-29 days')); break;
            case 'all': $fromDay = Sale::firstSoldDay(); $toDay = $today; break;
            case 'custom':
                if ($duOk) { $fromDay = $du; }
                if ($auOk) { $toDay = $au; }
                if ($fromDay === null || !$auOk) {
                    // Bornes invalides : bascule sur tout l'historique.
                    $ref = 'all';
                    $fromDay = Sale::firstSoldDay();
                    $toDay = $today;
                }
                break;
        }

        // Jours CALENDaires de la période (week-end inclus) : les moyennes
        // de consommation et l'autonomie sont exprimées en 7 j/7.
        $calDays = ($fromDay !== null && $toDay !== null)
            ? ComptaCalc::calendarDaysBetween($fromDay, $toDay)
            : 0;

        // ── Horizon de COUVERTURE (pour quoi commander) ─────────────────
        // Horizons en jours calendaires : le week-end est couvert aussi.
        $periods = [
            '1w' => ['label' => '1 semaine',  'days' => 7],    // 7 j calendaires
            '2w' => ['label' => '2 semaines', 'days' => 14],
            '1m' => ['label' => '1 mois',     'days' => 30],   // ≈ 1 mois calendaire
            '2m' => ['label' => '2 mois',     'days' => 61],
            '3m' => ['label' => '3 mois',     'days' => 91],
        ];
        $periodKey = $_GET['period'] ?? '1w';
        if (!isset($periods[$periodKey])) {
            $periodKey = '1w';
        }
        $targetDays = $periods[$periodKey]['days'];

        $data = $this->reorderData($targetDays, $fromDay, $toDay, $calDays);

        // Encart « Base de calcul » (identique à la liste de courses) :
        // période réellement analysée + horizon de couverture choisis.
        $ventes = (string) $refOptions[$ref];
        if ($fromDay !== null || $toDay !== null) {
            $bornes = [];
            if ($fromDay !== null) {
                $bornes[] = date('d/m/Y', strtotime($fromDay));
            }
            if ($toDay !== null) {
                $bornes[] = date('d/m/Y', strtotime($toDay));
            }
            $ventes .= $bornes !== [] ? ' (' . implode(' → ', $bornes) . ')' : '';
        }
        $basis = [
            'ventes'     => $ventes,
            'couverture' => (string) $periods[$periodKey]['label'],
            'source'     => self::BASE_CALCUL_SOURCE,
        ];

        if ($kiosk) {
            // Layout kiosque : aucune navigation, rien d'autre que la page.
            $this->renderKiosk('admin/compta/reorder', [
                'title'         => 'Réapprovisionnement',
                'user'          => null,
                'rows'          => $data['rows'],
                'alerts'        => $data['alerts'],
                'periods'       => $periods,
                'currentPeriod' => $periodKey,
                'targetDays'    => $targetDays,
                'refOptions'    => $refOptions,
                'currentRef'    => $ref,
                'refFrom'       => $fromDay,
                'refTo'         => $toDay,
                'refCalDays'    => $calDays,
                'du'            => $duOk ? $du : '',
                'au'            => $auOk ? $au : '',
                'kiosk'         => true,
                // Bouton retour flottant : hub du bon espace (admin/membres).
                'kioskHubUrl'   => $kioskHubUrl,
                // Faux = masque TOUT chiffre financier (jeton membres).
                'showMoney'     => $showMoney,
                'basis'         => $basis,
            ]);

            return;
        }

        $this->renderAdmin('admin/compta/reorder', [
            'title'         => 'Réapprovisionnement',
            'user'          => $user,
            'rows'          => $data['rows'],
            'alerts'        => $data['alerts'],
            'periods'       => $periods,
            'currentPeriod' => $periodKey,
            'targetDays'    => $targetDays,
            'refOptions'    => $refOptions,
            'currentRef'    => $ref,
            'refFrom'       => $fromDay,
            'refTo'         => $toDay,
            'refCalDays'    => $calDays,
            'du'            => $duOk ? $du : '',
            'au'            => $auOk ? $au : '',
            'kiosk'         => false,
            'showMoney'     => $showMoney,
            'basis'         => $basis,
        ]);
    }

    /**
     * Enregistre les tailles de pack éditées depuis le tableau Réappro
     * (tableaux parallèles pack_keys[] / pack_sizes[] pour préserver les
     * clés produits contenant espaces/accents).
     */
    public function savePacks(): void
    {
        $this->guardCompta();

        $back = trim((string) ($_POST['back'] ?? ''));
        $to   = url('/admin/compta/reappro' . ($back !== '' ? '?' . $back : ''));

        $keys  = is_array($_POST['pack_keys'] ?? null) ? $_POST['pack_keys'] : [];
        $sizes = is_array($_POST['pack_sizes'] ?? null) ? $_POST['pack_sizes'] : [];
        $userId = (string) (Auth::id() ?? '');
        $saved  = 0;

        foreach ($keys as $i => $rawKey) {
            $key = trim((string) $rawKey);
            if ($key === '') {
                continue;
            }

            $size = (int) trim((string) ($sizes[$i] ?? ''));
            if ($size <= 0) {
                // Vide ou 0 : pas de pack, commande à l'unité.
                ProductPack::remove($key);
                continue;
            }

            ProductPack::set($key, min(9999, $size), $userId);
            $saved++;
        }

        $this->audit('compta.reappro.packs', 'product_packs', null, ['saved' => $saved]);
        $this->setFlash('success', sprintf('Packs enregistrés (%d produit(s)).', $saved));

        redirect($to);
    }

    /**
     * Bascule le drapeau « stock infini » d'un produit (page Réappro).
     *
     * Un produit marqué infini affiche une autonomie « ∞ » et n'est jamais
     * proposé à la commande, même « à compter » (jamais inventorié).
     */
    public function toggleInfiniteStock(): void
    {
        $this->guardCompta();

        $key  = trim((string) ($_POST['product_key'] ?? ''));
        $back = trim((string) ($_POST['back'] ?? ''));
        $to   = url('/admin/compta/reappro' . ($back !== '' ? '?' . $back : ''));

        if ($key === '') {
            $this->setFlash('error', 'Produit manquant.');
            redirect($to);
        }

        $added = ProductInfinite::toggle($key, (string) (Auth::user()['email'] ?? null));
        $this->audit('compta.reappro.infinite', 'product_infinite', $key, ['infinite' => $added]);

        $this->setFlash('success', $added
            ? sprintf('« %s » : stock marqué infini (∞) — plus jamais proposé à la commande.', $key)
            : sprintf('« %s » : marquage stock infini retiré.', $key));

        redirect($to);
    }

    /**
     * Analyse de réapprovisionnement de la page : délégation au point
     * d'assemblage unique Dashboard\Data::analyseReappro() (même code,
     * partagé avec le bloc « À faire » du dashboard et l'accueil /admin).
     */
    private function reorderData(int $targetDays, ?string $fromDay, ?string $toDay, int $calDays): array
    {
        return Data::analyseReappro($targetDays, $fromDay, $toDay, $calDays);
    }

    // -----------------------------------------------------------------
    //  Comptage de caisse
    // -----------------------------------------------------------------

    /**
     * Comptage de caisse — saisie seule, ouverte à tout le bureau (hors élèves).
     *
     * Comptage « à l'aveugle » : le théorique n'est volontairement pas affiché
     * (un comptage honnête ne doit pas pouvoir s'ajuster sur l'attendu).
     * L'écart n'est révélé qu'après enregistrement, via le flash ; l'historique
     * reste réservé au groupe Système → Caisses (Fondateur).
     */
    public function caisse(): void
    {
        $user = $this->guardAdminArea();

        // La page affiche les liens kiosque à copier : jamais mémorisable.
        Kiosk::sendNoStore();

        $this->renderAdmin('admin/compta/caisse', [
            'title' => 'Comptage de caisse',
            'user'  => $user,
        ]);
    }

    /**
     * Enregistre le comptage saisi (même mécanique que Système → Caisses) :
     * écart figé dans cash_counts + mouvement AJUSTEMENT qui réaligne le théorique.
     */
    public function caisseCount(): void
    {
        $user = $this->guardAdminArea();

        $counted = parseFrenchFloat((string) ($_POST['counted'] ?? ''));
        if ($counted < 0) {
            $this->setFlash('error', 'Montant compté invalide.');
            redirect(url('/admin/compta/caisse'));
        }

        $date = datetime_selects_value();
        if (!$date['ok']) {
            $this->setFlash('error', 'Date invalide.');
            redirect(url('/admin/compta/caisse'));
        }

        $label = trim((string) ($_POST['label'] ?? ''));
        $res = CashLedger::recordCount($counted, $label, (string) $user['email'], $date['value']);
        $this->audit('cash.count', 'cash', $res['count_id'], [
            'counted'     => $counted,
            'theoretical' => round($counted - $res['ecart'], 2),
            'ecart'       => $res['ecart'],
            'source'      => 'compta',
        ]);

        if ($res['ecart'] < 0) {
            $this->setFlash('error', sprintf('Manquant de %s constaté — caisse réalignée.', formatPrice(abs($res['ecart']))));
        } elseif ($res['ecart'] > 0) {
            $this->setFlash('success', sprintf('Surplus de %s constaté — caisse réalignée.', formatPrice($res['ecart'])));
        } else {
            $this->setFlash('success', 'Comptage exact : aucun écart.');
        }

        redirect(url('/admin/compta/caisse'));
    }
}
