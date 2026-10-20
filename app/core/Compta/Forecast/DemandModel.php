<?php

declare(strict_types=1);

namespace App\Core\Compta\Forecast;

/**
 * Étapes 1-2 du cœur de prévision : modèle de demande N × P (N =
 * transactions, P = panier moyen) ajusté sur les jours ouverts PASSÉS.
 *
 * Structure (hyperparamètres fixes de Hyper, choix de mission) :
 *  - y_d = log(max(N_d, 1)) : modèle multiplicatif en log ;
 *  - effets de jour de semaine RÉTRÉCIS (partial pooling, poids des
 *    jours) : m_j = moyenne pondérée de y sur le dow j, m_g = moyenne
 *    pondérée globale, w_j = poids cumulé du dow ;
 *        e_j = (w_j × m_j + k × m_g) / (w_j + k)   (e_j = m_g si w_j < RARE_DOW)
 *    puis a_j = e_j − m̄ avec m̄ = moyenne des e_j sur les seuls dows
 *    ouverts habituels ;
 *  - niveau local à tendance amortie (α = ALPHA_SES, φ = PHI_DAMP) :
 *        l_t = α·w_t·ỹ_t + (1 − α·w_t)·(l_{t−1} + φ·b_{t−1})
 *        b_t = φ·(l_t − l_{t−1}) + (1 − φ)·b_{t−1}
 *    avec ỹ_t = y_t − (med_j − m_g) où med_j est la MÉDIANE de y sur le
 *    dow j : la ligne de base du démeanement est ROBUSTE (une journée
 *    atypique ne déplace ni med_j ni la chaîne), le niveau global
 *    restant la moyenne pondérée m_g. La chaîne est ainsi ajustée sur
 *    une série débarrassée de la saisonnalité hebdomadaire (pour une
 *    série périodique sans bruit, med_j = m_j et ỹ ≡ m_g : niveau
 *    exactement plat, tendance nulle). La prévision ajoute ensuite
 *    l'effet dow RÉTRÉCI a_j : chaque effet n'est compté qu'une seule
 *    fois. Le poids w_t (1.0, ou W_ATYPICAL pour un jour atypique)
 *    multiplie l'innovation du niveau : w_t = 1 redonne le SES standard,
 *    w_t = 0.3 ne laisse passer qu'un tiers de l'écart du jour (α×w =
 *    0.09) — la tendance b, pilotée par l'écart de l, est amortie
 *    d'autant ;
 *  - init l_1 = ỹ_1, b_1 = 0.
 *
 * Prévision : h = nb de jours calendaires entre le DERNIER jour de
 * l'historique et la date visée (≥ 1 ; date ≤ dernier jour → h = 1) ;
 * terme de tendance amortie b_T × Σ_{i=1..h} φ^i = b_T × φ(1−φ^h)/(1−φ)
 * (converge vers b_T/(1−φ), jamais extrapolé indéfiniment) ;
 * retour = exp(l_T + tendance + a_j) si le dow est ouvert habituel,
 * sinon 0.0 ; 0.0 sans historique.
 *
 * NB openDaysCount est une MÉTHODE (et non une propriété) : le moteur
 * ForecastEngine — consommateur non modifiable — l'appelle en méthode.
 */
final class DemandModel
{
    /** Moyenne pondérée des N par jour ouvert (0.0 sans historique). */
    public readonly float $globalN;

    /** Moyenne tronquée globale des paniers (0.0 si aucun panier). */
    public readonly float $globalP;

    /** @var list<int> dows ouverts habituels (0 = lundi … 6 = dimanche). */
    public readonly array $openWeekdays;

    /**
     * Effets rétrécis par dow vu : n = N moyen rétréci du dow (pool de la
     * moyenne N brute vers globalN, poids = poids cumulé du dow arrondi),
     * p = panier rétréci du dow (pool de la moyenne tronquée du dow vers
     * globalP, poids = nb de paniers du dow), occ = nb de jours vus.
     *
     * @var array<int, array{n: float, p: float, occ: int}>
     */
    public readonly array $dowEffects;

    /** Niveau final de la chaîne SES (log, démeané). */
    public readonly float $lastLevel;

    /** Tendance finale amortie de la chaîne SES (log par jour). */
    public readonly float $lastTrend;

    /**
     * Dispersion NegBin par la méthode des moments in-sample : μ̂_d est
     * l'attendu du modèle à état FINAL (approximation documentée), et
     * r̂ = Σ w μ̂² / (Σ w (x−μ̂)² − Σ w μ̂), clampée [R_MIN, R_MAX] ;
     * dénominateur ≤ 0 (sous-dispersion ou résidus nuls) → R_MAX
     * (quasi-Poisson).
     */
    public readonly float $dispersionR;

    /** @var array<int, float> effet dow rétréci a_j (dows ouverts habituels uniquement). */
    private array $dowA;

    /** @var array<int, int> nb de jours vus par dow. */
    private array $dowOcc;

    /** @var array<int, list<float>> paniers observés par dow (tout l'historique). */
    private array $dowBaskets;

    /** @var list<DayCurrent> jours ajustés, triés par ordre chronologique. */
    private array $sortedDays;

    /** @var list<float> paniers de tous les jours ajustés (ordre chronologique). */
    private array $allBaskets;

    private string $lastKey;

    private bool $hasHistory;

    /** @var array<string, float> poids de journée par clé 'Y-m-d' (défaut 1.0). */
    private array $dayWeights;

    /**
     * @param array<int, float>                                    $dowA
     * @param array<int, int>                                      $dowOcc
     * @param array<int, list<float>>                              $dowBaskets
     * @param array<int, array{n: float, p: float, occ: int}>      $dowEffects
     * @param list<int>                                            $openWeekdays
     * @param list<DayCurrent>                                     $sortedDays
     * @param list<float>                                          $allBaskets
     */
    private function __construct(
        float $globalN,
        float $globalP,
        array $openWeekdays,
        array $dowEffects,
        float $lastLevel,
        float $lastTrend,
        float $dispersionR,
        array $dowA,
        array $dowOcc,
        array $dowBaskets,
        array $sortedDays,
        array $allBaskets,
        string $lastKey,
        array $dayWeights = []
    ) {
        $this->globalN = $globalN;
        $this->globalP = $globalP;
        $this->openWeekdays = $openWeekdays;
        $this->dowEffects = $dowEffects;
        $this->lastLevel = $lastLevel;
        $this->lastTrend = $lastTrend;
        $this->dispersionR = $dispersionR;
        $this->dowA = $dowA;
        $this->dowOcc = $dowOcc;
        $this->dowBaskets = $dowBaskets;
        $this->sortedDays = $sortedDays;
        $this->allBaskets = $allBaskets;
        $this->lastKey = $lastKey;
        $this->hasHistory = $sortedDays !== [];
        $this->dayWeights = $dayWeights;
    }

    /**
     * Ajuste le modèle sur les jours ouverts passés.
     *
     * @param array<string, DayCurrent> $days        jours ouverts PASSÉS uniquement
     * @param array<string, float>      $weights     'Y-m-d' => poids (défaut 1.0 ; jour
     *                                               atypique = Hyper::W_ATYPICAL, cf.
     *                                               Clean::atypicalWeights)
     * @param float                     $k           intensité du rétrécissement partial pooling
     * @param ?float                    $dispersionR IMPOSE la dispersion NegBin (clampée
     *                                               [R_MIN, R_MAX]) au lieu de la recalculer
     *                                               par la méthode des moments : la dispersion
     *                                               est une propriété GLOBALE du modèle — le
     *                                               refit « hier » (sans le jour expliqué) doit
     *                                               réutiliser le r du modèle principal, sinon
     *                                               son estimateur local s'effondre vers R_MAX
     *                                               (quasi-Poisson) et la fourchette « Hier »
     *                                               devient absurdemment étroite vs « Aujourd'hui »
     *                                               (dump prod : r principal 18,0 vs refit 200).
     */
    public static function fit(array $days, array $weights = [], float $k = Hyper::K_POOL, ?float $dispersionR = null): self
    {
        // Tri chronologique (clés 'Y-m-d' triables lexicographiquement).
        $sorted = [];
        foreach ($days as $key => $day) {
            if ($day instanceof DayCurrent) {
                $sorted[$day->date] = $day;
            }
        }
        ksort($sorted);
        $nDays = count($sorted);
        if ($nDays === 0) {
            return new self(0.0, 0.0, [], [], 0.0, 0.0, Hyper::R_MAX, [], [], [], [], [], '');
        }

        $keys     = array_keys($sorted);
        $lastKey  = (string) $keys[$nDays - 1];
        $lastDate = new \DateTimeImmutable($lastKey);
        $todayKey = $lastDate->modify('+1 day')->format('Y-m-d');
        $openWeekdays = Clean::openWeekdays($sorted, $todayKey);

        // Passe 1 : effets de dow (log) bruts + cumuls par dow.
        $sumY = array_fill(0, 7, 0.0);
        $sumW = array_fill(0, 7, 0.0);
        $sumN = array_fill(0, 7, 0.0);
        $occ  = array_fill(0, 7, 0);
        $ysByDow = array_fill(0, 7, []);
        $dowBaskets = [];
        $allBaskets = [];
        $gYs = 0.0;
        $gWs = 0.0;
        $gNs = 0.0;
        foreach ($sorted as $key => $day) {
            $dow = self::dowOf((string) $key);
            $w   = self::weightOf($weights, (string) $key);
            $y   = log(max($day->n, 1));
            $sumY[$dow] += $w * $y;
            $sumW[$dow] += $w;
            $sumN[$dow] += $w * $day->n;
            $occ[$dow]++;
            $ysByDow[$dow][] = $y;
            foreach ($day->baskets as $b) {
                $dowBaskets[$dow][] = (float) $b;
                $allBaskets[] = (float) $b;
            }
            $gYs += $w * $y;
            $gWs += $w;
            $gNs += $w * $day->n;
        }

        $globalN = $gWs > 0.0 ? $gNs / $gWs : 0.0;
        $mG      = $gWs > 0.0 ? $gYs / $gWs : 0.0;

        // Effets bruts par dow (pour démeaner la chaîne) : médiane ROBUSTE
        // du dow (un jour atypique ne déplace pas la ligne de base) − m_g.
        $rawEffect = [];
        for ($dow = 0; $dow < 7; $dow++) {
            $rawEffect[$dow] = $occ[$dow] > 0
                ? Stats::median($ysByDow[$dow]) - $mG
                : 0.0;
        }

        // Effets rétrécis e_j sur les dows ouverts habituels, normalisés (a_j).
        $e = [];
        foreach ($openWeekdays as $dow) {
            $wj = $sumW[$dow];
            $mj = $wj > 0.0 ? $sumY[$dow] / $wj : $mG;
            $e[$dow] = $wj < (float) Hyper::RARE_DOW
                ? $mG
                : ($wj * $mj + $k * $mG) / ($wj + $k);
        }
        $mBar = $e === [] ? $mG : array_sum($e) / count($e);
        $dowA = [];
        foreach ($e as $dow => $ej) {
            $dowA[$dow] = $ej - $mBar;
        }

        // Passe 2 : niveau + tendance amortis sur la série démeanée.
        $level = 0.0;
        $trend = 0.0;
        $first = true;
        foreach ($sorted as $key => $day) {
            $dow = self::dowOf((string) $key);
            $w   = self::weightOf($weights, (string) $key);
            $yT  = log(max($day->n, 1)) - $rawEffect[$dow];
            if ($first) {
                $level = $yT;
                $trend = 0.0;
                $first = false;
                continue;
            }
            $innov  = Hyper::ALPHA_SES * $w;
            $levelNew = $innov * $yT + (1.0 - $innov) * ($level + Hyper::PHI_DAMP * $trend);
            $trend    = Hyper::PHI_DAMP * ($levelNew - $level) + (1.0 - Hyper::PHI_DAMP) * $trend;
            $level    = $levelNew;
        }

        // Effets publics par dow (rétrécis, linéaires).
        $globalP = Stats::trimmedMean($allBaskets, Hyper::TRIM);
        $dowEffects = [];
        for ($dow = 0; $dow < 7; $dow++) {
            if ($occ[$dow] === 0) {
                continue;
            }
            $baskets = $dowBaskets[$dow] ?? [];
            $nBaskets = count($baskets);
            $pDow = $nBaskets > 0
                ? Stats::pool(Stats::trimmedMean($baskets, Hyper::TRIM), $nBaskets, $globalP, $k)
                : $globalP;
            $dowEffects[$dow] = [
                'n'   => Stats::pool($sumW[$dow] > 0.0 ? $sumN[$dow] / $sumW[$dow] : 0.0, (int) round($sumW[$dow]), $globalN, $k),
                'p'   => $pDow,
                'occ' => $occ[$dow],
            ];
        }

        $model = new self(
            $globalN,
            $globalP,
            $openWeekdays,
            $dowEffects,
            $level,
            $trend,
            Hyper::R_MAX, // dispersion provisoire, recalcée ci-dessous
            $dowA,
            $occ,
            $dowBaskets,
            array_values($sorted),
            $allBaskets,
            $lastKey
        );

        if ($dispersionR !== null) {
            return new self(
                $globalN,
                $globalP,
                $openWeekdays,
                $dowEffects,
                $level,
                $trend,
                min(Hyper::R_MAX, max(Hyper::R_MIN, (float) $dispersionR)),
                $dowA,
                $occ,
                $dowBaskets,
                array_values($sorted),
                $allBaskets,
                $lastKey
            );
        }

        return $model->withDispersion();
    }

    /**
     * Nombre attendu de transactions pour $date (0.0 si dow fermé
     * habituel ou sans historique).
     */
    public function expectedN(\DateTimeImmutable $date): float
    {
        if (!$this->hasHistory) {
            return 0.0;
        }
        $dow = self::dowOfDate($date);
        if (!in_array($dow, $this->openWeekdays, true)) {
            return 0.0;
        }
        $lastDate = new \DateTimeImmutable($this->lastKey);
        $h = $date <= $lastDate
            ? 1
            : max(1, (int) $lastDate->diff($date)->format('%a'));
        $phi = Hyper::PHI_DAMP;
        $trendTerm = $this->lastTrend * $phi * (1.0 - $phi ** $h) / (1.0 - $phi);
        $a = $this->dowA[$dow] ?? 0.0;

        return max(0.0, exp($this->lastLevel + $trendTerm + $a));
    }

    /**
     * Panier moyen attendu pour $date : moyenne tronquée des paniers du
     * dow rétrécie vers la moyenne tronquée globale (poids = nb de
     * paniers du dow, intensité k identique). Aucun panier du dow →
     * globale (pool nJ = 0) ; aucun panier du tout → 1.0 (neutre
     * documenté : N × 1.0 = N).
     */
    public function expectedP(\DateTimeImmutable $date): float
    {
        if ($this->allBaskets === []) {
            return 1.0;
        }
        $dow = self::dowOfDate($date);
        $baskets = $this->dowBaskets[$dow] ?? [];
        $nBaskets = count($baskets);
        if ($nBaskets === 0) {
            return $this->globalP;
        }

        return Stats::pool(Stats::trimmedMean($baskets, Hyper::TRIM), $nBaskets, $this->globalP, Hyper::K_POOL);
    }

    /** CA attendu = N attendu × panier attendu. */
    public function expectedCa(\DateTimeImmutable $date): float
    {
        return $this->expectedN($date) * $this->expectedP($date);
    }

    /**
     * Confiance de la prévision pour $date : 'faible' si le dow a été
     * vu moins de RARE_DOW fois ; 'elevee' si ≥ 14 jours ouverts ET dow
     * vu au moins RARE_DOW fois ; 'moyenne' sinon.
     */
    public function confidenceFor(\DateTimeImmutable $date): string
    {
        $occ = $this->dowOcc[self::dowOfDate($date)] ?? 0;
        if ($occ < Hyper::RARE_DOW) {
            return 'faible';
        }

        return $this->openDaysCount() >= 14 ? 'elevee' : 'moyenne';
    }

    /** Historique insuffisant (moins de INSUFFICIENT_DAYS jours ouverts). */
    public function isInsufficient(): bool
    {
        return $this->openDaysCount() < Hyper::INSUFFICIENT_DAYS;
    }

    /** Nombre de jours ouverts ajustés (méthode : cf. docblock de classe). */
    public function openDaysCount(): int
    {
        return count($this->sortedDays);
    }

    /**
     * Paniers des $lastOpenDays derniers jours ouverts (ordre
     * chronologique), tous dows confondus.
     *
     * @return list<float>
     */
    public function recentBaskets(int $lastOpenDays = 10): array
    {
        $out = [];
        foreach ($this->lastOpenDays($lastOpenDays) as $day) {
            foreach ($day->baskets as $b) {
                $out[] = (float) $b;
            }
        }

        return $out;
    }

    /**
     * Paniers des $lastOpenDays derniers jours ouverts, groupés par dow.
     *
     * @return array<int, list<float>>
     */
    public function recentBasketsByDow(int $lastOpenDays = 10): array
    {
        $out = [];
        foreach ($this->lastOpenDays($lastOpenDays) as $day) {
            $dow = self::dowOf($day->date);
            foreach ($day->baskets as $b) {
                $out[$dow][] = (float) $b;
            }
        }

        return $out;
    }

    /**
     * Recalcule la dispersion NegBin sur l'état final du modèle (les
     * μ̂_d utilisent expectedN, tendance et effets inclus).
     */
    private function withDispersion(): self
    {
        $num = 0.0;
        $den = 0.0;
        $sumMu = 0.0;
        foreach ($this->sortedDays as $day) {
            $w  = self::weightOf($this->dayWeights, $day->date);
            $mu = $this->expectedN(new \DateTimeImmutable($day->date));
            $num += $w * $mu * $mu;
            $den += $w * ($day->n - $mu) * ($day->n - $mu);
            $sumMu += $w * $mu;
        }
        $den -= $sumMu;
        $r = $den > 0.0 ? $num / $den : Hyper::R_MAX;
        $r = min(Hyper::R_MAX, max(Hyper::R_MIN, $r));

        return new self(
            $this->globalN,
            $this->globalP,
            $this->openWeekdays,
            $this->dowEffects,
            $this->lastLevel,
            $this->lastTrend,
            $r,
            $this->dowA,
            $this->dowOcc,
            $this->dowBaskets,
            $this->sortedDays,
            $this->allBaskets,
            $this->lastKey,
            $this->dayWeights
        );
    }

    /**
     * Les $count derniers jours ouverts ajustés (ordre chronologique).
     *
     * @return list<DayCurrent>
     */
    private function lastOpenDays(int $count): array
    {
        if ($count <= 0 || $this->sortedDays === []) {
            return [];
        }

        return array_slice($this->sortedDays, -$count);
    }

    /**
     * Poids de journée d'une clé : valeur fournie bornée à [0, 1]
     * (1.0 par défaut — jour atypique = Hyper::W_ATYPICAL).
     *
     * @param array<string, float> $weights
     */
    private static function weightOf(array $weights, string $key): float
    {
        return max(0.0, min(1.0, (float) ($weights[$key] ?? 1.0)));
    }

    /** dow (0 = lundi) d'une clé 'Y-m-d'. */
    private static function dowOf(string $dateKey): int
    {
        return self::dowOfDate(new \DateTimeImmutable($dateKey));
    }

    /** dow (0 = lundi) d'une date. */
    private static function dowOfDate(\DateTimeImmutable $date): int
    {
        return (int) $date->format('N') - 1;
    }
}
