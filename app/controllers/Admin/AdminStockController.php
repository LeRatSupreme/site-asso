<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Compta\AliasSuggester;
use App\Core\Compta\ComptaCalc;
use App\Core\Compta\ProductAutoSync;
use App\Core\Compta\ProductLifecycle;
use App\Core\Compta\Kiosk;
use App\Core\Compta\PurchaseSaver;
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

        // Statistiques complètes de la période (toutes les lignes, pas
        // seulement les 200 affichées) : total, lignes, quantité, HT/TVA/TTC.
        $stats = Purchase::statsBetween($period['from'], $period['to']);

        // Page fusionnée « Opérations » : les quatre sections compta
        // (achats, dépenses, pertes, événements) partagent la même vue
        // avec un onglet de niveau 1 ; $section sélectionne l'onglet.
        $this->renderAdmin('admin/compta/operations', [
            'title'         => 'Opérations · Achats & stock',
            'section'       => 'achats',
            'user'          => $user,
            'rows'          => $rows,
            'products'      => Sale::distinctProducts(),
            'period'        => $period,
            'periodOptions' => ComptaCalc::PERIOD_OPTIONS,
            'stats'         => $stats,
            'vatRows'       => Purchase::vatByRateBetween($period['from'], $period['to']),
            'allKeys'       => ProductKeyMerge::allKeys(),
        ]);
    }

    /**
     * Enregistre une grille d'achats en un seul POST (une ligne par
     * produit — une course entière en une fois). Les champs communs
     * (date, TVA, fournisseur, n° de facture, update_cost) s'appliquent
     * à toutes les lignes ; les lignes totalement vides sont ignorées,
     * les lignes invalides sont signalées dans le flash.
     *
     * Deux réponses possibles :
     *  - standard (page Achats) : redirection PRG + flash ;
     *  - JSON (champ « as_json » = « 1 », ex. préremplissage depuis le
     *    livre comptable après un scan de facture) : même traitement,
     *    même message, mais réponse {ok, inserted|error} sans redirection.
     *
     * Taux de TVA : soit un taux unique d'en-tête (« vat_rate », page
     * Achats), soit un taux PAR LIGNE via le tableau « vat_rate[] »
     * aligné sur « product_key[] » (factures à TVA mixte, ex. METRO
     * boissons 5,5 % + droguerie 20 %) ; une case vide du tableau
     * retombe sur le taux d'en-tête ('' = montant « déjà TTC »).
     *
     * Le métier (validation + création + lot de coût + synchro carte +
     * cache public) vit dans PurchaseSaver::save — partagé avec le livre
     * comptable kiosque ; ce contrôleur garde la garde, la lecture du
     * POST, l'audit, le flash et la redirection.
     */
    public function savePurchasesBulk(): void
    {
        $user = $this->guardCompta();

        // Mode JSON (fetch) : réponds {ok,...} au lieu de rediriger.
        // Sans ce champ : comportement historique strictement inchangé.
        $asJson = trim((string) ($_POST['as_json'] ?? '')) === '1';

        $purchasedAt = trim((string) ($_POST['purchased_at'] ?? ''));
        if ($purchasedAt === '') {
            $purchasedAt = date('Y-m-d');
        }
        $supplier = trim((string) ($_POST['supplier'] ?? ''));
        $invoiceNumber = trim((string) ($_POST['invoice_number'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $updateCost = isset($_POST['update_cost']);

        // Taux de TVA : chaîne (en-tête unique) OU tableau vat_rate[] —
        // en tableau, l'en-tête est vide : les cases vides de la ligne
        // signifient « déjà TTC » (pas de décomposition).
        $vatInput = $_POST['vat_rate'] ?? '';

        // '' = montants saisis déjà TTC (pas de TVA à calculer), sinon taux en %.
        $vatRaw = is_array($vatInput) ? '' : trim((string) $vatInput);

        // Base des montants saisis : « ht » (TVA à ajouter, défaut) ou
        // « ttc » (TVA déjà incluse — HT déduit du taux choisi).
        $basis = (string) ($_POST['amount_basis'] ?? 'ht') === 'ttc' ? 'ttc' : 'ht';

        $keys = is_array($_POST['product_key'] ?? null) ? $_POST['product_key'] : [];

        // Taux par ligne (facture à TVA mixte) : null si le POST n'a pas
        // de tableau vat_rate[] (taux d'en-tête pour toutes les lignes).
        $lineVatRates = is_array($vatInput) ? $vatInput : null;

        // Case « hors stock » par ligne : cochée, la ligne reste un achat
        // réel (compta, lot de coût) mais n'entre pas en stock. Les noms
        // indexés no_stock[N] sont renumérotés par le JS au submit pour
        // rester alignés sur product_key[N].
        $noStockFlags = is_array($_POST['no_stock'] ?? null) ? $_POST['no_stock'] : [];

        $quantities = is_array($_POST['quantity'] ?? null) ? $_POST['quantity'] : [];
        $amounts = is_array($_POST['total_amount'] ?? null) ? $_POST['total_amount'] : [];

        // Lignes alignées pour le service (mêmes brutes que le POST :
        // la résolution taux de ligne vs en-tête vit dans le service).
        $count = max(count($keys), count($quantities), count($amounts));
        $lines = [];
        for ($i = 0; $i < $count; $i++) {
            $lines[] = [
                'key'       => trim((string) ($keys[$i] ?? '')),
                'qty'       => (string) ($quantities[$i] ?? ''),
                'total_raw' => trim((string) ($amounts[$i] ?? '')),
                'vat_raw'   => $lineVatRates !== null ? (string) ($lineVatRates[$i] ?? '') : '',
                'no_stock'  => (($noStockFlags[$i] ?? null) === '1' || ($noStockFlags[$i] ?? null) === 1),
            ];
        }

        $res = PurchaseSaver::save([
            'purchased_at'   => $purchasedAt,
            'supplier'       => $supplier,
            'invoice_number' => $invoiceNumber,
            'notes'          => $notes,
            'amount_basis'   => $basis,
            'update_cost'    => $updateCost,
            'vat_raw'        => $vatRaw,
            'vat_per_line'   => $lineVatRates !== null,
            'created_by'     => $user['id'] ?? null,
        ], $lines);

        if ($res['inserted'] === 0) {
            if ($asJson) {
                // Même message agrégé que le flash, en JSON (400).
                $this->json(['ok' => false, 'error' => $res['message']], 400);
            }
            $this->setFlash('error', $res['message']);
            redirect(url('/admin/compta/achats'));
        }

        // Audit agrégé unique : un seul evénement pour toute la grille
        // (les ids des achats/ lots restent consultables dans le journal).
        $this->audit('compta.purchase.create_bulk', 'purchase', null, [
            'inserted'       => $res['inserted'],
            'products'       => $res['products'],
            'ignored'        => count($res['errors']),
            'vat_rate'       => $vatRaw === '' ? null : parseFrenchFloat($vatRaw),
            'vat_per_line'   => $lineVatRates !== null,
            'amount_basis'   => $basis,
            'update_cost'    => $updateCost,
            'no_stock'       => $res['no_stock'],
            'supplier'       => $supplier,
            'invoice_number' => $invoiceNumber !== '' ? $invoiceNumber : null,
            'purchased_at'   => $purchasedAt,
        ]);

        if ($asJson) {
            // Même message que le flash, en JSON (200).
            $this->json(['ok' => true, 'inserted' => $res['inserted'], 'message' => $res['message']]);
        }
        $this->setFlash('success', $res['message']);
        redirect(url('/admin/compta/achats'));
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
     * Un achat « hors stock » (no_stock = 1) n'ayant rien ajouté au stock,
     * sa suppression le laisse inchangé.
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

        // Achat « hors stock » : aucun stock n'avait été ajouté à la
        // création — rien à contre-passé ici.
        $noStock = !empty($purchase['no_stock']);

        // Cascade : supprime les lots de coût liés à l'achat ; si l'un
        // d'eux était « en cours », le lot antérieur est réouvert
        // (ProductCost::delete) pour ne pas casser la chaîne de coûts.
        $lotsDeleted = ProductCost::deleteByPurchase($id);

        Purchase::delete($id);

        // Contre-passation du stock de référence : ajusté de +quantity à
        // la création de l'achat — sauf achat « hors stock » (rien n'avait
        // été ajouté).
        if (!$noStock) {
            ProductStock::adjust($productKey, -$quantity);
        }

        StockPublic::invalidate();

        $this->audit('compta.purchase.delete', 'purchase', $id, [
            'product_key'    => $productKey,
            'quantity'       => $quantity,
            'lots_deleted'   => $lotsDeleted,
            'stock_adjusted' => $noStock ? 0 : -$quantity,
            'no_stock'       => $noStock,
        ]);

        $flash = sprintf(
            'Achat supprimé — stock de référence ajusté (−%d).',
            $quantity
        );
        if ($noStock) {
            $flash = 'Achat supprimé — stock inchangé (achat hors stock).';
        }
        if ($lotsDeleted > 0) {
            $flash .= sprintf(' %d lot(s) de coût lié(s) supprimé(s), lot antérieur réouvert.', $lotsDeleted);
        }
        $this->setFlash('success', $flash);
        redirect(url('/admin/compta/achats'));
    }

    /**
     * Scan d'une facture fournisseur (JSON, préremplissage du formulaire
     * d'achat) : délégation au mécanisme partagé avec le scan de dépense
     * (AdminBaseController::handleInvoiceScan — texte collé ou upload
     * OCR). Le document est interprété par InvoiceParser : une facture
     * METRO reste traitée par MetroInvoiceParser (compatibilité achats
     * conservée), tout autre texte passe en ticket de caisse.
     */
    public function scanInvoice(): void
    {
        $this->guardCompta();
        $this->handleInvoiceScan('compta.purchase.scan', 'purchase');
    }

    public function inventory(): void
    {
        $user = $this->guardSystemOrPage('inventory');

        // Cycle de vie automatique : pauses automatiques avec du stock →
        // remise en vente ; produits en vente à stock 0 depuis ≥ 7 jours →
        // mise en pause (voir ProductLifecycle::sweep).
        $sweep = ProductLifecycle::sweep();

        $lastCounts = InventoryCount::lastCountsMap();
        $theoretical = InventoryCount::theoreticalStocksMap();
        $stockMap = ProductStock::allMap();

        // Produits « plus en vente pour l'instant » (pause saisonnière) :
        // exclus de la grille de comptage et listés à part en bas de page
        // pour le rétablissement en un clic.
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
            $paused = isset($hidden[$key]);

            if ($paused) {
                // Hors grille : fiches de la section « Plus en vente pour
                // l'instant » (dernier comptage, théorique, écart, rétablissement).
                $discontinuedRows[] = [
                    'key'         => $key,
                    'counted_at'  => $last !== null ? $last['at'] : null,
                    'counted_qty' => $last !== null ? $last['qty'] : null,
                    'gap'         => $last !== null ? $last['gap'] : null,
                    'theoretical' => $theoretical[$key] ?? null,
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

        // Valeur du stock : Σ (stock théorique × coût de revient du lot en
        // cours, même source que la page Réappro). Les produits jamais
        // comptés ou à stock nul/négatif ne représentent rien de physique :
        // exclus. Ceux sans coût saisi sont comptés à part (total sous-
        // estimé, signalé dans l'interface). Les produits « en pause »
        // (plus en vente pour l'instant) ont un stock physique réel mais
        // sorti de la vente : valorisés à part.
        $stockValue = 0.0;
        $stockUnits = 0;
        $valuedLines = 0;
        $noCostLines = 0;
        $pausedValue = 0.0;
        $pausedUnits = 0;

        foreach ($rows as $r) {
            $stock = $r['theoretical'];
            if ($stock === null || (int) $stock <= 0) {
                continue;
            }
            $stockUnits += (int) $stock;
            $cost = ProductCost::costAt((string) $r['key'], date('Y-m-d'));
            if ($cost === null) {
                $noCostLines++;
                continue;
            }
            $stockValue += (int) $stock * (float) $cost;
            $valuedLines++;
        }

        foreach ($discontinuedRows as $d) {
            $stock = $d['theoretical'];
            if ($stock === null || (int) $stock <= 0) {
                continue;
            }
            $pausedUnits += (int) $stock;
            $cost = ProductCost::costAt((string) $d['key'], date('Y-m-d'));
            if ($cost !== null) {
                $pausedValue += (int) $stock * (float) $cost;
            }
        }

        $this->renderAdmin('admin/compta/inventory', [
            'title'            => 'Inventaire',
            'user'             => $user,
            'rows'             => $rows,
            'discontinuedRows' => $discontinuedRows,
            'history'          => InventoryCount::history(50),
            'gaps'             => InventoryCount::recentGaps(30),
            'keyStats'         => $keyStats,
            'mergeDupes'       => AliasSuggester::groupDuplicates(array_keys($keyStats)),
            'stockValue'       => round($stockValue, 2),
            'stockUnits'       => $stockUnits,
            'valuedLines'      => $valuedLines,
            'noCostLines'      => $noCostLines,
            'pausedValue'      => round($pausedValue, 2),
            'pausedUnits'      => $pausedUnits,
            'sweep'            => $sweep,
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
     * Les produits « plus en vente pour l'instant » (pause saisonnière)
     * restent affichés en fin de liste, grisés et sans saisie : un produit
     * « disparu » serait pris pour un oubli. Le rétablissement reste dans
     * la page Inventaire (groupe Système).
     */
    public function blindCount(): void
    {
        $user = $this->guardAdminArea();

        // Uniquement les clés produits : ni stock, ni écart, ni date de
        // dernier comptage (aucune fuite du théorique). Les produits en
        // pause sont gardés (flag paused) mais sans champ de saisie — la
        // sauvegarde les ignore toujours côté serveur (saveBlindCount).
        $pausedKeys = array_flip(ProductDiscontinued::keys());
        $active = [];
        $paused = [];
        foreach (Sale::distinctProducts() as $key) {
            $k = (string) $key;
            if ($k === '') {
                continue;
            }
            if (isset($pausedKeys[$k])) {
                $paused[] = ['key' => $k, 'paused' => true];
            } else {
                $active[] = ['key' => $k, 'paused' => false];
            }
        }
        $byName = static fn(array $a, array $b): int => strcasecmp($a['key'], $b['key']);
        usort($active, $byName);
        usort($paused, $byName);
        $rows = array_merge($active, $paused);

        $this->renderAdmin('admin/compta/blind_count', [
            'title'         => 'Comptage inventaire',
            'user'          => $user,
            'rows'          => $rows,
            'kioskHub'      => Kiosk::url('/kiosque/comptage/'),
            'kioskInventaire' => Kiosk::url('/kiosque/comptage/inventaire/'),
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
     * Marque un produit « plus en vente pour l'instant » — pause de vente
     * TEMPORAIRE (ex. Redbull Summer hors été) : il sort du comptage à
     * l'aveugle, de la page Inventaire et du réappro, mais RIEN n'est
     * supprimé (ventes, stock, comptages et coûts conservés). Réversible
     * en un clic via resume() — section « Plus en vente » de l'Inventaire.
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
        // Aucune donnée n'est supprimée : le rétablissement (resume) est
        // immédiat et sans perte.
        $this->setFlash('success', "Produit mis en pause (« plus en vente pour l'instant ») — rien n'est supprimé : il sort de la grille de comptage, du comptage à l'aveugle et du réappro, et reste listé en bas de cette page (rétablissement en un clic).");
        redirect(url($back));
    }

    /**
     * Remet un produit en vente (retire le drapeau « plus en vente pour
     * l'instant ») : il réapparaît aussitôt dans les comptages et dans le
     * réappro, avec son stock théorique et son historique intacts.
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

        $this->setFlash('success', "Produit remis en vente — il réapparaît dans les comptages et le réappro, sans aucune perte de données.");
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
