<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Compta\AliasSuggester;
use App\Core\Compta\ComptaCalc;
use App\Core\Compta\ProductAutoSync;
use App\Core\Compta\StockPublic;
use App\Models\InventoryCount;
use App\Models\ProductCost;
use App\Models\ProductDiscontinued;
use App\Models\ProductKeyMerge;
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
 * achats − ventes) pour détecter pertes et casses. Fait partie du groupe
 * « Système » : réservé au Fondateur et aux ADMIN listés dans SYSTEM_ADMINS,
 * ou aux utilisateurs ayant reçu la page en attribution individuelle
 * (voir guardSystemOrPage()) ; la trésorerie n'y accède pas sinon.
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
            'allKeys'       => ProductKeyMerge::allKeys(),
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

        // Synchro automatique de la carte limitée aux clés saisies :
        // un achat sur un produit sans fiche crée la fiche. Jamais bloquant.
        $sync = ['created' => []];
        try {
            $sync = ProductAutoSync::ensureKeys(array_keys($products));
        } catch (\Throwable) {
            // Le stock ne doit jamais casser à cause de la synchro carte.
        }

        // Les achats font bouger le théorique : la carte publique suit.
        StockPublic::invalidate();

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
        if ($sync['created'] !== []) {
            $flash .= ' Carte mise à jour automatiquement : ' . implode(', ', $sync['created']) . '.';
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

        StockPublic::invalidate();

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
    //  Inventaire (groupe « Système » ou attribution individuelle)
    // -----------------------------------------------------------------

    public function inventory(): void
    {
        $user = $this->guardSystemOrPage('inventory');

        $lastCounts = InventoryCount::lastCountsMap();
        $theoretical = InventoryCount::theoreticalStocksMap();
        $stockMap = ProductStock::allMap();

        // Produits marqués « plus en vente » (saisonniers, discontinués) :
        // hors grille principale, listés à part pour pouvoir les rétablir.
        $hidden = array_flip(ProductDiscontinued::keys());

        // Grille = produits vendus (SumUp) + produits établis par un achat
        // ou une perte sans comptage : un achat ou une perte établit une
        // base 0, le stock théorique existe donc aussi pour ces clés
        // (jamais comptées → « Jamais compté » dans la grille).
        $keys = array_values(array_unique(array_merge(
            Sale::distinctProducts(),
            array_keys($theoretical)
        )));

        $rows = [];
        $discontinuedRows = [];
        foreach ($keys as $key) {
            $key = (string) $key;
            $last = $lastCounts[$key] ?? null;

            if (isset($hidden[$key])) {
                $discontinuedRows[] = [
                    'key'        => $key,
                    'counted_at' => $last !== null ? $last['at'] : null,
                ];
                continue;
            }

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

        usort($discontinuedRows, static fn(array $a, array $b): int => strcasecmp($a['key'], $b['key']));

        // Statistiques par clé + doublons probables (même clé normalisée) :
        // alimentent la carte « Fusionner des clés produits » (recherche,
        // aperçu des données déplacées, suggestions pré-remplies).
        $keyStats = ProductKeyMerge::keyStats();

        $this->renderAdmin('admin/compta/inventory', [
            'title'            => 'Inventaire',
            'user'             => $user,
            'rows'             => $rows,
            'discontinuedRows' => $discontinuedRows,
            'history'          => InventoryCount::history(50),
            'gaps'             => InventoryCount::recentGaps(30),
            'keyStats'         => $keyStats,
            'mergeDupes'       => AliasSuggester::groupDuplicates(array_keys($keyStats)),
        ]);
    }

    public function saveCount(): void
    {
        $user = $this->guardSystemOrPage('inventory');

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

        // Synchro automatique de la carte : couvre les nouveaux produits
        // comptés pour la première fois. Jamais bloquant.
        $sync = ['created' => []];
        try {
            $sync = ProductAutoSync::sync();
        } catch (\Throwable) {
            // L'inventaire ne doit jamais casser à cause de la synchro carte.
        }

        // Le comptage réaligne le théorique : la carte publique suit.
        StockPublic::invalidate();

        $flash = sprintf('%d produit(s) compté(s) — %d écart(s) détecté(s).', $done, $gaps);
        if ($sync['created'] !== []) {
            $flash .= ' Carte mise à jour automatiquement : ' . implode(', ', $sync['created']) . '.';
        }
        $this->setFlash('success', $flash);
        redirect(url('/admin/compta/inventaire'));
    }

    // -----------------------------------------------------------------
    //  Comptage inventaire « à l'aveugle » (tout le bureau, hors élèves)
    // -----------------------------------------------------------------

    /**
     * Comptage inventaire « à l'aveugle » — saisie seule, ouverte à tout
     * le bureau (hors élèves). Les stocks théoriques ne sont volontairement
     * pas affichés (un comptage honnête ne doit pas pouvoir s'ajuster sur
     * l'attendu) ; les écarts sont calculés à l'enregistrement.
     *
     * Les produits marqués « plus en vente » (saisonniers, discontinués)
     * sont exclus de la liste — rétablissables depuis la page Inventaire.
     */
    public function blindCount(): void
    {
        $user = $this->guardAdminArea();

        // Uniquement les clés produits : ni stock, ni écart, ni date de
        // dernier comptage (aucune fuite du théorique). Les produits
        // « plus en vente » sont retirés (array_flip + isset : O(1)/clé).
        $hidden = array_flip(ProductDiscontinued::keys());
        $rows = [];
        foreach (Sale::distinctProducts() as $key) {
            if (isset($hidden[(string) $key])) {
                continue;
            }
            $rows[] = ['key' => (string) $key];
        }
        usort($rows, static fn(array $a, array $b): int => strcasecmp($a['key'], $b['key']));

        $this->renderAdmin('admin/compta/blind_count', [
            'title' => 'Comptage inventaire',
            'user'  => $user,
            'rows'  => $rows,
        ]);
    }

    /**
     * Enregistre le comptage à l'aveugle : même mécanique que le comptage
     * de la page Inventaire complète (théorique calculé côté serveur,
     * écarts historisés, stocks réalignés, synchro carte non bloquante).
     *
     * Robustesse : une clé marquée « plus en vente » (ex. postée depuis un
     * onglet resté ouvert) est ignorée silencieusement.
     */
    public function saveBlindCount(): void
    {
        $user = $this->guardAdminArea();

        $counts = $_POST['count'] ?? [];
        if (!is_array($counts)) {
            $counts = [];
        }

        $hidden = array_flip(ProductDiscontinued::keys());
        $done = 0;
        $gaps = 0;

        foreach ($counts as $key => $value) {
            $productKey = trim((string) $key);
            if ($productKey === '' || isset($hidden[$productKey]) || trim((string) $value) === '') {
                continue;
            }

            $counted = (int) $value;
            if ($counted < 0) {
                continue;
            }

            // L'écart est calculé côté serveur AVANT le record : chaque
            // comptage devient la nouvelle référence du stock théorique.
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
            redirect(url('/admin/compta/inventaire/comptage'));
        }

        $this->audit('compta.inventory.count', 'inventory_count', null, [
            'products' => $done,
            'gaps'     => $gaps,
            'source'   => 'comptage',
        ]);

        // Synchro carte non bloquante (nouveaux produits comptés), comme
        // sur la page Inventaire complète.
        try {
            ProductAutoSync::sync();
        } catch (\Throwable) {
        }

        // Le comptage réaligne le théorique : la carte publique suit.
        StockPublic::invalidate();

        $this->setFlash($gaps > 0 ? 'warning' : 'success', sprintf('%d produit(s) compté(s) — %d écart(s) détecté(s).', $done, $gaps));
        redirect(url('/admin/compta/inventaire/comptage'));
    }

    /**
     * Marque un produit « plus en vente » (saisonnier ou discontinué) :
     * il disparaît du comptage à l'aveugle, de la page Inventaire et du
     * réappro. Actionnable depuis la page Comptage (tout le bureau) ;
     * l'historique des ventes reste inchangé.
     */
    public function discontinue(string $key): void
    {
        $this->guardAdminArea();

        $productKey = trim($key);
        $back = ($_POST['back'] ?? '') === 'inventaire'
            ? '/admin/compta/inventaire'
            : '/admin/compta/inventaire/comptage';

        if ($productKey === '') {
            $this->setFlash('error', 'Produit manquant.');
            redirect(url($back));
        }

        ProductDiscontinued::mark($productKey, Auth::id());

        $this->audit('inventory.discontinue', 'product', $productKey, ['key' => $productKey]);

        // Synchro : le drapeau est relu par l'inventaire, le comptage à
        // l'aveugle, le réappro et les alertes SMS — rien d'autre à faire.
        $this->setFlash('success', "Produit marqué plus en vente — il n'apparaîtra plus dans les comptages, l'inventaire ni le réappro.");
        redirect(url($back));
    }

    /**
     * Remet un produit en vente (retire le drapeau « plus en vente ») :
     * il réapparaît dans les comptages et dans le réappro.
     */
    public function resume(string $key): void
    {
        $this->guardSystemOrPage('inventory');

        $productKey = trim($key);
        if ($productKey === '') {
            $this->setFlash('error', 'Produit manquant.');
            redirect(url('/admin/compta/inventaire'));
        }

        ProductDiscontinued::resume($productKey);

        $this->audit('inventory.resume', 'product', $productKey, ['key' => $productKey]);

        $this->setFlash('success', 'Produit remis en vente.');
        redirect(url('/admin/compta/inventaire'));
    }

    /**
     * Fusionne deux clés produits (doublons) : toutes les données de la
     * clé source — ventes, achats, pertes, alias, comptages, stock de
     * référence, drapeau « plus en vente » — sont déplacées vers la clé
     * cible conservée (ProductKeyMerge::merge, transactionnel).
     */
    public function mergeKeys(): void
    {
        $this->guardSystemOrPage('inventory');

        $source = trim((string) ($_POST['source'] ?? ''));
        $target = trim((string) ($_POST['target'] ?? ''));

        if ($source === '' || $target === '') {
            $this->setFlash('error', 'Clé source et clé cible requises.');
            redirect(url('/admin/compta/inventaire'));
        }
        if ($source === $target) {
            $this->setFlash('error', 'Les deux clés sont identiques : rien à fusionner.');
            redirect(url('/admin/compta/inventaire'));
        }

        try {
            $moved = ProductKeyMerge::merge($source, $target, Auth::id());
        } catch (\InvalidArgumentException $e) {
            $this->setFlash('error', 'Fusion refusée : ' . $e->getMessage());
            redirect(url('/admin/compta/inventaire'));
        } catch (\Throwable $e) {
            $this->setFlash('error', 'Fusion impossible : ' . $e->getMessage());
            redirect(url('/admin/compta/inventaire'));
        }

        $this->audit('inventory.merge', 'product_key', $target, [
            'source' => $source,
            'target' => $target,
            'tables' => $moved,
        ]);

        $labels = [
            'purchases'    => 'achat(s)',
            'losses'       => 'perte(s)',
            'sales'        => 'vente(s)',
            'aliases'      => 'alias',
            'counts'       => 'comptage(s)',
            'stocks'       => 'stock additionné',
            'discontinued' => 'drapeau « plus en vente »',
            'costs'        => 'lots de coûts',
        ];
        $parts = [];
        foreach ($moved as $table => $n) {
            if ($n > 0) {
                $parts[] = $n . ' ' . ($labels[$table] ?? $table);
            }
        }

        $this->setFlash(
            'success',
            'Fusion effectuée : '
            . ($parts === [] ? 'aucune donnée trouvée sur la clé source.' : implode(', ', $parts))
            . '.'
        );
        redirect(url('/admin/compta/inventaire'));
    }
}
