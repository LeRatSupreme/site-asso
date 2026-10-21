<?php

declare(strict_types=1);

namespace Tests\Unit\Reco;

use App\Core\Compta\Reco\Hyper;
use App\Core\Compta\Reco\Scoring;
use PHPUnit\Framework\TestCase;

/**
 * Score exact, filtrage par historique (actifs, refus 29/31 jours),
 * dédoublonnage par produit, caps SÉPARÉS par bloc : idées (cap 3,
 * score pur, DIVERSITÉ ≤ 2 par règle) et corrections (cap 5, R11
 * prioritaire dans la limite de R11_MAX).
 */
final class ScoringTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-21 12:00:00');
    }

    /**
     * Candidat valide minimal.
     *
     * @param list<string> $keys
     *
     * @return array<string,mixed>
     */
    private static function c(string $regle, array $keys, float $median, string $confiance = 'haute', int $effort = 1): array
    {
        return [
            'regle'           => $regle,
            'keys'            => $keys,
            'titre'           => 'T',
            'pourquoi'        => 'P',
            'action'          => 'A',
            'gain'            => ['bas' => $median / 2.0, 'median' => $median, 'haut' => $median * 1.5],
            'confiance'       => $confiance,
            'hypotheses'      => [],
            'comment_mesurer' => 'CA hebdo',
            'effort'          => $effort,
            'produits'        => array_map(static fn (string $k): array => ['key' => $k, 'label' => $k], $keys),
            'fingerprint'     => 'fp_' . $regle . '_' . implode('_', $keys),
        ];
    }

    public function test_score_formule_exacte(): void
    {
        self::assertSame(50.0, Scoring::score(self::c('R1', ['P1'], 100.0, 'haute', 2)));
        self::assertSame(7.5, Scoring::score(self::c('R2', ['P2'], 10.0, 'moyenne', 1)));
        self::assertSame(100.0, Scoring::score(self::c('R11', ['P3'], 200.0, 'faible', 1)));
    }

    public function test_refus_29_jours_exclu_31_jours_conserve(): void
    {
        $cand = self::c('R1', ['P1'], 100.0);
        $fp = $cand['fingerprint'];

        $recents = ['actifs' => [], 'refus' => [['fingerprint' => $fp, 'date' => $this->now->modify('-29 days')->format('Y-m-d')]], 'appliquees' => []];
        self::assertSame([], Scoring::classe([$cand], $recents, $this->now));

        $anciens = ['actifs' => [], 'refus' => [['fingerprint' => $fp, 'date' => $this->now->modify('-31 days')->format('Y-m-d')]], 'appliquees' => []];
        self::assertSame([$cand], Scoring::classe([$cand], $anciens, $this->now));
    }

    public function test_fingerprint_actif_exclu(): void
    {
        $a = self::c('R1', ['P1'], 100.0);
        $b = self::c('R2', ['P2'], 50.0);
        $hist = ['actifs' => [$a['fingerprint']], 'refus' => [], 'appliquees' => []];

        self::assertSame([$b], Scoring::classe([$a, $b], $hist, $this->now));
    }

    public function test_dedoublonnage_par_produit(): void
    {
        $a = self::c('R1', ['P1'], 100.0);
        $b = self::c('R2', ['P1'], 50.0);

        // Rien d'unique → coupé.
        self::assertSame([$a], Scoring::classe([$a, $b], [], $this->now));

        // Produit unique restant → conservé, amputé du produit partagé.
        $c = self::c('R2', ['P1', 'P3'], 50.0);
        $retenus = Scoring::classe([$a, $c], [], $this->now);
        self::assertCount(2, $retenus);
        self::assertSame(['P1'], $retenus[0]['keys']);
        self::assertSame(['P3'], $retenus[1]['keys']);
        self::assertSame([['key' => 'P3', 'label' => 'P3']], $retenus[1]['produits']);
    }

    public function test_bloc_idee_cap_trois_score_pur(): void
    {
        $cands = [
            self::c('R1', ['P1'], 60.0),
            self::c('R4', ['P2'], 50.0),
            self::c('R5', ['P3'], 40.0),
            self::c('R6', ['P4'], 30.0),
            self::c('R7', ['P5'], 20.0),
            self::c('R10', ['P6'], 10.0),
        ];

        $retenus = Scoring::classe($cands, [], $this->now);
        self::assertCount(3, $retenus, 'Cap MAX_IDEES = 3 (défaut).');
        self::assertSame(['R1', 'R4', 'R5'], array_column($retenus, 'regle'), 'Tri score pur, aucun cap R11 appliqué.');

        $retenus5 = Scoring::classe($cands, [], $this->now, 5);
        self::assertCount(5, $retenus5, 'Cap lisible (paramètre).');
    }

    public function test_bloc_correction_cap_cinq_avec_r11_en_tete(): void
    {
        $cands = [
            self::c('R2', ['PX'], 10000.0),
            self::c('R11', ['G1'], 400.0),
            self::c('R11', ['G2'], 300.0),
            self::c('R11', ['G3'], 200.0),
            self::c('R11', ['G4'], 100.0),
        ];

        $retenus = Scoring::classe($cands, [], $this->now, Hyper::MAX_CORRECTIONS, true);
        self::assertCount(4, $retenus, 'Cap 5 : 3 R11 (R11_MAX) puis le meilleur non-R11.');
        self::assertSame('R11', $retenus[0]['regle']);
        self::assertSame('R11', $retenus[1]['regle']);
        self::assertSame('R11', $retenus[2]['regle']);
        self::assertSame(['G1'], $retenus[0]['keys']);
        self::assertSame(['G2'], $retenus[1]['keys']);
        self::assertSame(['G3'], $retenus[2]['keys']);
        self::assertSame('R2', $retenus[3]['regle'], 'R2 à gros gain vient APRÈS les R11 en mode corrections.');
    }

    public function test_cap_corrections_cinq_et_departage_regle_croissante(): void
    {
        $cands = [
            self::c('R8', ['P1'], 60.0),
            self::c('R2', ['P2'], 50.0),
            self::c('R9', ['P3'], 40.0),
            self::c('R2', ['P4'], 30.0),
            self::c('R8', ['P5'], 20.0),
            self::c('R9', ['P6'], 10.0),
        ];

        $retenus = Scoring::classe($cands, [], $this->now, Hyper::MAX_CORRECTIONS, true);
        self::assertCount(5, $retenus, 'Cap MAX_CORRECTIONS = 5.');
        self::assertSame(['R8', 'R2', 'R9', 'R2', 'R8'], array_column($retenus, 'regle'), 'Tri score pur : le second R9 (score 10) est coupé par le cap.');
        self::assertSame(['P5'], $retenus[4]['keys']);
    }

    public function test_egalite_de_score_departagee_par_numero_de_regle(): void
    {
        $r7 = self::c('R7', ['P7'], 100.0);
        $r2 = self::c('R2', ['P2'], 100.0);

        $retenus = Scoring::classe([$r7, $r2], [], $this->now);
        self::assertSame('R2', $retenus[0]['regle']);
        self::assertSame('R7', $retenus[1]['regle']);
    }

    /**
     * DIVERSITÉ des idées : au plus IDEES_MAX_PAR_REGLE (2) candidats par
     * règle, appliqué AVANT le cap global (le slot libéré va à la règle
     * suivante) ; 0 = pas de limite (comportement précédent).
     */
    public function test_diversite_idees_max_deux_par_regle(): void
    {
        // 3 candidats R4 dominants + 1 R7 moins bien notée.
        $cands = [
            self::c('R4', ['PA'], 100.0),
            self::c('R4', ['PB'], 90.0),
            self::c('R4', ['PC'], 80.0),
            self::c('R7', ['PD'], 70.0),
        ];

        // Sans limite (0) : le cap 3 garde R4×3, la R7 est évincée.
        $sans = Scoring::classe($cands, [], $this->now, 3, false, 0);
        self::assertSame(['R4', 'R4', 'R4'], array_column($sans, 'regle'));

        // Avec diversité : au plus 2 R4, la R7 prend le slot libéré.
        $retenus = Scoring::classe($cands, [], $this->now, 3, false, Hyper::IDEES_MAX_PAR_REGLE);
        self::assertSame(['R4', 'R4', 'R7'], array_column($retenus, 'regle'), 'Au plus 2 R4 puis la meilleure autre règle.');
        self::assertSame([['key' => 'PA', 'label' => 'PA']], $retenus[0]['produits']);
        self::assertSame([['key' => 'PD', 'label' => 'PD']], $retenus[2]['produits']);
    }
}
