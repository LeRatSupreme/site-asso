<?php

declare(strict_types=1);

namespace App\Core\Compta\Forecast;

/**
 * Étape 9 : moteur de prévision — point d'entrée UNIQUE du cœur.
 *
 * Enchaîne toutes les étapes (nettoyage, ajustement, backtest, Monte
 * Carlo, calibration conforme, objectifs, bénéfice, produits) et rend
 * TOUTES les sorties consommées par le contrôleur et les vues. Rien
 * n'est recalculé ailleurs. Pur : aucun accès DB.
 *
 * Conventions documentées :
 *  - Projection restant-du-jour : les tirages pleine journée d'aujourd'hui
 *    sont multipliés par (1 − s_h) × θ (approximation : la part restante
 *    de la journée suit le profil horaire, amortie par θ) ;
 *  - CA horaire réel : hourlyN[h] × panier moyen du jour (ca / n si n > 0,
 *    sinon panier global du modèle) — approximation documentée ;
 *  - Les événements planifiés (opts['events'], 'Y-m-d' => CA) sont
 *    ADDITIONNÉS aux prévisions et cumuls comme constantes (sans
 *    incertitude) : chaque tirage du jour concerné est décalé du montant.
 *  - Produits et bénéfice partent des MÊMES unités : les unités brutes
 *    du réappro (parts Dirichlet) sont calibrées sur le CA prévu 7 j
 *    (Σ des jours), invariant Σ unites × prix ≈ CA prévu 7 j, et le
 *    bénéfice est dérivé de cette liste (ProfitForecast::compute).
 *  - Choc de niveau commun (Goal::week/horizon) : UN facteur par chemin
 *    MC f_path ~ Gamma(8, 1/8) (moyenne 1, σ ≈ 35 %, même prior que la
 *    mise à jour horaire) multiplie la somme du chemin — le niveau ne
 *    se moyennise pas sur 30 jours, la fourchette de l'horizon reste
 *    honnête (±30-45 % au lieu de ±7 %).
 *  - Attendu du jour TOUJOURS calculé sur un jour ouvré habituel,
 *    même sans aucune vente encore enregistrée (aujourd'hui absent du
 *    dataset) : la prévision pleine journée vient du modèle, jamais
 *    des données partielles du jour ; null uniquement si le jour est
 *    fermé (les vues affichent « Fermé aujourd'hui », jamais 0,00 €).
 */
final class ForecastEngine
{
    /** Libellés FR courts des jours de semaine (0 = lundi). */
    private const LABELS = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];

    /**
     * Calcule l'ensemble des prévisions.
     *
     * @param ForecastDataset       $data    Dataset construit en amont (jours ouverts, aujourd'hui partiel inclus).
     * @param \DateTimeImmutable    $now     Instant de référence (forcé en Europe/Paris).
     * @param ?string               $nowTime Heure 'HH:MM' à considérer (défaut : heure de $now).
     * @param array<string,mixed>   $opts    horizon (int, 7), goal_weekly (?float),
     *                                        draws (int, Hyper::N_DRAWS), seed (?int),
     *                                        events (array<string,float>), k (float),
     *                                        backtest_days (array<string,DayCurrent>,
     *                                        historique COMPLET pour le backtest à
     *                                        origine glissante — défaut : la fenêtre
     *                                        fw, borne Hyper::BACKTEST_MAX_DAYS),
     *                                        backtest_draws (int, défaut
     *                                        min(draws, Hyper::BACKTEST_DRAWS_MAX)),
     *                                        slow_periods (?array{facteur:float,
     *                                        ranges:list<array{start,end}>} périodes
     *                                        creuses saisies à la main, cf.
     *                                        SlowPeriods).
     *
     * @return array Forme EXACTE décrite dans la doc de classe (consommée par vues/contrôleur).
     */
    public static function forecast(ForecastDataset $data, \DateTimeImmutable $now, ?string $nowTime = null, array $opts = []): array
    {
        // 0. Options et temps de référence.
        $now        = $now->setTimezone(new \DateTimeZone(Hyper::TZ));
        $todayKey   = $now->format('Y-m-d');
        $nowHour    = self::nowHour($now, $nowTime);
        $horizon    = max(1, (int) ($opts['horizon'] ?? 7));
        $goalWeekly = (float) ($opts['goal_weekly'] ?? Hyper::WEEKLY_GOAL_DEFAULT);
        $draws      = max(1, (int) ($opts['draws'] ?? Hyper::N_DRAWS));
        $seed       = isset($opts['seed']) ? (int) $opts['seed'] : null;
        $k          = (float) ($opts['k'] ?? Hyper::K_POOL);
        $events     = [];
        foreach ((array) ($opts['events'] ?? []) as $d => $v) {
            $events[(string) $d] = (float) $v;
        }
        $slow        = is_array($opts['slow_periods'] ?? null) ? $opts['slow_periods'] : null;
        $btDraws     = max(1, (int) ($opts['backtest_draws'] ?? min($draws, Hyper::BACKTEST_DRAWS_MAX)));

        // 1-4. Nettoyage, ajustements, backtest, calibration conforme.
        $split         = Clean::splitPast($data->days, $todayKey);
        $past          = $split['past'];
        $today         = $split['today'];
        $weights       = Clean::atypicalWeights($past);
        $model         = DemandModel::fit($past, $weights, $k);
        $hourly        = HourlyProfile::fit($past, $k);
        // Backtest à origine glissante sur l'HISTORIQUE COMPLET (opts
        // 'backtest_days', tronqué aux BACKTEST_MAX_DAYS derniers jours
        // ouverts) : BACKTEST_START=10 n'est plus consommé par la fenêtre
        // fw d'apprentissage. Le MODÈLE d'apprentissage reste sur fw.
        $hist          = is_array($opts['backtest_days'] ?? null) ? $opts['backtest_days'] : $past;
        if (count($hist) > Hyper::BACKTEST_MAX_DAYS) {
            ksort($hist);
            $hist = array_slice($hist, -Hyper::BACKTEST_MAX_DAYS, null, true);
        }
        $bt            = Backtest::run($hist, ['seed' => $seed, 'draws' => $btDraws, 'k' => $k]);
        $factor        = $bt['insufficient'] ? null : Conformal::widthFactor($bt['scores'], $bt['relative_half_widths']);

        // Période creuse saisie à la main : UN facteur par date (1.0 hors
        // plage) multiplie les prévisions — tirages MC, attendus et
        // intervalles suivent ; l'observé n'est jamais ajusté.
        $facteurCreux = static fn (\DateTimeImmutable $d): float => SlowPeriods::facteurPour($slow, $d);

        $insufficient = $model->isInsufficient() || $model->openDaysCount() < Hyper::INSUFFICIENT_DAYS;
        $dowToday     = (int) $now->format('N') - 1;
        $openWeekdays = $model->openWeekdays;
        $todayOpen    = in_array($dowToday, $openWeekdays, true);
        $modele       = (!$bt['insufficient'] && !$bt['use_main_model']) ? 'baseline_B2' : 'negbin_dow_damped';

        // Loi prédictive du jour en cours (pleine journée), mise en cache par date.
        $cache = [];
        $sampleFor = function (\DateTimeImmutable $d, string $tag) use (&$cache, $model, $seed, $draws, $facteurCreux): array {
            $key = $d->format('Y-m-d');
            if (!isset($cache[$key])) {
                $s = MonteCarlo::daySamples($model, $d, self::rng($seed, $tag . $key), $draws);
                $f = $facteurCreux($d);
                if ($f !== 1.0) {
                    foreach ($s as &$v) {
                        $v *= $f;
                    }
                    unset($v);
                }
                $cache[$key] = $s;
            }

            return $cache[$key];
        };
        $withEvent = static function (array $samples, float $ev): array {
            if ($ev == 0.0) {
                return $samples;
            }
            foreach ($samples as &$v) {
                $v += $ev;
            }
            unset($v);

            return $samples;
        };

        // 5. Horaire du jour en cours + prévision du jour.
        // L'attendu du jour (prévision PLEINE journée) vient du modèle :
        // il ne dépend PAS des données partielles du jour — à 8h sans
        // aucune vente, aujourd'hui est absent du dataset ($today null)
        // et la prévision reste celle des mêmes jours de semaine.
        // Les données partielles ne conditionnent que l'horaire
        // (réel/θ/projection) et le restant (tirages × (1−s_h) × θ,
        // reel = 0 quand le jour est absent).
        $todaySamples = $todayOpen ? $sampleFor($now, 'J') : [];
        $todaySum     = MonteCarlo::summarize($todaySamples);
        $expectedCa   = $model->expectedCa($now) * $facteurCreux($now);

        if ($todayOpen) {
            $cal        = Conformal::apply($factor, $todaySum['mean'], $todaySum['lo'], $todaySum['hi']);
            $prevision  = $todaySum['mean'];
            $intervalle = $cal;
        } else {
            $prevision  = null;
            $intervalle = null;
        }

        $sArr = self::normaliseS($hourly->cumulative($dowToday));
        $attenduCa = [];
        $prevS = 0.0;
        foreach ($sArr as $h => $sh) {
            $attenduCa[$h] = $todayOpen ? $expectedCa * ($sh - $prevS) : 0.0;
            $prevS = $sh;
        }

        $basketToday = ($today !== null && $today->n > 0) ? $today->ca / $today->n : $model->globalP;
        $reelCa = [];
        for ($h = 0; $h < 24; $h++) {
            $reelCa[$h] = ($todayOpen && $today !== null ? (float) ($today->hourlyN[$h] ?? 0.0) : 0.0) * $basketToday;
        }

        if ($todayOpen) {
            $proj     = $hourly->project($model->expectedN($now) * $facteurCreux($now), $dowToday, $today !== null ? $today->hourlyN : [], $nowHour, Hyper::A_PRIOR);
            $theta    = is_numeric($proj['theta'] ?? null) && (float) $proj['theta'] >= 0.0 && is_finite((float) $proj['theta']) ? (float) $proj['theta'] : 1.0;
            $sH       = is_numeric($proj['s_h'] ?? null) && (float) $proj['s_h'] > 0.0 && (float) $proj['s_h'] <= 1.0 ? (float) $proj['s_h'] : ($nowHour + 1) / 24.0;
            $tropTot  = (bool) ($proj['trop_tot'] ?? $sH < Hyper::S_MIN);
        } else {
            $theta   = null;
            $sH      = ($nowHour + 1) / 24.0;
            $tropTot = true;
        }

        $projeteCa = [];
        for ($h = 0; $h < 24; $h++) {
            $projeteCa[$h] = $h <= $nowHour ? $reelCa[$h] : ($theta ?? 0.0) * $attenduCa[$h];
        }
        $reelAH = 0.0;
        for ($h = 0; $h <= $nowHour; $h++) {
            $reelAH += $reelCa[$h];
        }

        $attenduAH = $todayOpen ? $expectedCa * $sH : null;
        $ecart     = ($todayOpen && !$insufficient && $sH >= Hyper::S_MIN) ? $reelAH - (float) $attenduAH : null;
        $dansIntervalle = null;
        if ($todayOpen && !$insufficient && $intervalle !== null) {
            $dansIntervalle = $reelAH >= $intervalle['lo'] * $sH && $reelAH <= $intervalle['hi'] * $sH;
        }
        $projectionFinJournee = $todayOpen ? array_sum($projeteCa) : null;

        // 6. Jours J+1 .. J+horizon (jours calendaires ; fermé → zéros, pas de tirage).
        $jours = [];
        for ($d = 1; $d <= $horizon; $d++) {
            $date = $now->modify('+' . $d . ' days');
            $key  = $date->format('Y-m-d');
            $dow  = (int) $date->format('N') - 1;
            $ev   = $events[$key] ?? 0.0;
            if (!in_array($dow, $openWeekdays, true)) {
                $jours[] = [
                    'date' => $key, 'label' => self::LABELS[$dow] . ' ' . $date->format('d/m'), 'dow' => $dow,
                    'n' => 0.0, 'ca' => 0.0, 'lo' => 0.0, 'hi' => 0.0, 'is_open' => false, 'evenement' => $ev,
                ];
                continue;
            }
            $sum = MonteCarlo::summarize($sampleFor($date, 'J'));
            $cal = Conformal::apply($factor, $sum['mean'], $sum['lo'], $sum['hi']);
            $jours[] = [
                'date' => $key, 'label' => self::LABELS[$dow] . ' ' . $date->format('d/m'), 'dow' => $dow,
                'n' => $model->expectedN($date) * $facteurCreux($date), 'ca' => $sum['mean'] + $ev,
                'lo' => $cal['lo'], 'hi' => $cal['hi'], 'is_open' => true, 'evenement' => $ev,
            ];
        }

        // 7. Hier : réajustement SANS hier (prévision honnête) puis intervalle calibré.
        $hier = null;
        $hierDate = $now->modify('-1 day');
        $hierKey = $hierDate->format('Y-m-d');
        if (isset($data->days[$hierKey])) {
            $hierReel = $data->days[$hierKey]->ca;
            $subsetH = [];
            foreach ($past as $key => $day) {
                if ($key !== $hierKey) {
                    $subsetH[$key] = $day;
                }
            }
            // Dispersion = propriété GLOBALE : le refit sans hier RÉUTILISE
            // le r du modèle principal (sinon son estimateur local des
            // moments s'effondre vers R_MAX quasi-Poisson — dump prod :
            // r principal 18,0 vs refit sans hier 200 → fourchette Hier
            // ±18 % contre ±35 % pour Aujourd'hui).
            $modelH = DemandModel::fit($subsetH, Clean::atypicalWeights($subsetH), $k, $model->dispersionR);
            $sumH   = MonteCarlo::summarize(MonteCarlo::daySamples($modelH, $hierDate, self::rng($seed, 'H' . $hierKey), $draws));
            $calH   = Conformal::apply($factor, $sumH['mean'], $sumH['lo'], $sumH['hi']);
            $refitInsuffisant = $modelH->isInsufficient() || $modelH->openDaysCount() < Hyper::INSUFFICIENT_DAYS;
            $hier = [
                'reel'            => $hierReel,
                'prevu'           => $sumH['mean'],
                'lo'              => $calH['lo'],
                'hi'              => $calH['hi'],
                'dans_intervalle' => ($insufficient || $refitInsuffisant) ? null : ($hierReel >= $calH['lo'] && $hierReel <= $calH['hi']),
            ];
        }

        // 8. Jours de la semaine courante (dowEffects du fit complet) + meilleur jour.
        $occByDow = array_fill(0, 7, 0);
        foreach ($past as $day) {
            $d = new \DateTimeImmutable($day->date);
            $occByDow[(int) $d->format('N') - 1]++;
        }
        $joursSemaine = [];
        $meilleur = null;
        for ($dow = 0; $dow < 7; $dow++) {
            $isOpen = in_array($dow, $openWeekdays, true);
            $st = self::dowStats($model->dowEffects, $dow);
            $n  = $isOpen ? $st['n'] : 0.0;
            $p  = $isOpen ? $st['p'] : 0.0;
            $ca = $n * $p;
            $joursSemaine[] = [
                'dow' => $dow, 'label' => self::LABELS[$dow], 'n' => $n, 'ca' => $ca,
                'occ' => $occByDow[$dow], 'is_open' => $isOpen,
            ];
            if ($isOpen && $occByDow[$dow] >= 1 && ($meilleur === null || $ca > $meilleur['ca'])) {
                $meilleur = ['dow' => $dow, 'label' => self::LABELS[$dow], 'ca' => $ca];
            }
        }

        // 9. Semaine courante (lun–dim) : réel + tirages restants → Goal::week.
        // Choc de niveau commun : un facteur par chemin MC, rng seedé du
        // moteur (flux 'N'), même prior Gamma(8, 1/8) que la mise à jour
        // horaire — cf. docblock de classe.
        $rngNiveau = self::rng($seed, 'N');
        $facteursNiveau = [];
        for ($i = 0; $i < $draws; $i++) {
            $facteursNiveau[] = Stats::gammaSample(Hyper::A_PRIOR, 1.0 / Hyper::A_PRIOR, $rngNiveau);
        }
        $lundiKey = $now->modify('-' . ((int) $now->format('N') - 1) . ' days')->format('Y-m-d');
        $lundi    = new \DateTimeImmutable($lundiKey);
        $reelSemaine = 0.0;
        foreach ($data->days as $key => $day) {
            if ($key >= $lundiKey && $key <= $lundi->modify('+6 days')->format('Y-m-d')) {
                $reelSemaine += $day->ca;
            }
        }
        $restants = [];
        if ($todayOpen) {
            $mult = max(0.0, 1.0 - $sH) * ($theta ?? 1.0);
            $arr = [];
            foreach ($todaySamples as $v) {
                $arr[] = $v * $mult;
            }
            $restants[] = $withEvent($arr, $events[$todayKey] ?? 0.0);
        }
        for ($d = 1; $d <= 6 - ((int) $now->format('N') - 1); $d++) {
            $date = $now->modify('+' . $d . ' days');
            $dow  = (int) $date->format('N') - 1;
            if (!in_array($dow, $openWeekdays, true)) {
                continue;
            }
            $key = $date->format('Y-m-d');
            $restants[] = $withEvent($sampleFor($date, 'J'), $events[$key] ?? 0.0);
        }
        $semaine = Goal::week($restants, $reelSemaine, $goalWeekly, $facteursNiveau);

        // 10. Horizon 30 jours : restant du jour + 29 jours ouverts suivants.
        $restants30 = [];
        if ($todayOpen) {
            $mult = max(0.0, 1.0 - $sH) * ($theta ?? 1.0);
            $arr = [];
            foreach ($todaySamples as $v) {
                $arr[] = $v * $mult;
            }
            $restants30[] = $withEvent($arr, $events[$todayKey] ?? 0.0);
        }
        $count = 0;
        $date  = $now->modify('+1 day');
        for ($guard = 0; $count < 29 && $guard < 400; $guard++) {
            $dow = (int) $date->format('N') - 1;
            if (in_array($dow, $openWeekdays, true)) {
                $key = $date->format('Y-m-d');
                $restants30[] = $withEvent($sampleFor($date, 'J'), $events[$key] ?? 0.0);
                $count++;
            }
            $date = $date->modify('+1 day');
        }
        $mois30 = Goal::horizon($restants30, $today !== null ? $today->ca : 0.0, $facteursNiveau);

        // 11. Produits (UNE source d'unités) puis bénéfice dérivé des
        // MÊMES unités. Les unités brutes (parts Dirichlet × demande) sont
        // CALIBRÉES sur le CA prévu 7 j du moteur (Σ jours) : invariant
        // Σ unites × prix ≈ caPrevu7j — les tableaux Réapprovisionnement
        // et Bénéfice prévu vivent à la même échelle que les prévisions.
        $caFenetre = 0.0;
        foreach ($data->days as $day) {
            $caFenetre += $day->ca;
        }
        $caPrevu7j = 0.0;
        foreach ($jours as $j) {
            $caPrevu7j += $j['ca'];
        }

        $sumQty = 0;
        foreach ($data->products as $p) {
            if ($p instanceof ProductStat) {
                $sumQty += $p->qty;
            }
        }
        $sumN = 0;
        foreach ($past as $day) {
            $sumN += $day->n;
        }
        $upT = $sumN > 0 ? $sumQty / $sumN : 1.0;
        $produits = ProductForecast::units(
            $data->products,
            $model,
            $upT,
            7,
            self::rng($seed, 'P'),
            $draws,
            $caPrevu7j
        );
        $benefice = ProfitForecast::compute($produits, $caFenetre);

        // 12. Anomalies et notes.
        $joursAtypiques = [];
        foreach ($weights as $key => $w) {
            if ($w < 1.0) {
                $joursAtypiques[] = (string) $key;
            }
        }
        $anomalies = [
            'n_jours_inclus'        => $model->openDaysCount(),
            'jours_atypiques'       => $joursAtypiques,
            'ventes_zero'           => (int) ($data->audit['ventes_zero'] ?? 0),
            'doublons_ignores'      => (int) ($data->audit['doublons_ignores'] ?? 0),
            'produits_cout_suspect' => array_values((array) ($data->audit['produits_cout_suspect'] ?? [])),
            'cles_fusionnees'       => array_values((array) ($data->audit['cles_fusionnees'] ?? [])),
            'produits_sans_stock'   => array_values((array) ($data->audit['produits_sans_stock'] ?? [])),
            'n_produits_sans_stock' => (int) ($data->audit['n_produits_sans_stock'] ?? 0),
            'donnees_insuffisantes' => $insufficient,
            'note_selection'        => $bt['selection_note'],
        ];

        $notes = [];
        if ($bt['selection_note'] !== null) {
            $notes[] = $bt['selection_note'];
        }
        if ($insufficient) {
            $notes[] = 'Données insuffisantes (< 7 jours ouverts) : aucun écart en pourcentage n\'est affiché.';
        }
        if (!$todayOpen) {
            $notes[] = 'Fermé aujourd\'hui.';
        }
        if ($bt['insufficient']) {
            $notes[] = 'Backtest indisponible (historique trop court) : modèle principal actif par défaut.';
        }
        $notes[] = $bt['insufficient']
            ? sprintf('Modèle actif : %s.', $modele)
            : sprintf('Modèle actif : %s — WAPE backtest %.1f %%.', $modele, (float) $bt['wape'] * 100.0);
        if ($events !== []) {
            $notes[] = sprintf('%d événement(s) planifié(s) ajouté(s) aux prévisions.', count($events));
        }
        foreach (SlowPeriods::plagesActives($slow, $now, $now->modify('+30 days')) as $plage) {
            $notes[] = sprintf(
                'Période creuse %s–%s : prévisions ajustées ×%s.',
                (new \DateTimeImmutable($plage['start']))->format('d/m'),
                (new \DateTimeImmutable($plage['end']))->format('d/m'),
                SlowPeriods::facteurFr((float) ($slow['facteur'] ?? 1.0))
            );
        }

        // 13. Assemblage final (forme EXACTE du contrat).
        return [
            'prevision_jour'         => $prevision,
            'intervalle_80'          => $intervalle,
            'attendu_a_h'            => $attenduAH,
            'trop_tot'               => $tropTot,
            'projection_fin_journee' => $projectionFinJournee,
            'ecart_vs_attendu'       => $ecart,
            'dans_intervalle'        => $dansIntervalle,
            'confiance'              => $insufficient ? 'insuffisant' : $model->confidenceFor($now),
            'modele'                 => $modele,
            'n_jours'                => $model->openDaysCount(),
            'wape_backtest'          => $bt['wape'] !== null ? $bt['wape'] * 100.0 : null,
            'hier'                   => $hier,
            'jours'                  => $jours,
            'horaire'                => [
                's'          => $sArr,
                'attendu_ca' => $attenduCa,
                'reel_ca'    => $reelCa,
                'projete_ca' => $projeteCa,
                's_h'        => $sH,
                'now_hour'   => $nowHour,
                'trop_tot'   => $tropTot,
                'reel_a_h'   => $reelAH,
                'theta'      => $theta,
            ],
            'jours_semaine'  => $joursSemaine,
            'meilleur_jour'  => $meilleur,
            'semaine'        => $semaine,
            'mois30'         => $mois30,
            'benefice'       => $benefice,
            'produits'       => $produits,
            'anomalies'      => $anomalies,
            'notes'          => $notes,
            'dispersion_r'   => (float) $model->dispersionR,
            'facteur_conforme' => $factor,
            'coverage_backtest' => $bt['coverage'],
        ];
    }

    /**
     * Générateur déterministe : Mt19937 seedé par ($seed ?? 0) combiné
     * au crc32 d'une étiquette (domaine + date) — graine unique par usage.
     */
    private static function rng(?int $seed, string $tag): \Random\Randomizer
    {
        return new \Random\Randomizer(new \Random\Engine\Mt19937(($seed ?? 0) + crc32($tag)));
    }

    /**
     * Heure de référence 0..23 : $nowTime 'HH:MM' si fournie (format
     * valide), sinon heure de $now.
     */
    private static function nowHour(\DateTimeImmutable $now, ?string $nowTime): int
    {
        if ($nowTime !== null && preg_match('/^(\d{1,2}):\d{2}$/', trim($nowTime), $mt) === 1) {
            return min(23, max(0, (int) $mt[1]));
        }

        return (int) $now->format('H');
    }

    /**
     * Profil horaire cumulé S(h) validé : 24 entrées 0→1 non-décroissantes
     * avec S(23) > 0. Profil invalide (dataset vide…) → profil neutre
     * uniforme (h+1)/24, jamais d'exception.
     *
     * @return list<float>
     */
    private static function normaliseS(array $s): array
    {
        $vals = array_values($s);
        if (count($vals) === 24) {
            $ok = true;
            $prev = 0.0;
            foreach ($vals as $v) {
                $f = (float) $v;
                if (!is_finite($f) || $f < $prev - 1.0e-9) {
                    $ok = false;
                    break;
                }
                $prev = $f;
            }
            if ($ok && $prev > 0.0) {
                return array_map(static fn ($v): float => (float) $v, $vals);
            }
        }
        $u = [];
        for ($h = 0; $h < 24; $h++) {
            $u[] = ($h + 1) / 24.0;
        }

        return $u;
    }

    /**
     * Lecture tolérante de dowEffects : forme idéale
     * array<int(dow), array{n:float,p:float,occ:int}> ; clés manquantes → 0.0
     * (les occurrences sont recalculées côté moteur depuis les jours passés).
     *
     * @return array{n:float,p:float}
     */
    private static function dowStats(array $dowEffects, int $dow): array
    {
        $e = $dowEffects[$dow] ?? null;
        if (!is_array($e)) {
            return ['n' => 0.0, 'p' => 0.0];
        }
        $n = $e['n'] ?? ($e['mean_n'] ?? null);
        $p = $e['p'] ?? ($e['mean_p'] ?? null);

        return [
            'n' => is_numeric($n) ? (float) $n : 0.0,
            'p' => is_numeric($p) ? (float) $p : 0.0,
        ];
    }
}
