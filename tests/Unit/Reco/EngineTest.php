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
 * sortie (16 clés), garde-fous globaux, blocs séparés idées (cap 3,
 * diversité ≤ 2 par règle) / corrections (cap 5, R11 prioritaire), R3
 * écartée et comptée, messages d'honnêteté, dédoublonnage + refus 30 j,
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

    public function test_historique_insuffisant_corrections_r2_r11_sans_idees(): void
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
            self::assertContains($r, ['R2', 'R11'], 'Historique insuffisant : corrections R2/R11 seulement (R8 écartée du mode restreint).');
        }
        foreach ($out10['idees'] as $idee) {
            self::assertSame('correction', $idee->bloc, 'AUCUNE idée en mode restreint : tout est correction.');
        }
        self::assertSame(0, $out10['meta']['idees_fiables']);
        self::assertStringContainsString('corrigez d\'abord les données', (string) $out10['meta']['message'], '0 idée → message honnête avec consigne de correction.');

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

    public function test_format_sortie_16_cles_et_fingerprint(): void
    {
        $produits = [self::produitR1('P1', 30.0), self::produitR1('P2', 24.0)];
        $now = new \DateTimeImmutable('2026-10-21T10:00:00+00:00'); // 12:00 à Paris
        $out = RecommendationEngine::recommend(RecoScenario::dataset($produits, [], [], 1.0, false, 30, self::forecastProb(0.9)), $now);

        self::assertNotSame([], $out['idees']);
        $attendues = ['id', 'regle', 'titre', 'pourquoi', 'action', 'gain_hebdo', 'confiance', 'effort', 'hypotheses', 'produits', 'garde_fous_ok', 'comment_mesurer', 'statut', 'bloc', 'debut', 'fin'];
        foreach ($out['idees'] as $idee) {
            self::assertInstanceOf(Recommendation::class, $idee);
            self::assertSame($attendues, array_keys($idee->toArray()), 'EXACTEMENT les 16 clés de Recommendation::toArray(), dans l\'ordre.');
            self::assertSame('idee', $idee->bloc, 'R1 est une idée (consigne).');
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $idee->debut, 'Consigne R1 datée (jeudi).');
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $idee->fin, 'Consigne R1 datée (vendredi).');
            self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $idee->id, 'id = fingerprint sha1 hexadécimal 40.');
            self::assertTrue($idee->gardeFousOk);
            self::assertSame('proposée', $idee->statut);
            self::assertSame($idee->id, (new RecoContext(RecoScenario::dataset(), $now))->fingerprint($idee->regle, array_column($idee->produits, 'key')), 'id = sha1(règle:clés triées).');
        }

        // meta : audit complet.
        $meta = $out['meta'];
        foreach (['creuse_active', 'facteur_creuse', 'historique_insuffisant', 'jours_ouverts_fenetre', 'n_paniers', 'genere_le', 'parametres', 'rejets', 'idees_fiables', 'message', 'ecartees'] as $k) {
            self::assertArrayHasKey($k, $meta);
        }
        self::assertSame('2026-10-21 12:00', $meta['genere_le'], 'Horodatage converti en Europe/Paris.');
        self::assertSame(0.15, $meta['parametres']['marge_min'], 'Paramètres résolus pour audit.');
        self::assertSame(30, $meta['jours_ouverts_fenetre']);
        self::assertSame(0, $meta['n_paniers']);
        self::assertIsInt($meta['rejets']);
        self::assertSame(2, $meta['idees_fiables'], 'Deux idées R1 retenues.');
        self::assertNull($meta['message'], '≥ 2 idées : pas de message.');
        self::assertSame(['R3' => 0], $meta['ecartees'], 'R3 absente du top CA à marge saine : rien d\'écarté.');
    }

    /**
     * BLOCS SÉPARÉS : 3 idées max (R1×3 ici, R7 évincée), corrections
     * R11 d'abord (cap R11_MAX) puis R2, R3 absente des deux blocs mais
     * comptée dans meta['ecartees']['R3'], et consignes impératives.
     */
    public function test_blocs_separees_caps_r11_prioritaire_et_r3_ecartee(): void
    {
        $now = self::paris('2026-10-21 12:00');
        $produits = [];
        for ($i = 1; $i <= 5; $i++) {
            $produits[] = self::produitR1('P' . $i, 20.0 + (float) $i); // 5 idées R1 possibles, cap 3
        }
        for ($i = 6; $i <= 9; $i++) {
            $produits[] = new RecoProduct('P' . $i, 'Produit P' . $i, 'Boissons', 50.0, 100.0, null, null, 'cout_inconnu', null, 10.0, 8.0, 12.0); // R11 ×4
        }
        $produits[] = new RecoProduct('P10', 'Rupture X', 'Snacks', 50.0, 100.0, 2.0, 1.0, null, 0, 10.0, 8.0, 12.0); // R2 (stock 0)
        $produits[] = new RecoProduct('P11', 'Soda top CA', 'Boissons', 400.0, 800.0, 2.0, 1.6, null, null, 100.0, 80.0, 120.0); // R3 (marge 20 %, top CA)

        $out = RecommendationEngine::recommend(RecoScenario::dataset($produits, [], [], 1.0, false, 30, self::forecastProb(0.9)), $now);

        $idees = array_values(array_filter($out['idees'], static fn (Recommendation $r): bool => $r->bloc === 'idee'));
        $corrections = array_values(array_filter($out['idees'], static fn (Recommendation $r): bool => $r->bloc === 'correction'));
        self::assertCount(3, $idees, 'Cap MAX_IDEES = 3.');
        self::assertCount(4, $corrections, 'Cap 5 : 3 R11 (R11_MAX) + 1 R2.');
        self::assertSame(['R11', 'R11', 'R11', 'R2'], array_column($corrections, 'regle'), 'R11 d\'abord dans les corrections.');
        self::assertSame(['R1', 'R1', 'R7'], array_column($idees, 'regle'), 'Diversité : au plus 2 R1, la R7 prend le 3e slot.');

        // R3 : ni idée ni correction, mais comptée comme écartée.
        foreach ($out['idees'] as $idee) {
            self::assertNotSame('R3', $idee->regle, 'R3 n\'est plus émise.');
        }
        self::assertSame(1, $out['meta']['ecartees']['R3'], 'Le candidat R3 (Soda top CA à 20 % de marge) est compté puis écarté.');
        self::assertSame(3, $out['meta']['idees_fiables']);
        self::assertNull($out['meta']['message']);

        // Consignes impératives : l'ensemble des idées contient au moins un ordre d'affichage/promo.
        $actions = implode(' | ', array_map(static fn (Recommendation $r): string => $r->action, $idees));
        self::assertSame(1, preg_match('/Affichez|Proposez|Entre .*h|offrez/u', $actions), 'Consignes attendues dans les idées : ' . $actions);
        foreach ($idees as $idee) {
            if ($idee->regle === 'R1') {
                self::assertStringContainsString('Affichez', $idee->action, 'Consigne R1 : affichage caisse.');
            }
        }
    }

    public function test_message_honnete_selon_le_nombre_d_idees(): void
    {
        $now = self::paris('2026-10-21 12:00');
        $f = self::forecastProb(0.9);

        // 0 idée : uniquement un coût suspect (R11 en correction).
        $pauvre = new RecoProduct('PX', 'Produit PX', 'Boissons', 50.0, 100.0, null, null, 'cout_inconnu', null, 10.0, 8.0, 12.0);
        $out0 = RecommendationEngine::recommend(RecoScenario::dataset([$pauvre], [], [], 1.0, false, 30, $f), $now);
        self::assertSame(0, $out0['meta']['idees_fiables']);
        self::assertSame(
            'Peu d\'idées fiables pour l\'instant — corrigez d\'abord les données ci-dessous.',
            $out0['meta']['message']
        );

        // 1 idée : R1 seule (R7 dédoublonnée sur le même produit).
        $out1 = RecommendationEngine::recommend(RecoScenario::dataset([self::produitR1('P1', 30.0)], [], [], 1.0, false, 30, $f), $now);
        self::assertSame(1, $out1['meta']['idees_fiables']);
        self::assertSame('Peu d\'idées fiables pour l\'instant.', $out1['meta']['message']);
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

    public function test_cap_trois_idees_dedoublonnage_et_refus_trente_jours(): void
    {
        $now = self::paris('2026-10-21 12:00');
        $produits = [];
        for ($i = 1; $i <= 8; $i++) {
            $produits[] = self::produitR1('P' . $i, 20.0 + (float) $i); // 8 candidats R1, gains croissants P1→P8
        }
        $ds = RecoScenario::dataset($produits, [], [], 1.0, false, 30, self::forecastProb(0.9));
        $fp = static fn (string $key): string => (new RecoContext($ds, $now))->fingerprint('R1', [$key]);

        $out = RecommendationEngine::recommend($ds, $now);
        $idees = array_values(array_filter($out['idees'], static fn (Recommendation $r): bool => $r->bloc === 'idee' && $r->regle === 'R1'));
        self::assertCount(2, $idees, 'Cap MAX_IDEES = 3 MAIS diversité ≤ IDEES_MAX_PAR_REGLE (2) par règle : 2 R1 seulement.');
        $cles = [];
        foreach ($idees as $idee) {
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
        $idees29 = array_values(array_filter($out29['idees'], static fn (Recommendation $r): bool => $r->bloc === 'idee' && $r->regle === 'R1'));
        self::assertCount(2, $idees29);
        $cles29 = [];
        foreach ($idees29 as $idee) {
            $cles29 = array_merge($cles29, array_column($idee->produits, 'key'));
        }
        self::assertNotContains('P8', $cles29, 'Refus < 30 j : l\'idée ne revient pas.');
        self::assertContains('P6', $cles29, 'Le candidat suivant (P6) entre dans la limite diversité.');

        // Refus daté now−31 j : expiré, P8 revient.
        $dsExpire = RecoScenario::dataset($produits, [], [], 1.0, false, 30, self::forecastProb(0.9), [
            'actifs' => [],
            'refus' => [['fingerprint' => $fp('P8'), 'date' => '2026-09-20']],
            'appliquees' => [],
        ]);
        $out31 = RecommendationEngine::recommend($dsExpire, $now);
        $idees31 = array_values(array_filter($out31['idees'], static fn (Recommendation $r): bool => $r->bloc === 'idee' && $r->regle === 'R1'));
        $cles31 = [];
        foreach ($idees31 as $idee) {
            $cles31 = array_merge($cles31, array_column($idee->produits, 'key'));
        }
        self::assertContains('P8', $cles31, 'Refus > 30 j : expiré, l\'idée revient.');
        self::assertCount(2, $idees31);
    }

    /**
     * DIVERSITÉ des idées (constat prod : R4×3 évinçait une R7 plus
     * riche) — 3 candidats R4 dominent le scoring + 1 candidat R7 moins
     * bien noté : le top 3 final contient au PLUS Hyper::IDEES_MAX_PAR_REGLE
     * (2) R4 et inclut la R7 ; avec la diversité désactivée (surcharge
     * « reco_idees_max_par_regle » = 0), l'ancien comportement R4×3
     * réapparaît.
     */
    public function test_diversite_idees_max_deux_r4_s_evacuent_pas_la_r7(): void
    {
        $now = self::paris('2026-10-21 12:00');
        $produits = [
            self::produitR4('P1'),
            self::produitR4('P2'),
            self::produitR4('P3'), // 3 candidats R4 (forte marge, sous-vendus)
        ];
        for ($i = 4; $i <= 7; $i++) {
            $produits[] = self::produitVolume('P' . $i); // fort volume, faible marge : ni R4 ni R7
        }
        $produits[] = self::produitVolume('P8', 0.2); // meilleur taux de marge : cible R7 (volume trop fort pour R4)

        $ds = RecoScenario::dataset($produits, [], [], 1.0, false, 30, self::forecastProb(0.9));

        $out = RecommendationEngine::recommend($ds, $now);
        $idees = array_values(array_filter($out['idees'], static fn (Recommendation $r): bool => $r->bloc === 'idee'));
        self::assertCount(3, $idees, 'Cap MAX_IDEES = 3.');
        self::assertSame(['R4', 'R4', 'R7'], array_column($idees, 'regle'), 'Au plus 2 R4 (diversité) : la R7 entre au cap.');
        $r7 = $idees[2];
        self::assertSame(['P8'], array_column($r7->produits, 'key'), 'La R7 retenue est bien celle du produit dédié.');
        self::assertSame(2, $out['meta']['parametres']['idees_max_par_regle'], 'Diversité lisible dans les paramètres audités.');

        // Contrôle : diversité désactivée → comportement précédent (R4×3, R7 évincée).
        $outSans = RecommendationEngine::recommend($ds, $now, ['parametres' => ['reco_idees_max_par_regle' => 0]]);
        $ideesSans = array_values(array_filter($outSans['idees'], static fn (Recommendation $r): bool => $r->bloc === 'idee'));
        self::assertSame(['R4', 'R4', 'R4'], array_column($ideesSans, 'regle'), 'Sans diversité : R4×3 évince la R7.');
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
        self::assertSame('idee', $r10->bloc, 'R10 est une idée.');
        self::assertStringContainsString('Offre fin de semaine : Produit P1', $r10->action, 'Action contenant le titre 1.');
        self::assertStringContainsString('Offre fin de semaine : Produit P2', $r10->action, 'Action contenant le titre 2.');
        self::assertSame(['P1', 'P2'], array_column($r10->produits, 'key'));

        // Contrôle : probabilité d'atteinte ≥ seuil → pas de R10, les 2 R1 restent.
        $outCtrl = RecommendationEngine::recommend(RecoScenario::dataset($produits, [], [], 1.0, false, 30, self::forecastProb(0.80)), $now);
        self::assertNotContains('R10', self::regles($outCtrl));
        self::assertSame(['R1', 'R1'], self::regles($outCtrl));
    }

    /** Produit R4-qualifiant : faible volume (10 ≥ sample 8), taux 0,6, stock non géré. */
    private static function produitR4(string $key): RecoProduct
    {
        return new RecoProduct($key, 'Produit ' . $key, 'Boissons', 10.0, 20.0, 2.0, 0.8, null, null, 10.0, 8.0, 12.0);
    }

    /** Produit fort volume (100, hors R4), prix 2 ; coût 1,8 par défaut (taux 0,1). */
    private static function produitVolume(string $key, float $cout = 1.8): RecoProduct
    {
        return new RecoProduct($key, 'Produit ' . $key, 'Boissons', 100.0, 200.0, 2.0, $cout, null, null, 20.0, 16.0, 24.0);
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
