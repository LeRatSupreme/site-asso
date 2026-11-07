<?php

declare(strict_types=1);

namespace App\Core\Compta;

/**
 * Garde-fou anti-doublons « expense × purchases » de la fusion « Saisir
 * une dépense » × « Achats » (invariant : UN ticket = UNE écriture, soit
 * `expenses`, soit `purchases` — jamais les deux).
 *
 * Liste les paires suspectes résiduelles (un même ticket comptabilisé
 * dans les DEUX registres) avec les deux signaux validés par l'audit
 * pré-fusion du 06/11/2026 (6 signaux croisés, 0 paire à l'époque) :
 *
 *  - « invoice_meme_jour » : une dépense et des achats portent le MÊME
 *    n° de facture le même jour (un n° de facture identifie un ticket
 *    unique — le flux buggé « Créer aussi ces achats » copiait toujours
 *    montant + facture) ;
 *  - « fournisseur_montant_jour » : sans n° de facture, une dépense du
 *    même jour qu'un groupe d'achats, dont le libellé cite le
 *    fournisseur et dont le montant ÉGALE la somme des achats de ce
 *    fournisseur ce jour-là.
 *
 * LECTURE SEULE (deux SELECT) : rapport de contrôle, jamais de
 * correction automatique. Consommé par scripts/compta_doublons_audit.php
 * (CLI admin) — tout pourrait aussi l'appeler pour un contrôle régulier.
 */
final class ExpensePurchaseDuplicateReport
{
    /**
     * Détecte les paires suspectes sur TOUTE la base.
     *
     * @return list<array<string,mixed>> une ligne par paire suspecte :
     *   {signal, expense_id, spent_at, label, amount_ttc, invoice_number?,
     *    supplier?, purchase_lines, purchases_total_ttc}
     */
    public static function detect(\PDO $pdo): array
    {
        return array_merge(
            self::sameInvoiceSameDay($pdo),
            self::supplierAmountSameDay($pdo)
        );
    }

    /**
     * Signal 1 — n° de facture identique le même jour (dépense × groupe
     * d'achats) : requête S1 de l'audit, inchangée.
     *
     * @return list<array<string,mixed>>
     */
    private static function sameInvoiceSameDay(\PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT e.id AS expense_id, e.spent_at, e.label, e.amount_ttc, e.invoice_number,
                    COUNT(*) AS purchase_lines, SUM(p.total_ttc) AS purchases_total_ttc
             FROM expenses e
             JOIN purchases p ON p.purchased_at = e.spent_at AND p.invoice_number = e.invoice_number
             WHERE e.invoice_number IS NOT NULL AND e.invoice_number <> ''
             GROUP BY e.id, e.spent_at, e.label, e.amount_ttc, e.invoice_number
             ORDER BY e.spent_at DESC, e.id"
        )->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'signal'     => 'invoice_meme_jour',
                'expense_id' => (string) $r['expense_id'],
                'spent_at'   => (string) $r['spent_at'],
                'label'      => (string) $r['label'],
                'amount_ttc' => (float) $r['amount_ttc'],
                'invoice_number' => (string) $r['invoice_number'],
                'purchase_lines' => (int) $r['purchase_lines'],
                'purchases_total_ttc' => round((float) $r['purchases_total_ttc'], 2),
            ];
        }

        return $out;
    }

    /**
     * Signal 2 — fournisseur cité dans le libellé + montant de la
     * dépense = Σ des achats de ce fournisseur le même jour (dépenses
     * sans n° de facture) : requête S2 de l'audit, inchangée.
     *
     * @return list<array<string,mixed>>
     */
    private static function supplierAmountSameDay(\PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT e.id AS expense_id, e.spent_at, e.label, e.amount_ttc,
                    p.supplier, p.s AS purchases_total_ttc
             FROM expenses e
             JOIN (SELECT purchased_at, supplier, SUM(total_ttc) AS s
                   FROM purchases
                   GROUP BY purchased_at, supplier) p
               ON p.purchased_at = e.spent_at AND p.s = e.amount_ttc
             WHERE (e.invoice_number IS NULL OR e.invoice_number = '')
               AND p.supplier IS NOT NULL AND p.supplier <> ''
               AND INSTR(LOWER(e.label), LOWER(p.supplier)) > 0
             ORDER BY e.spent_at DESC, e.id"
        )->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'signal'     => 'fournisseur_montant_jour',
                'expense_id' => (string) $r['expense_id'],
                'spent_at'   => (string) $r['spent_at'],
                'label'      => (string) $r['label'],
                'amount_ttc' => (float) $r['amount_ttc'],
                'supplier'   => (string) $r['supplier'],
                'purchase_lines' => null,
                'purchases_total_ttc' => round((float) $r['purchases_total_ttc'], 2),
            ];
        }

        return $out;
    }
}
