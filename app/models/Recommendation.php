<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Recommandations commerciales générées par le moteur (table `recommendations`).
 *
 * Chaque idée est identifiée par une empreinte sha1 (règle + produits) :
 * un re-upsert de la même idée ne duplique pas la ligne et ne remet pas
 * à zéro une décision déjà prise (statut, motif, dates).
 *
 * Cycle de vie d'une ligne :
 *   proposee --decision(appliquee|plus_tard|ignoree)--> décidée
 *   appliquee --saveBaseline() au moment de l'application,
 *              puis saveEffet() après mesure --> historique
 *
 * Les lectures sont tolérantes aux tables absentes (avant migration :
 * retour [] au lieu de lever) ; les écritures, elles, lèvent.
 */
final class Recommendation extends Model
{
    protected static string $table = 'recommendations';

    /**
     * Fenêtre (en jours) pendant laquelle une idée compte encore :
     * une application reste « active » 30 jours (mesure d'effet), et un
     * refus bloque le re-proposage de la même empreinte 30 jours.
     * Miroir du REFUS_JOURS du cœur (app/core/Compta/Reco/).
     */
    public const FENETRE_BLOCAGE_JOURS = 30;

    /** Statuts admis par decision(). */
    private const STATUTS_DECISION = ['appliquee', 'plus_tard', 'ignoree'];

    /**
     * Insère une idée (ou rafraîchit son contenu si l'empreinte existe déjà).
     *
     * Le re-upsert met à jour titre / produits / payload / fenêtre d'analyse
     * mais JAMAIS le statut, la décision (decided_at, motif), la baseline,
     * l'effet mesuré ni created_at : une idée déjà traitée par un humain
     * garde son historique.
     *
     * $reco : id (empreinte), regle (R1..R11), titre, et soit
     * produits_json / payload_json (chaînes JSON), soit produits / payload
     * (tableaux, encodés en JSON non-échappé).
     */
    public static function upsert(array $reco, string $fenetreJson): void
    {
        $produitsJson = isset($reco['produits_json'])
            ? (string) $reco['produits_json']
            : self::json(is_array($reco['produits'] ?? null) ? $reco['produits'] : []);
        $payloadJson = isset($reco['payload_json'])
            ? (string) $reco['payload_json']
            : self::json(is_array($reco['payload'] ?? null) ? $reco['payload'] : []);

        self::pdo()->prepare(
            'INSERT INTO recommendations
                (id, regle, titre, produits_json, payload_json, fenetre_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                titre = VALUES(titre),
                produits_json = VALUES(produits_json),
                payload_json = VALUES(payload_json),
                fenetre_json = VALUES(fenetre_json)'
        )->execute([
            (string) $reco['id'],
            (string) ($reco['regle'] ?? ''),
            (string) ($reco['titre'] ?? ''),
            $produitsJson,
            $payloadJson,
            $fenetreJson,
        ]);
    }

    /**
     * Enregistre une décision humaine sur une idée.
     *
     * appliquee → decided_at + applied_at à NOW() ;
     * plus_tard / ignoree → decided_at seulement (motif optionnel).
     *
     * @return bool false si le statut est invalide (la base n'est pas touchée).
     */
    public static function decision(string $id, string $statut, ?string $motif = null): bool
    {
        if (!in_array($statut, self::STATUTS_DECISION, true)) {
            return false;
        }

        if ($statut === 'appliquee') {
            self::pdo()->prepare(
                'UPDATE recommendations
                 SET statut = ?, motif = ?, decided_at = NOW(), applied_at = NOW()
                 WHERE id = ?'
            )->execute([$statut, $motif, $id]);
        } else {
            self::pdo()->prepare(
                'UPDATE recommendations
                 SET statut = ?, motif = ?, decided_at = NOW()
                 WHERE id = ?'
            )->execute([$statut, $motif, $id]);
        }

        return true;
    }

    /**
     * Écrit la baseline [{date, attendu, lo, hi, facteur}] au moment de
     * l'application (photo de l'avant, pour la mesure d'effet).
     */
    public static function saveBaseline(string $id, string $baselineJson): void
    {
        self::pdo()->prepare(
            'UPDATE recommendations SET baseline_json = ? WHERE id = ?'
        )->execute([$baselineJson, $id]);
    }

    /**
     * Écrit l'effet mesuré {etat, effet, bas, haut, jours, note} + sa
     * conclusion ('concluant'|'non_concluant') et date la mesure.
     */
    public static function saveEffet(string $id, array $effet, string $conclusion): void
    {
        self::pdo()->prepare(
            'UPDATE recommendations
             SET effet_json = ?, effet_conclusion = ?, effet_checked_at = NOW()
             WHERE id = ?'
        )->execute([self::json($effet), $conclusion, $id]);
    }

    /**
     * Empreintes encore « actives » : proposées/repoussées depuis moins
     * de FENETRE_BLOCAGE_JOURS (créées depuis — une vieille ligne jamais
     * décidée n'étouffe pas les idées futures), ou appliquées depuis
     * moins de FENETRE_BLOCAGE_JOURS (le cœur s'en sert pour ne pas
     * re-proposer une idée déjà connue).
     *
     * @return list<string>
     */
    public static function actifs(): array
    {
        $jours = self::FENETRE_BLOCAGE_JOURS;
        try {
            $rows = self::pdo()
                ->query(
                    "SELECT id FROM recommendations
                     WHERE ((statut IN ('proposee', 'plus_tard')
                               AND created_at >= NOW() - INTERVAL {$jours} DAY)
                         OR (statut = 'appliquee'
                               AND applied_at >= NOW() - INTERVAL {$jours} DAY))"
                )
                ->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable) {
            // Table pas encore migrée : aucune idée active.
            return [];
        }

        /** @var list<string> */
        return array_map('strval', $rows);
    }

    /**
     * Idées refusées depuis moins de FENETRE_BLOCAGE_JOURS
     * (le cœur évite de reformuler la même idée trop tôt).
     *
     * @return list<array{fingerprint:string, date:string}>
     */
    public static function refusRecents(): array
    {
        $jours = self::FENETRE_BLOCAGE_JOURS;
        try {
            $rows = self::pdo()
                ->query(
                    "SELECT id, decided_at FROM recommendations
                     WHERE statut = 'ignoree'
                       AND decided_at >= NOW() - INTERVAL {$jours} DAY
                     ORDER BY decided_at DESC"
                )
                ->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'fingerprint' => (string) $r['id'],
                'date'        => (string) $r['decided_at'],
            ];
        }

        return $out;
    }

    /**
     * Applications dont l'effet reste à mesurer : appliquées, jamais
     * contrôlées (effet_checked_at IS NULL). Sert au passage de mesure
     * d'effet ; baseline_json contient l'avant, produits_json les clés.
     *
     * @return list<array{id:string, produits_json:string, baseline_json:?string, applied_at:string}>
     */
    public static function appliqueesEnCours(): array
    {
        try {
            $rows = self::pdo()
                ->query(
                    'SELECT id, produits_json, baseline_json, applied_at
                     FROM recommendations
                     WHERE statut = \'appliquee\'
                       AND applied_at IS NOT NULL
                       AND effet_checked_at IS NULL
                     ORDER BY applied_at ASC'
                )
                ->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'            => (string) $r['id'],
                'produits_json' => (string) $r['produits_json'],
                'baseline_json' => $r['baseline_json'] !== null ? (string) $r['baseline_json'] : null,
                'applied_at'    => (string) $r['applied_at'],
            ];
        }

        return $out;
    }

    /**
     * Historique des idées appliquées (la plus récente d'abord), pour la
     * carte « Historique » : payload complet + effet mesuré + dates.
     *
     * @return list<array<string,mixed>>
     */
    public static function historique(): array
    {
        try {
            $rows = self::pdo()
                ->query(
                    'SELECT id, regle, titre, produits_json, payload_json, baseline_json,
                            effet_json, effet_conclusion, fenetre_json, decided_at,
                            applied_at, effet_checked_at
                     FROM recommendations
                     WHERE statut = \'appliquee\'
                     ORDER BY applied_at DESC'
                )
                ->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        /** @var list<array<string,mixed>> $rows */
        return $rows;
    }

    /**
     * Idées en attente de décision (carte « Idées du moment »).
     *
     * @return list<array<string,mixed>>
     */
    public static function proposees(): array
    {
        try {
            $rows = self::pdo()
                ->query(
                    "SELECT * FROM recommendations
                     WHERE statut = 'proposee'
                     ORDER BY created_at DESC"
                )
                ->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        /** @var list<array<string,mixed>> $rows */
        return $rows;
    }

    /**
     * Idées IGNORÉES (la plus récente d'abord) : section « Ignorées » de
     * l'historique, avec motif et bouton « Rétablir ». Liste bornée aux
     * 50 plus récentes (les refus datant de plus de FENETRE_BLOCAGE_JOURS
     * n'influencent plus le moteur).
     *
     * @return list<array<string,mixed>>
     */
    public static function ignorees(): array
    {
        try {
            $rows = self::pdo()
                ->query(
                    "SELECT id, regle, titre, produits_json, payload_json, motif, decided_at
                     FROM recommendations
                     WHERE statut = 'ignoree'
                     ORDER BY decided_at DESC
                     LIMIT 50"
                )
                ->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        /** @var list<array<string,mixed>> $rows */
        return $rows;
    }

    /**
     * Rétablit une idée ignorée : remise à l'état « proposee » (re-
     * proposable), motif et date de décision effacés. Rien d'autre n'est
     * touché (baseline/effet d'une application antérieure conservés si
     * présents). Retourne false si la ligne est absente ou non ignorée.
     */
    public static function retablir(string $id): bool
    {
        $stmt = self::pdo()->prepare(
            "UPDATE recommendations
             SET statut = 'proposee', motif = NULL, decided_at = NULL
             WHERE id = ? AND statut = 'ignoree'"
        );
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Encode un tableau en JSON (unicode lisible pour l'affichage admin).
     */
    private static function json(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);

        return $json === false ? '[]' : $json;
    }
}
