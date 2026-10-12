<?php

declare(strict_types=1);

namespace App\Core\Compta;

use App\Models\ProductAlias;
use App\Models\ProductCost;

/**
 * Création d'achats EN LOT (une ligne par produit, une course entière en
 * un seul appel) — cœur métier partagé par la page Achats admin
 * (AdminStockController::savePurchasesBulk) et le livre comptable
 * kiosque (KioskComptageController::ledgerPurchasesSave).
 *
 * Concentre tout ce qui est MÉTIER : validation ligne par ligne (mêmes
 * messages d'erreur FR que la page Achats), création de l'achat (stock
 * de référence ajusté par Purchase::create), lot de coût de revient
 * (ProductCost, lié à l'achat pour la suppression en cascade), synchro
 * carte (ProductAutoSync::ensureKeys) et invalidation du cache public
 * (StockPublic). La redirection, le flash et l'audit restent à la
 * charge du contrôleur appelant (mécanismes différents : session admin
 * vs jeton kiosque).
 *
 * Apprentissage des appellations : quand une ligne issue d'un scan
 * porte son libellé facture brut (« MONSTER PIPELINE PUNCH 24X0,5L »)
 * et que la clé saisie/corrigée est différente (« Monster rose »),
 * l'alias est retenu (product_aliases) — au prochain scan du même
 * libellé, ScanCatalogMatcher proposera directement le nom court.
 * Jamais bloquant pour l'enregistrement.
 *
 * Comportement STRICTEMENT identique à l'ancienne implémentation inline
 * du contrôleur admin : mêmes validations, mêmes arrondis (3 décimales),
 * mêmes messages, mêmes effets de bord.
 */
final class PurchaseSaver
{
    /** Taux de TVA autorisés pour les achats. */
    private const VAT_RATES = [20.0, 10.0, 5.5, 2.1, 0.0];

    /**
     * Enregistre une grille d'achats (une ligne par produit).
     *
     * @param array<string,mixed> $header En-tête commun à toutes les lignes :
     *   - purchased_at   : « YYYY-MM-DD » ('' = aujourd'hui) ;
     *   - supplier       : fournisseur (libellé libre) ;
     *   - invoice_number : n° de facture/ticket partagé par la course ;
     *   - notes          : notes communes (optionnel) ;
     *   - amount_basis   : 'ht' (TVA à ajouter, défaut) | 'ttc' (TVA incluse) ;
     *   - update_cost    : bool — chaque achat ouvre un lot de coût de revient ;
     *   - vat_raw        : taux d'en-tête BRUT ('' = montants « déjà TTC »,
     *     '5.5'…) — validé ligne par ligne (∈ VAT_RATES) ;
     *   - vat_per_line   : bool — les lignes portent leur propre taux brut
     *     (facture à TVA mixte) ; une case vide retombe sur vat_raw ;
     *   - created_by     : auteur ('' / null = aucun) — id utilisateur en
     *     admin, trace « kiosque … » en kiosque.
     *   - receipt_path   : justificatif stocké au scan (optionnel) —
     *     chemin relatif sous /assets renvoyé par ReceiptStorage,
     *     contrôlé par receiptPathOrNull (toute autre valeur ignorée).
     * @param list<array<string,mixed>> $lines Lignes alignées :
     *   [{key, qty, total_raw, vat_raw, no_stock, raw_label}] — key =
     *   libellé produit, qty = quantité (entier), total_raw = montant
     *   saisi BRUT (« 18,60 »), vat_raw = taux BRUT de la ligne
     *   ('' = hérite), no_stock = achat comptabilisé mais hors stock,
     *   raw_label = libellé FACTURE d'origine de la ligne (scan) quand
     *   il diffère de la clé — base de l'apprentissage d'alias.
     *
     * @return array{inserted:int, errors:list<string>, message:string, products:int, no_stock:int, aliases:int}
     *   inserted = nombre d'achats réellement créés ; errors = motifs
     *   d'ignorance ligne par ligne (« clé : raison ») ; message = texte
     *   FR complet (flash admin / JSON kiosque) : erreur agrégée si rien
     *   n'a été créé, récapitulatif sinon ; products = clés produit
     *   DISTINCTES créées ; no_stock = lignes « hors stock » créées
     *   (les deux derniers alimentent l'audit des contrôleurs) ;
     *   aliases = appellations retenues (libellé facture -> clé).
     */
    public static function save(array $header, array $lines): array
    {
        $purchasedAt = trim((string) ($header['purchased_at'] ?? ''));
        if ($purchasedAt === '') {
            $purchasedAt = date('Y-m-d');
        }
        $supplier = trim((string) ($header['supplier'] ?? ''));
        $invoiceNumber = trim((string) ($header['invoice_number'] ?? ''));
        $notes = trim((string) ($header['notes'] ?? ''));
        $updateCost = (bool) ($header['update_cost'] ?? false);

        // '' = montants saisis déjà TTC (pas de TVA à calculer), sinon taux en %.
        $vatRaw = trim((string) ($header['vat_raw'] ?? ''));
        $vatPerLine = (bool) ($header['vat_per_line'] ?? false);
        $createdBy = $header['created_by'] ?? null;
        $receiptPath = self::receiptPathOrNull($header['receipt_path'] ?? null);

        // Base des montants saisis : « ht » (TVA à ajouter, défaut) ou
        // « ttc » (TVA déjà incluse — HT déduit du taux choisi).
        $basis = (string) ($header['amount_basis'] ?? 'ht') === 'ttc' ? 'ttc' : 'ht';

        // Taux par ligne (facture à TVA mixte) : null si l'en-tête ne
        // déclare pas de mode par ligne (taux d'en-tête pour toutes).
        $lineVatRates = $vatPerLine
            ? array_map(static fn (array $l): string => (string) ($l['vat_raw'] ?? ''), $lines)
            : null;

        $inserted = 0;
        $noStockInserted = 0;
        $products = [];
        $errors = [];
        $toLearn = [];

        foreach (array_values($lines) as $i => $line) {
            $line = is_array($line) ? $line : [];
            $key = trim((string) ($line['key'] ?? ''));
            $amountRaw = trim((string) ($line['total_raw'] ?? ''));
            // Case « hors stock » de la ligne.
            $noStock = (bool) ($line['no_stock'] ?? false);

            // Ligne totalement vide (jamais remplie) : ignorée sans bruit.
            if ($key === '' && $amountRaw === '') {
                continue;
            }

            $reason = self::createOne([
                'purchased_at'   => $purchasedAt,
                'product_key'    => $key,
                'quantity'       => (string) ($line['qty'] ?? ''),
                'total_amount'   => $amountRaw,
                'vat_rate'       => self::lineVatRaw($lineVatRates, $i, $vatRaw),
                'amount_basis'   => $basis,
                'update_cost'    => $updateCost ? '1' : '',
                'no_stock'       => $noStock,
                'supplier'       => $supplier,
                'invoice_number' => $invoiceNumber,
                'notes'          => $notes,
                'created_by'     => $createdBy,
                'receipt_path'   => $receiptPath,
            ]);

            if ($reason === '') {
                $inserted++;
                if ($noStock) {
                    $noStockInserted++;
                }
                $products[$key] = true;

                // Apprentissage : libellé facture d'origine (scan) vs clé
                // retenue — dédupliqué par libellé normalisé.
                $learn = self::aliasToLearn((string) ($line['raw_label'] ?? ''), $key);
                if ($learn !== []) {
                    $toLearn[self::spaceless($learn['raw_description'])] = $learn;
                }
            } else {
                $errors[] = ($key !== '' ? $key : '(sans nom)') . ' : ' . $reason;
            }
        }

        if ($inserted === 0) {
            $message = $errors === []
                ? 'Aucun achat saisi.'
                : 'Aucun achat enregistré — ' . implode(' · ', $errors);

            return ['inserted' => 0, 'errors' => $errors, 'message' => $message, 'products' => 0, 'no_stock' => 0, 'aliases' => 0];
        }

        // Retient les appellations apprises (une par libellé, sans
        // écraser un alias existant pointant déjà vers la même clé).
        // Jamais bloquant : l'apprentissage ne doit pas faire échouer
        // l'enregistrement des achats.
        $aliasesLearned = 0;
        $aliasDetails = [];
        foreach ($toLearn as $learn) {
            try {
                $existing = ProductAlias::findByRaw($learn['raw_description']);
                if ($existing !== null && (string) $existing['product_key'] === $learn['product_key']) {
                    continue;
                }
                if (ProductAlias::save($learn) !== '') {
                    $aliasesLearned++;
                    $aliasDetails[] = $learn;
                }
            } catch (\Throwable) {
                // Alias secondaire : on passe.
            }
        }

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

        // Message adapté : « stock mis à jour » seulement si au moins une
        // ligne est réellement entrée en stock.
        $allNoStock = $noStockInserted === $inserted;
        $message = sprintf(
            '%d achat%s enregistré%s pour %d produit%s — %s',
            $inserted,
            $inserted > 1 ? 's' : '',
            $inserted > 1 ? 's' : '',
            count($products),
            count($products) > 1 ? 's' : '',
            $allNoStock ? 'stock inchangé (hors stock).' : 'stock mis à jour.'
        );
        if ($noStockInserted > 0 && !$allNoStock) {
            $message .= sprintf(' %d ligne(s) hors stock (stock inchangé).', $noStockInserted);
        }
        if ($invoiceNumber !== '') {
            $message .= ' Facture : ' . $invoiceNumber . '.';
        }
        if ($errors !== []) {
            $message .= ' Lignes ignorées : ' . implode(' · ', $errors);
        }
        if ($sync['created'] !== []) {
            $message .= ' Carte mise à jour automatiquement : ' . implode(', ', $sync['created']) . '.';
        }
        if ($aliasesLearned > 0) {
            $shown = array_slice($aliasDetails, 0, 3);
            $pairs = array_map(
                static fn (array $a): string => '« ' . $a['raw_description'] . ' » → « ' . $a['product_key'] . ' »',
                $shown
            );
            $message .= sprintf(
                ' %d appellation%s retenue%s : %s%s — visible%s dans le Mapping libellés.',
                $aliasesLearned,
                $aliasesLearned > 1 ? 's' : '',
                $aliasesLearned > 1 ? 's' : '',
                implode(' · ', $pairs),
                count($aliasDetails) > 3 ? '…' : '',
                $aliasesLearned > 1 ? 's' : ''
            );
        }

        return [
            'inserted' => $inserted,
            'errors'   => $errors,
            'message'  => $message,
            'products' => count($products),
            'no_stock' => $noStockInserted,
            'aliases'  => $aliasesLearned,
        ];
    }

    /**
     * Appellation à retenir pour une ligne d'achat issue d'un scan :
     * renvoie le couple [raw_description => libellé facture,
     * product_key => clé retenue] quand le libellé d'origine est
     * renseigné ET différent de la clé (comparaison normalisée :
     * casse, accents, séparateurs et espaces ignorés — même règle que
     * ScanCatalogMatcher), [] sinon (pas de libellé, clé inchangée ou
     * simple variante d'écriture de la même clé).
     *
     * Méthode pure (aucun accès POST/DB) : testée unitairement.
     *
     * @return array{raw_description:string, product_key:string}|array<never>
     */
    public static function aliasToLearn(string $rawLabel, string $productKey): array
    {
        $raw = trim($rawLabel);
        $key = trim($productKey);
        if ($raw === '' || $key === '') {
            return [];
        }

        return self::spaceless($raw) !== self::spaceless($key)
            ? ['raw_description' => $raw, 'product_key' => $key]
            : [];
    }

    /**
     * Clé normalisée sans espaces (« MONSTER PIPELINE PUNCH » =
     * « Monster pipeline punch ») — même repliement que
     * ScanCatalogMatcher::spaceless (AliasSuggester::normalizeKey
     * + retrait des espaces).
     */
    private static function spaceless(string $value): string
    {
        return str_replace(' ', '', AliasSuggester::normalizeKey($value));
    }

    /**
     * Chemin de justificatif accepté pour un achat (méthode pure,
     * testée) : uniquement un fichier stocké par ReceiptStorage sous
     * « uploads/receipts/ » (nom hexadécimal aléatoire + extension
     * sûre). Tout autre valeur (chemin arbitraire, ../, URL…) est
     * rejetée — null. Le POST utilisateur n'est jamais une source
     * de confiance pour un chemin de fichier.
     */
    public static function receiptPathOrNull(mixed $value): ?string
    {
        $path = trim((string) ($value ?? ''));
        if ($path === '') {
            return null;
        }

        return preg_match('#^uploads/receipts/[a-f0-9]{24}\.(pdf|jpe?g|png|webp)$#', $path) === 1
            ? $path
            : null;
    }

    /**
     * Taux de TVA BRUT d'une ligne du lot : la valeur du tableau
     * « vat_rate[] » quand elle est renseignée, sinon le taux d'en-tête
     * ('' = pas de décomposition TVA — montant « déjà TTC »). La
     * validation du taux (∈ VAT_RATES) reste dans createOne() : un taux
     * hors liste remonte comme erreur de la ligne.
     *
     * Méthode pure (aucun accès POST/DB) : testée unitairement.
     *
     * @param array<int,string>|null $lineRates Taux bruts par ligne
     *                                          (null = pas de tableau :
     *                                          taux d'en-tête partout).
     * @param int    $index     Indice de la ligne (aligné sur product_key[]).
     * @param string $headerRaw Taux d'en-tête brut ('' = aucun).
     */
    public static function lineVatRaw(?array $lineRates, int $index, string $headerRaw): string
    {
        if ($lineRates === null) {
            return $headerRaw;
        }

        $raw = trim((string) ($lineRates[$index] ?? ''));

        return $raw !== '' ? $raw : $headerRaw;
    }

    /**
     * Crée UN achat (et son lot de coût optionnel) à partir d'un jeu de
     * champs — cœur partagé de la saisie en lot.
     *
     * Sémantique du montant : la saisie fait foi, dans la base choisie.
     * « amount_basis » = « ht » (défaut) : le montant est HT, le TTC est
     * calculé avec le taux ; « ttc » : le montant est déjà TTC (ticket de
     * caisse), le HT est déduit du taux (TTC / (1 + taux/100)) et la TVA
     * apparaît décomposée. Sans taux (''), le montant est pris tel quel
     * (HT = TTC — comportement historique).
     *
     * @param array<string,mixed> $data purchased_at, product_key,
     *                                  quantity, total_amount, vat_rate
     *                                  ('' = sans décomposition TVA),
     *                                  amount_basis ('ht'|'ttc'),
     *                                  update_cost, supplier,
     *                                  invoice_number (n° de facture/ticket
     *                                  du fournisseur, commun à la course),
     *                                  notes, created_by, no_stock
     *                                  (case « hors stock » : compta sans
     *                                  stock)
     *
     * @return string '' si l'achat est créé, sinon le motif d'erreur
     *                (affiché ligne par ligne dans le flash).
     */
    private static function createOne(array $data): string
    {
        $purchasedAt = trim((string) ($data['purchased_at'] ?? ''));
        if ($purchasedAt === '') {
            $purchasedAt = date('Y-m-d');
        }
        $productKey = trim((string) ($data['product_key'] ?? ''));
        $quantity = (int) ($data['quantity'] ?? 0);
        $totalAmount = parseFrenchFloat((string) ($data['total_amount'] ?? ''));

        // '' = montant saisi sans TVA (HT = TTC), sinon taux en %.
        $vatRaw = trim((string) ($data['vat_rate'] ?? ''));
        $vatRate = null;
        if ($vatRaw !== '') {
            $candidate = parseFrenchFloat($vatRaw);
            if (!in_array($candidate, self::VAT_RATES, true)) {
                return 'Taux de TVA invalide (taux français : 20, 10, 5,5, 2,1 ou 0).';
            }
            $vatRate = $candidate;
        }

        // Base du montant saisi : HT (TVA à ajouter) ou TTC (TVA incluse).
        $isTtcBasis = ($data['amount_basis'] ?? 'ht') === 'ttc';

        if ($productKey === '') {
            return 'produit manquant';
        }
        if ($quantity < 1) {
            return 'quantité invalide';
        }
        if ($totalAmount <= 0.0) {
            return 'montant invalide';
        }

        // Le montant saisi fait foi, dans sa base ; l'autre côté est déduit.
        $total = round($totalAmount, 3);
        if ($vatRate === null) {
            $totalHt = $total;
            $totalTtc = $total;
        } elseif ($isTtcBasis) {
            $totalTtc = $total;
            $totalHt = round($total / (1 + $vatRate / 100), 3);
        } else {
            $totalHt = $total;
            $totalTtc = round($total * (1 + $vatRate / 100), 3);
        }

        // Coût unitaire dérivé (HT) : sert au lot de coût de revient et
        // à l'audit.
        $unitCost = round($totalHt / $quantity, 3);

        $id = \App\Models\Purchase::create([
            'purchased_at'   => $purchasedAt,
            'product_key'    => $productKey,
            'quantity'       => $quantity,
            'total_ht'       => $totalHt,
            'total_ttc'      => $isTtcBasis && $vatRate !== null ? $totalTtc : null,
            'vat_rate'       => $vatRate,
            'no_stock'       => !empty($data['no_stock']),
            'supplier'       => trim((string) ($data['supplier'] ?? '')),
            'invoice_number' => trim((string) ($data['invoice_number'] ?? '')),
            'notes'          => trim((string) ($data['notes'] ?? '')),
            'created_by'     => $data['created_by'] ?? null,
            'receipt_path'   => self::receiptPathOrNull($data['receipt_path'] ?? null),
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
            $costTtc = round($totalTtc / $quantity, 3);
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
}
