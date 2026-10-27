<?php

declare(strict_types=1);

namespace App\Core\Compta\T1;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductAlias;
use App\Models\ProductCost;
use App\Models\ProductStock;
use Throwable;

/**
 * Archive pré-fusion (T1.5) : la règle utilisateur du 27/10 — « les clés
 * fusionnées sont TOUJOURS stockées si jamais, comme ça ça garde la
 * possibilité ».
 *
 * Toute fusion (auto OU manuelle : mergeKeys, mergeKeysAll, t1Decide,
 * moteur T1AutoFusion) enrichit le details JSON de l'audit
 * « inventory.merge » EXISTANT d'un snapshot condensé par clé, AVANT la
 * fusion — AUCUNE nouvelle table (audit_logs.details est un TEXT : le
 * snapshot est un condensé propre, jamais un dump brut).
 *
 * Le snapshot documente, par clé : vivant, stock de référence, coût
 * unitaire du lot en cours, compteurs par table, drapeaux, alias
 * possédés (plafonnés), fiche cafétéria (présence, prix, stock). C'est
 * la base du bouton « Restaurer la clé » de l'écran des fusions auto.
 *
 * Restaurer recrée l'IDENTITÉ de la clé source (fiche + alias + stock +
 * lot de coût du snapshot) SANS re-scinder l'historique déjà repointé
 * sur la cible (ventes, achats, comptages… restent sur la cible — limite
 * honnête documentée à l'écran). Le stock de référence, lui, est
 * rééquilibré (cible − stock source restauré) : le total physique est
 * préservé, « zéro perte ».
 */
final class T1MergeArchive
{
    /** Plafond d'alias archivés par clé (condensé : le details est un TEXT). */
    private const MAX_ALIASES = 10;

    /** Action d'audit d'une restauration de clé depuis l'archive. */
    public const ACTION_RESTORE = 'inventory.restore';

    /**
     * Capture AVANT fusion des deux clés (source à absorber, cible
     * conservée) : condensé depuis les stats vivantes (UN point
     * d'assemblage : ProductKeyMerge::keyStats, déjà calculé par la
     * page), le lot de coût en cours, les alias possédés et la fiche.
     *
     * @param array<string,array<string,mixed>> $keyStats Stats par clé vivante (instantané du passage).
     *
     * @return array<string,mixed> Section « archive » du details d'audit.
     */
    public static function capturer(string $source, string $target, array $keyStats): array
    {
        return [
            'capture_le' => T1AutoFusion::maintenantIso(),
            'source'     => self::snapshotCle($source, $keyStats[$source] ?? []),
            'target'     => self::snapshotCle($target, $keyStats[$target] ?? []),
        ];
    }

    /**
     * Enrichit les details d'un audit inventory.merge avec la capture
     * (pure concaténation de clés — aucun appel base en plus de capturer).
     *
     * @param array<string,mixed>                $details  Details existants (source/target/tables…).
     * @param array<string,array<string,mixed>>  $keyStats Stats par clé vivante.
     *
     * @return array<string,mixed>
     */
    public static function enrichirAudit(array $details, string $source, string $target, array $keyStats): array
    {
        try {
            $details['archive'] = self::capturer($source, $target, $keyStats);
        } catch (Throwable) {
            // L'archive ne doit JAMAIS faire échouer une fusion : sans
            // snapshot, la fusion reste auditable (source/target/tables).
        }

        return $details;
    }

    /**
     * Restaure la clé source d'une fusion archivée (audit_id) : recrée
     * fiche + alias + stock + lot de coût du snapshot ; le stock de
     * référence de la cible est rééquilibré (− stock source) ; l'historique
     * repointé (ventes, achats…) RESTE sur la cible.
     *
     * @return array{ok:bool,erreur?:string,restaure:array<string,mixed>}
     */
    public static function restaurer(string $auditId, ?string $userId): array
    {
        try {
            $audit = AuditLog::find($auditId);
        } catch (Throwable) {
            $audit = null;
        }
        if ($audit === null || ($audit['action'] ?? '') !== 'inventory.merge') {
            return ['ok' => false, 'erreur' => 'Fusion introuvable dans le journal.', 'restaure' => []];
        }

        $details = json_decode((string) ($audit['details'] ?? ''), true);
        $archive = is_array($details) && is_array($details['archive'] ?? null) ? $details['archive'] : null;
        $source = is_array($details) ? trim((string) ($details['source'] ?? '')) : '';
        $target = is_array($details) ? trim((string) ($details['target'] ?? '')) : '';
        $snapshot = is_array($archive) && is_array($archive['source'] ?? null) ? $archive['source'] : null;
        if ($source === '' || $snapshot === null) {
            return ['ok' => false, 'erreur' => 'Aucun snapshot pré-fusion pour cette fusion (fusion antérieure à l\'archive).', 'restaure' => []];
        }

        $restaure = ['cle' => $source, 'depuis_merge' => $auditId];

        // 1. Stock de référence : la source reprend SON stock du snapshot,
        //    la cible rend ce qu'elle avait absorbé (total physique
        //    préservé, jamais négatif).
        $stock = $snapshot['stock'] ?? null;
        if (is_int($stock)) {
            ProductStock::set($source, $stock);
            if ($target !== '') {
                ProductStock::adjust($target, -$stock);
            }
            $restaure['stock'] = $stock;
        }

        // 2. Lot de coût en cours : recréé tel que photographié (la clé
        //    n'a plus aucun lot depuis la fusion — inutile de re-scinder
        //    l'historique de lots de la cible).
        $cout = $snapshot['cout'] ?? null;
        if (is_array($cout) && isset($cout['prix'], $cout['valid_from']) && (float) $cout['prix'] > 0.0) {
            $cree = ProductCost::create([
                'product_key' => $source,
                'cost_price'  => (float) $cout['prix'],
                'valid_from'  => (string) $cout['valid_from'],
                'notes'       => 'Lot restauré depuis l\'archive de fusion ' . $auditId . '.',
            ]);
            if ($cree !== '') {
                $restaure['cout'] = $cout;
            }
        }

        // 3. Alias possédés : recréés SEULEMENT si le libellé n'existe
        //    plus (s'il vit désormais sur la cible, on ne le vole pas —
        //    le mapping actif fait foi).
        $aliases = [];
        foreach (is_array($snapshot['alias'] ?? null) ? $snapshot['alias'] : [] as $label) {
            $label = trim((string) $label);
            if ($label === '') {
                continue;
            }
            if (ProductAlias::ensureAbsentCreate($label, $source)) {
                $aliases[] = $label;
            }
        }
        if ($aliases !== []) {
            $restaure['alias'] = $aliases;
        }

        // 4. Fiche cafétéria : recréée (nouvel id) si elle a disparu —
        //    prix/stock du snapshot, le reste (description, image) est
        //    une perte documentée de la fusion.
        $fiche = is_array($snapshot['fiche'] ?? null) ? $snapshot['fiche'] : null;
        if (is_array($fiche) && !empty($fiche['present']) && Product::findByName($source) === null) {
            Product::save([
                'name'         => $source,
                'price'        => (float) ($fiche['prix'] ?? 0),
                'stock'        => (int) ($fiche['stock'] ?? 0),
                'is_available' => 1,
                'is_active'    => 1,
            ]);
            $restaure['fiche'] = true;
        }

        AuditLog::log(
            self::ACTION_RESTORE,
            $userId,
            'product_key',
            $source,
            $restaure
        );

        return ['ok' => true, 'restaure' => $restaure];
    }

    // ── Internes ─────────────────────────────────────────────────────────

    /**
     * Snapshot condensé d'UNE clé (données lues AVANT la fusion).
     *
     * @param array<string,mixed> $stat Ligne keyStats de la clé ([] si absente).
     *
     * @return array<string,mixed>
     */
    private static function snapshotCle(string $cle, array $stat): array
    {
        // Lot de coût en cours (prix TTC unitaire + début de période).
        $lot = null;
        try {
            $lot = ProductCost::current($cle);
        } catch (Throwable) {
            $lot = null;
        }

        // Alias possédés (plafonnés : le details d'audit est un TEXT).
        $alias = [];
        try {
            foreach (ProductAlias::all() as $a) {
                if ((string) ($a['product_key'] ?? '') === $cle) {
                    $alias[] = (string) ($a['raw_description'] ?? '');
                    if (count($alias) >= self::MAX_ALIASES) {
                        break;
                    }
                }
            }
        } catch (Throwable) {
            $alias = [];
        }
        sort($alias, SORT_STRING);

        // Fiche jumelle de la carte cafétéria (présence, prix, stock).
        $fiche = null;
        try {
            $row = Product::findByName($cle);
            if ($row !== null) {
                $fiche = [
                    'present' => true,
                    'prix'    => round((float) ($row['price'] ?? 0), 2),
                    'stock'   => (int) ($row['stock'] ?? 0),
                ];
            }
        } catch (Throwable) {
            $fiche = null;
        }

        return [
            'vivant'      => $stat !== [],
            'stock'       => $stat['stock'] ?? null,
            'cout'        => $lot === null ? null : [
                'prix'       => round((float) ($lot['cost_price'] ?? 0), 3),
                'valid_from' => substr((string) ($lot['valid_from'] ?? ''), 0, 10),
            ],
            'compteurs'   => [
                'ventes'    => (int) ($stat['sales'] ?? 0),
                'achats'    => (int) ($stat['purchases'] ?? 0),
                'pertes'    => (int) ($stat['losses'] ?? 0),
                'comptages' => (int) ($stat['counts'] ?? 0),
                'lots'      => (int) ($stat['costs'] ?? 0),
                'promos'    => (int) ($stat['promotions'] ?? 0),
            ],
            'drapeaux'    => [
                'pause'      => (bool) ($stat['discontinued'] ?? false),
                'infini'     => (int) ($stat['infinite'] ?? 0) > 0,
                'zero_depuis' => (int) ($stat['zero_since'] ?? 0) > 0,
                'pack'       => (int) ($stat['packs'] ?? 0),
                'courses'    => (int) ($stat['checks'] ?? 0) > 0,
            ],
            'alias'       => $alias,
            'fiche'       => $fiche,
        ];
    }
}
