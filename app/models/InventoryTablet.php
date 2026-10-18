<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Inventaire tablette SumUp (tables `inventory_tablet_sessions` et
 * `inventory_tablet_lines`).
 *
 * L'utilisateur relève à la main les quantités affichées sur la tablette
 * de vente SumUp ; le site calcule l'écart contre son stock théorique
 * (InventoryCount::theoreticalStock — dernier comptage + achats − ventes
 * − pertes). Chaque écart négatif est une suspicion de vol/casse,
 * valorisée au coût de revient du lot applicable à la date du relevé
 * (perte € estimée). Une session garde TOUTES ses lignes en historique
 * permanent : l'action « corriger » (pertes créées, stock de référence
 * aligné sur le surplus) marque les lignes corrigées de façon
 * irrévocable (corrected=1) et interdit la suppression de la session.
 */
final class InventoryTablet extends Model
{
    protected static string $table = 'inventory_tablet_sessions';

    /**
     * Enregistre une session de relevé tablette : l'écart, le coût et la
     * perte sont TOUS recalculés côté serveur (jamais lus dans le POST).
     *
     * Pour chaque ligne :
     *  - site_theoretical = InventoryCount::theoreticalStock($key) ;
     *    null (produit jamais compté ni mouvementé) → gap null ;
     *  - gap = tablet_qty − site_theoretical ;
     *  - unit_cost = ProductCost::costAt($key, $countedAt) (null = coût
     *    manquant) ;
     *  - loss_value = gap < 0 ? round(−gap × unit_cost, 2) : null (null
     *    aussi si le coût est manquant : rien à valoriser).
     *
     * Transactionnel : soit session + toutes les lignes, soit rien.
     *
     * @param list<array{key:string, tablet_qty:int, avis:?string}> $lines
     *
     * @return string Identifiant de session créé ('tabt_...').
     */
    public static function createSession(string $countedAt, ?string $note, ?string $userId, array $lines): string
    {
        $pdo = self::pdo();
        $sessionId = 'tabt_' . bin2hex(random_bytes(10));

        $pdo->beginTransaction();

        try {
            $pdo->prepare(
                'INSERT INTO inventory_tablet_sessions (id, counted_at, note, created_by, created_at)
                 VALUES (?,?,?,?,NOW())'
            )->execute([$sessionId, $countedAt, $note, $userId]);

            $stmt = $pdo->prepare(
                'INSERT INTO inventory_tablet_lines
                    (id, session_id, product_key, tablet_qty, site_theoretical, gap,
                     unit_cost, loss_value, avis, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,NOW())'
            );

            foreach ($lines as $line) {
                $key = (string) $line['key'];
                $tabletQty = (int) $line['tablet_qty'];
                $avis = ($line['avis'] ?? null) !== null && (string) $line['avis'] !== ''
                    ? (string) $line['avis']
                    : null;

                // Théorique (null = produit sans aucune donnée de stock).
                $theoretical = InventoryCount::theoreticalStock($key);
                $gap = $theoretical === null ? null : $tabletQty - $theoretical;

                // Coût unitaire au jour du relevé (chaîne DECIMAL → float).
                $costRaw = ProductCost::costAt($key, $countedAt);
                $unitCost = $costRaw === null ? null : (float) $costRaw;

                // Perte estimée : uniquement un MANQUANT avec un coût connu.
                $lossValue = $gap !== null && $gap < 0 && $unitCost !== null
                    ? round(-$gap * $unitCost, 2)
                    : null;

                $stmt->execute([
                    'tabl_' . bin2hex(random_bytes(10)),
                    $sessionId,
                    $key,
                    $tabletQty,
                    $theoretical,
                    $gap,
                    $unitCost,
                    $lossValue,
                    $avis,
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }

        return $sessionId;
    }

    /**
     * Sessions, de la plus récente à la plus ancienne, avec leurs
     * agrégats par session (une seule requête, GROUP BY).
     *
     * @return list<array<string,mixed>> Chaque session porte lines_count,
     *                                   gaps_count (écart ≠ 0),
     *                                   missing_cost_count (écart négatif
     *                                   sans coût connu),
     *                                   loss_value_sum et corrected_count.
     */
    public static function sessions(int $limit = 100): array
    {
        $limit = max(1, (int) $limit);

        $sql = 'SELECT s.*,
                       COUNT(l.id)                                            AS lines_count,
                       COALESCE(SUM(CASE WHEN l.gap IS NOT NULL AND l.gap <> 0 THEN 1 ELSE 0 END), 0) AS gaps_count,
                       COALESCE(SUM(CASE WHEN l.gap < 0 AND l.unit_cost IS NULL THEN 1 ELSE 0 END), 0) AS missing_cost_count,
                       COALESCE(SUM(COALESCE(l.loss_value, 0)), 0)            AS loss_value_sum,
                       COALESCE(SUM(CASE WHEN l.corrected = 1 THEN 1 ELSE 0 END), 0) AS corrected_count
                FROM inventory_tablet_sessions s
                LEFT JOIN inventory_tablet_lines l ON l.session_id = s.id
                GROUP BY s.id
                ORDER BY s.counted_at DESC, s.created_at DESC
                LIMIT ' . $limit;

        try {
            /** @var list<array<string,mixed>> $rows */
            $rows = self::pdo()->query($sql)->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        // Décimaux SQL → types PHP propres pour la vue.
        foreach ($rows as &$r) {
            $r['lines_count'] = (int) $r['lines_count'];
            $r['gaps_count'] = (int) $r['gaps_count'];
            $r['missing_cost_count'] = (int) $r['missing_cost_count'];
            $r['loss_value_sum'] = (float) $r['loss_value_sum'];
            $r['corrected_count'] = (int) $r['corrected_count'];
        }
        unset($r);

        return $rows;
    }

    /**
     * Lignes d'une session, de l'écart le plus négatif au plus positif
     * puis par produit (les manquants en premier : ce sont eux qu'on
     * corrige).
     *
     * @return list<array<string,mixed>>
     */
    public static function lines(string $sessionId): array
    {
        try {
            $stmt = self::pdo()->prepare(
                'SELECT * FROM inventory_tablet_lines
                 WHERE session_id = ?
                 ORDER BY gap ASC, product_key ASC'
            );
            $stmt->execute([$sessionId]);

            /** @var list<array<string,mixed>> $r */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * La session contient-elle au moins une ligne corrigée (trace
     * irrévocable) ?
     */
    public static function hasCorrectedLines(string $sessionId): bool
    {
        try {
            $stmt = self::pdo()->prepare(
                'SELECT EXISTS(SELECT 1 FROM inventory_tablet_lines
                               WHERE session_id = ? AND corrected = 1)'
            );
            $stmt->execute([$sessionId]);

            return (bool) $stmt->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Marque « corrigées » les lignes à écart non nul de la session
     * (corrected=1 + horodatage + auteur) : irrévocable, la session ne
     * peut plus être corrigée ni supprimée. Les lignes sans écart (ou
     * déjà corrigées) ne sont pas touchées.
     */
    public static function markCorrected(string $sessionId, string $userId): void
    {
        self::pdo()->prepare(
            'UPDATE inventory_tablet_lines
                SET corrected = 1, corrected_at = NOW(), corrected_by = ?
              WHERE session_id = ? AND corrected = 0
                AND gap IS NOT NULL AND gap <> 0'
        )->execute([$userId, $sessionId]);
    }

    /**
     * Analyse d'une période (pour l'onglet « analyse ») : KPIs, détail
     * par session et top produits manquants. Le filtre par date porte sur
     * sessions.counted_at (jointure lignes → sessions, indexée).
     *
     * @return array{
     *   kpis: array{sessions:int, gaps:int, loss_value:float, missing_cost_count:int, products_affected:int},
     *   per_session: list<array{date:string, label:string, gaps:int, loss_value:float}>,
     *   top_products: list<array{key:string, gaps_units_sum:int, loss_value:float}>
     * }
     */
    public static function analysis(string $from, string $to): array
    {
        // ---- KPIs agrégés de la fenêtre ----
        $sqlKpis = 'SELECT COUNT(DISTINCT s.id) AS sessions,
                           COALESCE(SUM(CASE WHEN l.gap IS NOT NULL AND l.gap <> 0 THEN 1 ELSE 0 END), 0) AS gaps,
                           COALESCE(SUM(COALESCE(l.loss_value, 0)), 0) AS loss_value,
                           COALESCE(SUM(CASE WHEN l.gap < 0 AND l.unit_cost IS NULL THEN 1 ELSE 0 END), 0) AS missing_cost_count,
                           COUNT(DISTINCT CASE WHEN l.gap IS NOT NULL AND l.gap <> 0 THEN l.product_key END) AS products_affected
                    FROM inventory_tablet_sessions s
                    LEFT JOIN inventory_tablet_lines l ON l.session_id = s.id
                    WHERE s.counted_at BETWEEN ? AND ?';

        // ---- Détail par session de la fenêtre ----
        $sqlPerSession = 'SELECT s.counted_at, s.note,
                                 COALESCE(SUM(CASE WHEN l.gap IS NOT NULL AND l.gap <> 0 THEN 1 ELSE 0 END), 0) AS gaps,
                                 COALESCE(SUM(COALESCE(l.loss_value, 0)), 0) AS loss_value
                          FROM inventory_tablet_sessions s
                          LEFT JOIN inventory_tablet_lines l ON l.session_id = s.id
                          WHERE s.counted_at BETWEEN ? AND ?
                          GROUP BY s.id, s.counted_at, s.note
                          ORDER BY s.counted_at ASC, s.created_at ASC';

        // ---- Top produits manquants (perte en unités = −Σ gap des
        //      manquants, plus perte € totale de la clé) ----
        $sqlTop = 'SELECT l.product_key,
                          -COALESCE(SUM(CASE WHEN l.gap < 0 THEN l.gap ELSE 0 END), 0) AS gaps_units_sum,
                          COALESCE(SUM(COALESCE(l.loss_value, 0)), 0) AS loss_value
                   FROM inventory_tablet_lines l
                   JOIN inventory_tablet_sessions s ON s.id = l.session_id
                   WHERE s.counted_at BETWEEN ? AND ?
                     AND l.gap IS NOT NULL AND l.gap <> 0
                   GROUP BY l.product_key
                   ORDER BY gaps_units_sum DESC, loss_value DESC
                   LIMIT 10';

        try {
            $pdo = self::pdo();

            $stmt = $pdo->prepare($sqlKpis);
            $stmt->execute([$from, $to]);
            /** @var array<string,mixed>|false $kpi */
            $kpi = $stmt->fetch();

            $stmt = $pdo->prepare($sqlPerSession);
            $stmt->execute([$from, $to]);
            /** @var list<array<string,mixed>> $perSession */
            $perSession = $stmt->fetchAll();

            $stmt = $pdo->prepare($sqlTop);
            $stmt->execute([$from, $to]);
            /** @var list<array<string,mixed>> $top */
            $top = $stmt->fetchAll();
        } catch (\Throwable) {
            return [
                'kpis'         => ['sessions' => 0, 'gaps' => 0, 'loss_value' => 0.0, 'missing_cost_count' => 0, 'products_affected' => 0],
                'per_session'  => [],
                'top_products' => [],
            ];
        }

        $perSessionOut = [];
        foreach ($perSession as $r) {
            $perSessionOut[] = [
                'date'       => (string) $r['counted_at'],
                'label'      => (string) ($r['note'] ?? ''),
                'gaps'       => (int) $r['gaps'],
                'loss_value' => (float) $r['loss_value'],
            ];
        }

        $topOut = [];
        foreach ($top as $r) {
            $topOut[] = [
                'key'            => (string) $r['product_key'],
                'gaps_units_sum' => (int) $r['gaps_units_sum'],
                'loss_value'     => (float) $r['loss_value'],
            ];
        }

        return [
            'kpis'         => [
                'sessions'           => (int) ($kpi['sessions'] ?? 0),
                'gaps'               => (int) ($kpi['gaps'] ?? 0),
                'loss_value'         => (float) ($kpi['loss_value'] ?? 0.0),
                'missing_cost_count' => (int) ($kpi['missing_cost_count'] ?? 0),
                'products_affected'  => (int) ($kpi['products_affected'] ?? 0),
            ],
            'per_session'  => $perSessionOut,
            'top_products' => $topOut,
        ];
    }

    /**
     * Supprime une session (les lignes partent en cascade via la FK).
     *
     * Refus (false) si la session contient des lignes corrigées : la
     * trace des corrections (pertes créées, stock aligné) doit rester
     * consultable à vie.
     */
    public static function deleteSession(string $sessionId): bool
    {
        if (self::hasCorrectedLines($sessionId)) {
            return false;
        }

        $stmt = self::pdo()->prepare('DELETE FROM inventory_tablet_sessions WHERE id = ?');
        $stmt->execute([$sessionId]);

        return $stmt->rowCount() === 1;
    }
}
