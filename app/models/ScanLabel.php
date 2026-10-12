<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Compta\ScanCatalogMatcher;

/**
 * File « libellés factures non rattachés » (table `scan_labels`).
 *
 * Chaque libellé OCR qu'aucun produit du catalogue n'a reconnu
 * (ex. « EVIEN 50CL », le vrai produit étant « Eau ») est mémorisé
 * avec son nombre d'occurrences : la page « Ajouter une
 * appellation » le propose au rattachement, et l'entrée disparaît
 * dès qu'un alias (ou une fiche) la résout. Écriture jamais
 * bloquante : une panne de base ne doit pas faire échouer un scan.
 */
final class ScanLabel extends Model
{
    protected static string $table = 'scan_labels';

    /**
     * Libellés NON reconnus d'un scan (méthode pure, testée) :
     * lignes portant un libellé mais aucun produit reconnu
     * (« product » null ou absent), dédupliquées, ordre stable.
     *
     * @param list<array<string,mixed>> $lines
     * @return list<string>
     */
    public static function unmatchedLabels(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $label = trim((string) ($line['label'] ?? ''));
            if ($label === '' || ($line['product'] ?? null) !== null) {
                continue; // vide ou déjà reconnu
            }
            if (!in_array($label, $out, true)) {
                $out[] = $label;
            }
        }

        return $out;
    }

    /**
     * Mémorise les libellés non reconnus d'un scan : occurrence +1
     * si déjà connu, création sinon. Silencieux en cas de panne.
     *
     * @param list<array<string,mixed>> $lines
     */
    public static function recordUnmatched(array $lines): void
    {
        $labels = self::unmatchedLabels($lines);
        if ($labels === []) {
            return;
        }

        try {
            $pdo = self::pdo();
            $upd = $pdo->prepare(
                'UPDATE scan_labels SET occurrences = occurrences + 1, last_seen = NOW() WHERE label = ?'
            );
            $ins = $pdo->prepare(
                'INSERT INTO scan_labels (id, label) VALUES (?, ?)'
            );
            foreach ($labels as $label) {
                $upd->execute([$label]);
                if ($upd->rowCount() === 0) {
                    try {
                        $ins->execute(['scan_' . bin2hex(random_bytes(10)), $label]);
                    } catch (\Throwable) {
                        // Doublon concurrent : re-tenter l'incrément.
                        $upd->execute([$label]);
                    }
                }
            }
        } catch (\Throwable) {
            // Silencieux : le scan reste utilisable sans file.
        }
    }

    /**
     * File complète (toutes entrées, triée par fréquence).
     *
     * @return list<array<string,mixed>>
     */
    public static function all(): array
    {
        try {
            /** @var list<array<string,mixed>> $r */
            return self::pdo()
                ->query('SELECT * FROM scan_labels ORDER BY occurrences DESC, last_seen DESC')
                ->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * File « à classer » : libellés toujours inconnus du catalogue
     * (fiches, alias, clés SumUp). Les entrées désormais résolues
     * sont purgées au passage (auto-nettoyage).
     *
     * @return list<array<string,mixed>>
     */
    public static function pending(): array
    {
        $out = [];
        foreach (self::all() as $row) {
            $label = (string) $row['label'];
            try {
                if (ScanCatalogMatcher::match($label) !== null) {
                    self::deleteByLabel($label);
                    continue;
                }
            } catch (\Throwable) {
                // Catalogue indisponible : garder la ligne affichée.
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Supprime une entrée (silencieux en cas de panne).
     */
    public static function deleteByLabel(string $label): void
    {
        try {
            $stmt = self::pdo()->prepare('DELETE FROM scan_labels WHERE label = ?');
            $stmt->execute([$label]);
        } catch (\Throwable) {
            // Silencieux.
        }
    }
}
