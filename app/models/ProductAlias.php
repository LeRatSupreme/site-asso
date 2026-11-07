<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Compta\StockPublic;
use App\Core\Logger;

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
     * `kind` : 'appellation' pour un libellé long de facture/scan (page
     * « Ajouter une appellation »), 'label' (défaut) pour un alias
     * produit. À la MISE À JOUR d'une ligne existante, un kind absent
     * préserve celui en base (la grille des catégories ne reclasse
     * jamais une appellation) ; un kind explicite reclasse la ligne
     * (ressaisir un libellé sur la page appellations le déplace).
     *
     * RÈGLE ANTI-DOUBLONS D'APPELLATIONS : un libellé qui NORMALISE
     * (StockPublic::normalizeKey : casse, accents, ponctuation,
     * espacements) comme une ligne existante — « Coca_cherry » face à
     * « Coca cherry » — FUSIONNE avec elle : la ligne existante est mise
     * à jour (cible, catégorie, kind), JAMAIS une seconde ligne. La
     * fusion est tracée 'alias.fusion' avec les occurrences de ventes
     * avant/après (ex. 80+6→86) — aucune perte de compteur ni de cible.
     *
     * @param array<string,mixed> $data
     * @param string|null         $userId Auteur (audit de la fusion), null en import CLI.
     */
    public static function save(array $data, ?string $userId = null): string
    {
        $raw = trim((string) ($data['raw_description'] ?? ''));
        $productKey = trim((string) ($data['product_key'] ?? ''));
        $category = ($data['category'] ?? '') !== '' ? (string) $data['category'] : null;
        $kind = trim((string) ($data['kind'] ?? ''));
        if (!in_array($kind, ['label', 'appellation'], true)) {
            $kind = '';
        }

        if ($raw === '' || $productKey === '') {
            return '';
        }

        $existing = self::findByRaw($raw);
        $doublonNormalise = null;
        if ($existing === null) {
            // Garde anti-doublons : libellé inconnu à l'écriture près mais
            // de même forme NORMALISÉE qu'une ligne existante → FUSION sur
            // cette ligne (la plus ancienne du groupe fait référence).
            $doublonNormalise = self::findByNormalizedRaw($raw);
            $existing = $doublonNormalise;
        }

        if ($existing !== null) {
            $cibleAvant = (string) $existing['product_key'];
            if ($kind !== '') {
                $stmt = self::pdo()->prepare(
                    'UPDATE product_aliases SET product_key = ?, category = ?, kind = ? WHERE id = ?'
                );
                $stmt->execute([$productKey, $category, $kind, $existing['id']]);
            } else {
                $stmt = self::pdo()->prepare(
                    'UPDATE product_aliases SET product_key = ?, category = ? WHERE id = ?'
                );
                $stmt->execute([$productKey, $category, $existing['id']]);
            }

            if ($doublonNormalise !== null) {
                self::tracerFusion($doublonNormalise, $raw, $cibleAvant, $productKey, $userId);
            }

            return (string) $existing['id'];
        }

        $id = 'alias_' . bin2hex(random_bytes(10));
        $stmt = self::pdo()->prepare(
            "INSERT INTO product_aliases (id, raw_description, product_key, category, kind, created_at)
             VALUES (?,?,?,?,?, NOW())"
        );
        $stmt->execute([$id, $raw, $productKey, $category, $kind !== '' ? $kind : 'label']);

        return $id;
    }

    /**
     * Première ligne d'alias de même forme NORMALISÉE que le libellé
     * (la plus ancienne du groupe fait référence), null si aucune :
     * même repliement que resolve() — « Coca_cherry » retrouve la ligne
     * « Coca cherry ». Garde structurelle anti-doublons d'appellations.
     *
     * @return array<string,mixed>|null
     */
    private static function findByNormalizedRaw(string $raw): ?array
    {
        $norm = StockPublic::normalizeKey($raw);
        if ($norm === '') {
            return null;
        }

        try {
            /** @var list<array<string,mixed>> $rows */
            $rows = self::pdo()
                ->query('SELECT * FROM product_aliases ORDER BY created_at ASC, id ASC')
                ->fetchAll();
        } catch (\Throwable) {
            return null;
        }

        foreach ($rows as $row) {
            if (StockPublic::normalizeKey((string) ($row['raw_description'] ?? '')) === $norm) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Trace d'audit d'une FUSION d'appellations (« alias.fusion ») :
     * libellé conservé, libellé absorbé (disparaît physiquement mais
     * ses données sont d'abord fusionnées), cible avant/après et
     * occurrences de ventes avant/après (ex. 80+6→86) — jamais une
     * simple suppression silencieuse. La trace ne doit jamais faire
     * échouer la fusion elle-même.
     *
     * @param array<string,mixed> $ligne Ligne d'alias conservée (avant mise à jour).
     */
    private static function tracerFusion(array $ligne, string $absorbe, string $cibleAvant, string $cibleApres, ?string $userId): void
    {
        $conserve = (string) ($ligne['raw_description'] ?? '');
        $avant = self::occurrencesVentes([$conserve, $absorbe]);

        try {
            AuditLog::log('alias.fusion', $userId, 'product_alias', (string) $ligne['id'], [
                'conserve'            => $conserve,
                'absorbe'             => $absorbe,
                'cible_avant'         => $cibleAvant,
                'cible_apres'         => $cibleApres,
                'occurrences_avant'   => $avant,
                'occurrences_apres'   => array_sum($avant),
            ]);
        } catch (\Throwable $e) {
            Logger::warning('alias: trace de fusion impossible', [
                'conserve' => $conserve,
                'absorbe'  => $absorbe,
                'msg'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Renomme le LIBELLÉ d'une ligne d'alias existante (tableau « Alias
     * existants » de la page Mapping des libellés) SANS jamais déplacer
     * sa cible produit :
     *
     *  a. libellé neuf ou de même forme NORMALISÉE que l'ancien (casse,
     *     accents, ponctuation…) → simple renommage de la ligne, cible /
     *     catégorie / kind conservés ;
     *  b. libellé de la forme normalisée d'une AUTRE ligne existante de
     *     MÊME produit → FUSION par l'upsert de save() : la ligne
     *     existante fait foi (SA cible est conservée, jamais l'inverse,
     *     et son orthographe reste la ligne de référence), la ligne
     *     renommée disparaît, trace « alias.fusion » ;
     *  c. libellé déjà rattaché à un produit DIFFÉRENT — alias existant
     *     d'autre cible, ou clé canonique d'un autre produit (fiche
     *     carte, cible d'alias, produit vendu) — → REFUS : AUCUNE
     *     écriture pour la ligne, le motif est retourné pour l'UI (fini
     *     l'écrasement silencieux — SB3).
     *
     * Les collisions se résolvent par les mécanismes partagés existants
     * (findByRaw / findByNormalizedRaw / StockPublic::normalizeKey) —
     * jamais de comparaison strtolower ad hoc.
     *
     * @param string            $id        Id de la ligne à renommer.
     * @param string            $newRaw    Nouveau libellé.
     * @param string|null       $userId    Auteur (audit de la fusion), null en CLI.
     * @param list<string>|null $knownKeys Clés produits connues (assemblage
     *                                     knownProductKeys() ; réassemblé si null).
     *
     * @return array{status:'unchanged'|'renamed'|'fused'|'refused'|'missing', message:string, old:string, new:string, product_key:string, id:string}
     */
    public static function rename(string $id, string $newRaw, ?string $userId = null, ?array $knownKeys = null): array
    {
        $new = trim($newRaw);

        $row = self::find($id);
        if ($row === null) {
            return [
                'status'      => 'missing',
                'message'     => 'Ligne d\'alias introuvable (déjà supprimée ?).',
                'old'         => '',
                'new'         => $new,
                'product_key' => '',
                'id'          => $id,
            ];
        }

        $old    = trim((string) $row['raw_description']);
        $target = trim((string) $row['product_key']);
        $mk     = static fn (string $status, string $message): array => [
            'status'      => $status,
            'message'     => $message,
            'old'         => $old,
            'new'         => $new,
            'product_key' => $target,
            'id'          => $id,
        ];

        if ($new === $old) {
            return $mk('unchanged', 'Libellé inchangé.');
        }
        if ($new === '') {
            return $mk('refused', 'le libellé ne peut pas être vide.');
        }
        if (mb_strlen($new) > 255) {
            return $mk('refused', 'le libellé est limité à 255 caractères.');
        }

        $normNew    = StockPublic::normalizeKey($new);
        $normTarget = StockPublic::normalizeKey($target);

        // b/c1 — collision avec une AUTRE ligne : résolution EXACTE puis
        // normalisée, mêmes mécanismes que resolve()/save().
        $exactHit = false;
        $other    = self::findByRaw($new);
        if ($other !== null && (string) $other['id'] === $id) {
            $other = null;
        }
        if ($other !== null) {
            $exactHit = true;
        } else {
            $other = self::findByNormalizedRaw($new);
            if ($other !== null && (string) $other['id'] === $id) {
                $other = null; // même forme normalisée que NOTRE ligne : cas a
            }
        }

        if ($other !== null) {
            $otherRaw    = trim((string) $other['raw_description']);
            $otherTarget = trim((string) $other['product_key']);

            // c1 — produits DISTINCTS : refus, jamais d'écrasement de la
            // cible de l'alias existant (l'upsert de save() le ferait
            // silencieusement — SB3).
            if (StockPublic::normalizeKey($otherTarget) !== $normTarget) {
                return $mk(
                    'refused',
                    '« ' . $new . ' » est déjà le libellé du produit « ' . $otherTarget
                    . ' » (cette ligne vise « ' . $target . ' »).'
                );
            }

            // b — FUSION : la ligne existante fait foi (sa cible, sa
            // catégorie comblée par la nôtre si vide — même règle que
            // consolidateFor), trace « alias.fusion », notre ligne disparaît.
            $category = trim((string) ($other['category'] ?? '')) !== ''
                ? (string) $other['category']
                : (trim((string) ($row['category'] ?? '')) !== '' ? (string) $row['category'] : null);

            $pdo = self::pdo();
            $pdo->beginTransaction();
            try {
                self::deleteRow($id);
                self::save([
                    'raw_description' => $new,
                    'product_key'     => $otherTarget,
                    'category'        => $category,
                ], $userId);
                // Chemin EXACT : save() n'a rien tracé (le libellé existait
                // déjà tel quel, pas de doublon normalisé pour lui) — la trace
                // de la ligne absorbée est posée ici, mêmes conventions.
                if ($exactHit) {
                    self::tracerFusion($other, $old, $otherTarget, $otherTarget, $userId);
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            return [
                'status'      => 'fused',
                'message'     => '« ' . $old . ' » fusionné dans le libellé existant « ' . $otherRaw
                    . ' » (produit « ' . $otherTarget . ' » conservé).',
                'old'         => $old,
                'new'         => $new,
                'product_key' => $otherTarget,
                'id'          => (string) $other['id'],
            ];
        }

        // c2 — le libellé ne doit pas être non plus une CLÉ CANONIQUE
        // d'un autre produit : l'alias détournerait ce nom vers sa cible.
        $conflict = self::knownKeyConflict($normNew, $normTarget, $knownKeys ?? self::knownProductKeys());
        if ($conflict !== null) {
            return $mk(
                'refused',
                '« ' . $new . ' » est le nom d\'un autre produit (« ' . $conflict . ' »), pas un simple libellé.'
            );
        }

        // a — simple renommage : seule l'orthographe change, la ligne
        // garde sa cible, sa catégorie et son kind.
        self::pdo()
            ->prepare('UPDATE product_aliases SET raw_description = ? WHERE id = ?')
            ->execute([$new, $id]);

        return $mk('renamed', 'Libellé « ' . $old . ' » renommé en « ' . $new . ' » (produit « ' . $target . ' » conservé).');
    }

    /**
     * Première clé produit connue de même forme NORMALISÉE que le
     * libellé testé, quand elle ne désigne PAS le produit de la ligne
     * renommée (garde c2 de rename()) : le libellé deviendrait une clé
     * canonique détournée vers un autre produit.
     *
     * @param list<string> $knownKeys
     */
    private static function knownKeyConflict(string $normNew, string $normTarget, array $knownKeys): ?string
    {
        if ($normNew === '') {
            return null;
        }
        if ($normNew === $normTarget) {
            return null; // orthographe canonique de NOTRE produit : autorisé
        }

        foreach ($knownKeys as $key) {
            $key = trim((string) $key);
            if ($key !== '' && StockPublic::normalizeKey($key) === $normNew) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Occurrences réelles (ventes) de libellés bruts : lignes de vente
     * dont la clé résolue (COALESCE(product_key, description), même
     * convention que Sale::lastSoldByProduct) s'écrit comme le libellé
     * (comparaison insensible à la casse/accents, collation ci).
     *
     * @param list<string> $labels
     *
     * @return array<string,int> Libellé demandé → nombre de ventes (0 si aucune).
     */
    private static function occurrencesVentes(array $labels): array
    {
        $labels = array_values(array_unique(array_map('trim', $labels)));
        $labels = array_values(array_filter($labels, static fn (string $l): bool => $l !== ''));
        $out = array_fill_keys($labels, 0);
        if ($labels === []) {
            return $out;
        }

        try {
            $placeholders = implode(',', array_fill(0, count($labels), '?'));
            $stmt = self::pdo()->prepare(
                'SELECT COALESCE(product_key, description) AS k, COUNT(*) AS n
                 FROM sales
                 WHERE COALESCE(product_key, description) IN (' . $placeholders . ')
                 GROUP BY k'
            );
            $stmt->execute($labels);
            foreach ($stmt->fetchAll() as $row) {
                $k = (string) $row['k'];
                foreach ($labels as $label) {
                    if (strcasecmp($label, $k) === 0) {
                        $out[$label] += (int) $row['n'];
                    }
                }
            }
        } catch (\Throwable) {
            // Sans lecture des ventes, la fusion reste tracée (compteurs à 0).
        }

        return $out;
    }

    /**
     * Recrée un alias SEULEMENT si son libellé n'existe plus nulle part
     * (contrairement à save(), qui réaffecterait un libellé vivant) :
     * primitive de la restauration d'une clé fusionnée (T1MergeArchive) —
     * un alias migré sur la cible lors de la fusion n'est jamais volé.
     *
     * @return bool true si l'alias a été créé, false s'il existait déjà.
     */
    public static function ensureAbsentCreate(string $rawDescription, string $productKey): bool
    {
        $raw = trim($rawDescription);
        $productKey = trim($productKey);
        if ($raw === '' || $productKey === '') {
            return false;
        }

        try {
            if (self::findByRaw($raw) !== null) {
                return false;
            }
            $id = 'alias_' . bin2hex(random_bytes(10));
            $stmt = self::pdo()->prepare(
                "INSERT INTO product_aliases (id, raw_description, product_key, kind, created_at)
                 VALUES (?,?,?, 'label', NOW())"
            );
            $stmt->execute([$id, $raw, $productKey]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Alias produits uniquement (hors appellations de facture/scan) :
     * c'est la liste de la page « Mapping des libellés ».
     *
     * @return list<array<string,mixed>>
     */
    public static function allLabels(): array
    {
        try {
            /** @var list<array<string,mixed>> $r */
            return self::pdo()
                ->query(
                    "SELECT * FROM product_aliases
                     WHERE kind IS NULL OR kind <> 'appellation'
                     ORDER BY product_key, raw_description"
                )
                ->fetchAll();
        } catch (\Throwable) {
            return self::all();
        }
    }

    /**
     * Appellations enregistrées (libellés longs facture/CSV), de la plus
     * récente à la plus ancienne : liste du bas de la page
     * « Ajouter une appellation ».
     *
     * @return list<array<string,mixed>>
     */
    public static function appellations(): array
    {
        try {
            /** @var list<array<string,mixed>> $r */
            return self::pdo()
                ->query(
                    "SELECT * FROM product_aliases WHERE kind = 'appellation'
                     ORDER BY created_at DESC, raw_description"
                )
                ->fetchAll();
        } catch (\Throwable) {
            return [];
        }
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
        if ($from === '' || $to === '') {
            return ['repointed' => 0, 'absorbed' => 0];
        }

        $stmt = self::pdo()->prepare('SELECT id, raw_description, product_key FROM product_aliases WHERE product_key = ?');
        $stmt->execute([$from]);
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();

        $repoint = self::pdo()->prepare('UPDATE product_aliases SET product_key = ? WHERE id = ?');
        $repointed = 0;
        $absorbed = 0;
        foreach ($rows as $row) {
            // Collation ci : la requête attrape aussi les lignes déjà
            // rattachées à la cible par une variante de casse — elles
            // sont déjà en place, rien à faire ni à compter.
            if ((string) $row['product_key'] === $to) {
                continue;
            }

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
        if ($raw === '' || $to === '') {
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
     * Clés produits CONNUES (point d'assemblage unique, réutilisé par le
     * datalist « Fusionner deux produits » et la garde anti-collision du
     * renommage — rename()/knownKeyConflict) : noms des fiches carte
     * (products.name), cibles des alias (product_aliases.product_key) et
     * produits réellement vendus (COALESCE(product_key, description) de
     * sales). Trié, dédupliqué, sans chaîne vide.
     *
     * @return list<string>
     */
    public static function knownProductKeys(): array
    {
        $all = array_unique(array_merge(
            array_map('strval', array_column(Product::allForAdmin(), 'name')),
            array_map('strval', array_column(self::all(), 'product_key')),
            Sale::distinctProducts()
        ), SORT_STRING);

        $out = [];
        foreach ($all as $k) {
            $key = trim((string) $k);
            if ($key !== '') {
                $out[] = $key;
            }
        }
        sort($out, SORT_STRING);

        return $out;
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
