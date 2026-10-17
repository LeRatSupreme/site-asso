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
use App\Core\Compta\ReceiptStorage;
use App\Core\Compta\StockPublic;
use App\Core\Logger;
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

        // Libellé facture d'origine de chaque ligne (scan) : sert à
        // retenir l'appellation libellé -> clé (PurchaseSaver).
        $rawLabels = is_array($_POST['raw_label'] ?? null) ? $_POST['raw_label'] : [];

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
                'raw_label' => trim((string) ($rawLabels[$i] ?? '')),
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
            // Justificatif conservé au scan (optionnel — contrôlé par
            // PurchaseSaver, toute valeur arbitraire est ignorée).
            'receipt_path'   => trim((string) ($_POST['receipt_path'] ?? '')),
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
            'aliases_learned' => $res['aliases'] ?? 0,
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
     *
     * Option « ne pas corriger le stock » (POST keep_stock = « 1 ») :
     * pour une resaisie de facture déjà comptabilisée dont le stock
     * physique est correct — l'achat part, le stock de référence reste
     * tel quel (aucun ProductStock::adjust, même pour un achat en stock).
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

        // Case « ne pas corriger le stock » : cochée (« 1 ») ou absente.
        $keepStock = trim((string) ($_POST['keep_stock'] ?? '')) === '1';

        $summary = $this->deletePurchaseLines([$purchase], $keepStock);

        StockPublic::invalidate();

        $this->audit('compta.purchase.delete', 'purchase', $id, [
            'product_key'      => (string) $purchase['product_key'],
            'quantity'         => (int) $purchase['quantity'],
            'lots_deleted'     => $summary['lots_deleted'],
            'stock_adjusted'   => $summary['stock_adjusted'],
            'no_stock'         => !empty($purchase['no_stock']),
            'keep_stock'       => $keepStock,
            'stock_kept_lines' => $summary['stock_kept_lines'],
        ]);

        $noStock = !empty($purchase['no_stock']);
        $flash = sprintf(
            'Achat supprimé — stock de référence ajusté (−%d).',
            (int) $purchase['quantity']
        );
        if ($keepStock) {
            $flash = 'Achat supprimé — stock inchangé (conservé à la demande).';
        } elseif ($noStock) {
            $flash = 'Achat supprimé — stock inchangé (achat hors stock).';
        }
        if ($summary['lots_deleted'] > 0) {
            $flash .= sprintf(' %d lot(s) de coût lié(s) supprimé(s), lot antérieur réouvert.', $summary['lots_deleted']);
        }
        $this->setFlash('success', $flash);
        redirect(url('/admin/compta/achats'));
    }

    /**
     * Cascade de suppression d'une ou plusieurs lignes d'achat — le cœur
     * commun de deletePurchase() (une ligne) et deletePurchaseGroup()
     * (toutes les lignes d'une facture). Pour chaque ligne, exactement la
     * cascade historique : lots de coût liés supprimés (lot antérieur
     * réouvert par ProductCost::delete), ligne retirée, contre-passation
     * du stock de référence (sauf achat « hors stock »).
     *
     * Option keepStock (case « ne pas corriger le stock ») : AUCUN
     * ProductStock::adjust n'est passé, même pour un achat en stock —
     * les lignes concernées sont comptées dans stock_kept_lines. Le
     * comportement par défaut (false) reste strictement identique.
     *
     * L'invalidation du cache public et l'audit restent à la charge de
     * l'appelant (une seule invalidation / une seule entrée d'audit pour
     * tout le groupe). Le justificatif n'est PAS supprimé du disque : une
     * même photo peut être partagée par plusieurs lignes.
     *
     * @param list<array<string,mixed>> $rows Lignes complètes lues AVANT
     *                                        suppression (Purchase::find /
     *                                        Purchase::findByIds).
     *
     * @return array{lines:int, lots_deleted:int, stock_adjusted:int, no_stock_lines:int, stock_kept_lines:int, ids:list<string>}
     */
    private function deletePurchaseLines(array $rows, bool $keepStock = false): array
    {
        $lotsDeleted = 0;
        $stockAdjusted = 0;
        $noStockLines = 0;
        $stockKeptLines = 0;
        $ids = [];

        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $productKey = (string) $row['product_key'];
            $quantity = (int) $row['quantity'];

            // Achat « hors stock » : aucun stock n'avait été ajouté à la
            // création — rien à contre-passé ici.
            $noStock = !empty($row['no_stock']);

            // Cascade : supprime les lots de coût liés à l'achat ; si l'un
            // d'eux était « en cours », le lot antérieur est réouvert
            // (ProductCost::delete) pour ne pas casser la chaîne de coûts.
            $lotsDeleted += ProductCost::deleteByPurchase($id);

            Purchase::delete($id);

            // Contre-passation du stock de référence : ajusté de +quantity
            // à la création de l'achat — sauf achat « hors stock » (rien
            // n'avait été ajouté) ou stock volontairement conservé
            // (option « ne pas corriger le stock » : compteur dédié).
            if (!$noStock && !$keepStock) {
                ProductStock::adjust($productKey, -$quantity);
                $stockAdjusted += -$quantity;
            } elseif (!$noStock) {
                $stockKeptLines++;
            } else {
                $noStockLines++;
            }

            $ids[] = $id;
        }

        return [
            'lines'            => count($ids),
            'lots_deleted'     => $lotsDeleted,
            'stock_adjusted'   => $stockAdjusted,
            'no_stock_lines'   => $noStockLines,
            'stock_kept_lines' => $stockKeptLines,
            'ids'              => $ids,
        ];
    }

    /**
     * Supprime un GROUPE d'achats en un seul POST (toutes les lignes
      * d'une même facture — ids séparés par des virgules) : exactement la
      * cascade de deletePurchase() appliquée à chaque ligne, une seule
      * invalidation du cache public et une seule entrée d'audit pour tout
      * le groupe. Même option « ne pas corriger le stock » (POST
      * keep_stock = « 1 ») : le stock de référence reste tel quel.
      */
    public function deletePurchaseGroup(): void
    {
        $this->guardCompta();

        $ids = array_map('trim', explode(',', (string) ($_POST['ids'] ?? '')));
        $ids = array_values(array_unique(array_filter($ids, static fn (string $v): bool => $v !== '')));

        if ($ids === []) {
            $this->setFlash('error', 'Aucun achat sélectionné (ids manquants).');
            redirect(url('/admin/compta/achats'));
        }

        // Lecture AVANT suppression : la contre-passation (stock) et
        // l'audit ont besoin des données des achats. Les ids inconnus
        // (déjà supprimés ?) sont simplement absents du résultat.
        $rows = Purchase::findByIds($ids);
        if ($rows === []) {
            $this->setFlash('error', 'Achat(s) introuvable(s) (déjà supprimé(s) ?).');
            redirect(url('/admin/compta/achats'));
        }

        // Case « ne pas corriger le stock » : cochée (« 1 ») ou absente.
        $keepStock = trim((string) ($_POST['keep_stock'] ?? '')) === '1';

        $summary = $this->deletePurchaseLines($rows, $keepStock);

        StockPublic::invalidate();

        $this->audit('compta.purchase.delete', 'purchase', null, [
            'ids'              => $summary['ids'],
            'lines'            => $summary['lines'],
            'lots_deleted'     => $summary['lots_deleted'],
            'stock_adjusted'   => $summary['stock_adjusted'],
            'no_stock_lines'   => $summary['no_stock_lines'],
            'keep_stock'       => $keepStock,
            'stock_kept_lines' => $summary['stock_kept_lines'],
        ]);

        $allNoStock = $summary['no_stock_lines'] === $summary['lines'];
        $flash = $keepStock
            ? sprintf('%d achat(s) supprimé(s) — stock inchangé (conservé à la demande).', $summary['lines'])
            : ($allNoStock
                ? sprintf('%d achat(s) supprimé(s) — stock inchangé (achats hors stock).', $summary['lines'])
                : sprintf('%d achat(s) supprimé(s) — stock de référence ajusté (−%d).', $summary['lines'], -$summary['stock_adjusted']));
        if ($summary['lots_deleted'] > 0) {
            $flash .= sprintf(' %d lot(s) de coût lié(s) supprimé(s).', $summary['lots_deleted']);
        }
        $this->setFlash('success', $flash);
        redirect(url('/admin/compta/achats'));
    }

    /**
     * Met à jour l'en-tête commun d'un GROUPE d'achats en un seul POST
     * (toutes les lignes d'une même facture — ids séparés par des
     * virgules) : date, fournisseur, n° de facture et, optionnellement,
     * un nouveau justificatif (photo/PDF stocké par ReceiptStorage). Le
     * stock n'est PAS touché : quantités, montants et clés produit
     * restent inchangés.
     *
     * Justificatif : si un nouveau fichier est stocké, receipt_path est
     * appliqué à toutes les lignes ; l'ancien fichier est supprimé du
     * disque uniquement s'il était uniforme sur le groupe (un seul chemin
     * non vide) ET porté par aucune autre ligne (receiptUsedElsewhere).
     */
    public function updatePurchaseGroup(): void
    {
        $this->guardCompta();

        $ids = array_map('trim', explode(',', (string) ($_POST['ids'] ?? '')));
        $ids = array_values(array_unique(array_filter($ids, static fn (string $v): bool => $v !== '')));

        if ($ids === []) {
            $this->setFlash('error', 'Aucun achat sélectionné (ids manquants).');
            redirect(url('/admin/compta/achats'));
        }

        // Lignes lues AVANT mise à jour : l'ancien justificatif (pour son
        // remplacement éventuel) et l'audit en ont besoin.
        $rows = Purchase::findByIds($ids);
        if ($rows === []) {
            $this->setFlash('error', 'Achat(s) introuvable(s) (déjà supprimé(s) ?).');
            redirect(url('/admin/compta/achats'));
        }

        $validated = self::validatePurchaseGroupUpdate(
            (string) ($_POST['purchased_at'] ?? ''),
            (string) ($_POST['supplier'] ?? ''),
            (string) ($_POST['invoice_number'] ?? '')
        );
        if (!$validated['ok']) {
            $this->setFlash('error', (string) $validated['error']);
            redirect(url('/admin/compta/achats'));
        }

        // Justificatif optionnel (même mécanique que les dépenses) :
        // ReceiptStorage stocke le fichier, receiptPathOrNull contrôle le
        // chemin retourné. Erreur d'upload : flash + redirection (comme
        // AdminExpenseController::storeReceipt).
        $newReceipt = null;
        try {
            $newReceipt = ReceiptStorage::store();
        } catch (\RuntimeException $e) {
            Logger::error('compta: enregistrement du justificatif impossible', ['msg' => $e->getMessage()]);
            $this->setFlash('error', 'Le justificatif n\'a pas pu être enregistré. L\'incident a été journalisé.');
            redirect(url('/admin/compta/achats'));
        }

        // Ancien justificatif du groupe : retenu seulement s'il est
        // uniforme (un seul chemin distinct non vide sur les lignes).
        $oldReceipts = [];
        foreach ($rows as $row) {
            $p = trim((string) ($row['receipt_path'] ?? ''));
            if ($p !== '' && !in_array($p, $oldReceipts, true)) {
                $oldReceipts[] = $p;
            }
        }
        $oldReceipt = count($oldReceipts) === 1 ? $oldReceipts[0] : null;

        $fields = [
            'purchased_at'   => $validated['purchased_at'],
            'supplier'       => $validated['supplier'],
            'invoice_number' => $validated['invoice_number'],
        ];
        if ($newReceipt !== null) {
            $fields['receipt_path'] = $newReceipt;
        }

        $groupIds = array_map(static fn (array $r): string => (string) $r['id'], $rows);

        if (!Purchase::updateByIds($groupIds, $fields)) {
            $this->setFlash('error', 'Mise à jour impossible (achats introuvables ou champs invalides).');
            redirect(url('/admin/compta/achats'));
        }

        // L'ancien justificatif (uniforme sur le groupe) est supprimé du
        // disque seulement s'il n'est porté par aucune autre ligne et si
        // le nouveau fichier est différent (jamais un chemin arbitraire :
        // dossier receipts uniquement).
        if ($newReceipt !== null && $oldReceipt !== null && $oldReceipt !== $newReceipt
            && !Purchase::receiptUsedElsewhere($oldReceipt, $groupIds)) {
            $this->deleteReceiptFile($oldReceipt);
        }

        $this->audit('compta.purchase.update', 'purchase', null, [
            'ids'            => $groupIds,
            'lines'          => count($groupIds),
            'purchased_at'   => $validated['purchased_at'],
            'supplier'       => $validated['supplier'] !== '' ? $validated['supplier'] : null,
            'invoice_number' => $validated['invoice_number'] !== '' ? $validated['invoice_number'] : null,
            'receipt'        => $newReceipt,
            'old_receipt'    => $oldReceipt,
        ]);

        $flash = sprintf('%d ligne(s) d\'achat mise(s) à jour.', count($groupIds));
        if ($newReceipt !== null) {
            $flash .= ' Justificatif remplacé.';
        }
        $this->setFlash('success', $flash);
        redirect(url('/admin/compta/achats'));
    }

    /**
     * Validation pure des champs d'en-tête de la mise à jour d'un groupe
     * d'achats (méthode statique publique, aucun accès POST/DB : testée
     * unitairement). Date obligatoire au format « YYYY-MM-DD » (jour de
     * calendrier réel) ; fournisseur et n° de facture bornés à 255
     * caractères (taille des colonnes), trimés ; '' = champ vidé (sera
     * stocké NULL par le modèle).
     *
     * @return array{ok:bool, error:?string, purchased_at:string, supplier:string, invoice_number:string}
     */
    public static function validatePurchaseGroupUpdate(
        string $purchasedAt,
        string $supplier,
        string $invoiceNumber
    ): array {
        $purchasedAt = trim($purchasedAt);
        $supplier = trim($supplier);
        $invoiceNumber = trim($invoiceNumber);

        $date = \DateTime::createFromFormat('Y-m-d', $purchasedAt);
        if ($purchasedAt === '' || $date === false || $date->format('Y-m-d') !== $purchasedAt) {
            return [
                'ok'             => false,
                'error'          => 'Date invalide (format attendu AAAA-MM-JJ).',
                'purchased_at'   => $purchasedAt,
                'supplier'       => $supplier,
                'invoice_number' => $invoiceNumber,
            ];
        }

        if (mb_strlen($supplier) > 255) {
            return [
                'ok'             => false,
                'error'          => 'Fournisseur trop long (255 caractères maximum).',
                'purchased_at'   => $purchasedAt,
                'supplier'       => $supplier,
                'invoice_number' => $invoiceNumber,
            ];
        }

        if (mb_strlen($invoiceNumber) > 255) {
            return [
                'ok'             => false,
                'error'          => 'Numéro de facture trop long (255 caractères maximum).',
                'purchased_at'   => $purchasedAt,
                'supplier'       => $supplier,
                'invoice_number' => $invoiceNumber,
            ];
        }

        return [
            'ok'             => true,
            'error'          => null,
            'purchased_at'   => $purchasedAt,
            'supplier'       => $supplier,
            'invoice_number' => $invoiceNumber,
        ];
    }

    /**
     * Supprime du disque un fichier justificatif (chemin relatif sous
     * /assets renvoyé par ReceiptStorage). Jamais un chemin arbitraire :
     * seul le dossier receipts est concerné (même garde que la
     * suppression de dépense).
     */
    private function deleteReceiptFile(string $path): void
    {
        $file = realpath(AEIC_PUBLIC . '/assets/' . ltrim($path, '/'));
        $base = realpath(AEIC_PUBLIC . '/assets/uploads/receipts');
        if ($file !== false && $base !== false && str_starts_with($file, $base) && is_file($file)) {
            @unlink($file);
        }
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
        // Catégorie d'un produit = celle de ses ventes (même map que le
        // tri par rayon du comptage kiosque), « Non classé » sinon : la
        // valeur du stock est ventilée par catégorie dans l'interface.
        $catByKey = Sale::categoryByProduct();
        $catOf = static function (string $key) use ($catByKey): string {
            $cat = $catByKey[strtolower(trim($key))] ?? '';

            return $cat !== '' ? $cat : 'Non classé';
        };

        $stockValue = 0.0;
        $stockUnits = 0;
        $valuedLines = 0;
        $noCostLines = 0;
        $pausedValue = 0.0;
        $pausedUnits = 0;

        // Ventilation par catégorie : mêmes règles que les totaux (une
        // ligne par catégorie rencontrée, pauses dans la même ligne que
        // le rayon du produit).
        $byCategory = [];
        $catBucket = static function (string $cat) use (&$byCategory): void {
            if (!isset($byCategory[$cat])) {
                $byCategory[$cat] = [
                    'name'        => $cat,
                    'value'       => 0.0,
                    'units'       => 0,
                    'valued'      => 0,
                    'noCost'      => 0,
                    'pausedValue' => 0.0,
                    'pausedUnits' => 0,
                ];
            }
        };

        foreach ($rows as $r) {
            $stock = $r['theoretical'];
            if ($stock === null || (int) $stock <= 0) {
                continue;
            }
            $cat = $catOf((string) $r['key']);
            $catBucket($cat);
            $stockUnits += (int) $stock;
            $byCategory[$cat]['units'] += (int) $stock;
            $cost = ProductCost::costAt((string) $r['key'], date('Y-m-d'));
            if ($cost === null) {
                $noCostLines++;
                $byCategory[$cat]['noCost']++;
                continue;
            }
            $stockValue += (int) $stock * (float) $cost;
            $valuedLines++;
            $byCategory[$cat]['value'] += (int) $stock * (float) $cost;
            $byCategory[$cat]['valued']++;
        }

        foreach ($discontinuedRows as $d) {
            $stock = $d['theoretical'];
            if ($stock === null || (int) $stock <= 0) {
                continue;
            }
            $cat = $catOf((string) $d['key']);
            $catBucket($cat);
            $pausedUnits += (int) $stock;
            $byCategory[$cat]['pausedUnits'] += (int) $stock;
            $cost = ProductCost::costAt((string) $d['key'], date('Y-m-d'));
            if ($cost !== null) {
                $pausedValue += (int) $stock * (float) $cost;
                $byCategory[$cat]['pausedValue'] += (int) $stock * (float) $cost;
            }
        }

        // Ordre d'affichage : valeur décroissante puis unités, « Non
        // classé » toujours en dernier.
        uasort($byCategory, static function (array $a, array $b): int {
            if ($a['value'] !== $b['value']) {
                return $b['value'] <=> $a['value'];
            }
            if ($a['units'] !== $b['units']) {
                return $b['units'] <=> $a['units'];
            }

            return strcasecmp((string) $a['name'], (string) $b['name']);
        });
        if (isset($byCategory['Non classé'])) {
            $uncategorized = $byCategory['Non classé'];
            unset($byCategory['Non classé']);
            $byCategory['Non classé'] = $uncategorized;
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
            'stockByCategory'  => $byCategory,
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
            // Refus de validation : motif réel dans le log, message générique
            // affiché (les garde-fous du formulaire couvrent déjà les cas
            // courants).
            Logger::warning('inventory: fusion refusée', [
                'source' => $source,
                'target' => $target,
                'msg'    => $e->getMessage(),
            ]);
            $this->setFlash('error', 'Fusion refusée : la demande est invalide.');
            redirect(url('/admin/compta/inventaire'));
        } catch (\Throwable $e) {
            Logger::error('inventory: fusion impossible', [
                'source' => $source,
                'target' => $target,
                'msg'    => $e->getMessage(),
            ]);
            $this->setFlash('error', 'Fusion impossible : une erreur inattendue est survenue. L\'incident a été journalisé.');
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
