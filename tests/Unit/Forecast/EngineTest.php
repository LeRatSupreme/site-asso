<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Core\Compta\Forecast\Clean;
use App\Core\Compta\Forecast\Conformal;
use App\Core\Compta\Forecast\DemandModel;
use App\Core\Compta\Forecast\ForecastDataset;
use App\Core\Compta\Forecast\ForecastEngine;
use App\Core\Compta\Forecast\DayCurrent;
use App\Core\Compta\Forecast\Hyper;
use App\Core\Compta\Forecast\MonteCarlo;
use App\Core\Compta\Forecast\ProductStat;
use PHPUnit\Framework\TestCase;

/**
 * Moteur complet (étape 9) sur séries synthétiques déterministes :
 *  - série plate de 28 jours ouverts (lun–ven × 4, panier 1.25) → somme
 *    des 5 prochains jours ouverts à ±5 % de 5 × 1.25 × 72 = 450 € ;
 *  - jour atypique ca ×10 → poids 0.3 et prévision stable (< 10 %) ;
 *  - 4 jours seulement → confiance « insuffisant », écart/intervalle null ;
 *  - nowHour = 23 → projection_fin_journee == reel_a_h ;
 *  - dataset vide → aucune exception, nulls cohérents.
 */
final class EngineTest extends TestCase
{
    private const MOTIF = [70, 60, 65, 75, 90]; // N par jour : lun..ven

    public function test_serie_plate_28_jours_somme_prochains_jours(): void
    {
        $r = ForecastEngine::forecast(
            new ForecastDataset(self::serie(28)),
            self::paris('2026-10-19 23:59'), // lundi suivant la série (qui se termine un mercredi)
            '23:59',
            ['seed' => 42, 'draws' => 1000]
        );

        self::assertSame(7, count($r['jours']));
        self::assertSame([true, true, true, true, false, false, true], array_column($r['jours'], 'is_open'), 'Mar..Ven + lun suivant ouverts ; sam/dim fermés.');

        $somme = 0.0;
        foreach ($r['jours'] as $j) {
            if ($j['is_open']) {
                $somme += $j['ca'];
            }
        }
        self::assertEqualsWithDelta(450.0, $somme, 450.0 * 0.05, 'Série plate (72 transactions/j en moyenne) → 5 × 1.25 × 72.');

        self::assertNotSame('insuffisant', $r['confiance']);
        self::assertSame(28, $r['n_jours']);
        self::assertNotNull($r['prevision_jour']);
        self::assertNotNull($r['intervalle_80']);
        self::assertContains($r['modele'], ['negbin_dow_damped', 'baseline_B2']);
        self::assertNotNull($r['semaine']);
        self::assertSame(4, $r['semaine']['jours_restants'], 'Lundi 19/10 hors dataset (série finie le 14) : restent mar..ven.');
        self::assertGreaterThan(0.0, $r['mois30']['attendu']);
        self::assertCount(7, $r['jours_semaine']);
        self::assertSame('Lun', $r['jours_semaine'][0]['label']);
        self::assertSame('Dim', $r['jours_semaine'][6]['label']);
        self::assertNotNull($r['meilleur_jour'], 'Vendredi = meilleur jour (90 transactions).');
        self::assertSame(4, $r['meilleur_jour']['dow']);
    }

    public function test_jour_atypique_poids_0_3_prevision_stable(): void
    {
        $base = self::serie(28);
        $variante = self::serie(28);
        $atypique = $variante['2026-09-16']; // mercredi semaine 2
        $variante['2026-09-16'] = new DayCurrent(
            '2026-09-16',
            $atypique->ca * 10.0,
            $atypique->n * 10,
            array_map(static fn (int $v): int => $v * 10, $atypique->hourlyN),
            array_merge($atypique->baskets, $atypique->baskets, $atypique->baskets, $atypique->baskets, $atypique->baskets, $atypique->baskets, $atypique->baskets, $atypique->baskets, $atypique->baskets, $atypique->baskets)
        );

        $poids = Clean::atypicalWeights($variante);
        self::assertEqualsWithDelta(0.3, $poids['2026-09-16'], 0.0, 'Jour atypique ca ×10 → poids W_ATYPICAL.');
        self::assertEqualsWithDelta(1.0, $poids['2026-09-09'], 0.0, 'Jour normal → poids 1.0.');

        $rBase = ForecastEngine::forecast(new ForecastDataset($base), self::paris('2026-10-19 23:59'), '23:59', ['seed' => 42, 'draws' => 1000]);
        $rVar = ForecastEngine::forecast(new ForecastDataset($variante), self::paris('2026-10-19 23:59'), '23:59', ['seed' => 42, 'draws' => 1000]);

        self::assertContains('2026-09-16', $rVar['anomalies']['jours_atypiques']);

        $sommeBase = 0.0;
        $sommeVar = 0.0;
        foreach ($rBase['jours'] as $j) {
            if ($j['is_open']) {
                $sommeBase += $j['ca'];
            }
        }
        foreach ($rVar['jours'] as $j) {
            if ($j['is_open']) {
                $sommeVar += $j['ca'];
            }
        }
        self::assertGreaterThan(0.0, $sommeBase);
        self::assertLessThan(0.10, abs($sommeVar - $sommeBase) / $sommeBase, 'Le poids 0.3 borne l\'impact du jour atypique sur la prévision.');
    }

    public function test_quatre_jours_confiance_insuffisante(): void
    {
        $jours = array_slice(self::serie(28), 0, 4, true); // lun..ven semaine 1 : 4 premiers jours
        $r = ForecastEngine::forecast(
            new ForecastDataset($jours),
            self::paris('2026-09-11 12:00'), // vendredi, non ouvert habituel
            '12:00',
            ['seed' => 42, 'draws' => 300]
        );

        self::assertSame('insuffisant', $r['confiance']);
        self::assertNull($r['ecart_vs_attendu']);
        self::assertNull($r['dans_intervalle']);
        self::assertNull($r['prevision_jour'], 'Vendredi non ouvert habituel → pas de prévision.');
        self::assertTrue($r['anomalies']['donnees_insuffisantes']);
        self::assertContains('Données insuffisantes (< 7 jours ouverts) : aucun écart en pourcentage n\'est affiché.', $r['notes']);
    }

    public function test_projection_fin_journee_egal_reel_a_23h(): void
    {
        $r = ForecastEngine::forecast(
            new ForecastDataset(self::serie(28)),
            self::paris('2026-10-02 23:59'), // dernier vendredi du dataset (aujourd'hui)
            '23:59',
            ['seed' => 42, 'draws' => 300]
        );

        self::assertSame(23, $r['horaire']['now_hour']);
        self::assertEqualsWithDelta($r['horaire']['reel_a_h'], $r['projection_fin_journee'], 0.01, 'À 23h tout le projété est du réel.');
        self::assertEqualsWithDelta(112.5, $r['horaire']['reel_a_h'], 0.01, '90 transactions × panier 1.25.');
        self::assertNotNull($r['hier'], 'Hier (jeudi) est dans le dataset.');
        self::assertEqualsWithDelta(75.0 * 1.25, $r['hier']['reel'], 0.0001, 'Jeudi : 75 × 1.25.');
        self::assertEqualsWithDelta(112.5, $r['projection_fin_journee'], 0.01);
    }

    public function test_evenements_additionnes_sans_incertitude(): void
    {
        $opts = ['seed' => 42, 'draws' => 500];
        $rSans = ForecastEngine::forecast(new ForecastDataset(self::serie(28)), self::paris('2026-10-19 23:59'), '23:59', $opts);
        $rAvec = ForecastEngine::forecast(new ForecastDataset(self::serie(28)), self::paris('2026-10-19 23:59'), '23:59', $opts + ['events' => ['2026-10-20' => 50.0]]);

        $caSans = null;
        $caAvec = null;
        foreach ($rSans['jours'] as $j) {
            if ($j['date'] === '2026-10-20') {
                $caSans = $j['ca'];
            }
        }
        foreach ($rAvec['jours'] as $j) {
            if ($j['date'] === '2026-10-20') {
                $caAvec = $j['ca'];
            }
        }
        self::assertNotNull($caSans);
        self::assertEqualsWithDelta(50.0, $caAvec - $caSans, 1.0e-9, 'Événement ajouté comme constante.');
        self::assertEqualsWithDelta(50.0, $rAvec['jours'][0]['evenement'], 0.0);
        self::assertContains('1 événement(s) planifié(s) ajouté(s) aux prévisions.', $rAvec['notes']);
        self::assertNotContains('1 événement(s) planifié(s) ajouté(s) aux prévisions.', $rSans['notes']);
    }

    public function test_determinisme_meme_graine(): void
    {
        $opts = ['seed' => 7, 'draws' => 200];
        $a = ForecastEngine::forecast(new ForecastDataset(self::serie(28)), self::paris('2026-10-19 23:59'), '23:59', $opts);
        $b = ForecastEngine::forecast(new ForecastDataset(self::serie(28)), self::paris('2026-10-19 23:59'), '23:59', $opts);

        self::assertSame($a, $b, 'Même graine → sortie moteur strictement identique.');
    }

    public function test_dataset_vide_sans_erreur(): void
    {
        $r = ForecastEngine::forecast(new ForecastDataset([]), self::paris('2026-10-05 12:00'), '12:00', ['seed' => 1, 'draws' => 100]);

        self::assertNull($r['prevision_jour']);
        self::assertSame('insuffisant', $r['confiance']);
        self::assertNull($r['ecart_vs_attendu']);
        self::assertNull($r['dans_intervalle']);
        self::assertNull($r['meilleur_jour']);
        self::assertSame(0, $r['n_jours']);
        self::assertCount(7, $r['jours_semaine']);
        self::assertCount(7, $r['jours']);
        self::assertTrue($r['anomalies']['donnees_insuffisantes']);
    }

    /**
     * FIX A — TEST DE RÉCONCILIATION (demandé explicitement) : une seule
     * source d'unités calibrée sur le moteur.
     *  - |Σ CA produits − Σ CA jours| / Σ CA jours < 5 % ;
     *  - marge catégorie = Σ marges variantes (±0,01), marge_pct = marge/ca
     *    des MÊMES unités (comparable à la marge observée) ;
     *  - unités totales post-calibration cohérentes avec l'échelle du
     *    moteur (≈ caJours / prix ; tolérance large vs meanN × upT × 7).
     */
    public function test_reconciliation_ca_produits_egal_ca_jours(): void
    {
        $serie = self::serie(28);
        $data = new ForecastDataset($serie, self::produits());
        $r = ForecastEngine::forecast($data, self::paris('2026-10-19 23:59'), '23:59', ['seed' => 42, 'draws' => 1000]);

        $caJours = 0.0;
        foreach ($r['jours'] as $j) {
            $caJours += $j['ca'];
        }
        $caProduits = 0.0;
        $sommeMarges = 0.0;
        $unitesTot = 0.0;
        foreach ($r['produits'] as $p) {
            $caProduits += (float) $p['unites'] * (float) $p['unitPrice'];
            $sommeMarges += (float) $p['unites'] * ((float) $p['unitPrice'] - (float) $p['unitCost']);
            $unitesTot += (float) $p['unites'];
        }

        self::assertGreaterThan(0.0, $caJours);
        self::assertLessThan(0.05, abs($caProduits - $caJours) / $caJours, 'Σ CA produits ≈ Σ CA jours (< 5 %) : invariant de calibration.');

        // Bénéfice dérivé des MÊMES unités (une seule catégorie fiable).
        self::assertEqualsWithDelta($sommeMarges, $r['benefice']['total_marge_7j'], 0.01);
        self::assertEqualsWithDelta($sommeMarges, $r['benefice']['par_categorie']['Energisant']['marge'], 0.01, 'Marge catégorie = Σ marges variantes (±0,01).');
        self::assertEqualsWithDelta(50.0, $r['benefice']['par_categorie']['Energisant']['marge_pct'], 0.01, 'Marge/ca des mêmes unités (prix 2, coût 1 → 50 %).');
        self::assertEqualsWithDelta($caProduits, $r['benefice']['par_categorie']['Energisant']['ca'], 0.01);

        // Cohérence d'échelle : unités calibrées ≈ caJours / prix (2 €) ;
        // ordre de grandeur préservé vs meanN × upT × 7 (tolérance large).
        $m = DemandModel::fit($serie, [], Hyper::K_POOL);
        $sumQty = 0.0;
        foreach (self::produits() as $p) {
            $sumQty += $p->qty;
        }
        $sumN = 0;
        foreach ($serie as $day) {
            $sumN += $day->n;
        }
        $brut = $m->globalN * ($sumQty / $sumN) * 7;
        self::assertEqualsWithDelta($caJours / 2.0, $unitesTot, $caJours / 2.0 * 0.005, 'Toutes les unités sont calibrées sur le CA prévu.');
        self::assertGreaterThan($brut * 0.2, $unitesTot, 'Ordre de grandeur préservé (pas de facteur ×2.7).');
        self::assertLessThan($brut * 5.0, $unitesTot);
    }

    /**
     * FIX C — le backtest tourne sur l'HISTORIQUE COMPLET (opts
     * 'backtest_days') même quand la fenêtre fw compte moins de
     * BACKTEST_START jours ouverts : wape_backtest non null, note de
     * sélection et facteur conforme produits. Sans 'backtest_days',
     * l'historique court reste insuffisant (comportement préservé).
     */
    public function test_backtest_sur_historique_complet_quand_fenetre_courte(): void
    {
        $full = self::serie(28);
        $fwCourt = array_slice($full, 20, null, true); // 8 derniers jours ouverts (< 10)
        self::assertCount(8, $fwCourt);

        $sans = ForecastEngine::forecast(new ForecastDataset($fwCourt), self::paris('2026-10-19 23:59'), '23:59', ['seed' => 5, 'draws' => 200]);
        self::assertNull($sans['wape_backtest'], 'Fenêtre seule < 10 jours ouverts → backtest indisponible.');

        $avec = ForecastEngine::forecast(new ForecastDataset($fwCourt), self::paris('2026-10-19 23:59'), '23:59', [
            'seed' => 5, 'draws' => 200, 'backtest_days' => $full,
        ]);
        self::assertNotNull($avec['wape_backtest'], 'Backtest lancé sur l\'historique complet malgré fw < 10 jours ouverts.');
        self::assertNotNull($avec['anomalies']['note_selection']);
        self::assertNotNull($avec['facteur_conforme'], '19 points de backtest ≥ CONFORMAL_MIN → facteur conforme.');
        self::assertNotContains('Backtest indisponible (historique trop court) : modèle principal actif par défaut.', $avec['notes']);
    }

    /**
     * FIX D — période creuse saisie à la main : les prévisions des dates
     * DANS la plage sont multipliées par le facteur (jours futurs, n et
     * ca, projection du jour), l'observé « hier » reste inchangé, et la
     * note l'explique en clair.
     */
    public function test_periode_creuse_ajuste_les_previsions_pas_lobserve(): void
    {
        $opts = ['seed' => 42, 'draws' => 500];
        $slow = ['facteur' => 0.8, 'ranges' => [['start' => '2026-10-19', 'end' => '2026-11-30']]];

        $base = ForecastEngine::forecast(new ForecastDataset(self::serie(28)), self::paris('2026-10-19 23:59'), '23:59', $opts);
        $ajuste = ForecastEngine::forecast(new ForecastDataset(self::serie(28)), self::paris('2026-10-19 23:59'), '23:59', $opts + ['slow_periods' => $slow]);

        foreach ($ajuste['jours'] as $i => $j) {
            if ($j['is_open']) {
                self::assertEqualsWithDelta((float) $base['jours'][$i]['ca'] * 0.8, (float) $j['ca'], 0.05, 'Jour futur en plage creuse ×0,8.');
                self::assertEqualsWithDelta((float) $base['jours'][$i]['n'] * 0.8, (float) $j['n'], 0.05, 'Transactions attendues ×0,8.');
            }
        }
        self::assertLessThan($base['semaine']['attendu'], $ajuste['semaine']['attendu'], 'La semaine restante suit l\'ajustement.');
        self::assertLessThan($base['mois30']['attendu'], $ajuste['mois30']['attendu'], 'L\'horizon 30 j suit l\'ajustement.');
        self::assertContains('Période creuse 19/10–30/11 : prévisions ajustées ×0,8.', $ajuste['notes']);
        self::assertNotContains('Période creuse 19/10–30/11 : prévisions ajustées ×0,8.', $base['notes']);

        // Aujourd'hui DANS la plage : projection ajustée ; hier (observé,
        // hors plage) strictement identique entre les deux exécutions.
        $optsJour = ['seed' => 42, 'draws' => 500];
        $slowJour = ['facteur' => 0.5, 'ranges' => [['start' => '2026-10-02', 'end' => '2026-10-31']]];
        $a = ForecastEngine::forecast(new ForecastDataset(self::serie(28)), self::paris('2026-10-02 12:00'), '12:00', $optsJour);
        $b = ForecastEngine::forecast(new ForecastDataset(self::serie(28)), self::paris('2026-10-02 12:00'), '12:00', $optsJour + ['slow_periods' => $slowJour]);

        self::assertEqualsWithDelta((float) $a['prevision_jour'] * 0.5, (float) $b['prevision_jour'], 0.05, 'Projection du jour ×0,5 (aujourd\'hui en plage).');
        self::assertSame($a['hier'], $b['hier'], '« Hier » est observé : jamais ajusté.');
    }

    /**
     * FIX E — choc de niveau commun : la largeur RELATIVE de la
     * fourchette 30 j dépasse celle d'un jour (le niveau ne se
     * moyennise pas sur 30 jours) et prob_atteinte reste une fraction.
     */
    public function test_choc_de_niveau_commun_elargit_l_horizon_30j(): void
    {
        $r = ForecastEngine::forecast(new ForecastDataset(self::serie(28)), self::paris('2026-10-19 23:59'), '23:59', ['seed' => 42, 'draws' => 1000]);

        self::assertNotNull($r['intervalle_80']);
        $relJour = ((float) $r['intervalle_80']['hi'] - (float) $r['intervalle_80']['lo']) / max(0.01, (float) $r['prevision_jour']);
        $rel30 = ((float) $r['mois30']['hi'] - (float) $r['mois30']['lo']) / max(0.01, (float) $r['mois30']['attendu']);

        self::assertGreaterThan($relJour, $rel30, 'Fourchette 30 j plus large RELATIVEMENT que celle d\'un jour.');
        self::assertGreaterThan(0.20, $rel30, 'Horizon 30 j : largeur honnête (choc Gamma(8, 1/8) non moyennisé).');
        self::assertGreaterThanOrEqual(0.0, $r['semaine']['prob_atteinte']);
        self::assertLessThanOrEqual(1.0, $r['semaine']['prob_atteinte']);
    }

    /**
     * FIX G — « Hier » : le refit RÉUTILISE la dispersion r du modèle
     * principal (propriété globale). Scénario du dump prod reproduit sur
     * série bruitée : le r local du refit sans hier diffère du r principal
     * (28,2 vs 14,8). La fourchette du payload doit être ÉGALE au
     * recalcul déterministe avec le r principal imposé — elle ne l'est
     * PAS avec l'ancien comportement (r local du refit).
     */
    public function test_hier_reutilise_la_dispersion_principale(): void
    {
        $days = self::serieBruitee(28);
        $now = self::paris('2026-10-15 23:59'); // hier = dernier jour de la série (mer 14/10)
        $r = ForecastEngine::forecast(new ForecastDataset($days), $now, '23:59', ['seed' => 42, 'draws' => 1000]);

        self::assertNotNull($r['hier'], 'Hier (mercredi) est dans le dataset.');

        $hierKey = '2026-10-14';
        $subsetH = array_filter($days, static fn (string $k): bool => $k !== $hierKey, ARRAY_FILTER_USE_KEY);
        $main  = DemandModel::fit($days, Clean::atypicalWeights($days), Hyper::K_POOL);
        $refit = DemandModel::fit($subsetH, Clean::atypicalWeights($subsetH), Hyper::K_POOL);
        self::assertNotEqualsWithDelta(
            $main->dispersionR,
            $refit->dispersionR,
            0.5,
            'Scénario du dump : le r local du refit sans hier diffère du r principal.'
        );

        // Recalcul déterministe de la fourchette attendue avec le r
        // principal (même graine que le moteur : Mt19937(seed + crc32 tag)).
        $modelH = DemandModel::fit($subsetH, Clean::atypicalWeights($subsetH), Hyper::K_POOL, $main->dispersionR);
        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937(42 + crc32('H' . $hierKey)));
        $sum = MonteCarlo::summarize(MonteCarlo::daySamples($modelH, new \DateTimeImmutable($hierKey), $rng, 1000));
        $cal = Conformal::apply(
            is_numeric($r['facteur_conforme'] ?? null) ? (float) $r['facteur_conforme'] : null,
            $sum['mean'],
            $sum['lo'],
            $sum['hi']
        );

        self::assertEqualsWithDelta($sum['mean'], (float) $r['hier']['prevu'], 1.0e-9, 'Prévu : refit identique au moteur.');
        self::assertEqualsWithDelta($cal['lo'], (float) $r['hier']['lo'], 1.0e-9, 'Fourchette « Hier » : r du modèle PRINCIPAL réutilisé (pas le r local).');
        self::assertEqualsWithDelta($cal['hi'], (float) $r['hier']['hi'], 1.0e-9, 'Fourchette « Hier » : r du modèle PRINCIPAL réutilisé (pas le r local).');
    }

    /**
     * Produits synthétiques du test de réconciliation : quantités
     * hétérogènes, prix 2, coût 1, même catégorie (agrégats lisibles).
     *
     * @return list<ProductStat>
     */
    private static function produits(): array
    {
        return [
            new ProductStat('a', 'Boisson A', 'Energisant', 720, 1440.0, 2.0, 1.0, false, null),
            new ProductStat('b', 'Boisson B', 'Energisant', 360, 720.0, 2.0, 1.0, false, null),
            new ProductStat('c', 'Boisson C', 'Energisant', 240, 480.0, 2.0, 1.0, false, null),
            new ProductStat('d', 'Boisson D', 'Energisant', 120, 240.0, 2.0, 1.0, false, null),
        ];
    }

    /**
     * Série synthétique plate : $jours jours ouverts consécutifs lun–ven,
     * N selon le motif [70,60,65,75,90], panier constant 1.25, horaire 8h→17h.
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
     * Série du motif avec bruit déterministe en zigzag (jours ouverts
     * pairs ×1,25, impairs ×0,8) : surdispersion réaliste — le r du
     * modèle principal diffère du r local du refit sans le dernier jour
     * (scénario du dump prod, test du FIX G).
     *
     * @return array<string,DayCurrent>
     */
    private static function serieBruitee(int $jours): array
    {
        $days = [];
        $d = new \DateTimeImmutable('2026-09-07'); // lundi
        $i = 0;
        while (count($days) < $jours) {
            $dow = (int) $d->format('N') - 1;
            if ($dow <= 4) {
                $n = (int) round(self::MOTIF[$dow] * ($i % 2 === 0 ? 1.25 : 0.8));
                $key = $d->format('Y-m-d');
                $days[$key] = new DayCurrent($key, $n * 1.25, $n, self::horaire($n), array_fill(0, $n, 1.25));
                $i++;
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
