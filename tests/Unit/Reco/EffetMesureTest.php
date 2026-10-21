<?php

declare(strict_types=1);

namespace Tests\Unit\Reco;

use App\Core\Compta\Reco\EffetMesure;
use App\Core\Compta\Reco\Hyper;
use PHPUnit\Framework\TestCase;

/**
 * EffetMesure sur baseline synthétique : 14 jours à 100 €/j attendu
 * (lo 90, hi 110) — concluant / non concluant / insuffisant / attente,
 * désaisonnalisation creuse, chevauchement, libellés FR.
 */
final class EffetMesureTest extends TestCase
{
    private const DEBUT = '2026-11-02'; // lundi

    public function test_concluant_effet_210_intervalles_70_350(): void
    {
        $r = EffetMesure::calculer(self::baseline14(), self::reel(115.0), 14);

        self::assertSame('concluant', $r['etat']);
        self::assertEqualsWithDelta(210.0, $r['effet'], 1.0e-9, '14 × 115 − 14 × 100.');
        self::assertEqualsWithDelta(70.0, $r['bas'], 1.0e-9, '1610 − 14 × 110.');
        self::assertEqualsWithDelta(350.0, $r['haut'], 1.0e-9, '1610 − 14 × 90.');
        self::assertSame(14, $r['jours']);
    }

    public function test_reel_egal_attendu_non_concluant(): void
    {
        $r = EffetMesure::calculer(self::baseline14(), self::reel(100.0), 14);

        self::assertSame('non_concluant', $r['etat']);
        self::assertEqualsWithDelta(0.0, $r['effet'], 1.0e-9);
        self::assertEqualsWithDelta(-140.0, $r['bas'], 1.0e-9);
        self::assertEqualsWithDelta(140.0, $r['haut'], 1.0e-9);
    }

    public function test_neuf_jours_insuffisant_douze_attente(): void
    {
        $r9 = EffetMesure::calculer(self::baseline14(), self::reel(115.0), 9);
        self::assertSame('insuffisant', $r9['etat']);
        self::assertNull($r9['effet']);
        self::assertNull($r9['bas']);
        self::assertNull($r9['haut']);

        $r12 = EffetMesure::calculer(self::baseline14(), self::reel(115.0), 12);
        self::assertSame('attente', $r12['etat'], '12 ≥ 10 jours couverts mais < 14 de déclenchement.');
        self::assertNull($r12['effet']);
        self::assertStringContainsString('2', $r12['note'], 'Il reste 2 jours ouverts avant mesure.');
        self::assertStringContainsString('jours ouverts', $r12['note']);
    }

    public function test_chevauchement_note_etat_inchange(): void
    {
        $r = EffetMesure::calculer(self::baseline14(), self::reel(115.0), 14, true);

        self::assertSame('concluant', $r['etat'], 'L\'état n\'est pas changé par le chevauchement.');
        self::assertEqualsWithDelta(210.0, $r['effet'], 1.0e-9);
        self::assertStringContainsStringIgnoringCase('chevauchement', $r['note']);
    }

    public function test_creuse_reel_desaisonalise_effet_nul(): void
    {
        // Jour « creux » : 80 € observés ÷ facteur 0,8 → 100 € désaisonnalisé = attendu.
        $r = EffetMesure::calculer(self::baseline14(0.8), self::reel(80.0), 14);

        self::assertSame('non_concluant', $r['etat']);
        self::assertEqualsWithDelta(0.0, $r['effet'], 1.0e-9, '80/0,8 = 100 = attendu : aucun effet.');
    }

    public function test_libelle_concluant_et_non_concluant(): void
    {
        $concluant = EffetMesure::calculer(self::baseline14(), self::reel(115.0), 14);
        $libelle = EffetMesure::libelle($concluant);
        self::assertStringStartsWith('Effet mesuré +210,00 €', $libelle, $libelle);
        self::assertStringContainsString('[70,00 ; 350,00]', $libelle, $libelle);
        self::assertStringContainsString('€/période', $libelle, $libelle);

        $negatif = EffetMesure::calculer(self::baseline14(), self::reel(85.0), 14);
        self::assertSame('concluant', $negatif['etat'], '1190 < loTotal : hors intervalle.');
        self::assertStringStartsWith('Effet mesuré -210,00 €', EffetMesure::libelle($negatif), EffetMesure::libelle($negatif));

        $nc = EffetMesure::calculer(self::baseline14(), self::reel(100.0), 14);
        self::assertSame('Non concluant (l\'intervalle contient zéro)', EffetMesure::libelle($nc));

        // attente / insuffisant : le libellé EST la note.
        $attente = EffetMesure::calculer(self::baseline14(), self::reel(115.0), 12);
        self::assertSame($attente['note'], EffetMesure::libelle($attente));
    }

    public function test_constantes_hyper_reprises(): void
    {
        // Aucune nouvelle constante : les seuils viennent de Forecast\Reco\Hyper.
        self::assertSame(14, Hyper::EFFET_DECLANCHEMENT_JOURS_OUVERTS);
        self::assertSame(10, Hyper::EFFET_MIN_JOURS_OUVERTS);
    }

    /**
     * Baseline de 14 jours ouverts : 100 €/j attendu, lo 90, hi 110.
     *
     * @return list<array{date:string,attendu:float,lo:float,hi:float,facteur:float}>
     */
    private static function baseline14(float $facteur = 1.0): array
    {
        $lignes = [];
        $d = new \DateTimeImmutable(self::DEBUT);
        for ($i = 0; $i < 14; $i++) {
            $lignes[] = ['date' => $d->format('Y-m-d'), 'attendu' => 100.0, 'lo' => 90.0, 'hi' => 110.0, 'facteur' => $facteur];
            $d = $d->modify('+1 day');
        }

        return $lignes;
    }

    /**
     * CA réel constant sur les 14 mêmes dates.
     *
     * @return array<string,float>
     */
    private static function reel(float $parJour): array
    {
        $reel = [];
        $d = new \DateTimeImmutable(self::DEBUT);
        for ($i = 0; $i < 14; $i++) {
            $reel[$d->format('Y-m-d')] = $parJour;
            $d = $d->modify('+1 day');
        }

        return $reel;
    }
}
