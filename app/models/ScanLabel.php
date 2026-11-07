<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Compta\ScanCatalogMatcher;
use App\Core\Compta\StockPublic;

/**
 * File « libellés factures non rattachés » (table `scan_labels`).
 *
 * CHAQUE libellé OCR extrait d'un scan de facture (ex. « EVIEN 50CL »,
 * le vrai produit étant « Eau ») est mémorisé avec son nombre
 * d'occurrences : la page « Ajouter une appellation » le propose
 * pré-rempli dans son formulaire, et l'entrée disparaît dès qu'un
 * alias UTILISATEUR existe pour ce libellé exact — ou qu'il devient
 * lui-même une clé exacte du catalogue HORS nom de facture (une fiche
 * auto-créée au nom long ne compte plus : c'est justement ce que
 * l'utilisateur doit renommer). Les appariements flous
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
     * RÈGLE ANTI-DOUBLONS D'APPELLATIONS : deux libellés de même forme
     * NORMALISÉE (« Coca cherry » 80 occurrences et « Coca_cherry » 6)
     * ne font QU'UNE seule entrée — l'événement courant incrémente la
     * ligne canonique du groupe (la plus fréquente) et les lignes sœurs
     * redondantes sont FUSIONNÉES dedans (occurrences additionnées,
     * première vue conservée, trace 'alias.fusion' 80+6→86) — jamais
     * supprimées sans absorption.
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
            foreach ($labels as $label) {
                self::memoriserUnLibelle($pdo, trim((string) $label));
            }
        } catch (\Throwable) {
            // Silencieux : le scan reste utilisable sans file.
        }
    }

    /**
     * Mémorise UN libellé : incrément de la ligne canonique de son
     * groupe de forme normalisée (fusion des sœurs redondantes au
     * passage), ou création si le libellé est inconnu à toute écriture
     * près.
     */
    private static function memoriserUnLibelle(\PDO $pdo, string $label): void
    {
        $label = trim($label);
        if ($label === '') {
            return;
        }

        $canonique = self::ligneCanonique($pdo, $label);
        if ($canonique === null) {
            try {
                $pdo->prepare('INSERT INTO scan_labels (id, label) VALUES (?, ?)')
                    ->execute(['scan_' . bin2hex(random_bytes(10)), $label]);

                return;
            } catch (\Throwable) {
                // Doublon concurrent (UNIQUE ci) : retomber sur la ligne
                // existante pour ne pas perdre l'occurrence.
                $canonique = self::ligneCanonique($pdo, $label);
                if ($canonique === null) {
                    return;
                }
            }
        }

        self::fusionnerSoeursNormalisees($pdo, $canonique);
        $pdo->prepare('UPDATE scan_labels SET occurrences = occurrences + 1, last_seen = NOW() WHERE id = ?')
            ->execute([(string) $canonique['id']]);
    }

    /**
     * Ligne canonique d'un libellé : la plus fréquence des lignes de
     * même forme NORMALISÉE (StockPublic::normalizeKey — l'entrée la
     * plus utilisée est l'entrée canonique), à égalité la plus ancienne
     * puis l'id le plus petit (déterminisme). Null si le libellé est
     * inconnu à toute écriture près.
     *
     * @return array<string,mixed>|null
     */
    private static function ligneCanonique(\PDO $pdo, string $label): ?array
    {
        $norm = StockPublic::normalizeKey($label);
        if ($norm === '') {
            return null;
        }

        $meilleure = null;
        foreach ($pdo->query('SELECT id, label, occurrences, first_seen FROM scan_labels')->fetchAll() as $row) {
            if (StockPublic::normalizeKey((string) $row['label']) !== $norm) {
                continue;
            }
            if ($meilleure === null || self::cmpLigneCanonique($row, $meilleure) < 0) {
                $meilleure = $row;
            }
        }

        return $meilleure;
    }

    /** Ordre canonique : occurrences décroissantes, puis ancienneté, puis id. */
    private static function cmpLigneCanonique(array $a, array $b): int
    {
        $oa = (int) ($a['occurrences'] ?? 0);
        $ob = (int) ($b['occurrences'] ?? 0);
        if ($oa !== $ob) {
            return $ob <=> $oa;
        }

        $fa = (string) ($a['first_seen'] ?? '');
        $fb = (string) ($b['first_seen'] ?? '');
        if ($fa !== $fb) {
            return $fa <=> $fb;
        }

        return strcmp((string) $a['id'], (string) $b['id']);
    }

    /**
     * FUSIONNE (jamais une suppression nue) les lignes sœurs de même
     * forme normalisée dans la ligne canonique : occurrences
     * ADDITIONNÉES, première vue la plus ancienne conservée, puis la
     * ligne physique redondante disparaît — aucune perte de compteur
     * ni d'historique. Trace d'audit « alias.fusion » avec les
     * compteurs avant/après (ex. 80+6→86), jamais bloquante.
     *
     * @param array<string,mixed> $canonique Ligne conservée (id, label, occurrences, first_seen).
     */
    private static function fusionnerSoeursNormalisees(\PDO $pdo, array $canonique): void
    {
        $norm = StockPublic::normalizeKey((string) $canonique['label']);
        if ($norm === '') {
            return;
        }

        $avant = [(string) $canonique['label'] => (int) ($canonique['occurrences'] ?? 0)];
        $total = (int) ($canonique['occurrences'] ?? 0);
        $absorbees = [];

        $maj = $pdo->prepare(
            'UPDATE scan_labels SET occurrences = occurrences + ?, first_seen = LEAST(first_seen, ?) WHERE id = ?'
        );
        $del = $pdo->prepare('DELETE FROM scan_labels WHERE id = ?');

        foreach ($pdo->query('SELECT id, label, occurrences, first_seen FROM scan_labels')->fetchAll() as $row) {
            if ((string) $row['id'] === (string) $canonique['id']) {
                continue;
            }
            if (StockPublic::normalizeKey((string) $row['label']) !== $norm) {
                continue;
            }

            $maj->execute([(int) ($row['occurrences'] ?? 0), (string) ($row['first_seen'] ?? ''), (string) $canonique['id']]);
            $del->execute([(string) $row['id']]);

            $avant[(string) $row['label']] = (int) ($row['occurrences'] ?? 0);
            $total += (int) ($row['occurrences'] ?? 0);
            $absorbees[] = (string) $row['label'];
        }

        if ($absorbees === []) {
            return;
        }

        try {
            AuditLog::log('alias.fusion', null, 'scan_label', (string) $canonique['id'], [
                'conserve'          => (string) $canonique['label'],
                'absorbe'           => $absorbees,
                'occurrences_avant' => $avant,
                'occurrences_apres' => $total,
            ]);
        } catch (\Throwable) {
            // La fusion est faite : la trace ne doit jamais la faire échouer.
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
     *      clés vendues/SumUp (ScanCatalogMatcher::exactCatalogKey) —
     *      SAUF si cette clé est elle-même un « libellé de facture »
     *      (looksLikeInvoiceLabel : nom long tout caps avec
     *      conditionnement) : une fiche auto-créée par un ancien scan
     *      au nom long ne résout PLUS — le libellé reste en file pour
     *      que l'utilisateur donne le nom court (ex. fiche « OASIS
     *      TROPICAL SLIM BTE 33CL » ne purge plus « OASIS TROPICAL
     *      SLIM BTE 33CL » ; une fiche propre « Eau » purge « eau »).
     *
     * Les correspondances FLOUES (paliers heuristiques du matcher :
     * troncatures, « SLIM BTE 33CL », faute de frappe…) ne résolvent
     * PAS : le libellé reste dans la file jusqu'à ce que
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

        // (b) Clé de catalogue exacte — hors fiche au nom de facture.
        $key = ScanCatalogMatcher::exactCatalogKey($label);

        return $key !== null && !ScanCatalogMatcher::looksLikeInvoiceLabel($key);
    }

    /**
     * Union dédupliquée des sources de pré-remplissage du formulaire
     * « Nouvelles appellations » (méthode PURE, testée) :
     *
     *  - $scanRows : file des libellés scannés (ScanLabel::pending(),
     *    enrichie de suggested/category par l'appelant) — hint 'scan' ;
     *  - $ficheRows : fiches `products` au nom de facture, hint 'fiche'.
     *
     * Déduplication par clé normalisée (StockPublic::normalizeKey :
     * casse, accents, ponctuation et espaces ignorés) — un libellé déjà
     * présent côté scan n'est PAS repris côté fiches. Ordre : scans
     * d'abord (occurrences décroissantes, ordre d'entrée à égalité),
     * puis fiches par ordre alphabétique naturel.
     *
     * @param list<array<string,mixed>> $scanRows
     * @param list<array<string,mixed>> $ficheRows
     *
     * @return list<array{label:string, occurrences:int, hint:string, suggested:string, category:string}>
     */
    public static function mergePendingSources(array $scanRows, array $ficheRows): array
    {
        $seen = [];
        $scan = [];
        foreach ($scanRows as $row) {
            $prepared = self::pendingRow($row, 'scan');
            if ($prepared === null || isset($seen[$prepared['norm']])) {
                continue;
            }
            $seen[$prepared['norm']] = true;
            $scan[] = $prepared;
        }

        // Occurrences décroissantes (usort est stable en PHP >= 8.0 :
        // ordre d'entrée conservé à égalité — ScanLabel::all() trie déjà).
        usort($scan, static fn (array $a, array $b): int => $b['occurrences'] <=> $a['occurrences']);

        $fiches = [];
        foreach ($ficheRows as $row) {
            $prepared = self::pendingRow($row, 'fiche');
            if ($prepared === null || isset($seen[$prepared['norm']])) {
                continue;
            }
            $seen[$prepared['norm']] = true;
            $fiches[] = $prepared;
        }
        usort($fiches, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

        // Le champ interne « norm » ne sort pas de la fonction.
        $rows = array_merge($scan, $fiches);
        foreach ($rows as &$row) {
            unset($row['norm']);
        }
        unset($row);

        return $rows;
    }

    /**
     * Normalise une ligne de pré-remplissage (méthode pure) : label
     * requis, occurrences >= 0, suggested/category trimmés. Retourne
     * null si la ligne est vide ou sans clé normalisée. Le champ
     * interne « norm » sert à la déduplication (retiré avant rendu).
     *
     * @param array<string,mixed> $row
     *
     * @return array{norm:string, label:string, occurrences:int, hint:string, suggested:string, category:string}|null
     */
    private static function pendingRow(array $row, string $hint): ?array
    {
        $label = trim((string) ($row['label'] ?? ''));
        $norm = StockPublic::normalizeKey($label);
        if ($norm === '') {
            return null;
        }

        return [
            'norm'        => $norm,
            'label'       => $label,
            'occurrences' => max(0, (int) ($row['occurrences'] ?? 0)),
            'hint'        => $hint,
            'suggested'   => trim((string) ($row['suggested'] ?? '')),
            'category'    => trim((string) ($row['category'] ?? '')),
        ];
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
