<?php

declare(strict_types=1);

namespace App\Core\Compta\Reco;

use App\Core\Compta\Forecast\Stats;

/**
 * Contexte de calcul PUR dérivé d'un RecoDataset + un instant $now :
 * toutes les valeurs utilisées par les règles sont calculées
 * PARESSEUSEMENT et mémoïsées (le dataset est immuable). Aucun accès DB.
 */
final class RecoContext
{
    /** @var list<RecoProduct>|null */
    private ?array $produitsFiltres = null;

    /** @var array<string,RecoProduct>|null */
    private ?array $parCle = null;

    /** @var array<string,float> */
    private array $qtyDesaisonCache = [];

    /** @var ?array{0:?float} Sentinelle pour mémoïser un résultat nullable. */
    private ?array $medTauxMarge = null;

    /** @var ?array{0:float} */
    private ?array $medVolume = null;

    /** @var ?list<string> */
    private ?array $topCa = null;

    /** @var ?list<Basket> */
    private ?array $paniersMulti = null;

    /** @var ?array{0:?array<int,float>} */
    private ?array $partsHoraires = null;

    public function __construct(
        private readonly RecoDataset $dataset,
        private readonly \DateTimeImmutable $now
    ) {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function dataset(): RecoDataset
    {
        return $this->dataset;
    }

    /**
     * Hyperparamètre surchargeable : lit « reco_<nom> » dans les
     * paramètres du dataset, retombe sur $defaut (constante Hyper de la
     * règle appelante).
     */
    public function param(string $nom, float|int $defaut): float|int
    {
        return Hyper::valeur($this->dataset->parametres, $nom, $defaut);
    }

    /**
     * Produits éligibles : exclut les catégories Hyper::CATEGORIES_EXCLUES.
     *
     * @return list<RecoProduct>
     */
    public function produitsFiltres(): array
    {
        if ($this->produitsFiltres === null) {
            $this->produitsFiltres = array_values(array_filter(
                $this->dataset->produits,
                static fn (RecoProduct $p): bool => !in_array($p->category, Hyper::CATEGORIES_EXCLUES, true)
            ));
        }

        return $this->produitsFiltres;
    }

    /** Produit par clé (recherche sur TOUT le catalogue, catégories exclues comprises). */
    public function produitParCle(string $key): ?RecoProduct
    {
        if ($this->parCle === null) {
            $this->parCle = [];
            foreach ($this->dataset->produits as $p) {
                $this->parCle[$p->key] = $p;
            }
        }

        return $this->parCle[$key] ?? null;
    }

    /**
     * Quantité désaisonnalisée : qty30 ÷ facteurCreuse si période
     * creuse active, sinon qty30 ; facteur ≤ 0 → qty30 (jamais de
     * division par zéro).
     */
    public function qtyDesaison(string $key): float
    {
        if (isset($this->qtyDesaisonCache[$key])) {
            return $this->qtyDesaisonCache[$key];
        }
        $p = $this->produitParCle($key);
        $qty30 = $p !== null ? $p->qty30 : 0.0;
        $f = $this->dataset->facteurCreuse;
        $qty = ($this->dataset->periodeCreuseActive && $f > 0.0) ? $qty30 / $f : $qty30;

        return $this->qtyDesaisonCache[$key] = $qty;
    }

    /**
     * Médiane des taux de marge des produits à coût utilisable
     * (produitsFiltres) ; null si moins de 3 produits exploitables.
     */
    public function medianeTauxMarge(): ?float
    {
        if ($this->medTauxMarge === null) {
            $taux = [];
            foreach ($this->produitsFiltres() as $p) {
                $t = $p->tauxMarge();
                if ($t !== null) {
                    $taux[] = $t;
                }
            }
            $this->medTauxMarge = [count($taux) < 3 ? null : Stats::median($taux)];
        }

        return $this->medTauxMarge[0];
    }

    /** Médiane des volumes désaisonnalisés des produitsFiltres (0.0 si vide). */
    public function medianeVolume(): float
    {
        if ($this->medVolume === null) {
            $vols = [];
            foreach ($this->produitsFiltres() as $p) {
                $vols[] = $this->qtyDesaison($p->key);
            }
            $this->medVolume = [Stats::median($vols)];
        }

        return $this->medVolume[0];
    }

    /**
     * Clés du « top CA » : max(1, ceil(TOP_CA_PART × n)) produits par
     * ca30 décroissant (produitsFiltres ; égalités : ordre d'entrée).
     *
     * @return list<string>
     */
    public function topCaKeys(): array
    {
        if ($this->topCa === null) {
            $ps = $this->produitsFiltres();
            $n = count($ps);
            if ($n === 0) {
                $this->topCa = [];
            } else {
                $trie = $ps;
                usort($trie, static fn (RecoProduct $a, RecoProduct $b): int => $b->ca30 <=> $a->ca30);
                $k = max(1, (int) ceil(Hyper::TOP_CA_PART * $n));
                $this->topCa = array_slice(array_map(static fn (RecoProduct $p): string => $p->key, $trie), 0, $k);
            }
        }

        return $this->topCa;
    }

    /** Nombre total de paniers de la fenêtre. */
    public function nPaniers(): int
    {
        return count($this->dataset->paniers);
    }

    /**
     * Paniers multi-articles (≥ 2 lignes) — seuls exploitables pour
     * les associations (R6).
     *
     * @return list<Basket>
     */
    public function paniersMulti(): array
    {
        if ($this->paniersMulti === null) {
            $this->paniersMulti = array_values(array_filter(
                $this->dataset->paniers,
                static fn (Basket $b): bool => count($b->lignes) >= 2
            ));
        }

        return $this->paniersMulti;
    }

    /** Montant total du panier (€) = somme des lignes. */
    public function montantPanier(Basket $b): float
    {
        $t = 0.0;
        foreach ($b->lignes as $l) {
            $t += (float) $l['montant'];
        }

        return $t;
    }

    /**
     * Parts de demande par heure (h => part), dérivées du cumul
     * forecast['horaire']['s'] : part(h) = s[h] − s[h−1], s[−1] = 0.
     * null si absent ou plat (somme des parts ≤ 0).
     *
     * @return ?array<int,float>
     */
    public function partsHoraires(): ?array
    {
        if ($this->partsHoraires !== null) {
            return $this->partsHoraires[0];
        }
        $s = $this->dataset->forecast['horaire']['s'] ?? null;
        $parts = null;
        if (is_array($s) && $s !== []) {
            $calc = [];
            $prev = 0.0;
            $ok = true;
            $h = 0;
            foreach ($s as $v) {
                if (!is_numeric($v)) {
                    $ok = false;
                    break;
                }
                $calc[$h] = (float) $v - $prev;
                $prev = (float) $v;
                $h++;
            }
            if ($ok && array_sum($calc) > 0.0) {
                $parts = $calc;
            }
        }
        $this->partsHoraires = [$parts];

        return $parts;
    }

    /**
     * Empreinte stable d'une reco : sha1('règle:clés triées uniques') —
     * sert à l'historique (actifs / refus / appliquées).
     *
     * @param list<string> $keys
     */
    public function fingerprint(string $regle, array $keys): string
    {
        $keys = array_values(array_unique($keys));
        sort($keys);

        return sha1($regle . ':' . implode(',', $keys));
    }
}
