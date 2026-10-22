<?php

declare(strict_types=1);

namespace App\Core\Compta\Reappro;

use App\Core\Compta\StockPublic;

/**
 * Résolution du pack d'achat d'un produit (pur, sans accès DB).
 *
 * Les packs (product_packs) sont enregistrés sous les product_key de la
 * page Réappro, mais la liste de courses travaille sur les lignes du
 * moteur de prévision dont la clé et le libellé viennent des ventes
 * (descriptions parfois différentes : casse, accents, ponctuation, alias).
 * Une lecture « exacte lower » seule rate les packs (ex. « Pepsi zéro »
 * vs « pepsi Zero » → commande à l'unité de 5 cannettes au lieu d'un
 * pack de 6) — régression signalée le 22/10/2026.
 *
 * Mêmes passes de rapprochement que StockBook, appliquées à CHAQUE
 * candidat (clé moteur ET libellé) :
 *  1. EXACTE insensible à la casse/espacements de bords ;
 *  2. NORMALISÉE (minuscules, sans accents, séparateurs/espaces repliés) ;
 *  3. ALIAS (raw_description → product_key), exact puis normalisé, la
 *     cible étant relue par les passes 1-2.
 *
 * Conflits (plusieurs candidats trouvent des packs différents) : MAX —
 * conservateur, on préfère arrondir au pack plus grand que commander
 * à l'unité.
 */
final class PackResolver
{
    /** @var array<string,int> tailles par clé EXACTE (lower/trim) */
    private array $byLower;

    /** @var array<string,int> tailles par clé NORMALISÉE (conflit → max) */
    private array $byNorm;

    /** @var array<string,string> alias par libellé brut EXACT (trim) */
    private array $aliasExact;

    /** @var array<string,string> alias par libellé brut NORMALISÉ */
    private array $aliasByNorm;

    /**
     * @param array<string,int>                                  $sizes   Tailles de pack (clé = product_key).
     * @param list<array{raw_description:string,product_key:string}> $aliases Lignes product_aliases.
     */
    public function __construct(array $sizes, array $aliases = [])
    {
        $this->byLower = [];
        foreach ($sizes as $key => $size) {
            $k = strtolower(trim((string) $key));
            if ($k !== '' && (int) $size > 0) {
                $this->byLower[$k] = (int) $size;
            }
        }

        $this->byNorm = [];
        foreach ($this->byLower as $lk => $size) {
            $norm = StockPublic::normalizeKey($lk);
            if ($norm === '') {
                continue;
            }
            $this->byNorm[$norm] = isset($this->byNorm[$norm])
                ? max($size, $this->byNorm[$norm])
                : $size;
        }

        $this->aliasExact  = [];
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
     * Taille du pack du premier candidat qui en a un (0 = à l'unité).
     *
     * @param string ...$candidates Clés/libellés du produit (ex. clé moteur, puis libellé).
     */
    public function resolve(string ...$candidates): int
    {
        $best = 0;
        foreach ($candidates as $c) {
            $best = max($best, $this->resolveOne(trim($c)));
            if ($best > 0) {
                break; // premier candidat qui trouve un pack l'impose
            }
        }

        return $best;
    }

    private function resolveOne(string $c): int
    {
        if ($c === '') {
            return 0;
        }

        // Passe 1 : exacte (casse/espacements de bords ignorés).
        $lk = strtolower($c);
        if (isset($this->byLower[$lk])) {
            return $this->byLower[$lk];
        }

        // Passe 2 : normalisée.
        $norm = StockPublic::normalizeKey($c);
        if ($norm !== '' && isset($this->byNorm[$norm])) {
            return $this->byNorm[$norm];
        }

        // Passe 3 : alias (« pepsi Zero » → « Pepsi sans sucre »), puis
        // relecture exacte/normalisée de la cible.
        $cible = $this->aliasExact[$c] ?? ($norm !== '' ? ($this->aliasByNorm[$norm] ?? null) : null);
        if (is_string($cible) && $cible !== '') {
            $cLk = strtolower(trim($cible));
            if (isset($this->byLower[$cLk])) {
                return $this->byLower[$cLk];
            }
            $cNorm = StockPublic::normalizeKey($cible);
            if ($cNorm !== '' && isset($this->byNorm[$cNorm])) {
                return $this->byNorm[$cNorm];
            }
        }

        return 0;
    }
}
