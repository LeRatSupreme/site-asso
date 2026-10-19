<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Compta\StockPublic;

/**
 * Mapping libellés CSV SumUp -> produit canonique (table `product_aliases`).
 *
 * Chaque libellé brut rencontré dans un rapport (ex: « Bueno_white »,
 * « Bueno », « cristaline ») est rattaché une fois pour toutes à un
 * product_key canonique. Permet des statistiques fiables malgré les
 * variations de saisie.
 */
final class ProductAlias extends Model
{
    protected static string $table = 'product_aliases';

    /**
     * Tous les alias, triés par product_key puis libellé brut.
     *
     * @return list<array<string,mixed>>
     */
    public static function all(): array
    {
        try {
            /** @var list<array<string,mixed>> $r */
            return self::pdo()
                ->query('SELECT * FROM product_aliases ORDER BY product_key, raw_description')
                ->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Résout un libellé brut en product_key canonique : correspondance
     * EXACTE d'abord, puis repli par forme NORMALISÉE (casse, accents,
     * ponctuation et espaces ignorés — StockPublic::normalizeKey).
     *
     * Le repli couvre les libellés dont la ligne d'alias exacte a été
     * consolidée (doublon supprimé par consolidateFor) : l'import CSV
     * ne recrée pas de clé fantôme pour ces orthographes.
     */
    public static function resolve(string $description): ?string
    {
        $stmt = self::pdo()->prepare(
            'SELECT product_key FROM product_aliases WHERE raw_description = ? LIMIT 1'
        );
        $stmt->execute([$description]);
        $row = $stmt->fetch();

        if ($row !== false && (string) $row['product_key'] !== '') {
            return (string) $row['product_key'];
        }

        $norm = StockPublic::normalizeKey($description);
        if ($norm === '') {
            return null;
        }

        try {
            /** @var list<array<string,mixed>> $rows */
            $rows = self::pdo()
                ->query('SELECT raw_description, product_key FROM product_aliases')
                ->fetchAll();
        } catch (\Throwable) {
            return null;
        }

        foreach ($rows as $alias) {
            $key = trim((string) $alias['product_key']);
            if ($key !== '' && StockPublic::normalizeKey((string) $alias['raw_description']) === $norm) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Récupère l'alias correspondant à un libellé brut.
     *
     * @return array<string,mixed>|null
     */
    public static function findByRaw(string $rawDescription): ?array
    {
        $stmt = self::pdo()->prepare(
            'SELECT * FROM product_aliases WHERE raw_description = ? LIMIT 1'
        );
        $stmt->execute([$rawDescription]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Crée ou met à jour un alias (upsert sur raw_description).
     *
     * @param array<string,mixed> $data
     */
    public static function save(array $data): string
    {
        $raw = trim((string) ($data['raw_description'] ?? ''));
        $productKey = trim((string) ($data['product_key'] ?? ''));
        $category = ($data['category'] ?? '') !== '' ? (string) $data['category'] : null;

        if ($raw === '' || $productKey === '') {
            return '';
        }

        $existing = self::findByRaw($raw);
        if ($existing !== null) {
            $stmt = self::pdo()->prepare(
                'UPDATE product_aliases SET product_key = ?, category = ? WHERE id = ?'
            );
            $stmt->execute([$productKey, $category, $existing['id']]);

            return (string) $existing['id'];
        }

        $id = 'alias_' . bin2hex(random_bytes(10));
        $stmt = self::pdo()->prepare(
            'INSERT INTO product_aliases (id, raw_description, product_key, category, created_at)
             VALUES (?,?,?,?, NOW())'
        );
        $stmt->execute([$id, $raw, $productKey, $category]);

        return $id;
    }

    public static function deleteRow(string $id): void
    {
        $stmt = self::pdo()->prepare('DELETE FROM product_aliases WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Liste les catégories distinctes utilisées par les alias.
     *
     * @return list<string>
     */
    public static function distinctCategories(): array
    {
        try {
            $rows = self::pdo()
                ->query('SELECT DISTINCT category FROM product_aliases WHERE category IS NOT NULL AND category <> \'\' ORDER BY category')
                ->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = (string) $r['category'];
        }

        return $out;
    }

    /**
     * Renomme une catégorie sur tous les alias (renommage effectué dans la
     * page Catégories de la cafétéria).
     */
    public static function renameCategory(string $old, string $new): int
    {
        $old = trim($old);
        $new = trim($new);
        if ($old === '' || $new === '' || $old === $new) {
            return 0;
        }

        $stmt = self::pdo()->prepare('UPDATE product_aliases SET category = ? WHERE category = ?');
        $stmt->execute([$new, $old]);

        return (int) $stmt->rowCount();
    }

    /**
     * Ré-affecte tous les alias pointant vers une clé canonique vers une
     * autre (fusion de doublons).
     */
    public static function reassign(string $from, string $to): int
    {
        $stmt = self::pdo()->prepare(
            'UPDATE product_aliases SET product_key = ? WHERE product_key = ?'
        );
        $stmt->execute([$to, $from]);

        return $stmt->rowCount();
    }

    /**
     * Absorbe les alias d'une clé fusionnée : les libellés rattachés à la
     * source suivent la cible, SAUF la ligne « identité » de la source
     * (libellé = clé absorbée) quand ce libellé n'apparaît plus dans les
     * ventes récentes — cette ligne serait un doublon sans utilité, le
     * libellé mort ne reviendra plus dans les imports.
     *
     * Un libellé encore VENDU (description présente dans les ventes des
     * `$liveDays` derniers jours) garde au contraire sa ligne : le
     * prochain import CSV doit continuer à le résoudre vers la cible.
     *
     * @return array{repointed:int, absorbed:int}
     */
    public static function absorbInto(string $from, string $to, int $liveDays = 60): array
    {
        $from = trim($from);
        $to = trim($to);
        if ($from === '' || $to === '' || strcasecmp($from, $to) === 0) {
            return ['repointed' => 0, 'absorbed' => 0];
        }

        $stmt = self::pdo()->prepare('SELECT id, raw_description FROM product_aliases WHERE product_key = ?');
        $stmt->execute([$from]);
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();

        $repoint = self::pdo()->prepare('UPDATE product_aliases SET product_key = ? WHERE id = ?');
        $repointed = 0;
        $absorbed = 0;
        foreach ($rows as $row) {
            $isSelf = strcasecmp(trim((string) $row['raw_description']), $from) === 0;
            if ($isSelf && !self::labelIsLive((string) $row['raw_description'], $liveDays)) {
                self::deleteRow((string) $row['id']);
                $absorbed++;
            } else {
                $repoint->execute([$to, (string) $row['id']]);
                $repointed++;
            }
        }

        return ['repointed' => $repointed, 'absorbed' => $absorbed];
    }

    /**
     * Garantit qu'un libellé absorbé encore actif au POS (ventes
     * récentes) continuera de résoudre vers la cible : crée la ligne
     * d'alias manquante si resolve() ne la couvre pas déjà (ligne
     * exacte ou repli normalisé).
     */
    public static function ensurePointer(string $raw, string $to, int $liveDays = 60): bool
    {
        $raw = trim($raw);
        $to = trim($to);
        if ($raw === '' || $to === '' || strcasecmp($raw, $to) === 0) {
            return false;
        }
        if (!self::labelIsLive($raw, $liveDays)) {
            return false;
        }

        $resolved = self::resolve($raw);
        if ($resolved !== null && strcasecmp($resolved, $to) === 0) {
            return false;
        }

        self::save(['raw_description' => $raw, 'product_key' => $to]);

        return true;
    }

    /**
     * Ce libellé apparaît-il encore dans les ventes récentes ? (comparaison
     * ci : « schweppes » couvre la description « Schweppes »).
     */
    private static function labelIsLive(string $raw, int $liveDays): bool
    {
        $stmt = self::pdo()->prepare(
            'SELECT 1 FROM sales WHERE description = ? AND sold_at >= DATE_SUB(NOW(), INTERVAL ' . max(1, $liveDays) . ' DAY) LIMIT 1'
        );
        $stmt->execute([$raw]);

        return $stmt->fetch() !== false;
    }

    /**
     * Consolide les DOUBLONS d'alias d'une clé canonique : les libellés
     * de même forme normalisée (ex. « Coca cherry » / « Coca_cherry » /
     * « Coca Cherry ») sont réduits à une seule ligne, celle du libellé
     * le plus utilisé dans les ventes réelles (chooseSurvivor).
     *
     * Sans perte :
     *  - la catégorie du survivant est comblée par la première catégorie
     *    non vide des lignes absorbées ;
     *  - les ventes portant un libellé absorbé reçoivent la catégorie du
     *    survivant si la leur est vide (comblement, jamais d'écrasement) ;
     *  - les orthographes supprimées restent résolvables grâce au repli
     *    normalisé de resolve().
     *
     * Appelée dans la transaction de ProductKeyMerge::merge() (même PDO
     * partagé) : tout est validé ou annulé ensemble.
     *
     * @return int Nombre de lignes d'alias supprimées.
     */
    public static function consolidateFor(string $productKey): int
    {
        $productKey = trim($productKey);
        if ($productKey === '') {
            return 0;
        }

        $stmt = self::pdo()->prepare(
            'SELECT id, raw_description, category, created_at FROM product_aliases WHERE product_key = ?'
        );
        $stmt->execute([$productKey]);
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();

        // Groupement par forme normalisée ; un libellé irreconnaissable
        // (forme vide) n'est jamais fusionné.
        $groups = [];
        foreach ($rows as $row) {
            $norm = StockPublic::normalizeKey((string) $row['raw_description']);
            if ($norm === '') {
                continue;
            }
            $groups[$norm][] = $row;
        }

        $deleted = 0;
        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }

            // Occurrences réelles de chaque libellé dans les ventes.
            $raws = [];
            foreach ($group as $row) {
                $raws[] = (string) $row['raw_description'];
            }
            $placeholders = implode(',', array_fill(0, count($raws), '?'));
            $stmt = self::pdo()->prepare(
                'SELECT description, COUNT(*) AS n FROM sales WHERE description IN (' . $placeholders . ') GROUP BY description'
            );
            $stmt->execute($raws);
            $usage = [];
            foreach ($stmt->fetchAll() as $u) {
                $usage[(string) $u['description']] = (int) $u['n'];
            }

            $survivorId = self::chooseSurvivor($group, $usage, $productKey);
            $survivor = null;
            $losers = [];
            foreach ($group as $row) {
                if ((string) $row['id'] === $survivorId) {
                    $survivor = $row;
                } else {
                    $losers[] = $row;
                }
            }
            if ($survivor === null) {
                continue;
            }

            // Catégorie du survivant comblée si vide.
            $survivorCat = trim((string) ($survivor['category'] ?? ''));
            if ($survivorCat === '') {
                foreach ($losers as $loser) {
                    $cat = trim((string) ($loser['category'] ?? ''));
                    if ($cat !== '') {
                        $survivorCat = $cat;
                        self::pdo()->prepare('UPDATE product_aliases SET category = ? WHERE id = ?')
                            ->execute([$cat, $survivorId]);
                        break;
                    }
                }
            }

            // Comblement (jamais d'écrasement) des ventes des libellés absorbés.
            if ($survivorCat !== '') {
                $fill = self::pdo()->prepare(
                    "UPDATE sales SET category = ? WHERE description = ? AND (category IS NULL OR category = '')"
                );
                foreach ($losers as $loser) {
                    $fill->execute([$survivorCat, (string) $loser['raw_description']]);
                }
            }

            $del = self::pdo()->prepare('DELETE FROM product_aliases WHERE id = ?');
            foreach ($losers as $loser) {
                $del->execute([(string) $loser['id']]);
                $deleted += (int) $del->rowCount();
            }
        }

        return $deleted;
    }

    /**
     * Choisit la ligne d'alias survivante d'un groupe de doublons (même
     * forme normalisée). Méthode PURE, ordre de préférence déterministe :
     *
     *  1. le libellé le plus VENDU (occurrences exactes dans sales) —
     *     c'est l'orthographe que les imports utilisent réellement ;
     *  2. à égalité, le libellé identique à la clé canonique ;
     *  3. à égalité, le libellé dont la catégorie est renseignée ;
     *  4. à égalité, le plus ancien (created_at) ;
     *  5. à égalité, l'id le plus petit.
     *
     * @param list<array<string,mixed>>    $rows        Lignes du groupe (id, raw_description, category, created_at).
     * @param array<string,int>            $usage       Libellé brut -> occurrences dans sales.
     * @param string                       $productKey  Clé canonique du groupe.
     *
     * @return string Id de la ligne survivante.
     */
    public static function chooseSurvivor(array $rows, array $usage, string $productKey): string
    {
        usort($rows, static function (array $a, array $b) use ($usage, $productKey): int {
            $ua = $usage[(string) $a['raw_description']] ?? 0;
            $ub = $usage[(string) $b['raw_description']] ?? 0;
            if ($ua !== $ub) {
                return $ub <=> $ua;
            }

            $sa = trim((string) $a['raw_description']) === $productKey ? 1 : 0;
            $sb = trim((string) $b['raw_description']) === $productKey ? 1 : 0;
            if ($sa !== $sb) {
                return $sb <=> $sa;
            }

            $ca = trim((string) ($a['category'] ?? '')) !== '' ? 1 : 0;
            $cb = trim((string) ($b['category'] ?? '')) !== '' ? 1 : 0;
            if ($ca !== $cb) {
                return $cb <=> $ca;
            }

            $ta = (string) ($a['created_at'] ?? '');
            $tb = (string) ($b['created_at'] ?? '');
            if ($ta !== $tb) {
                return $ta <=> $tb;
            }

            return strcmp((string) $a['id'], (string) $b['id']);
        });

        return (string) $rows[0]['id'];
    }
}
