<?php

declare(strict_types=1);

/**
 * AEIC — Purge PONCTUELLE des écritures METRO du 16/10/2026 (one-off).
 *
 * Cibles EXACTES (hardcodées, volontairement étroites) :
 *  - Achats (table `purchases`) : purchased_at = 2026-10-16, supplier =
 *    METRO, invoice_number ∈ {0/0(087)0054/035408, 0/0(087)0051/024330}
 *    → 16 lignes attendues, 368,21 € TTC ;
 *  - Dépenses (table `expenses`) : spent_at = 2026-10-16, category =
 *    DIVERS, label = METRO, amount_ttc ∈ {141,79 ; 226,41} (comparaison
 *    à ± 0,005 — colonne DECIMAL(10,3)) → 3 lignes attendues,
 *    594,61 € TTC.
 *
 * Si les comptes trouvés ne correspondent PAS exactement (16 et 3), le
 * script affiche le détail de ce qu'il a trouvé et REFUSE de s'exécuter
 * (code retour 1) — aucun effet de bord dans ce cas.
 *
 * Suppression ACHAT : réplique exacte de la cascade
 * AdminStockController::deletePurchaseLines() avec keepStock = true —
 * pour chaque ligne ProductCost::deleteByPurchase($id) (le lot antérieur
 * est réouvert par ProductCost::delete si le lot supprimé était « en
 * cours ») puis Purchase::delete($id) ; JAMAIS de ProductStock::adjust
 * (le stock actuel est conservé) ; StockPublic::invalidate() une seule
 * fois à la fin ; une seule entrée d'audit « compta.purchase.delete »
 * groupée, identique à deletePurchaseGroup() avec keep_stock = true.
 *
 * Suppression DÉPENSE : réplique de AdminExpenseController::delete() —
 * justificatif supprimé du disque (même garde « dossier receipts
 * uniquement », jamais un chemin arbitraire), Expense::delete($id),
 * une entrée d'audit « compta.expense.delete » par dépense, comme le
 * contrôleur.
 *
 * DRY-RUN par défaut : liste ce qui serait supprimé + totaux, n'écrit
 * RIEN. --execute effectue réellement la suppression (transactionnelle :
 * tout ou rien). Idempotent : relancé ensuite, le script trouve 0 ligne
 * et affiche « Rien à faire » (code 0).
 *
 * Usage :
 *   php scripts/purge_achats_depenses_20261016_metro.php            (dry-run)
 *   php scripts/purge_achats_depenses_20261016_metro.php --execute  (réel)
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/database.php';

use App\Core\Compta\StockPublic;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\ProductCost;
use App\Models\Purchase;

// ── Cibles exactes (one-off : rien d'autre ne doit matcher) ─────────────

const TARGET_DATE  = '2026-10-16';
const TARGET_SUPPLIER = 'METRO';
const TARGET_INVOICES = ['0/0(087)0054/035408', '0/0(087)0051/024330'];
const TARGET_EXPENSE_CATEGORY = 'DIVERS';
const TARGET_EXPENSE_LABEL = 'METRO';
/** Montants TTC cibles des dépenses (comparés à ± AMOUNT_TOLERANCE). */
const TARGET_EXPENSE_AMOUNTS = [141.79, 226.41];
/** Tolérance de comparaison des montants (colonne DECIMAL(10,3)). */
const AMOUNT_TOLERANCE = 0.005;

/** Comptes attendus — le script REFUSE de s'exécuter si ≠. */
const EXPECTED_PURCHASE_LINES = 16;
const EXPECTED_PURCHASE_TTC   = 368.21;
const EXPECTED_EXPENSE_LINES  = 3;
const EXPECTED_EXPENSE_TTC    = 594.61;

$execute = in_array('--execute', $_SERVER['argv'] ?? [], true);

// ── Lecture des cibles (aucune écriture) ────────────────────────────────

try {
    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT * FROM purchases
          WHERE purchased_at = ? AND supplier = ? AND invoice_number IN (?, ?)
          ORDER BY invoice_number, product_key, id'
    );
    $stmt->execute([TARGET_DATE, TARGET_SUPPLIER, ...TARGET_INVOICES]);
    /** @var list<array<string,mixed>> $purchaseRows */
    $purchaseRows = $stmt->fetchAll();

    // Dépenses : d'abord toutes les candidates (date + catégorie + libellé),
    // puis filtrage des montants cibles en PHP (comparaison à tolérance).
    $stmt = $pdo->prepare(
        'SELECT * FROM expenses
          WHERE spent_at = ? AND category = ? AND label = ?
          ORDER BY amount_ttc, id'
    );
    $stmt->execute([TARGET_DATE, TARGET_EXPENSE_CATEGORY, TARGET_EXPENSE_LABEL]);
    /** @var list<array<string,mixed>> $expenseCandidates */
    $expenseCandidates = $stmt->fetchAll();

    $expenseRows = array_values(array_filter(
        $expenseCandidates,
        static function (array $r): bool {
            $amount = (float) ($r['amount_ttc'] ?? 0);
            foreach (TARGET_EXPENSE_AMOUNTS as $target) {
                if (abs($amount - $target) <= AMOUNT_TOLERANCE) {
                    return true;
                }
            }

            return false;
        }
    ));
} catch (Throwable $e) {
    fwrite(STDERR, 'ERREUR de connexion/lecture base : ' . $e->getMessage() . "\n");
    exit(1);
}

$purchaseTtc = 0.0;
foreach ($purchaseRows as $r) {
    $purchaseTtc += (float) ($r['total_ttc'] ?? 0);
}
$purchaseTtc = round($purchaseTtc, 3);

$expenseTtc = 0.0;
foreach ($expenseRows as $r) {
    $expenseTtc += (float) ($r['amount_ttc'] ?? 0);
}
$expenseTtc = round($expenseTtc, 3);

// ── Idempotence : déjà purgé → « Rien à faire » ─────────────────────────

if ($purchaseRows === [] && $expenseRows === []) {
    echo "Rien à faire : aucune écriture METRO du " . TARGET_DATE
        . " correspondant aux cibles (déjà purgées ?).\n";
    exit(0);
}

// ── Vérification des comptes — refus sans aucun effet de bord si ≠ ──────

$purchasesOk = count($purchaseRows) === EXPECTED_PURCHASE_LINES
    && abs($purchaseTtc - EXPECTED_PURCHASE_TTC) <= AMOUNT_TOLERANCE;
$expensesOk = count($expenseRows) === EXPECTED_EXPENSE_LINES
    && abs($expenseTtc - EXPECTED_EXPENSE_TTC) <= AMOUNT_TOLERANCE;

if (!$purchasesOk || !$expensesOk) {
    echo "==============================================================\n";
    echo " REFUS D'EXÉCUTION — les comptes ne correspondent pas.\n";
    echo " Aucune modification n'a été effectuée.\n";
    echo "==============================================================\n";
    printf(
        "  Achats   attendus : %d ligne(s), %s — trouvés : %d ligne(s), %s\n",
        EXPECTED_PURCHASE_LINES,
        formatPrice(EXPECTED_PURCHASE_TTC),
        count($purchaseRows),
        formatPrice($purchaseTtc)
    );
    printf(
        "  Dépenses attendues : %d ligne(s), %s — trouvées : %d ligne(s), %s\n",
        EXPECTED_EXPENSE_LINES,
        formatPrice(EXPECTED_EXPENSE_TTC),
        count($expenseRows),
        formatPrice($expenseTtc)
    );

    echo "\nDétail des ACHATS trouvés (id, produit, qté, TTC, facture) :\n";
    if ($purchaseRows === []) {
        echo "  (aucun)\n";
    }
    foreach ($purchaseRows as $r) {
        printf(
            "  [%s] %-32s x%-4d %12s  facture %s\n",
            (string) $r['id'],
            (string) $r['product_key'],
            (int) $r['quantity'],
            formatPrice((float) ($r['total_ttc'] ?? 0)),
            (string) ($r['invoice_number'] ?? '')
        );
    }

    echo "\nDétail des DÉPENSES candidates trouvées (id, libellé, montant, justificatif) :\n";
    if ($expenseCandidates === []) {
        echo "  (aucune)\n";
    }
    foreach ($expenseCandidates as $r) {
        $amount = (float) ($r['amount_ttc'] ?? 0);
        $inTarget = false;
        foreach (TARGET_EXPENSE_AMOUNTS as $target) {
            if (abs($amount - $target) <= AMOUNT_TOLERANCE) {
                $inTarget = true;
                break;
            }
        }
        printf(
            "  [%s] %-16s %12s  justificatif : %s%s\n",
            (string) $r['id'],
            (string) $r['label'],
            formatPrice($amount),
            ($r['receipt_path'] ?? '') !== '' ? (string) $r['receipt_path'] : '—',
            $inTarget ? '' : '   ← montant hors cible'
        );
    }

    exit(1);
}

// ── Listing commun (dry-run et exécution) ───────────────────────────────

echo ($execute ? "EXÉCUTION — suppression réelle\n" : "DRY-RUN — aucune écriture en base\n");
echo "==============================================================\n";
echo 'Cible achats   : ' . TARGET_DATE . ', fournisseur ' . TARGET_SUPPLIER
    . ', factures ' . implode(' / ', TARGET_INVOICES) . "\n";
echo 'Cible dépenses : ' . TARGET_DATE . ', catégorie ' . TARGET_EXPENSE_CATEGORY
    . ', libellé ' . TARGET_EXPENSE_LABEL . ', montants '
    . implode(' / ', array_map(static fn (float $v): string => formatPrice($v), TARGET_EXPENSE_AMOUNTS)) . "\n";

echo "\nACHATS à supprimer (" . count($purchaseRows) . " ligne(s)) — id, produit, qté, TTC, facture :\n";
foreach ($purchaseRows as $r) {
    printf(
        "  [%s] %-32s x%-4d %12s  facture %s\n",
        (string) $r['id'],
        (string) $r['product_key'],
        (int) $r['quantity'],
        formatPrice((float) ($r['total_ttc'] ?? 0)),
        (string) ($r['invoice_number'] ?? '')
    );
}
printf(
    "  Total achats : %d ligne(s), %s\n",
    count($purchaseRows),
    formatPrice($purchaseTtc)
);

echo "\nDÉPENSES à supprimer (" . count($expenseRows) . " ligne(s)) — id, libellé, montant, justificatif :\n";
foreach ($expenseRows as $r) {
    printf(
        "  [%s] %-16s %12s  justificatif : %s\n",
        (string) $r['id'],
        (string) $r['label'],
        formatPrice((float) ($r['amount_ttc'] ?? 0)),
        ($r['receipt_path'] ?? '') !== '' ? (string) $r['receipt_path'] : '—'
    );
}
printf(
    "  Total dépenses : %d ligne(s), %s\n",
    count($expenseRows),
    formatPrice($expenseTtc)
);

if (!$execute) {
    echo "\nDRY-RUN terminé — RIEN n'a été supprimé.\n";
    echo "Relancer avec --execute pour appliquer réellement la suppression.\n";
    exit(0);
}

// ── EXÉCUTION : cascade contrôleur, transactionnelle (tout ou rien) ─────

/**
 * Réplique exacte de AdminStockController::deletePurchaseLines() avec
 * keepStock = true : pour chaque ligne, ProductCost::deleteByPurchase()
 * (chaque lot supprimé « en cours » fait réouvrir le lot antérieur du
 * produit — mécanique interne de ProductCost::delete) puis
 * Purchase::delete(). JAMAIS de ProductStock::adjust : le stock actuel
 * est conservé (les lignes en stock sont comptées dans
 * stock_kept_lines, les achats « hors stock » dans no_stock_lines).
 *
 * @param list<array<string,mixed>> $rows Lignes lues AVANT suppression.
 *
 * @return array{lines:int, lots_deleted:int, stock_adjusted:int, no_stock_lines:int, stock_kept_lines:int, ids:list<string>}
 */
$purgePurchaseRows = static function (array $rows): array {
    $lotsDeleted = 0;
    $noStockLines = 0;
    $stockKeptLines = 0;
    $ids = [];

    foreach ($rows as $row) {
        $id = (string) ($row['id'] ?? '');
        if ($id === '') {
            continue;
        }

        // Cascade du contrôleur : lots de coût liés supprimés (lot
        // antérieur réouvert si le lot supprimé était « en cours »),
        // puis ligne d'achat retirée.
        $lotsDeleted += ProductCost::deleteByPurchase($id);

        if (!Purchase::delete($id)) {
            throw new RuntimeException('Suppression de l\'achat ' . $id . ' impossible.');
        }

        // keepStock = true : AUCUN ProductStock::adjust, même pour un
        // achat en stock — réplique exacte des compteurs du contrôleur.
        if (!empty($row['no_stock'])) {
            $noStockLines++;
        } else {
            $stockKeptLines++;
        }

        $ids[] = $id;
    }

    return [
        'lines'            => count($ids),
        'lots_deleted'     => $lotsDeleted,
        'stock_adjusted'   => 0,
        'no_stock_lines'   => $noStockLines,
        'stock_kept_lines' => $stockKeptLines,
        'ids'              => $ids,
    ];
};

/**
 * Même garde que AdminExpenseController::deleteReceiptFile() : chemin
 * relatif sous /assets renvoyé par ReceiptStorage, résolu par realpath
 * et accepté SEULEMENT s'il reste dans le dossier receipts — jamais un
 * chemin arbitraire (pas de traversal).
 */
$deleteReceiptFile = static function (string $path): void {
    $file = realpath(AEIC_PUBLIC . '/assets/' . ltrim($path, '/'));
    $base = realpath(AEIC_PUBLIC . '/assets/uploads/receipts');
    if ($file !== false && $base !== false && str_starts_with($file, $base)) {
        @unlink($file);
    }
};

$pdo->beginTransaction();
try {
    $summary = $purgePurchaseRows($purchaseRows);

    $expenseIds = [];
    foreach ($expenseRows as $row) {
        $id = (string) ($row['id'] ?? '');
        if ($id === '') {
            continue;
        }
        if (!Expense::delete($id)) {
            throw new RuntimeException('Suppression de la dépense ' . $id . ' impossible.');
        }
        $expenseIds[] = $id;
    }

    // Audit ACHAT groupé : mêmes clés que
    // AdminStockController::deletePurchaseGroup(), keep_stock = true.
    // user_id null : script CLI sans utilisateur connecté.
    AuditLog::log('compta.purchase.delete', null, 'purchase', null, [
        'ids'              => $summary['ids'],
        'lines'            => $summary['lines'],
        'lots_deleted'     => $summary['lots_deleted'],
        'stock_adjusted'   => $summary['stock_adjusted'],
        'no_stock_lines'   => $summary['no_stock_lines'],
        'keep_stock'       => true,
        'stock_kept_lines' => $summary['stock_kept_lines'],
    ]);

    // Audit DÉPENSE : une entrée par ligne, identique au contrôleur.
    foreach ($expenseIds as $id) {
        AuditLog::log('compta.expense.delete', null, 'expense', $id);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'ERREUR pendant la suppression — transaction annulée (rien de modifié) : ' . $e->getMessage() . "\n");
    exit(1);
}

// Justificatifs des dépenses : supprimés du disque APRÈS le commit (une
// suppression de fichier est irréversible ; la base reste la source de
// vérité) — même garde « dossier receipts uniquement » que le contrôleur.
foreach ($expenseRows as $row) {
    $receipt = trim((string) ($row['receipt_path'] ?? ''));
    if ($receipt !== '' && in_array((string) ($row['id'] ?? ''), $expenseIds, true)) {
        $deleteReceiptFile($receipt);
    }
}

// Cache carte publique : une seule invalidation, comme le contrôleur.
StockPublic::invalidate();

// ── Résumé final ────────────────────────────────────────────────────────

echo "\n==============================================================\n";
printf(
    "RÉSUMÉ : %d ligne(s) d'achat supprimée(s) (%s), %d dépense(s) supprimée(s) (%s).\n",
    $summary['lines'],
    formatPrice($purchaseTtc),
    count($expenseIds),
    formatPrice($expenseTtc)
);
printf(
    "Lots de coût liés supprimés : %d (lot antérieur réouvert le cas échéant).\n",
    $summary['lots_deleted']
);
echo "Stock inchangé (conservé — aucun ProductStock::adjust passé).\n";
echo "Cache carte publique invalidé ; entrées d'audit enregistrées.\n";
