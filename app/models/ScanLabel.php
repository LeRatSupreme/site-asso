<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Compta\ScanCatalogMatcher;

/**
 * File « libellés factures non rattachés » (table `scan_labels`).
 *
 * CHAQUE libellé OCR extrait d'un scan de facture (ex. « EVIEN 50CL »,
 * le vrai produit étant « Eau ») est mémorisé avec son nombre
 * d'occurrences : la page « Ajouter une appellation » le propose
 * pré-rempli dans son formulaire, et l'entrée disparaît dès qu'un
 * alias UTILISATEUR existe pour ce libellé exact — ou qu'il devient
 * lui-même une clé exacte du catalogue. Les appariements flous
 * (troncatures, conditionnements…) ne purgent PAS : c'est
 * l'utilisateur qui nomme, jamais l'heuristique. Écriture jamais
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
     * TOUS les libellés d'un scan (méthode pure, testée) : chaque
     * ligne portant un libellé — reconnue ou non —, dédupliquée,
     * ordre stable. Les libellés reconnus restent mémorisés : c'est
     * le formulaire « Nouvelles appellations » qui les montre
     * pré-remplis tant qu'aucun alias utilisateur ne les nomme.
     *
     * @param list<array<string,mixed>> $lines
     * @return list<string>
     */
    public static function allLabels(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $label = trim((string) ($line['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            if (!in_array($label, $out, true)) {
                $out[] = $label;
            }
        }

        return $out;
    }

    /**
     * Mémorise les libellés d'un scan (occurrence +1 si déjà connu,
     * création sinon). Silencieux en cas de panne.
     *
     * @param list<string> $labels
     */
    private static function upsertLabels(array $labels): void
    {
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
     * Mémorise TOUS les libellés d'un scan (reconnus et non reconnus).
     * Silencieux en cas de panne.
     *
     * @param list<array<string,mixed>> $lines
     */
    public static function recordAll(array $lines): void
    {
        self::upsertLabels(self::allLabels($lines));
    }

    /**
     * Mémorise les libellés non reconnus d'un scan (comportement
     * historique). Silencieux en cas de panne.
     *
     * @param list<array<string,mixed>> $lines
     */
    public static function recordUnmatched(array $lines): void
    {
        self::upsertLabels(self::unmatchedLabels($lines));
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
     * File « à classer » : libellés que l'utilisateur n'a pas encore
     * nommés. Les entrées résolues sont purgées au passage
     * (auto-nettoyage) selon la règle exacte de isResolved().
     *
     * @return list<array<string,mixed>>
     */
    public static function pending(): array
    {
        $out = [];
        foreach (self::all() as $row) {
            $label = (string) $row['label'];
            try {
                if (self::isResolved($label)) {
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
     * Un libellé de scan est-il résolu (donc purgé de la file) ?
     *
     * Règle EXACTE uniquement :
     *  (a) un ALIAS utilisateur existe pour ce libellé exact
     *      (ProductAlias::findByRaw, ou variante d'écriture comparée
     *      en clé normalisée sans espaces — casse, accents,
     *      séparateurs et espaces ignorés, comme la résolution du
     *      matcher) ;
     *  (b) OU le libellé correspond EXACTEMENT (même normalisation)
     *      à une clé existante du catalogue : fiches produits, alias,
     *      clés vendues/SumUp (ScanCatalogMatcher::exactCatalogKey).
     *
     * Les correspondances FLOUES (paliers heuristiques du matcher :
     * troncatures, « SLIM BTE 33CL », faute de frappe…) ne résolvent
     * PLUS : le libellé reste dans la file jusqu'à ce que
     * l'utilisateur crée lui-même l'appellation — l'heuristique ne
     * nomme jamais à sa place.
     */
    public static function isResolved(string $label): bool
    {
        $label = trim($label);
        if ($label === '') {
            return false;
        }

        // (a) Alias utilisateur exact (lecture directe, puis
        //     comparaison normalisée : « MONSTER ROSE » ~ « Monster Rose »).
        try {
            if (ProductAlias::findByRaw($label) !== null) {
                return true;
            }
            foreach (ProductAlias::all() as $alias) {
                if (ScanCatalogMatcher::sameKey((string) ($alias['raw_description'] ?? ''), $label)) {
                    return true;
                }
            }
        } catch (\Throwable) {
            // Alias illisibles : la règle catalogue suffit.
        }

        // (b) Clé de catalogue exacte (fiches, alias, clés vendues).
        return ScanCatalogMatcher::exactCatalogKey($label) !== null;
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
