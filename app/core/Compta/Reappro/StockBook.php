<?php

declare(strict_types=1);

namespace App\Core\Compta\Reappro;

use App\Core\Compta\AliasSuggester;
use App\Core\Compta\StockPublic;

/**
 * Livre de stock UNIQUE du réapprovisionnement (pur, sans accès DB).
 *
 * Une seule source de vérité pour le stock consommé par TOUTES les pages
 * réappro (Liste de courses, carte Réapprovisionnement Analytics, page
 * Réappro détaillée) : le stock THÉORIQUE de l'inventaire (dernier
 * comptage + achats − ventes − pertes), avec sa date (dernier comptage).
 * L'ancienne source « product_stocks » (photo mise à jour aux mouvements,
 * bornée à 0, ventes non déduites) produisait des stocks différents de
 * la liste de courses (ex. prod 21/10/2026 : Monster Rose 13 contre 3
 * en théorique) — elle n'est plus lue ici.
 *
 * RAPPROCHEMENT DES NOMS (exigence : « redbull Peach » / « Redbull Peach »,
 * « pepsi Zero » / « Pepsi sans sucre » ne formaient qu'une seule ligne
 * d'un côté, deux de l'autre) — trois passes déterministes :
 *  1. EXACTE insensible à la casse/espaces (trim + strtolower) ;
 *  2. NORMALISÉE (minuscules, accents retirés, ponctuation et espaces
 *     supprimés — StockPublic::normalizeKey : « Red Bull », « red-bull »
 *     et « redbull » convergent) ;
 *  3. ALIAS (table product_aliases : raw_description → product_key),
 *     exact puis normalisé, la clé cible étant relue par les passes 1-2.
 *
 * Le stock théorique PEUT être négatif (survente) : il est retourné tel
 * quel — le signaler comme anomalie d'inventaire et ne jamais l'ajouter
 * au besoin relève du calcul de besoin (BesoinListe / ProductForecast).
 */
final class StockBook
{
    /** @var array<string,array{stock:int,at:?string,orig:string}> entrées par clé EXACTE (product_key d'inventaire) */
    private array $entries;

    /** @var array<string,array{stock:int,at:?string,orig:string}> entrées par clé normalisée (conflits : max) */
    private array $byNorm;

    /** @var array<string,string> alias par libellé brut normalisé → product_key */
    private array $aliasByNorm;

    /** @var array<string,string> alias par libellé brut EXACT (trim) → product_key */
    private array $aliasExact;

    /**
     * @param array<string,int>                    $theoretical Stocks théoriques (clé = product_key d'inventaire).
     * @param array<string,array{at:?string,qty:int,gap:int}> $lastCounts Derniers comptages par product_key (date).
     * @param list<array{raw_description:string,product_key:string}> $aliases Lignes product_aliases.
     */
    public function __construct(array $theoretical, array $lastCounts = [], array $aliases = [])
    {
        $this->entries = [];
        foreach ($theoretical as $key => $stock) {
            $k = trim((string) $key);
            if ($k === '') {
                continue;
            }
            $this->entries[strtolower($k)] = [
                'stock' => (int) $stock,
                'at'    => isset($lastCounts[$k]['at']) ? (string) $lastCounts[$k]['at'] : null,
                'orig'  => $k,
            ];
        }
        // Comptages sans stock théorique (ne devrait pas arriver) : date seule.
        foreach ($lastCounts as $key => $c) {
            $k = trim((string) $key);
            $lk = strtolower($k);
            if ($k !== '' && !isset($this->entries[$lk])) {
                $this->entries[$lk] = ['stock' => 0, 'at' => isset($c['at']) ? (string) $c['at'] : null, 'orig' => $k];
            }
        }

        $this->byNorm = [];
        foreach ($this->entries as $lk => $e) {
            $norm = StockPublic::normalizeKey($lk);
            if ($norm === '') {
                continue;
            }
            if (!isset($this->byNorm[$norm])) {
                $this->byNorm[$norm] = $e;
                continue;
            }
            // Conflit de normalisation : comportement conservateur (max),
            // date la plus récente — cohérent avec StockPublic::normalizeMap.
            // La clé exacte retenue (« orig ») suit l'entrée au stock max.
            if ($e['stock'] > $this->byNorm[$norm]['stock']) {
                $e['at'] = self::dateMax($e['at'], $this->byNorm[$norm]['at']);
                $this->byNorm[$norm] = $e;
            } else {
                $this->byNorm[$norm]['at'] = self::dateMax($e['at'], $this->byNorm[$norm]['at']);
            }
        }

        $this->aliasExact = [];
        $this->aliasByNorm = [];
        foreach ($aliases as $alias) {
            $raw = trim((string) ($alias['raw_description'] ?? ''));
            $to  = trim((string) ($alias['product_key'] ?? ''));
            if ($raw === '' || $to === '') {
                continue;
            }
            $this->aliasExact[$raw] = $to;
            $norm = StockPublic::normalizeKey($raw);
            if ($norm !== '') {
                $this->aliasByNorm[$norm] = $to;
            }
        }
    }

    /**
     * Résout le stock d'un produit (clé de vente / libellé moteur).
     *
     * @return null|array{key:string,stock:int,at:?string,via:'exact'|'normalise'|'alias'}
     *              null = produit sans AUCUNE donnée d'inventaire (ni comptage,
     *              ni achat, ni perte) : à lister à part (« à compter »).
     */
    public function resolve(string $productKey): ?array
    {
        $trim = trim($productKey);
        if ($trim === '') {
            return null;
        }

        $hit = $this->resolveEntry($trim);
        if ($hit === null) {
            return null;
        }

        return ['key' => $hit['key'], 'stock' => $hit['entry']['stock'], 'at' => $hit['entry']['at'], 'via' => $hit['via']];
    }

    /**
     * Résolution « souple » pour l'assistant conversationnel (T4) :
     * resolve() d'abord, puis — si les trois passes échouent — repli par
     * VARIANTE : le libellé est raboté de ses suffixes de couleur/goût
     * (AliasSuggester::suggest : « Monster Blanche » → « monster ») puis
     * re-résolu par les MÊMES passes. Réservé à la suggestion interactive :
     * resolve() reste la voie stricte des pages réappro.
     *
     * Contrairement à resolve(), la clé renvoyée est TOUJOURS la clé
     * EXACTE du livre (« orig », telle que saisie en inventaire) : le
     * consommateur (endpoint assistant) expose une clé canonique stable.
     *
     * @return null|array{key:string,stock:int,at:?string,via:'exact'|'normalise'|'alias'|'variante'}
     */
    public function resolveLoose(string $productKey): ?array
    {
        $trim = trim($productKey);
        if ($trim === '') {
            return null;
        }

        $hit = $this->resolveEntry($trim);
        if ($hit !== null) {
            return ['key' => $hit['entry']['orig'], 'stock' => $hit['entry']['stock'], 'at' => $hit['entry']['at'], 'via' => $hit['via']];
        }

        // Variante : rabotage des suffixes de couleur/goût puis re-résolution
        // par les mêmes passes — jamais de rapprochement ad hoc ici.
        $variante = AliasSuggester::suggest($trim);
        if ($variante !== '' && StockPublic::normalizeKey($variante) !== StockPublic::normalizeKey($trim)) {
            $hit = $this->resolveEntry($variante);
            if ($hit !== null) {
                return ['key' => $hit['entry']['orig'], 'stock' => $hit['entry']['stock'], 'at' => $hit['entry']['at'], 'via' => 'variante'];
            }
        }

        return null;
    }

    /**
     * Clés candidates pour un libellé introuvable (assistant T4) :
     * entrées du livre dont la forme normalisée contient celle du libellé
     * cherché — ou réciproquement (libellé plus large que la clé) — au
     * maximum $limit, tri alphabétique déterministe. Simple SUGGESTION à
     * présenter à l'utilisateur : ne décide jamais rien.
     *
     * @return list<array{key:string,stock:int}>
     */
    public function searchCandidates(string $needle, int $limit = 5): array
    {
        $limit = max(1, $limit);
        $norm = StockPublic::normalizeKey($needle);
        if ($norm === '') {
            return [];
        }

        $out = [];
        foreach ($this->byNorm as $entryNorm => $e) {
            if (str_contains($entryNorm, $norm) || str_contains($norm, $entryNorm)) {
                $out[] = ['key' => $e['orig'], 'stock' => $e['stock']];
            }
        }
        usort($out, static fn (array $a, array $b): int => strcasecmp($a['key'], $b['key']));

        return array_slice($out, 0, $limit);
    }

    /**
     * Trois passes de résolution (exacte → normalisée → alias), cœur
     * partagé de resolve() et resolveLoose().
     *
     * @param string $trim Libellé déjà trimé.
     *
     * @return null|array{entry:array{stock:int,at:?string,orig:string},via:'exact'|'normalise'|'alias',key:string}
     *              « key » = clé PRÉSENTÉE (libellé d'entrée pour les passes
     *              1-2, cible de l'alias pour la passe 3) ; l'entrée du livre
     *              porte la clé EXACTE d'inventaire (« orig »).
     */
    private function resolveEntry(string $trim): ?array
    {
        // Passe 1 : exacte (casse/espaces de bords ignorés).
        $lk = strtolower($trim);
        if (isset($this->entries[$lk])) {
            return ['entry' => $this->entries[$lk], 'via' => 'exact', 'key' => $trim];
        }

        // Passe 2 : normalisée (« Redbull  Peach » == « redbull peach »).
        $norm = StockPublic::normalizeKey($trim);
        if ($norm !== '' && isset($this->byNorm[$norm])) {
            return ['entry' => $this->byNorm[$norm], 'via' => 'normalise', 'key' => $trim];
        }

        // Passe 3 : alias (« pepsi Zero » → « Pepsi sans sucre » si l'alias
        // existe), puis re-résolution EXACTE puis NORMALISÉE de la cible.
        $cible = $this->aliasExact[$trim] ?? ($norm !== '' ? ($this->aliasByNorm[$norm] ?? null) : null);
        if (is_string($cible) && $cible !== '') {
            $cTrim = trim($cible);
            $cLk = strtolower($cTrim);
            if (isset($this->entries[$cLk])) {
                return ['entry' => $this->entries[$cLk], 'via' => 'alias', 'key' => $cTrim];
            }
            $cNorm = StockPublic::normalizeKey($cTrim);
            if ($cNorm !== '' && isset($this->byNorm[$cNorm])) {
                return ['entry' => $this->byNorm[$cNorm], 'via' => 'alias', 'key' => $cTrim];
            }
        }

        return null;
    }

    /**
     * Date du comptage le plus récent du livre (affichage global « stock
     * au JJ/MM/AAAA ») ; null si aucun comptage.
     */
    public function derniereDate(): ?string
    {
        $max = null;
        foreach ($this->entries as $e) {
            $max = self::dateMax($max, $e['at']);
        }

        return $max;
    }

    /**
     * Total des unités restantes du livre (vue d'ensemble « stock
     * restant », ex. tuile Président) : somme des stocks théoriques, UN
     * comptage par produit (dédupliqué par clé normalisée, conflit →
     * max, comme resolve()) ; une survente (stock négatif = anomalie
     * d'inventaire) compte 0, jamais moins — le même bornage que
     * BesoinListe (l'anomalie ne retranche pas du total).
     */
    public function totalUnits(): int
    {
        $total = 0;
        foreach ($this->byNorm as $e) {
            $total += max(0, $e['stock']);
        }

        return $total;
    }

    /**
     * Lignes du livre pour la valorisation : UN comptage par produit
     * (clé normalisée, conflit → max) avec sa clé EXACTE d'inventaire
     * (telle que saisie — les tables de coûts partagent ces clés telles
     * quelles, cf. CostLotStock) et son stock théorique (négatif possible).
     *
     * @return list<array{key:string,stock:int}>
     */
    public function lines(): array
    {
        $lines = [];
        foreach ($this->byNorm as $e) {
            $lines[] = ['key' => $e['orig'], 'stock' => $e['stock']];
        }

        return $lines;
    }

    /**
     * Valeur EUROS du stock restant (méthode PURE) : Σ max(0, stock) ×
     * coût unitaire par clé EXACTE d'inventaire. Un produit sans coût
     * connu (absent de la carte ou NULL) est IGNORÉ — aucune valeur
     * inventée. La carte de coûts vient de l'appelant : le résolveur ne
     * lit aucune base.
     *
     * @param array<string,?float> $unitCosts Coût unitaire par clé exacte (NULL/absent = ignoré).
     */
    public function totalValue(array $unitCosts): float
    {
        $total = 0.0;
        foreach ($this->byNorm as $e) {
            $cost = $unitCosts[$e['orig']] ?? null;
            if ($cost === null) {
                continue;
            }
            $total += max(0, $e['stock']) * (float) $cost;
        }

        return round($total, 2);
    }

    /** La plus récente de deux dates 'Y-m-d HH:MM:SS' (null-aware). */
    private static function dateMax(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }

        return ($b !== null && $b > $a) ? $b : $a;
    }
}
