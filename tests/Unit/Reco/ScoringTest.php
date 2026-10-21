<?php

declare(strict_types=1);

namespace Tests\Unit\Reco;

use App\Core\Compta\Reco\Scoring;
use PHPUnit\Framework\TestCase;

/**
 * Score exact, filtrage par historique (actifs, refus 29/31 jours),
 * dédoublonnage par produit, cap MAX_ACTIVES et priorité R11.
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

    public function test_cap_cinq_recommandations(): void
    {
        $cands = [
            self::c('R1', ['P1'], 60.0),
            self::c('R2', ['P2'], 50.0),
            self::c('R3', ['P3'], 40.0),
            self::c('R4', ['P4'], 30.0),
            self::c('R5', ['P5'], 20.0),
            self::c('R6', ['P6'], 10.0),
        ];

        $retenus = Scoring::classe($cands, [], $this->now);
        self::assertCount(5, $retenus);
        self::assertSame('R5', $retenus[4]['regle']);
        self::assertSame(['P5'], $retenus[4]['keys']);
        foreach ($retenus as $r) {
            self::assertNotSame('R6', $r['regle']);
        }
    }

    public function test_r11_en_tete_avec_cap_trois(): void
    {
        $cands = [
            self::c('R1', ['PX'], 10000.0),
            self::c('R11', ['G1'], 400.0),
            self::c('R11', ['G2'], 300.0),
            self::c('R11', ['G3'], 200.0),
            self::c('R11', ['G4'], 100.0),
        ];

        $retenus = Scoring::classe($cands, [], $this->now);
        self::assertCount(4, $retenus);
        self::assertSame('R11', $retenus[0]['regle']);
        self::assertSame('R11', $retenus[1]['regle']);
        self::assertSame('R11', $retenus[2]['regle']);
        self::assertSame(['G1'], $retenus[0]['keys']);
        self::assertSame(['G2'], $retenus[1]['keys']);
        self::assertSame(['G3'], $retenus[2]['keys']);
        self::assertSame('R1', $retenus[3]['regle']);
    }

    public function test_egalite_de_score_departagee_par_numero_de_regle(): void
    {
        $r7 = self::c('R7', ['P7'], 100.0);
        $r2 = self::c('R2', ['P2'], 100.0);

        $retenus = Scoring::classe([$r7, $r2], [], $this->now);
        self::assertSame('R2', $retenus[0]['regle']);
        self::assertSame('R7', $retenus[1]['regle']);
    }
}
