<?php

declare(strict_types=1);

/**
 * AEIC — Rapport anti-doublons « expense × purchases » (CLI, LECTURE SEULE).
 *
 * Garde-fou de la fusion « Saisir une dépense » × « Achats » (invariant :
 * un ticket = UNE écriture, soit expenses, soit purchases — jamais les
 * deux). Liste les paires suspectes résiduelles avec les deux signaux
 * validés par l'audit pré-fusion du 06/11/2026 (voir
 * App\Core\Compta\ExpensePurchaseDuplicateReport) :
 *   - n° de facture identique le même jour (dépense × groupe d'achats) ;
 *   - fournisseur cité dans le libellé + montant de la dépense = Σ des
 *     achats de ce fournisseur le même jour (dépense sans n° de facture).
 *
 * Usage :
 *   php scripts/compta_doublons_audit.php
 *
 * Code de sortie : 0 si aucune paire suspecte, 1 s'il en existe (utilisable
 * en contrôle périodique / pré-MEP).
 *
 * Garanties LECTURE SEULE : uniquement des SELECT, aucune écriture.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/database.php';

use App\Core\Compta\ExpensePurchaseDuplicateReport;

$pdo = db();

$nbExpenses = (int) $pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn();
$nbPurchases = (int) $pdo->query('SELECT COUNT(*) FROM purchases')->fetchColumn();
$pairs = ExpensePurchaseDuplicateReport::detect($pdo);

fwrite(STDOUT, "Anti-doublons expense × purchases — " . date('Y-m-d H:i:s') . "\n");
fwrite(STDOUT, sprintf(
    "Base : %d dépense(s) × %d ligne(s) d'achats — invariant : un ticket = une écriture.\n\n",
    $nbExpenses,
    $nbPurchases
));

if ($pairs === []) {
    fwrite(STDOUT, "Aucune paire suspecte détectée.\n");
    exit(0);
}

fwrite(STDOUT, sprintf("%d paire(s) suspecte(s) :\n\n", count($pairs)));
foreach ($pairs as $p) {
    $ref = ($p['signal'] === 'invoice_meme_jour')
        ? ('facture « ' . $p['invoice_number'] . ' »')
        : ('fournisseur « ' . $p['supplier'] . ' » (montant = Σ achats du jour)');
    fwrite(STDOUT, sprintf(
        "  [%s] %s — dépense « %s » (%s) : %s TTC\n             achats liés : %s TTC%s\n",
        (string) $p['signal'],
        (string) $p['spent_at'],
        (string) $p['label'],
        (string) $p['expense_id'],
        number_format((float) $p['amount_ttc'], 2, ',', ''),
        number_format((float) $p['purchases_total_ttc'], 2, ',', ''),
        $p['purchase_lines'] !== null ? sprintf(' sur %d ligne(s)', (int) $p['purchase_lines']) : ''
    ));
}
fwrite(STDOUT, "\nVérifier chaque paire puis corriger dans Opérations (Dépenses ou Achats) — ce rapport n'écrit jamais rien.\n");
exit(1);
