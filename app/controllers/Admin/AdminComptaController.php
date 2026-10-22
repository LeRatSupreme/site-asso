<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Compta\AliasSuggester;
use App\Core\Compta\CardFees;
use App\Core\Compta\CashLedger;
use App\Core\Compta\ComptaCalc;
use App\Core\Compta\Dashboard\Afaire;
use App\Core\Compta\Dashboard\Cartes;
use App\Core\Compta\Dashboard\Hyper;
use App\Core\Compta\Dashboard\Verdict;
use App\Core\Compta\Kiosk;
use App\Core\Compta\ProductAutoSync;
use App\Core\Compta\ScanCatalogMatcher;
use App\Core\Compta\StockPublic;
use App\Core\Compta\SumUpCsvParser;
use App\Core\Logger;
use App\Models\Analytics;
use App\Models\CashCount;
use App\Models\Expense;
use App\Models\ImportBatch;
use App\Models\InventoryCount;
use App\Models\InventoryTablet;
use App\Models\Product;
use App\Models\ProductAlias;
use App\Models\ProductCategory;
use App\Models\ProductCost;
use App\Models\ProductDiscontinued;
use App\Models\ProductInfinite;
use App\Models\ProductKeyMerge;
use App\Models\ProductPack;
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

    // -----------------------------------------------------------------
    //  Dashboard
    // -----------------------------------------------------------------

    public function dashboard(): void
    {
        $user = $this->guardCompta();

        $period = ComptaCalc::resolvePeriod($_GET['period'] ?? null, $_GET['from'] ?? null, $_GET['to'] ?? null);
        $agg = Sale::aggregatesBetween($period['from'], $period['to']);
        $split = Sale::paymentSplitBetween($period['from'], $period['to']);
        $txCount = Sale::transactionsBetween($period['from'], $period['to']);
        $top = Sale::topProductsBetween($period['from'], $period['to']);
        $byCategory = Sale::byCategoryBetween($period['from'], $period['to']);
        $cardFee = CardFees::estimatedTotal($period['from'], $period['to']);
        $cardNet = round((float) ($split['CARTE'] ?? 0) - $cardFee, 2);

        // Alerte stock faible : analyse des 30 derniers jours.
        // (L'horizon de couverture n'influence pas les alertes, seul
        // compte le rythme de consommation journalier.)
        $refFrom = date('Y-m-d', strtotime('-29 days'));
        $reorder = $this->reorderData(
            5,
            $refFrom,
            date('Y-m-d'),
            ComptaCalc::calendarDaysBetween($refFrom, date('Y-m-d'))
        );
        $reorderAlerts = $reorder['alerts'];

        // Suivi avancé : dépenses, résultat net, fiabilité des coûts, alertes.
        $expenseAgg = Expense::aggregatesBetween($period['from'], $period['to']);
        $coverage = Sale::costCoverageBetween($period['from'], $period['to']);
        $vat = Sale::vatByRateBetween($period['from'], $period['to']);
        $lossLeaders = Sale::lossLeadersBetween($period['from'], $period['to']);
        $invGaps = InventoryCount::recentGaps(30);
        $invPending = InventoryTablet::pendingSessions();
        $lastImport = Sale::lastImportAt();

        $vatTotal = 0.0;
        foreach ($vat as $v) {
            $vatTotal += (float) ($v['vat'] ?? 0);
        }

        $daysSinceImport = $lastImport !== null
            ? (new \DateTimeImmutable($lastImport))->diff(new \DateTimeImmutable('now'))->days
            : null;

        // ── Complétude de la période (règle de mission, documentée) ─────
        // $periodeComplete = la fenêtre sélectionnée est entièrement passée
        // (to < aujourd'hui) ET les données sont plausiblement à jour dessus
        // (un import SumUp existe ET couvre le début de la fenêtre). Sans
        // import du tout → incomplète : aucun % ni verdict définitif.
        // Les presets « 1d / 7d / 30d » (fin = aujourd'hui) et « all »
        // (bornes ouvertes) sont donc toujours incomplets.
        $today = date('Y-m-d');
        $periodeComplete = $period['from'] !== null
            && $period['to'] !== null
            && $period['to'] < $today
            && $lastImport !== null
            && $lastImport >= $period['from'];

        // ── Fenêtre PRÉCÉDENTE : même longueur, finissant J-1 avant $from
        //      (même calcul qu'Analytics::kpisPrevious — pattern maison).
        //      « all » (bornes null) n'a pas de précédent comparable.
        $prevFrom = null;
        $prevTo = null;
        if ($period['from'] !== null && $period['to'] !== null) {
            $days = ComptaCalc::calendarDaysBetween($period['from'], $period['to']);
            $prevTo = (new \DateTimeImmutable($period['from']))->modify('-1 day')->format('Y-m-d');
            $prevFrom = (new \DateTimeImmutable($period['from']))->modify('-' . $days . ' days')->format('Y-m-d');
        }

        // Fiable (pattern analytics : AdminAnalyticsController::prevComplete)
        // si la fenêtre précédente est entièrement passée ET commence après
        // la première vente importée (pas de comparaison sur du vide).
        $precedentComplet = $prevFrom !== null
            && $prevTo !== null
            && $prevTo < $today
            && $prevFrom >= Analytics::firstSaleDate();

        $prevAgg = null;
        if ($precedentComplet) {
            $prevAgg = Sale::aggregatesBetween($prevFrom, $prevTo);
            $prevExpenses = Expense::aggregatesBetween($prevFrom, $prevTo);
        }

        // ── Objectif de CA (période complète seulement) : pro-rata de
        //      l'objectif HEBDOMADAIRE existant (source unique, setting
        //      « analytics_weekly_goal ») sur les jours calendaires de la
        //      fenêtre : objectif = hebdo × nbJours / 7.
        $objectif = null;
        if ($periodeComplete) {
            $weeklyGoal = (float) Setting::get('analytics_weekly_goal', '500');
            $days = ComptaCalc::calendarDaysBetween((string) $period['from'], (string) $period['to']);
            $objectif = round($weeklyGoal * $days / 7.0, 2);
        }

        // ── Cœur « Niveau 1 » (classes pures) : verdict, cartes, à faire.
        //      Les surcharges de seuils viennent des Paramètres (clés
        //      « dashboard_* », préfixe attendu par Hyper::valeur) ; valeur
        //      vide → constante Hyper (jamais 0, qui alerterait à tort).
        $parametres = [
            'dashboard_baisse_attention_pct' => self::seuilSetting('dashboard_baisse_attention_pct', Hyper::BAISSE_ATTENTION_PCT),
            'dashboard_baisse_alerte_pct'    => self::seuilSetting('dashboard_baisse_alerte_pct', Hyper::BAISSE_ALERTE_PCT),
        ];

        $actuel = [
            'ca'       => (float) $agg['ca'],
            'profit'   => (float) $agg['profit'],
            'qty'      => (float) $agg['qty'],
            'expenses' => (float) $expenseAgg['ttc'],
        ];
        $precedent = $precedentComplet ? [
            'ca'       => (float) $prevAgg['ca'],
            'profit'   => (float) $prevAgg['profit'],
            'qty'      => (float) $prevAgg['qty'],
            'expenses' => (float) $prevExpenses['ttc'],
        ] : null;

        $verdict = Verdict::construire(
            $actuel['profit'] - $actuel['expenses'],
            $precedent !== null ? $precedent['profit'] - $precedent['expenses'] : null,
            $precedentComplet,
            $periodeComplete,
            $actuel['ca'],
            $objectif,
            $parametres
        );
        $cartes = Cartes::construire($actuel, $precedent, $precedentComplet, $periodeComplete, $parametres);

        // ── Bloc « À faire » : items construits ici, triés par Afaire. ──
        $afaireItems = [];

        // 1. Ruptures réappro (réutilise reorderData()) : produits en état
        //    « reorder », dont ceux déjà à zéro (plus urgents).
        $nbRupture = 0;
        $nbRuptureZero = 0;
        foreach ($reorder['rows'] as $row) {
            if (($row['state'] ?? '') === 'reorder') {
                $nbRupture++;
                if ((int) ($row['stock'] ?? 0) <= 0) {
                    $nbRuptureZero++;
                }
            }
        }
        if ($nbRupture > 0) {
            $afaireItems[] = [
                'cle'     => 'rupture',
                'urgence' => $nbRuptureZero > 0 ? 3 : 2,
                'texte'   => $nbRuptureZero > 0
                    ? sprintf('%d produits en rupture (dont %d à zéro)', $nbRupture, $nbRuptureZero)
                    : sprintf('%d produits en rupture', $nbRupture),
                'lien'    => url('/admin/compta/liste'),
            ];
        }

        // 2. Inventaire : écarts des 30 derniers jours + sessions tablette
        //    en attente de correction.
        $nbGaps = count($invGaps);
        $nbPending = count($invPending);
        if ($nbGaps + $nbPending > 0) {
            $afaireItems[] = [
                'cle'     => 'inventaire',
                'urgence' => 2,
                'texte'   => $nbPending > 0
                    ? sprintf("%d écarts d'inventaire sur 30 jours (dont %d sessions tablette en attente)", $nbGaps, $nbPending)
                    : sprintf("%d écarts d'inventaire sur 30 jours", $nbGaps),
                'lien'    => url('/admin/compta/inventaire'),
            ];
        }

        // 3. Coûts manquants : ventes sans lot de coût connu (% du CA
        //    seulement si la période est complète).
        $uncovered = (float) ($coverage['uncovered'] ?? 0);
        if ($uncovered > 0.0) {
            $caProducts = (float) $agg['ca_products'];
            $pct = ($periodeComplete && $caProducts > 0.0)
                ? sprintf(' (%s %% du CA)', number_format($uncovered / $caProducts * 100, 1, ',', ' '))
                : '';
            $afaireItems[] = [
                'cle'     => 'couts',
                'urgence' => 2,
                'texte'   => formatPrice($uncovered) . ' de ventes sans coût connu' . $pct,
                'lien'    => url('/admin/compta/couts'),
            ];
        }

        // 4. Import SumUp : absent → urgent ; ≥ 14 j → urgent ; ≥ 7 j →
        //    à traiter ; < 7 j → rien à signaler.
        if ($daysSinceImport === null) {
            $afaireItems[] = [
                'cle'     => 'import',
                'urgence' => 3,
                'texte'   => 'Aucun import SumUp connu',
                'lien'    => url('/admin/compta/import'),
            ];
        } elseif ($daysSinceImport >= 14) {
            $afaireItems[] = [
                'cle'     => 'import',
                'urgence' => 3,
                'texte'   => sprintf('Dernier import SumUp il y a %d jours', $daysSinceImport),
                'lien'    => url('/admin/compta/import'),
            ];
        } elseif ($daysSinceImport >= 7) {
            $afaireItems[] = [
                'cle'     => 'import',
                'urgence' => 2,
                'texte'   => sprintf('Dernier import SumUp il y a %d jours', $daysSinceImport),
                'lien'    => url('/admin/compta/import'),
            ];
        }

        // 5. Caisse : liquide encaissé depuis le dernier comptage — au-delà
        //    de 0,50 €, un comptage est attendu (urgence maximale).
        $lastCountAt = CashCount::lastCountAt();
        $liquide = Sale::sumLiquidSince($lastCountAt);
        if ($liquide > 0.5) {
            $depuis = $lastCountAt !== null
                ? 'depuis le dernier comptage (' . formatDate($lastCountAt) . ')'
                : 'depuis toujours';
            $afaireItems[] = [
                'cle'     => 'caisse',
                'urgence' => 3,
                'texte'   => 'Caisse non comptée : ' . formatPrice($liquide) . ' de liquide ' . $depuis,
                'lien'    => url('/admin/compta/caisse'),
            ];
        }

        $afaire = Afaire::trier($afaireItems, $parametres);

        $this->renderAdmin('admin/compta/dashboard', [
            'title'        => 'Comptabilité',
            'user'         => $user,
            'period'       => $period,
            'periodOptions'=> ComptaCalc::PERIOD_OPTIONS,
            'agg'          => $agg,
            'split'        => $split,
            'txCount'      => $txCount,
            'cardFee'      => $cardFee,
            'cardNet'      => $cardNet,
            'feeRate'      => CardFees::formattedRate(),
            'top'          => $top,
            'byCategory'   => $byCategory,
            'reorderAlerts'=> $reorderAlerts,
            'margin'       => ComptaCalc::marginPercent($agg['profit'], $agg['ca_products']),
            'expenseTtc'   => $expenseAgg['ttc'],
            'expenseCount' => (int) ($expenseAgg['count'] ?? 0),
            'netResult'    => $agg['profit'] - $expenseAgg['ttc'],
            'noCostCa'     => $coverage['uncovered'],
            'vat'          => $vat,
            'vatTotal'     => $vatTotal,
            'lossLeaders'  => $lossLeaders,
            'invGaps'      => array_slice($invGaps, 0, 5),
            'lastImport'   => $lastImport,
            'daysSinceImport' => $daysSinceImport,
            // Niveau 1 (refonte 3 niveaux).
            'verdict'         => $verdict,
            'cartes'          => $cartes,
            'afaire'          => $afaire,
            'periode_complete'=> $periodeComplete,
            'precedent_complet'=> $precedentComplet,
            'prev_from'       => $prevFrom,
            'prev_to'         => $prevTo,
            'objectif'        => $objectif,
        ]);
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

        $this->renderAdmin('admin/compta/categories', [
            'title'         => 'Bénéfice par catégorie',
            'user'          => $user,
            'rows'          => $rows,
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
            'categories'  => array_values(array_unique(array_filter($catByProduct))),
            'productKeys' => $keys,
            'dupes'       => $dupes,
            'editLot'     => $editLot,
            'form'        => [
                'product_key' => (string) ($editLot['product_key'] ?? ($_GET['product_key'] ?? '')),
                'cost_price'  => $editLot !== null ? number_format((float) $editLot['cost_price'], 3, ',', '') : '',
                'valid_from'  => $editLot !== null ? substr((string) $editLot['valid_from'], 0, 10) : date('Y-m-d'),
                'supplier'    => $editLot !== null ? (string) ($editLot['supplier'] ?? '') : '',
                'notes'       => '',
            ],
        ]);
    }

    public function saveCost(): void
    {
        $user = $this->guardSystemOrPage('costs');

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
                'product_key' => $key,
                'cost_price'  => $price,
                'valid_from'  => $validFrom,
                'supplier'    => $supplier,
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

        if (ProductCost::update($id, $data)) {
            $this->audit('compta.cost.update', 'product_cost', $id, [
                'cost_price' => $data['cost_price'],
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
     * doublons (même clé normalisée), puis regroupe en une transaction :
     * ventes, lots de coûts et alias — la ligne d'un libellé encore vendu
     * reste un pointeur vers le survivant (les prochains imports résolvent
     * au bon endroit), celle d'un libellé plus vendu est absorbée.
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

        $pdo = db();
        $salesMoved = 0;
        $lotsMoved = 0;
        $aliasesMoved = 0;
        $aliasesAbsorbed = 0;

        try {
            $pdo->beginTransaction();

            foreach ($others as $old) {
            $salesMoved += Sale::mergeInto($keep, [$old]);
            $lotsMoved += ProductCost::reassign($old, $keep);
            // Alias : la ligne « identité » d'un libellé plus vendu est
            // absorbée (fusion totale, pas de doublon) ; un libellé encore
            // actif au POS garde son pointeur vers le survivant.
            $abs = ProductAlias::absorbInto($old, $keep);
            $aliasesMoved += $abs['repointed'];
            $aliasesAbsorbed += $abs['absorbed'];
        }

        // Alias consolidés : les libellés ramenés sur le survivant peuvent
        // doubler une forme normalisée déjà rattachée.
        $deduped = ProductAlias::consolidateFor($keep);

        // Pointeurs des libellés encore actifs au POS (si resolve() ne
        // les couvre pas déjà).
        foreach ($others as $old) {
            if (ProductAlias::ensurePointer($old, $keep)) {
                $aliasesMoved++;
            }
        }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->audit('compta.product.merge_failed', 'product', $keep, ['error' => $e->getMessage()]);
            // Message générique affiché : le détail de l'erreur reste dans
            // l'audit et le log applicatif.
            Logger::error('compta: fusion de produits échouée', [
                'keep' => $keep,
                'msg'  => $e->getMessage(),
            ]);
            $this->setFlash('error', 'Fusion annulée : une erreur inattendue est survenue. L\'incident a été journalisé.');
            redirect(url('/admin/compta/couts'));
        }

        $this->audit('compta.product.merge', 'product', $keep, [
            'merged'   => $others,
            'sales'    => $salesMoved,
            'lots'     => $lotsMoved,
            'aliases'  => $aliasesMoved,
            'absorbed' => $aliasesAbsorbed,
            'deduped'  => $deduped,
        ]);
        $this->setFlash(
            'success',
            sprintf(
                'Fusion effectuée vers « %s » : %d vente(s), %d lot(s), %d alias re-groupés, %d alias obsolète(s) absorbé(s), %d doublon(s) d\'alias consolidé(s).',
                $keep,
                $salesMoved,
                $lotsMoved,
                $aliasesMoved,
                $aliasesAbsorbed,
                $deduped
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
            // Préserve la catégorie existante de l'alias (le save est un
            // upsert : sans ce champ, la catégorie serait écrasée à NULL).
            $prev = ProductAlias::findByRaw($raw);
            $category = $prev !== null ? ($prev['category'] ?? null) : null;

            if (ProductAlias::save(['raw_description' => $raw, 'product_key' => $key, 'category' => $category]) !== '') {
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

        $this->renderReorderPage(false, $user);
    }

    /**
     * Accès « kiosque » au Réapprovisionnement par lien secret, SANS
     * connexion : destiné au raccourci écran d'accueil du téléphone.
     * Lecture seule ; jeton révocable via regenerateKioskToken().
     */
    public function kioskReorder(string $token): void
    {
        if (!$this->kioskAnyTokenOk($token)) {
            http_response_code(403);
            echo '<h1>Erreur 403 — Lien invalide ou révoqué.</h1>';

            return;
        }

        $this->renderReorderPage(true, null, $token);
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
     */
    public function liste(): void
    {
        $user = $this->guardCompta();

        $this->renderListePage(false, $user);
    }

    /**
     * Accès « kiosque » à la liste de courses par lien secret (même jeton
     * que le Réappro) : lecture seule, sans connexion, sans navigation.
     */
    public function kioskListe(string $token): void
    {
        if (!$this->kioskAnyTokenOk($token)) {
            http_response_code(403);
            echo '<h1>Erreur 403 — Lien invalide ou révoqué.</h1>';

            return;
        }

        $this->renderListePage(true, null, $token);
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

    private function kioskTokenOk(string $token): bool
    {
        $expected = trim((string) Setting::get('reappro_kiosk_token', ''));
        $given    = trim($token);

        return $expected !== '' && $given !== '' && hash_equals($expected, $given);
    }

    /**
     * Jeton MEMBRES ou ADMIN accepté : les pages outils (liste, réappro)
     * sont accessibles depuis les deux espaces kiosque, l'URL conservant
     * le contexte d'origine (les liens de retour y restent).
     */
    private function kioskAnyTokenOk(string $token): bool
    {
        if ($this->kioskTokenOk($token)) {
            return true;
        }

        $expected = trim((string) Setting::get('admin_kiosk_token', ''));

        return $expected !== '' && hash_equals($expected, trim($token));
    }

    /**
     * Hub de retour selon le jeton de la liste : admin → hub admin,
     * membres → hub membres.
     */
    private function listeHubUrl(string $token): string
    {
        $admin = trim((string) Setting::get('admin_kiosk_token', ''));

        return $admin !== '' && hash_equals($admin, trim($token))
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
        $this->guardCompta();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($this->listeStatePayload());
    }

    /** État des cases (JSON) — version kiosque. */
    public function kioskListeState(string $token): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->kioskAnyTokenOk($token)) {
            http_response_code(403);
            echo '{"ok":false,"error":"lien invalide"}';

            return;
        }
        echo json_encode($this->listeStatePayload());
    }

    /** Cocher / décocher (JSON) — version connectée. */
    public function listeCheck(): void
    {
        $this->guardCompta();
        $this->handleListeCheck();
    }

    /** Cocher / décocher (JSON) — version kiosque. */
    public function kioskListeCheck(string $token): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->kioskAnyTokenOk($token)) {
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
    private function renderListePage(bool $kiosk, ?array $user = null, ?string $kioskToken = null): void
    {
        // Analyse FIXE : 14 derniers jours glissants (masquée de l'interface).
        $fromDay = date('Y-m-d', strtotime('-13 days'));
        $toDay   = date('Y-m-d');
        $calDays = ComptaCalc::calendarDaysBetween($fromDay, $toDay);

        // Couverture (« Couvrir pour »), défaut 1 semaine.
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

        $data = $this->reorderData($covers[$coverKey]['days'], $fromDay, $toDay, $calDays);

        // Uniquement ce qu'il faut acheter pour l'horizon choisi :
        // les « À racheter » du réappro (stock épuisé / autonomie < 7 j)
        // ET tout produit dont la quantité à commander devient positive
        // quand on allonge la couverture (ex. autonomie 20 j + couvrir
        // 1 mois → commander). Les « à compter » (jamais comptés, ex.
        // Mister Freeze) et les artefacts SumUp sont exclus.
        $parser = new SumUpCsvParser();
        $isArtifact = static fn(string $k): bool => $parser->isCustomAmount($k);

        $items = array_values(array_filter(
            $data['rows'],
            static fn(array $r): bool =>
                !($isArtifact)((string) ($r['name'] ?? ''))
                && (
                    ($r['state'] ?? '') === 'reorder'
                    || (
                        (int) ($r['to_order'] ?? 0) > 0
                        && ($r['state'] ?? '') !== 'unknown'
                    )
                )
        ));

        // Tri comme le Réappro : autonomie croissante (les plus critiques
        // en premier, « inconnue » en dernier), puis quantité, puis nom.
        usort($items, static function (array $a, array $b): int {
            $aa = $a['autonomy'] ?? PHP_INT_MAX;
            $bb = $b['autonomy'] ?? PHP_INT_MAX;
            if ($aa !== $bb) {
                return $aa <=> $bb;
            }
            if ((int) $a['to_order'] !== (int) $b['to_order']) {
                return (int) $b['to_order'] <=> (int) $a['to_order'];
            }

            return strcasecmp((string) $a['name'], (string) $b['name']);
        });

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

        // Lien kiosque — même jeton que le Réappro (généré paresseusement).
        // En mode kiosque avec un jeton fourni : conserver CE jeton (espace
        // membres OU admin) pour que les liens de retour y restent.
        $kioskToken = ($kiosk && $kioskToken !== null && trim($kioskToken) !== '')
            ? trim($kioskToken)
            : Kiosk::token();
        $kioskUrl   = '';
        if (!$kiosk) {
            $kioskUrl = Kiosk::url('/kiosque/liste/');
        }

        $viewData = [
            'title'       => 'Liste de courses',
            'user'        => $user,
            'items'       => $items,
            'covers'      => $covers,
            'coverKey'    => $coverKey,
            'totalUnits'  => $totalUnits,
            'totalCost'   => round($totalCost, 2),
            'missingCost' => $missingCost,
            'kiosk'       => $kiosk,
            'kioskUrl'    => $kioskUrl,
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
    private function renderReorderPage(bool $kiosk, ?array $user = null, ?string $kioskToken = null): void
    {
        // Lien kiosque : généré à la première visite (connecté uniquement).
        $kioskUrl = '';
        if (!$kiosk) {
            $kioskUrl = Kiosk::url('/kiosque/reappro/');
        }
        // Hub de retour selon le jeton entrant (admin ou membres).
        $kioskHubUrl = ($kiosk && $kioskToken !== null && trim($kioskToken) !== '')
            ? $this->listeHubUrl(trim($kioskToken))
            : '';

        // ── Période d'ANALYSE (sur quoi calculer les moyennes) ──────────
        $refOptions = [
            '1d'     => '1 jour',
            '7d'     => '7 derniers jours',
            '14d'    => '14 derniers jours',
            '30d'    => '30 derniers jours',
            'all'    => 'Tout',
            'custom' => 'Personnalisé',
        ];
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
            case '7d':  $fromDay = date('Y-m-d', strtotime('-6 days')); break;
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
                'kioskUrl'      => '',
                // Bouton retour flottant : hub du bon espace (admin/membres).
                'kioskHubUrl'   => $kioskHubUrl,
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
            'kioskUrl'      => $kioskUrl,
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
     * Calcule l'analyse de réapprovisionnement sur une période donnée.
     *
     * Chaque produit vendu dans la période d'analyse est listé avec sa
     * consommation moyenne (rapportée aux jours CALENDaires de la période,
     * week-end inclus — rythme 7 j/7) par jour / semaine / mois, et la
     * quantité à commander pour couvrir l'horizon cible (lui aussi en
     * jours calendaires). L'autonomie est donc le nombre de jours avant
     * rupture WEEK-END COMPRIS. Le stock est le THÉORIQUE de l'inventaire
     * (dernier comptage + achats − ventes − pertes) : jamais une saisie
     * manuelle qui se périme. Un achat ou une perte établit une base 0 :
     * le théorique existe même sans comptage préalable ; seuls les
     * produits sans aucune donnée (ni comptage, ni achat, ni perte)
     * apparaissent « à compter », besoin calculé sans stock déduit. Les
     * produits marqués « plus en vente » sont exclus (voir
     * ProductDiscontinued).
     *
     * @param int         $targetDays Horizon de couverture, en jours calendaires.
     * @param string|null $fromDay    Début de la période d'analyse (inclus).
     * @param string|null $toDay      Fin de la période d'analyse (inclus).
     * @param int         $calDays    Jours calendaires de la période (week-end inclus).
     *
     * @return array{rows:list<array<string,mixed>>, alerts:int}
     */
    private function reorderData(int $targetDays, ?string $fromDay, ?string $toDay, int $calDays): array
    {
        // 100 % basé sur les ventes SumUp : chaque produit du CSV est listé,
        // sa catégorie vient du CSV, et son stock est le théorique issu de
        // l'inventaire (même source que la page Inventaire).
        $consumption = Sale::consumptionBetween($fromDay, $toDay);
        $calDays = max(1, $calDays);

        // Produits marqués « plus en vente » (saisonniers/discontinués) :
        // exclus du réappro pour ne pas encombrer l'analyse des commandes.
        // Clés normalisées : insensible à la casse/espaces, comme le lookup stock.
        $discontinued = array_flip(array_map(
            static fn(string $k): string => strtolower(trim($k)),
            ProductDiscontinued::keys()
        ));

        // Produits marqués « stock infini » (jamais réapprovisionnés) :
        // autonomie ∞, jamais proposés à la commande, même « à compter ».
        $infinite = array_flip(array_map(
            static fn(string $k): string => strtolower(trim($k)),
            ProductInfinite::keys()
        ));

        // Packs d'achat (12, 24, 32…) : « À commander » est arrondi au
        // multiple supérieur du pack — on achète par pack, pas à l'unité.
        $packs = ProductPack::sizesMap();

        // Stock théorique + dernier comptage, indexés en minuscules pour un
        // rapprochement insensible à la casse (ex. « Red bull » == « Red Bull »).
        $theoreticalLower = [];
        foreach (InventoryCount::theoreticalStocksMap() as $k => $v) {
            $theoreticalLower[strtolower(trim((string) $k))] = (int) $v;
        }
        $lastCountsLower = [];
        foreach (InventoryCount::lastCountsMap() as $k => $c) {
            $lastCountsLower[strtolower(trim((string) $k))] = $c;
        }

        $rows = [];
        $alerts = 0;

        foreach ($consumption as $key => $data) {
            $key = (string) $key;
            if ($key === '' || isset($discontinued[strtolower(trim($key))])) {
                continue;
            }

            $qty      = (int) ($data['qty'] ?? 0);
            // Rythme 7 j/7 : les ventes du week-end sont incluses dans la
            // quantité ET dans le nombre de jours — week-end couvert.
            $avgDay   = $qty / $calDays;
            $avgWeek  = $avgDay * 7.0;
            $avgMonth = $avgDay * 30.44;

            $lookupKey = strtolower(trim($key));
            $hasStock = array_key_exists($lookupKey, $theoreticalLower);
            $stock    = $hasStock ? $theoreticalLower[$lookupKey] : null;
            $countedAt = $lastCountsLower[$lookupKey]['at'] ?? null;

            // Autonomie : jours CALENDaires avant rupture, week-end inclus
            // (arrondie à la baisse ; un stock négatif signifie rupture
            // déjà atteinte).
            $autonomy = ($stock !== null && $avgDay > 0)
                ? max(0, (int) floor($stock / $avgDay))
                : null;

            $need   = (int) ceil($avgDay * $targetDays);
            // Un stock négatif (survente) majore la quantité à commander :
            // reconstituer le niveau perdu en plus de couvrir le besoin.
            $toOrderRaw = max(0, $need - ($stock ?? 0));

            // Pack d'achat : arrondi au multiple supérieur (besoin brut 2,
            // pack de 12 → commander 12). 0/absent = commande à l'unité.
            $packSize = $packs[$lookupKey] ?? 0;
            if ($packSize > 1 && $toOrderRaw > 0) {
                $toOrder = (int) ceil($toOrderRaw / $packSize) * $packSize;
            } else {
                $toOrder = $toOrderRaw;
            }

            // Stock déclaré infini : autonomie ∞ et jamais à commander,
            // même si le produit n'a jamais été compté (« à compter »).
            $infiniteStock = isset($infinite[$lookupKey]);
            if ($infiniteStock) {
                $autonomy = null;
                $toOrder  = 0;
            }

            // Coût unitaire actuel (lot en cours à aujourd'hui) et coût
            // estimé de la ligne (qté à commander × coût unitaire).
            $unitCostRaw = ProductCost::costAt($key, date('Y-m-d'));
            $unitCost = $unitCostRaw !== null ? (float) $unitCostRaw : null;
            $orderCost = $unitCost !== null ? round($toOrder * $unitCost, 2) : null;

            if ($infiniteStock) {
                $state = 'ok';
            } elseif ($stock === null) {
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
                'name'       => $key,
                'category'   => (string) ($data['category'] ?? '—'),
                'qty'        => $qty,
                'stock'      => $stock,
                'counted_at' => $countedAt,
                'avg_day'    => $avgDay,
                'avg_week'   => $avgWeek,
                'avg_month'  => $avgMonth,
                'unit_cost'  => $unitCost,
                'order_cost' => $orderCost,
                'autonomy'   => $autonomy,
                'need'       => $need,
                'to_order'   => $toOrder,
                'to_order_raw' => $toOrderRaw,
                'pack'       => $packSize,
                'state'      => $state,
                'is_alert'   => $isAlert,
                'infinite'   => $infiniteStock,
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

        $this->renderAdmin('admin/compta/caisse', [
            'title'       => 'Comptage de caisse',
            'user'        => $user,
            'kioskHub'    => Kiosk::url('/kiosque/comptage/'),
            'kioskCaisse' => Kiosk::url('/kiosque/comptage/caisse/'),
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
