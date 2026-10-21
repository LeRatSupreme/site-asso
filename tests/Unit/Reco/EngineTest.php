<?php

declare(strict_types=1);

namespace Tests\Unit\Reco;

use App\Core\Compta\Forecast\DayCurrent;
use App\Core\Compta\Forecast\ForecastDataset;
use App\Core\Compta\Forecast\ForecastEngine;
use App\Core\Compta\Forecast\ProductStat;
use App\Core\Compta\Reco\GuardRails;
use App\Core\Compta\Reco\Hyper;
use App\Core\Compta\Reco\Recommendation;
use App\Core\Compta\Reco\RecommendationEngine;
use App\Core\Compta\Reco\RecoContext;
use App\Core\Compta\Reco\RecoProduct;
use App\Core\Compta\Reco\R1StockDormant;
use App\Core\Compta\Reco\R5Duos;
use App\Core\Compta\Reco\R9Tendance;
use PHPUnit\Framework\TestCase;

/**
 * RecommendationEngine (cœur pur) : cohérence avec le VRAI ForecastEngine
 * (test mission), historique insuffisant, période creuse, format de
 * sortie, garde-fous globaux, cap 5 + dédoublonnage + refus 30 j,
 * assemblage R10.
 */
final class EngineTest extends TestCase
{
    private const MOTIF = [70, 60, 65, 75, 90]; // N par jour : lun..ven (façon tests/Unit/Forecast/EngineTest)

    /**
     * TEST MISSION — cohérence ForecastEngine : les unités prévues 7 j du
     * VRAI forecast alimentent RecoProduct, et la reco R1 qui en découle
     * donne des semaines de stock == stock ÷ unités (1e-9 via le gain
     * médian recalculé à l'identique, « pourquoi » arrondi à 1 décimale).
     */
    public function test_coherence_forecastengine_unites_prevues_alimentent_r1(): void
    {
        $serie = self::serie(28);
        $fc = ForecastEngine::forecast(
            new ForecastDataset($serie, [new ProductStat('a', 'Boisson A', 'Energisant', 720, 1440.0, 2.0, 1.0, false, null)]),
            self::paris('2026-10-19 23:59'),
            '23:59',
            ['seed' => 42, 'draws' => 1000]
        );
        self::assertNotEmpty($fc['produits']);
        $fp = $fc['produits'][0];

        $unites = (float) $fp['unites'];
        self::assertGreaterThan(0.0, $unites);
        $stock = (int) round(10.0 * $unites + 20.0);
        $p = new RecoProduct((string) $fp['key'], (string) $fp['label'], 'Boissons', 100.0, 200.0, 2.0, 1.0, null, $stock, $unites, $unites * 0.8, $unites * 1.2);
        self::assertSame($unites, $p->unitesPrevues7j, 'unitesPrevues7j = forecast[produits][i][unites] EXACTEMENT.');

        // Dataset à UN produit : R1 est le seul déclenchement à gain (R3/R4 sans médiane exploitable, R5 sans cible).
        $out = RecommendationEngine::recommend(
            RecoScenario::dataset([$p], [], [], 1.0, false, 28, self::forecastProb(0.9)),
            self::paris('2026-10-21 12:00')
        );
        self::assertFalse($out['meta']['historique_insuffisant']);
        $r1 = null;
        foreach ($out['idees'] as $idee) {
            if ($idee->regle === 'R1') {
                $r1 = $idee;
                break;
            }
        }
        self::assertNotNull($r1, 'Stock 10×prévision+20 : R1 déclenché.');
        self::assertStringContainsString(number_format($unites, 1, ',', ''), $r1->pourquoi, 'La prévision du forecast est bien celle affichée.');

        // Semaines de stock lisibles dans le « pourquoi » (arrondi 1 décimale).
        self::assertSame(1, preg_match('/([\d,]+) sem de stock/', $r1->pourquoi, $m), $r1->pourquoi);
        $sem = (float) str_replace(',', '.', $m[1]);
        self::assertEqualsWithDelta($stock / $unites, $sem, 0.05, 'Semaines de stock = stock ÷ unitesPrevues7j.');

        // Gain médian recalculé EXACTEMENT comme R1, avec les unités DU forecast.
        $cible = min(Hyper::REMISE_DORMANT_HAUT, max(Hyper::REMISE_DORMANT_BAS, R1StockDormant::REMISE_CIBLE));
        $remiseMax = 1.0 - 1.0 * (1.0 + Hyper::MARGE_MIN) / 2.0;
        $remise = min($remiseMax, $cible);
        $margeRemise = 2.0 * (1.0 - $remise) - 1.0;
        $surplus = max((float) $stock - (float) Hyper::STOCK_DORMANT_SEMAINES * $unites, 0.0);
        $medianAttendu = $surplus / R1StockDormant::DUREE_ECOULEMENT_SEMAINES * $margeRemise;
        self::assertEqualsWithDelta($medianAttendu, $r1->gainHebdo['median'], 1.0e-9, 'Gain médian = surplus/4 × (prix×(1−X) − coût).');
    }

    /**
     * Fourchette de gain non plate sur un RecoDataset complet : dès que
     * prevLo < prevHi, l'idée R1 doit vérifier bas < median < haut
     * (garde-fou 6 — dump prod : « 9,91–9,91–9,91 €/sem » quand
     * l'intervalle du forecast n'était pas propagé).
     */
    public function test_fourchette_r1_non_plate_quand_prev_lo_inf_prev_hi(): void
    {
        $out = RecommendationEngine::recommend(
            RecoScenario::dataset([self::produitR1('P1', 30.0)], [], [], 1.0, false, 30, self::forecastProb(0.9)),
            self::paris('2026-10-21 12:00')
        );
        $r1 = null;
        foreach ($out['idees'] as $idee) {
            if ($idee->regle === 'R1') {
                $r1 = $idee;
                break;
            }
        }
        self::assertNotNull($r1, 'produitR1 (prevLo 24 < prevHi 36) : R1 déclenché.');
        $g = $r1->gainHebdo;
        self::assertLessThan($g['median'] - 1.0e-9, $g['bas'], 'bas = surplus(prevHi), strictement sous le médian.');
        self::assertLessThan($g['haut'] - 1.0e-9, $g['median'], 'médian strictement sous haut = surplus(prevLo).');
    }

    public function test_historique_insuffisant_limite_aux_regles_r2_r8_r11(): void
    {
        $produits = [
            self::produitR1('P1', 30.0),
            new RecoProduct('P2', 'Produit P2', 'Boissons', 50.0, 100.0, 2.0, null, 'cout_inconnu', null, 40.0, 32.0, 48.0), // R11
        ];
        $now = self::paris('2026-10-21 12:00');

        $out10 = RecommendationEngine::recommend(RecoScenario::dataset($produits, [], [], 1.0, false, 10, self::forecastProb(0.9)), $now);
        self::assertTrue($out10['meta']['historique_insuffisant']);
        $regles10 = self::regles($out10);
        self::assertNotSame([], $regles10, 'R11 reste évaluable à 10 jours ouverts.');
        foreach ($regles10 as $r) {
            self::assertContains($r, ['R2', 'R8', 'R11'], 'Historique insuffisant : R2/R8/R11 seulement.');
        }

        $out30 = RecommendationEngine::recommend(RecoScenario::dataset($produits, [], [], 1.0, false, 30, self::forecastProb(0.9)), $now);
        self::assertFalse($out30['meta']['historique_insuffisant']);
        self::assertContains('R1', self::regles($out30), 'Historique suffisant : R1 redevient possible.');
        self::assertContains('R11', self::regles($out30));
    }

    public function test_creneau_actif_meta_et_r9_neutralise(): void
    {
        $p = self::produitR1('P1', 30.0);
        $qtyPrec = ['P1' => 300.0]; // déclin ≈ −60 % (100 u. désaisonnalisées vs 300 la période précédente)
        $now = self::paris('2026-10-21 12:00');

        $outCreuse = RecommendationEngine::recommend(RecoScenario::dataset([$p], [], $qtyPrec, 0.8, true, 30, self::forecastProb(0.9)), $now);
        self::assertTrue($outCreuse['meta']['creuse_active']);
        self::assertSame(0.8, $outCreuse['meta']['facteur_creuse'], 'Facteur creuse propagé tel quel.');
        $dsCreuse = RecoScenario::dataset([$p], [], $qtyPrec, 0.8, true, 30, self::forecastProb(0.9));
        self::assertSame(
            [],
            R9Tendance::evaluer(new RecoContext($dsCreuse, $now)),
            'Période creuse active : R9 sans candidat malgré le déclin −60 %.'
        );

        $dsNormale = RecoScenario::dataset([$p], [], $qtyPrec, 1.0, false, 30, self::forecastProb(0.9));
        self::assertNotSame(
            [],
            R9Tendance::evaluer(new RecoContext($dsNormale, $now)),
            'Contrôle : sans creuse, le déclin déclenche bien R9 (candidat émis).'
        );
        self::assertNotContains('R9', self::regles($outCreuse));
    }

    public function test_format_sortie_13_cles_et_fingerprint(): void
    {
        $produits = [self::produitR1('P1', 30.0), self::produitR1('P2', 24.0)];
        $now = new \DateTimeImmutable('2026-10-21T10:00:00+00:00'); // 12:00 à Paris
        $out = RecommendationEngine::recommend(RecoScenario::dataset($produits, [], [], 1.0, false, 30, self::forecastProb(0.9)), $now);

        self::assertNotSame([], $out['idees']);
        $attendues = ['id', 'regle', 'titre', 'pourquoi', 'action', 'gain_hebdo', 'confiance', 'effort', 'hypotheses', 'produits', 'garde_fous_ok', 'comment_mesurer', 'statut'];
        foreach ($out['idees'] as $idee) {
            self::assertInstanceOf(Recommendation::class, $idee);
            self::assertSame($attendues, array_keys($idee->toArray()), 'EXACTEMENT les 13 clés de Recommendation::toArray(), dans l\'ordre.');
            self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $idee->id, 'id = fingerprint sha1 hexadécimal 40.');
            self::assertTrue($idee->gardeFousOk);
            self::assertSame('proposée', $idee->statut);
            self::assertSame($idee->id, (new RecoContext(RecoScenario::dataset(), $now))->fingerprint($idee->regle, array_column($idee->produits, 'key')), 'id = sha1(règle:clés triées).');
        }

        // meta : audit complet.
        $meta = $out['meta'];
        foreach (['creuse_active', 'facteur_creuse', 'historique_insuffisant', 'jours_ouverts_fenetre', 'n_paniers', 'genere_le', 'parametres', 'rejets'] as $k) {
            self::assertArrayHasKey($k, $meta);
        }
        self::assertSame('2026-10-21 12:00', $meta['genere_le'], 'Horodatage converti en Europe/Paris.');
        self::assertSame(0.15, $meta['parametres']['marge_min'], 'Paramètres résolus pour audit.');
        self::assertSame(30, $meta['jours_ouverts_fenetre']);
        self::assertSame(0, $meta['n_paniers']);
        self::assertIsInt($meta['rejets']);
    }

    /**
     * Garde-fou global sur 4 datasets variés (coût suspect, catégorie
     * Événement, duos, promo R1 + plancher inatteignable) : AUCUNE idée
     * tarifaire (R1/R3/R5/R6/R7) sur un produit à coutRaison non nul,
     * jamais de catégorie exclue ciblée, prix remisé R1 ≥ plancher.
     */
    public function test_garde_fou_global_sur_datasets_varies(): void
    {
        $now = self::paris('2026-10-21 12:00');
        $f = self::forecastProb(0.9);
        $tarifaires = ['R1', 'R3', 'R5', 'R6', 'R7'];

        // D1 : coût suspect à côté d'un produit R1 sain (chiffres R1-qualifiants mais coût inconnu).
        $d1 = RecoScenario::dataset([
            self::produitR1('P1', 30.0),
            new RecoProduct('P2', 'Produit P2', 'Boissons', 80.0, 160.0, 2.0, null, 'cout_inconnu', 400, 30.0, 24.0, 36.0),
        ], [], [], 1.0, false, 30, $f);

        // D2 : produit « Événement » (catégorie exclue) aux chiffres R1-qualifiants.
        $d2 = RecoScenario::dataset([
            self::produitR1('P1', 30.0),
            self::produitR1('PE', 40.0, 'Événement'),
        ], [], [], 1.0, false, 30, $f);

        // D3 : duos — association forte P1→P2 (40 paniers multi sur 80).
        $paniers = [];
        for ($i = 0; $i < 40; $i++) {
            $paniers[] = RecoScenario::panier('2026-10-20', [['key' => 'P1', 'montant' => 2.0, 'qty' => 1], ['key' => 'P2', 'montant' => 2.0, 'qty' => 1]]);
            $paniers[] = RecoScenario::panier('2026-10-20', [['key' => 'P3', 'montant' => 3.0, 'qty' => 1]]);
        }
        $d3 = RecoScenario::dataset([
            new RecoProduct('P1', 'Boisson A', 'Boissons', 100.0, 200.0, 2.0, 1.0, null, null, 60.0, 48.0, 72.0),
            new RecoProduct('P2', 'Snack B', 'Snacks', 80.0, 160.0, 2.0, 1.0, null, null, 50.0, 40.0, 60.0),
            new RecoProduct('P3', 'Journal C', 'Presse', 60.0, 180.0, 3.0, 1.0, null, null, 40.0, 32.0, 48.0),
        ], $paniers, [], 1.0, false, 30, $f);

        // D4 : promo R1 saine + produit au plancher inatteignable (ni remise ni lot 3 pour 2).
        $d4 = RecoScenario::dataset([
            self::produitR1('P1', 30.0),
            new RecoProduct('P9', 'Produit P9', 'Boissons', 100.0, 200.0, 2.0, 1.6, null, 300, 30.0, 24.0, 36.0),
        ], [], [], 1.0, false, 30, $f);

        $datasets = ['D1' => $d1, 'D2' => $d2, 'D3' => $d3, 'D4' => $d4];
        foreach ($datasets as $nom => $ds) {
            $parCle = [];
            foreach ($ds->produits as $p) {
                $parCle[$p->key] = $p;
            }
            $out = RecommendationEngine::recommend($ds, $now);
            foreach ($out['idees'] as $idee) {
                foreach ($idee->produits as $pr) {
                    $p = $parCle[$pr['key']] ?? null;
                    self::assertNotNull($p, $nom . ' : produit ciblé inexistant.');
                    if ($p->coutRaison !== null) {
                        self::assertNotContains($idee->regle, $tarifaires, $nom . ' : aucun tarifaire sur coût suspect.');
                    }
                    self::assertNotContains($p->category, Hyper::CATEGORIES_EXCLUES, $nom . ' : catégorie exclue jamais ciblée.');
                }
            }
        }

        // D1 : R11 « renseigner le coût » présent, R1 confiné au produit sain.
        $out1 = RecommendationEngine::recommend($d1, $now);
        $r11P2 = null;
        foreach ($out1['idees'] as $idee) {
            if ($idee->regle === 'R11' && in_array('P2', array_column($idee->produits, 'key'), true)) {
                $r11P2 = $idee;
            }
        }
        self::assertNotNull($r11P2, 'Coût suspect signalé par R11.');
        self::assertContains('R1', self::regles($out1));

        // D2 : le produit Événement n'apparaît nulle part.
        $out2 = RecommendationEngine::recommend($d2, $now);
        foreach ($out2['idees'] as $idee) {
            self::assertNotContains('PE', array_column($idee->produits, 'key'), 'Catégorie exclue : aucune idée.');
        }

        // D3 : au niveau candidat, R5 produit un duo valide — chaque prix_effectif ≥ plancher.
        $parCleD3 = [];
        foreach ($d3->produits as $p) {
            $parCleD3[$p->key] = $p;
        }
        $duos = R5Duos::evaluer(new RecoContext($d3, $now));
        self::assertNotSame([], $duos, 'Association P1→P2 détectée (40 paniers multi / 80).');
        foreach ($duos as $c) {
            self::assertTrue(GuardRails::candidatValide($c, $parCleD3, Hyper::MARGE_MIN), 'Duo validé par les garde-fous.');
            foreach ((array) ($c['prix_effectifs'] ?? []) as $pe) {
                $p = $parCleD3[$pe['key']];
                self::assertGreaterThanOrEqual($p->cout * (1.0 + Hyper::MARGE_MIN) - 1.0e-9, $pe['prix'], 'Prix duo ≥ coût × (1 + marge_min).');
            }
        }

        // D4 : la remise R1 de P1 respecte le plancher ; P9 (plancher inatteignable) n'a AUCUNE idée.
        $out4 = RecommendationEngine::recommend($d4, $now);
        $r1P1 = null;
        foreach ($out4['idees'] as $idee) {
            self::assertNotContains('P9', array_column($idee->produits, 'key'), 'P9 : aucune remise possible sans casser le plancher.');
            if ($idee->regle === 'R1' && in_array('P1', array_column($idee->produits, 'key'), true)) {
                $r1P1 = $idee;
            }
        }
        self::assertNotNull($r1P1, 'R1 présent sur le produit sain.');
        self::assertSame(1, preg_match('/-(\d+),(\d+) %/', $r1P1->action, $m), 'Remise lisible dans l\'action : ' . $r1P1->action);
        $remisePct = (float) ($m[1] . '.' . $m[2]);
        self::assertGreaterThanOrEqual(1.0 * (1.0 + Hyper::MARGE_MIN) - 1.0e-9, 2.0 * (1.0 - $remisePct / 100.0), 'Prix remisé ≥ plancher de marge.');
    }

    public function test_max_cinq_dedoublonnage_et_refus_trente_jours(): void
    {
        $now = self::paris('2026-10-21 12:00');
        $produits = [];
        for ($i = 1; $i <= 8; $i++) {
            $produits[] = self::produitR1('P' . $i, 20.0 + (float) $i); // 8 candidats R1, gains croissants P1→P8
        }
        $ds = RecoScenario::dataset($produits, [], [], 1.0, false, 30, self::forecastProb(0.9));
        $fp = static fn (string $key): string => (new RecoContext($ds, $now))->fingerprint('R1', [$key]);

        $out = RecommendationEngine::recommend($ds, $now);
        self::assertCount(5, $out['idees'], 'Cap MAX_ACTIVES = 5.');
        $cles = [];
        foreach ($out['idees'] as $idee) {
            foreach (array_column($idee->produits, 'key') as $k) {
                self::assertNotContains($k, $cles, 'Dédoublonnage : un produit dans une seule idée.');
                $cles[] = $k;
            }
        }
        self::assertContains('P8', $cles, 'Meilleur gain présent.');

        // Refus daté now−29 j : P8 bloqué, remplacé par le candidat suivant.
        $dsRefus = RecoScenario::dataset($produits, [], [], 1.0, false, 30, self::forecastProb(0.9), [
            'actifs' => [],
            'refus' => [['fingerprint' => $fp('P8'), 'date' => '2026-09-22']],
            'appliquees' => [],
        ]);
        $out29 = RecommendationEngine::recommend($dsRefus, $now);
        self::assertCount(5, $out29['idees']);
        $cles29 = [];
        foreach ($out29['idees'] as $idee) {
            $cles29 = array_merge($cles29, array_column($idee->produits, 'key'));
        }
        self::assertNotContains('P8', $cles29, 'Refus < 30 j : l\'idée ne revient pas.');
        self::assertContains('P3', $cles29, 'Le candidat suivant (P3) entre au cap.');

        // Refus daté now−31 j : expiré, P8 revient.
        $dsExpire = RecoScenario::dataset($produits, [], [], 1.0, false, 30, self::forecastProb(0.9), [
            'actifs' => [],
            'refus' => [['fingerprint' => $fp('P8'), 'date' => '2026-09-20']],
            'appliquees' => [],
        ]);
        $out31 = RecommendationEngine::recommend($dsExpire, $now);
        $cles31 = [];
        foreach ($out31['idees'] as $idee) {
            $cles31 = array_merge($cles31, array_column($idee->produits, 'key'));
        }
        self::assertContains('P8', $cles31, 'Refus > 30 j : expiré, l\'idée revient.');
        self::assertCount(5, $out31['idees']);
    }

    public function test_r10_assemble_gain_somme_et_titres(): void
    {
        $now = self::paris('2026-10-21 12:00');
        $produits = [self::produitR1('P1', 30.0), self::produitR1('P2', 25.0)];

        $out = RecommendationEngine::recommend(RecoScenario::dataset($produits, [], [], 1.0, false, 30, self::forecastProb(0.30)), $now);
        self::assertSame(['R10'], self::regles($out), 'R10 (meilleur score) absorbe les clés des 2 sources R1, dédoublonnées.');
        $r10 = $out['idees'][0];
        self::assertEqualsWithDelta(
            self::gainMedianR1(30.0) + self::gainMedianR1(25.0),
            $r10->gainHebdo['median'],
            1.0e-9,
            'gain_hebdo R10 = somme des 2 sources.'
        );
        self::assertStringContainsString('Écouler le stock dormant de Produit P1', $r10->action, 'Action contenant le titre 1.');
        self::assertStringContainsString('Écouler le stock dormant de Produit P2', $r10->action, 'Action contenant le titre 2.');
        self::assertSame(['P1', 'P2'], array_column($r10->produits, 'key'));

        // Contrôle : probabilité d'atteinte ≥ seuil → pas de R10, les 2 R1 restent.
        $outCtrl = RecommendationEngine::recommend(RecoScenario::dataset($produits, [], [], 1.0, false, 30, self::forecastProb(0.80)), $now);
        self::assertNotContains('R10', self::regles($outCtrl));
        self::assertSame(['R1', 'R1'], self::regles($outCtrl));
    }

    /** Gain médian R1 recalculé pour un produit produitR1($key, $unites). */
    private static function gainMedianR1(float $unites): float
    {
        $stock = 10.0 * $unites + 20.0;
        $remise = min(1.0 - 1.0 * (1.0 + Hyper::MARGE_MIN) / 2.0, min(Hyper::REMISE_DORMANT_HAUT, max(Hyper::REMISE_DORMANT_BAS, 0.15)));

        return max($stock - (float) Hyper::STOCK_DORMANT_SEMAINES * $unites, 0.0) / R1StockDormant::DUREE_ECOULEMENT_SEMAINES * (2.0 * (1.0 - $remise) - 1.0);
    }

    /** Produit R1-qualifiant : stock = 10 × unités + 20, qty30 100, prix 2, coût 1. */
    private static function produitR1(string $key, float $unites, string $category = 'Boissons'): RecoProduct
    {
        return new RecoProduct(
            $key,
            'Produit ' . $key,
            $category,
            100.0,
            200.0,
            2.0,
            1.0,
            null,
            (int) round(10.0 * $unites + 20.0),
            $unites,
            $unites * 0.8,
            $unites * 1.2
        );
    }

    /** Liste des règles des idées d'une sortie recommend(). */
    private static function regles(array $out): array
    {
        return array_map(static fn (Recommendation $i): string => $i->regle, $out['idees']);
    }

    /** forecastMinimal avec prob_atteinte imposée (R10 on/off). */
    private static function forecastProb(float $prob): array
    {
        $f = RecoScenario::forecastMinimal();
        $f['semaine']['prob_atteinte'] = $prob;

        return $f;
    }

    /**
     * Série synthétique plate : $jours jours ouverts lun–ven, motif N
     * [70,60,65,75,90], panier 1,25 (façon tests/Unit/Forecast/EngineTest).
     *
     * @return array<string,DayCurrent>
     */
    private static function serie(int $jours): array
    {
        $days = [];
        $d = new \DateTimeImmutable('2026-09-07'); // lundi
        while (count($days) < $jours) {
            $dow = (int) $d->format('N') - 1;
            if ($dow <= 4) {
                $n = self::MOTIF[$dow];
                $key = $d->format('Y-m-d');
                $days[$key] = new DayCurrent($key, $n * 1.25, $n, self::horaire($n), array_fill(0, $n, 1.25));
            }
            $d = $d->modify('+1 day');
        }

        return $days;
    }

    /**
     * @return array<int,int>
     */
    private static function horaire(int $n): array
    {
        $h = array_fill(0, 24, 0);
        $base = intdiv($n, 10);
        $reste = $n - $base * 10;
        for ($i = 0; $i < 10; $i++) {
            $h[8 + $i] = $base + ($i < $reste ? 1 : 0);
        }

        return $h;
    }

    private static function paris(string $datetime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($datetime, new \DateTimeZone('Europe/Paris'));
    }
}
