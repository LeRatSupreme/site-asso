<?php

declare(strict_types=1);

namespace Tests\Unit\Compta;

use App\Core\Compta\T1\EvalDataset;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests de la logique PURE de construction du jeu d'évaluation T1
 * (aucun accès base : tout est injecté, le script CLI est couvert à part).
 */
final class T1EvalDatasetTest extends TestCase
{
    // ── Normalisation (délégation aux résolveurs du projet) ─────────────────

    public function test_forme_delegue_a_stock_public(): void
    {
        self::assertSame('redbull', EvalDataset::forme('Red Bull'));
        self::assertSame('redbull', EvalDataset::forme('RedBull'));
        self::assertSame('redbull', EvalDataset::forme('red-bull'));
        self::assertSame(EvalDataset::forme('Coca Cherry'), EvalDataset::forme('coca_cherry'));
    }

    public function test_repair_mojibake(): void
    {
        self::assertSame('Oasis Thémangue Passion', EvalDataset::repairMojibake('Oasis ThÃ©mangue Passion'));
        self::assertSame('é', EvalDataset::repairMojibake('Ã©'));
        // Sans « Ã » (accents français légitimes) : inchangé.
        self::assertSame('Café allongé', EvalDataset::repairMojibake('Café allongé'));
        self::assertSame('Monster Blanche', EvalDataset::repairMojibake('Monster Blanche'));
    }

    public function test_famille_premier_token(): void
    {
        self::assertSame('redbull', EvalDataset::famille('Redbull Bleu'));
        // Libellé brut « Red Bull … » : 1er token « red » (la famille d'une
        // IDENTITÉ est calculée sur son libellé REPRÉSENTANT, cf. script).
        self::assertSame('red', EvalDataset::famille('Red Bull Ice'));
        self::assertSame('kit', EvalDataset::famille('Kit-Kat'));
        self::assertSame('fanta', EvalDataset::famille('Fanta_Citron'));
        self::assertSame('', EvalDataset::famille('.'));
    }

    public function test_casse_pure(): void
    {
        self::assertTrue(EvalDataset::estCassePure('Bueno white', 'Bueno White'));
        self::assertTrue(EvalDataset::estCassePure('Coca_cherry', 'coca cherry'));
        self::assertFalse(EvalDataset::estCassePure('pepper', 'Dr Pepper'));
        self::assertFalse(EvalDataset::estCassePure('Monster Blanche', 'Monster Bleu'));
    }

    public function test_paire_formes_symetrique(): void
    {
        self::assertSame(
            EvalDataset::paireFormes('eau', 'cristaline'),
            EvalDataset::paireFormes('cristaline', 'eau')
        );
        self::assertSame('', EvalDataset::paireFormes('', 'eau'));
    }

    public function test_representant_deterministe(): void
    {
        self::assertSame(
            'Redbull',
            EvalDataset::representant([
                ['libelle' => 'Red bull', 'sources' => 1, 'occurrences' => 5],
                ['libelle' => 'Redbull', 'sources' => 8, 'occurrences' => 2],
            ])
        );
        // Égalité de sources → occurrences, puis ordre lexicographique.
        self::assertSame(
            'Gauffre choco',
            EvalDataset::representant([
                ['libelle' => 'Gauffre choco', 'sources' => 2, 'occurrences' => 9],
                ['libelle' => 'gauffre choco', 'sources' => 2, 'occurrences' => 4],
            ])
        );
        self::assertNull(EvalDataset::representant([]));
    }

    // ── Paires positives : dédoublonnage / classement / exclusions ──────────

    private function ev(string $brut, string $cible, string $origine, string $action = '', string $kind = '', string $ref = 'r1', string $ts = '2026-01-01'): array
    {
        return ['brut' => $brut, 'cible' => $cible, 'origine' => $origine, 'action' => $action, 'kind' => $kind, 'ref' => $ref, 'ts' => $ts];
    }

    public function test_positifs_dedoublonnage_semantique_et_casse(): void
    {
        $res = EvalDataset::construirePositifs([
            // Même fusion sémantique vue sous 2 casses différentes → 1 ligne sémantique.
            $this->ev('pepper', 'Dr Pepper', 'audit_logs', 'compta.aliases.merge', '', 'a1'),
            $this->ev('Pepper', 'Dr Pepper', 'product_aliases', '', 'label', 'al1'),
            // Casse pure → fichier de transparence, pas dans le jeu sémantique.
            $this->ev('Redbull Bleu', 'RedBull Bleu', 'product_aliases', '', 'label', 'al2'),
        ], []);

        self::assertCount(1, $res['semantique']);
        // Représentant prioritaire : l'alias vivant l'emporte sur l'audit.
        self::assertSame('product_aliases', $res['semantique'][0]['origine']);
        self::assertStringContainsString('doublons : 2 événements', $res['semantique'][0]['remarque']);

        self::assertCount(1, $res['casse']);
        self::assertSame('Redbull Bleu', $res['casse'][0]['libelle_brut']);
        self::assertSame([], $res['exclues']);
    }

    public function test_positifs_exclusion_residuels(): void
    {
        $cleExclue = EvalDataset::paire('Madel Coquille', 'Madeleine');
        $res = EvalDataset::construirePositifs([
            $this->ev('Madel Coquille', 'Madeleine', 'audit_logs', 'inventory.merge', '', 'a9'),
            $this->ev('Cristaline', 'Eau', 'product_aliases', '', 'appellation', 'al9'),
        ], [$cleExclue]);

        self::assertCount(1, $res['semantique']);
        self::assertSame('Cristaline', $res['semantique'][0]['libelle_brut']);
        self::assertCount(1, $res['exclues']);
        self::assertStringContainsString('§1.3', $res['exclues'][0]['remarque']);
    }

    public function test_positifs_auto_alias_et_vides(): void
    {
        $res = EvalDataset::construirePositifs([
            $this->ev('Redbull', 'Redbull', 'product_aliases', '', 'label', 'al0'),
            $this->ev('  ', 'Eau', 'audit_logs', 'inventory.merge'),
        ], []);

        self::assertSame([], $res['semantique']);
        self::assertSame([], $res['casse']);
        self::assertCount(2, $res['anomalies']);
    }

    public function test_positifs_determinisme(): void
    {
        $evs = [
            $this->ev('pepper', 'Dr Pepper', 'audit_logs', 'compta.aliases.merge', '', 'a1'),
            $this->ev('cocacola', 'Coca Cola', 'product_aliases', '', 'label', 'al1'),
            $this->ev('Bueno_white', 'Bueno White', 'product_aliases', '', 'label', 'al2'),
        ];
        $a = EvalDataset::construirePositifs($evs, []);
        $b = EvalDataset::construirePositifs(array_reverse($evs), []);
        self::assertSame(
            array_column($a['semantique'], 'libelle_brut'),
            array_column($b['semantique'], 'libelle_brut')
        );
    }

    // ── Groupes de split (union-find) ───────────────────────────────────────

    public function test_groupes_chaines(): void
    {
        $groupes = EvalDataset::groupesDepuisPaires([
            ['redbullblanche', 'redbull'],
            ['redbull', 'redbullblue'],
        ]);
        // Un seul composant, racine = plus petite forme.
        self::assertSame('redbull', $groupes['redbullblanche']);
        self::assertSame('redbull', $groupes['redbullblue']);
        self::assertSame('redbull', $groupes['redbull']);
    }

    public function test_groupe_de_repli_sur_soimeme(): void
    {
        self::assertSame('monsterbleu', EvalDataset::groupeDe([], 'monsterbleu'));
    }

    // ── Split par produit ───────────────────────────────────────────────────

    public function test_split_sans_fuite_et_min_paires_test(): void
    {
        // 10 groupes singleton sans paires + un gros groupe porteur de 25 paires.
        $paires = [];
        for ($i = 0; $i < 25; $i++) {
            $paires[] = ['ga' . $i, 'gb' . $i];
        }
        $groupes = EvalDataset::groupesDepuisPaires($paires);
        $poids = [];
        for ($i = 0; $i < 25; $i++) {
            $poids[EvalDataset::groupeDe($groupes, 'ga' . $i)] = 2;
        }
        foreach (['s1', 's2', 's3', 's4', 's5', 's6', 's7', 's8'] as $s) {
            $poids[$s] = 1;
        }

        $res = EvalDataset::splitParGroupe($groupes, $poids, $paires, 42, 0.30, 20);

        // Toutes les paires d'un même côté, ≥ 20 en test.
        self::assertGreaterThanOrEqual(20, $res['stats']['paires_semantiques_test']);
        foreach ($paires as [$a, $b]) {
            self::assertSame(
                $res['mapping'][EvalDataset::groupeDe($groupes, $a)],
                $res['mapping'][EvalDataset::groupeDe($groupes, $b)],
                "fuite de split sur {$a}/{$b}"
            );
        }
        // Déterminisme.
        $res2 = EvalDataset::splitParGroupe($groupes, $poids, $paires, 42, 0.30, 20);
        self::assertSame($res['mapping'], $res2['mapping']);
        self::assertSame($res['stats'], $res2['stats']);
    }

    public function test_split_ratio_approximatif(): void
    {
        $poids = array_fill_keys(range('a', 'z'), 1); // 26 groupes
        $res = EvalDataset::splitParGroupe([], $poids, [], 42, 0.30, 0);
        self::assertGreaterThan(0.20, $res['stats']['ratio_test']);
        self::assertLessThan(0.40, $res['stats']['ratio_test']);
    }

    public function test_split_jeu_trop_petit_leve_exception(): void
    {
        $this->expectException(RuntimeException::class);
        EvalDataset::splitParGroupe([], ['a' => 1, 'b' => 1], [], 42, 0.30, 5);
    }

    // ── Négatifs ────────────────────────────────────────────────────────────

    private function identites(): array
    {
        return [
            'redbullblanche' => ['libelle' => 'Redbull Blanche', 'famille' => 'redbull'],
            'redbullbleu'    => ['libelle' => 'Redbull Bleu', 'famille' => 'redbull'],
            'redbullice'     => ['libelle' => 'Redbull Ice', 'famille' => 'redbull'],
            'monsterblanche' => ['libelle' => 'Monster Blanche', 'famille' => 'monster'],
            'eau'            => ['libelle' => 'Eau', 'famille' => 'eau'],
            ''               => ['libelle' => '.', 'famille' => ''],
        ];
    }

    public function test_negatifs_durs_exhaustifs_intra_famille(): void
    {
        $lignes = EvalDataset::negatifsDurs($this->identites(), []);
        // redbull ×3 → C(3,2) = 3 paires ; monster et eau singleton ; « » exclu.
        self::assertCount(3, $lignes);
        foreach ($lignes as $l) {
            self::assertSame('redbull', $l['famille']);
        }
    }

    public function test_negatifs_durs_excluent_paires_interdites(): void
    {
        $interdites = [EvalDataset::paireFormes('redbullice', 'redbullbleu') => true];
        $lignes = EvalDataset::negatifsDurs($this->identites(), $interdites);
        self::assertCount(2, $lignes);
        foreach ($lignes as $l) {
            self::assertNotSame(
                EvalDataset::paireFormes($l['forme_a'], $l['forme_b']),
                EvalDataset::paireFormes('redbullice', 'redbullbleu')
            );
        }
    }

    public function test_negatifs_durs_jamais_inter_familles(): void
    {
        foreach (EvalDataset::negatifsDurs($this->identites(), []) as $l) {
            $this->assertNotSame('eau', $l['famille']);
        }
    }

    public function test_echantillon_negatifs_deterministe_et_inter_familles(): void
    {
        $a = EvalDataset::echantillonnerNegatifs($this->identites(), [], 4, 42);
        $b = EvalDataset::echantillonnerNegatifs($this->identites(), [], 4, 42);
        self::assertSame($a, $b);
        self::assertCount(4, $a);
        foreach ($a as $l) {
            self::assertNotSame($l['famille_a'], $l['famille_b']);
        }
        // Jamais positives.
        $interdites = [EvalDataset::paireFormes('redbullbleu', 'eau') => true];
        foreach (EvalDataset::echantillonnerNegatifs($this->identites(), $interdites, 20, 7) as $l) {
            self::assertFalse(isset($interdites[EvalDataset::paireFormes($l['forme_a'], $l['forme_b'])]));
        }
    }

    // ── Near-miss résiduels ─────────────────────────────────────────────────

    public function test_near_miss_distance_1_a_2(): void
    {
        $identites = [
            'pepper'    => ['libelle' => 'Pepper', 'famille' => 'dr'],
            'drpepper'  => ['libelle' => 'Dr Pepper', 'famille' => 'dr'],
            'monsterblanche' => ['libelle' => 'Monster Blanche', 'famille' => 'monster'],
            'monsterblanchr' => ['libelle' => 'Monster Blanchr', 'famille' => 'monster'],
            'eau'       => ['libelle' => 'Eau', 'famille' => 'eau'],
        ];
        $lignes = EvalDataset::nearMiss($identites, []);
        $paires = array_map(static fn (array $l): string => EvalDataset::paireFormes($l['forme_a'], $l['forme_b']), $lignes);
        self::assertContains(EvalDataset::paireFormes('pepper', 'drpepper'), $paires);
        self::assertContains(EvalDataset::paireFormes('monsterblanche', 'monsterblanchr'), $paires);
        self::assertCount(2, $lignes); // eau trop loin de tout.
    }

    public function test_near_miss_exclut_paires_connues(): void
    {
        $identites = [
            'pepper'   => ['libelle' => 'Pepper', 'famille' => 'dr'],
            'drpepper' => ['libelle' => 'Dr Pepper', 'famille' => 'dr'],
        ];
        $lignes = EvalDataset::nearMiss($identites, [EvalDataset::paireFormes('pepper', 'drpepper') => true]);
        self::assertSame([], $lignes);
    }

    public function test_near_miss_exclut_variantes_numerotees(): void
    {
        // Règle projet (AliasSuggester::isNearMiss) : « Sucette 1 » vs
        // « Sucette 3 » est une variante numérotée, pas une faute de frappe.
        $identites = [
            'sucette1' => ['libelle' => 'Sucette 1', 'famille' => 'sucette'],
            'sucette3' => ['libelle' => 'Sucette 3', 'famille' => 'sucette'],
            'sucette'  => ['libelle' => 'sucette', 'famille' => 'sucette'],
        ];
        self::assertSame([], EvalDataset::nearMiss($identites, []));
    }

    public function test_near_miss_exclut_paires_deja_liees_par_fusion(): void
    {
        // redbullblue et redbullbleue sont liées transitivement via redbullbleu
        // (fusions auditées) : même groupe → pas un résiduel.
        $identites = [
            'redbullblue'  => ['libelle' => 'Redbull Blue', 'famille' => 'redbull'],
            'redbullbleue' => ['libelle' => 'Redbull_Bleue', 'famille' => 'redbull'],
            'redbullbleu'  => ['libelle' => 'Redbull Bleu', 'famille' => 'redbull'],
        ];
        $groupes = EvalDataset::groupesDepuisPaires([
            ['redbullblue', 'redbullbleu'],
            ['redbullbleue', 'redbullbleu'],
        ]);
        $lignes = EvalDataset::nearMiss($identites, [], EvalDataset::NEAR_MISS_DISTANCE_MAX, $groupes);
        self::assertSame([], $lignes);
    }

    // ── Anonymisation (D3) ──────────────────────────────────────────────────

    public function test_masque_prenom_cas_connu(): void
    {
        self::assertSame(
            'Décoration halloween | [PRENOM]',
            EvalDataset::masquerPrenom('Décoration halloween | les creation de ju...')
        );
        // Variante plurielle / accents.
        self::assertSame(
            'Soirée | [PRENOM]',
            EvalDataset::masquerPrenom('Soirée | créations de Marie')
        );
    }

    public function test_masque_prenom_sans_nom_ne_change_rien(): void
    {
        self::assertSame('Redbull Blanche', EvalDataset::masquerPrenom('Redbull Blanche'));
        self::assertSame('Menu | BBQ spécial', EvalDataset::masquerPrenom('Menu | BBQ spécial'));
    }

    // ── Utilitaires ─────────────────────────────────────────────────────────

    public function test_melange_seede_deterministe_et_permutation(): void
    {
        $base = range(1, 50);
        $a = EvalDataset::melangeSeede($base, 42);
        $b = EvalDataset::melangeSeede($base, 42);
        self::assertSame($a, $b);
        self::assertSame(array_sum($base), array_sum($a));
        sort($a);
        self::assertSame($base, $a); // permutation exacte
    }
}
