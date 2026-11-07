<?php

declare(strict_types=1);

namespace App\Core\Compta\Dashboard;

use App\Core\Compta\CardFees;
use App\Core\Compta\ComptaCalc;
use App\Core\Compta\Edt\CalendarContext;
use App\Models\Analytics;
use App\Models\Budget;
use App\Models\CashCount;
use App\Models\Expense;
use App\Models\InventoryCount;
use App\Models\InventoryTablet;
use App\Models\ProductCost;
use App\Models\ProductDiscontinued;
use App\Models\ProductInfinite;
use App\Models\ProductPack;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Setting;

/**
 * Point d'assemblage UNIQUE du payload du dashboard compta (règle
 * AGENTS.md « une donnée utilisée par plusieurs pages vient d'un seul
 * point d'assemblage ») : la page /admin/compta ET l'accueil /admin
 * (variante compta) lisent tous deux Data::construire() — aucun des deux
 * ne recalcule verdict, cartes, bloc « À faire » ou sections.
 *
 * Le contrat de la vue compta est INCHANGÉ : les clés renvoyées sont
 * exactement celles que AdminComptaController::dashboard() passait déjà à
 * sa vue. Aucun calcul n'a lieu dans les vues : elles lisent la sortie des
 * moteurs (Verdict, Cartes, Afaire) et les agrégats assemblés ici.
 *
 * @return array{
 *     period:array{preset:string,from:?string,to:?string},
 *     agg:array<string,mixed>,
 *     split:array<string,float>,
 *     txCount:int,
 *     top:list<array<string,mixed>>,
 *     byCategory:list<array<string,mixed>>,
 *     cardFee:float,
 *     cardNet:float,
 *     feeRate:string,
 *     reorderAlerts:int,
 *     margin:float,
 *     expenseTtc:float,
 *     expenseCount:int,
 *     netResult:float,
 *     noCostCa:float,
 *     vat:list<array<string,mixed>>,
 *     vatTotal:float,
 *     lossLeaders:list<array<string,mixed>>,
 *     invGaps:list<array<string,mixed>>,
 *     lastImport:?string,
 *     daysSinceImport:?int,
 *     verdict:array{phrase:string,code:string,objectif_phrase:?string},
 *     cartes:list<array<string,mixed>>,
 *     afaire:list<array<string,mixed>>,
 *     periode_complete:bool,
 *     precedent_complet:bool,
 *     prev_from:?string,
 *     prev_to:?string,
 *     objectif:?float,
 *     splitTotal:float,
 *     cardPct:int
 * }
 */
final class Data
{
    /**
     * Assemble le payload complet du dashboard pour la fenêtre demandée
     * (même sémantique GET que la page : period/from/to — null = défaut
     * « semaine en cours »).
     *
     * @param string|null $periodPreset Paramètre GET « period ».
     * @param string|null $from         Paramètre GET « from ».
     * @param string|null $to           Paramètre GET « to ».
     */
    public static function construire(?string $periodPreset = null, ?string $from = null, ?string $to = null): array
    {
        $period = ComptaCalc::resolvePeriod($periodPreset, $from, $to);
        $agg = Sale::aggregatesBetween($period['from'], $period['to']);
        $split = Sale::paymentSplitBetween($period['from'], $period['to']);
        $txCount = Sale::transactionsBetween($period['from'], $period['to']);
        $top = Sale::topProductsBetween($period['from'], $period['to']);
        $byCategory = Sale::byCategoryBetween($period['from'], $period['to']);
        $cardFee = CardFees::estimatedTotal($period['from'], $period['to']);
        $cardNet = round((float) ($split['CARTE'] ?? 0) - $cardFee, 2);

        // Alerte stock faible : analyse des 30 derniers jours.
        // (L'horizon de couverture n'influence pas les alertes, seul
        // compte le rythme de consommation journalier.)
        $refFrom = date('Y-m-d', strtotime('-29 days'));
        $reorder = self::analyseReappro(
            5,
            $refFrom,
            date('Y-m-d'),
            ComptaCalc::calendarDaysBetween($refFrom, date('Y-m-d'))
        );
        $reorderAlerts = $reorder['alerts'];

        // Suivi avancé : dépenses, résultat net, fiabilité des coûts, alertes.
        $expenseAgg = Expense::aggregatesBetween($period['from'], $period['to']);
        $coverage = Sale::costCoverageBetween($period['from'], $period['to']);
        $vat = Sale::vatByRateBetween($period['from'], $period['to']);
        $lossLeaders = Sale::lossLeadersBetween($period['from'], $period['to']);
        $invGaps = InventoryCount::recentGaps(30);
        $invPending = InventoryTablet::pendingSessions();
        $lastImport = Sale::lastImportAt();

        $vatTotal = 0.0;
        foreach ($vat as $v) {
            $vatTotal += (float) ($v['vat'] ?? 0);
        }

        $daysSinceImport = $lastImport !== null
            ? (new \DateTimeImmutable($lastImport))->diff(new \DateTimeImmutable('now'))->days
            : null;

        // ── Complétude de la période (règle de mission, documentée) ─────
        // $periodeComplete = la fenêtre sélectionnée est entièrement passée
        // (to < aujourd'hui) ET les données sont plausiblement à jour dessus
        // (un import SumUp existe ET couvre le début de la fenêtre). Sans
        // import du tout → incomplète : aucun % ni verdict définitif.
        // Les presets « 1d / 7d / 30d » (fin = aujourd'hui) et « all »
        // (bornes ouvertes) sont donc toujours incomplets.
        $today = date('Y-m-d');
        $periodeComplete = $period['from'] !== null
            && $period['to'] !== null
            && $period['to'] < $today
            && $lastImport !== null
            && $lastImport >= $period['from'];

        // ── Fenêtre PRÉCÉDENTE : même longueur, finissant J-1 avant $from
        //      (même calcul qu'Analytics::kpisPrevious — pattern maison).
        //      « all » (bornes null) n'a pas de précédent comparable.
        $prevFrom = null;
        $prevTo = null;
        if ($period['from'] !== null && $period['to'] !== null) {
            $days = ComptaCalc::calendarDaysBetween($period['from'], $period['to']);
            $prevTo = (new \DateTimeImmutable($period['from']))->modify('-1 day')->format('Y-m-d');
            $prevFrom = (new \DateTimeImmutable($period['from']))->modify('-' . $days . ' days')->format('Y-m-d');
        }

        // Fiable (pattern analytics : AdminAnalyticsController::prevComplete)
        // si la fenêtre précédente est entièrement passée ET commence après
        // la première vente importée (pas de comparaison sur du vide).
        $precedentComplet = $prevFrom !== null
            && $prevTo !== null
            && $prevTo < $today
            && $prevFrom >= Analytics::firstSaleDate();

        $prevAgg = null;
        $prevExpenses = null;
        if ($precedentComplet) {
            $prevAgg = Sale::aggregatesBetween($prevFrom, $prevTo);
            $prevExpenses = Expense::aggregatesBetween($prevFrom, $prevTo);
        }

        // ── Objectif de CA (période complète seulement) : pro-rata de
        //      l'objectif HEBDOMADAIRE existant (source unique, setting
        //      « analytics_weekly_goal ») sur les jours calendaires de la
        //      fenêtre : objectif = hebdo × nbJours / 7.
        $objectif = null;
        if ($periodeComplete) {
            $weeklyGoal = (float) Setting::get('analytics_weekly_goal', '500');
            $days = ComptaCalc::calendarDaysBetween((string) $period['from'], (string) $period['to']);
            $objectif = round($weeklyGoal * $days / 7.0, 2);
        }

        // ── Cœur « Niveau 1 » (classes pures) : verdict, cartes, à faire.
        //      Les surcharges de seuils viennent des Paramètres (clés
        //      « dashboard_* », préfixe attendu par Hyper::valeur) ; valeur
        //      vide → constante Hyper (jamais 0, qui alerterait à tort).
        $parametres = [
            'dashboard_baisse_attention_pct' => self::seuilSetting('dashboard_baisse_attention_pct', Hyper::BAISSE_ATTENTION_PCT),
            'dashboard_baisse_alerte_pct'    => self::seuilSetting('dashboard_baisse_alerte_pct', Hyper::BAISSE_ALERTE_PCT),
        ];

        $actuel = [
            'ca'       => (float) $agg['ca'],
            'profit'   => (float) $agg['profit'],
            'qty'      => (float) $agg['qty'],
            'expenses' => (float) $expenseAgg['ttc'],
        ];
        $precedent = $precedentComplet ? [
            'ca'       => (float) $prevAgg['ca'],
            'profit'   => (float) $prevAgg['profit'],
            'qty'      => (float) $prevAgg['qty'],
            'expenses' => (float) $prevExpenses['ttc'],
        ] : null;

        $verdict = Verdict::construire(
            $actuel['profit'] - $actuel['expenses'],
            $precedent !== null ? $precedent['profit'] - $precedent['expenses'] : null,
            $precedentComplet,
            $periodeComplete,
            $actuel['ca'],
            $objectif,
            $parametres
        );
        $cartes = Cartes::construire($actuel, $precedent, $precedentComplet, $periodeComplete, $parametres);

        // ── Bloc « À faire » : items construits ici, triés par Afaire. ──
        $afaireItems = [];

        // 1. Ruptures réappro (analyseReappro()) : produits en état
        //    « reorder », dont ceux déjà à zéro (plus urgents).
        $nbRupture = 0;
        $nbRuptureZero = 0;
        foreach ($reorder['rows'] as $row) {
            if (($row['state'] ?? '') === 'reorder') {
                $nbRupture++;
                if ((int) ($row['stock'] ?? 0) <= 0) {
                    $nbRuptureZero++;
                }
            }
        }
        if ($nbRupture > 0) {
            $afaireItems[] = [
                'cle'     => 'rupture',
                'urgence' => $nbRuptureZero > 0 ? 3 : 2,
                'texte'   => $nbRuptureZero > 0
                    ? sprintf('%d produits en rupture (dont %d à zéro)', $nbRupture, $nbRuptureZero)
                    : sprintf('%d produits en rupture', $nbRupture),
                'lien'    => url('/admin/compta/liste'),
            ];
        }

        // 2. Inventaire : écarts des 30 derniers jours + sessions tablette
        //    en attente de correction.
        $nbGaps = count($invGaps);
        $nbPending = count($invPending);
        if ($nbGaps + $nbPending > 0) {
            $afaireItems[] = [
                'cle'     => 'inventaire',
                'urgence' => 2,
                'texte'   => $nbPending > 0
                    ? sprintf("%d écarts d'inventaire sur 30 jours (dont %d sessions tablette en attente)", $nbGaps, $nbPending)
                    : sprintf("%d écarts d'inventaire sur 30 jours", $nbGaps),
                'lien'    => url('/admin/compta/inventaire'),
            ];
        }

        // 3. Coûts manquants : ventes sans lot de coût connu (% du CA
        //    seulement si la période est complète).
        $uncovered = (float) ($coverage['uncovered'] ?? 0);
        if ($uncovered > 0.0) {
            $caProducts = (float) $agg['ca_products'];
            $pct = ($periodeComplete && $caProducts > 0.0)
                ? sprintf(' (%s %% du CA)', number_format($uncovered / $caProducts * 100, 1, ',', ' '))
                : '';
            $afaireItems[] = [
                'cle'     => 'couts',
                'urgence' => 2,
                'texte'   => formatPrice($uncovered) . ' de ventes sans coût connu' . $pct,
                'lien'    => url('/admin/compta/couts'),
            ];
        }

        // 4. Import SumUp : absent → urgent ; ≥ 14 j → urgent ; ≥ 7 j →
        //    à traiter ; < 7 j → rien à signaler.
        if ($daysSinceImport === null) {
            $afaireItems[] = [
                'cle'     => 'import',
                'urgence' => 3,
                'texte'   => 'Aucun import SumUp connu',
                'lien'    => url('/admin/compta/import'),
            ];
        } elseif ($daysSinceImport >= 14) {
            $afaireItems[] = [
                'cle'     => 'import',
                'urgence' => 3,
                'texte'   => sprintf('Dernier import SumUp il y a %d jours', $daysSinceImport),
                'lien'    => url('/admin/compta/import'),
            ];
        } elseif ($daysSinceImport >= 7) {
            $afaireItems[] = [
                'cle'     => 'import',
                'urgence' => 2,
                'texte'   => sprintf('Dernier import SumUp il y a %d jours', $daysSinceImport),
                'lien'    => url('/admin/compta/import'),
            ];
        }

        // 5. Caisse : liquide encaissé depuis le dernier comptage — au-delà
        //    de 0,50 €, un comptage est attendu (urgence maximale).
        $lastCountAt = CashCount::lastCountAt();
        $liquide = Sale::sumLiquidSince($lastCountAt);
        if ($liquide > 0.5) {
            $depuis = $lastCountAt !== null
                ? 'depuis le dernier comptage (' . formatDate($lastCountAt) . ')'
                : 'depuis toujours';
            $afaireItems[] = [
                'cle'     => 'caisse',
                'urgence' => 3,
                'texte'   => 'Caisse non comptée : ' . formatPrice($liquide) . ' de liquide ' . $depuis,
                'lien'    => url('/admin/compta/caisse'),
            ];
        }

        // 6. Budget du mois courant : aucune ligne saisie (rappel mensuel —
        //    sans lui, le budget reste invisible et inutilisé) ou enveloppe
        //    de dépenses déjà consommée à ≥ 85 %. Décision pure dans
        //    Afaire::itemBudget ; réalisé = MÊMES sources que la page
        //    Budgets (dépenses du mois + achats de stock comptés dans
        //    MATIERE), du 1ᵉʳ du mois à aujourd'hui.
        $curY = (int) date('Y');
        $curM = (int) date('n');
        $curMonthStart = sprintf('%04d-%02d-01', $curY, $curM);
        $realiseBudget = [];
        foreach (Expense::byCategoryBetween($curMonthStart, $today) as $c) {
            $realiseBudget[(string) $c['category']] = (float) $c['ttc'];
        }
        $realiseBudget['MATIERE'] = ($realiseBudget['MATIERE'] ?? 0.0)
            + (float) Purchase::statsBetween($curMonthStart, $today)['ttc'];
        $itemBudget = Afaire::itemBudget(Budget::forMonth($curY, $curM), $realiseBudget);
        if ($itemBudget !== null) {
            $itemBudget['lien'] = url('/admin/compta/budgets?month=' . sprintf('%04d-%02d', $curY, $curM));
            $afaireItems[] = $itemBudget;
        }

        $afaire = Afaire::trier($afaireItems, $parametres);

        // ── Split paiements (lot 4B : calculs sortis du partiel) ────────
        // Total CARTE+LIQUIDE et part de la carte en % (arrondie, 0 si
        // aucun encaissement) : lus par compta_section_paiements (dashboard
        // et accueil admin — même payload, clés simplement ignorées là où
        // elles ne servent pas).
        $splitTotal = round((float) ($split['CARTE'] ?? 0) + (float) ($split['LIQUIDE'] ?? 0), 2);
        $cardPct = $splitTotal > 0.0 ? (int) round((float) ($split['CARTE'] ?? 0) / $splitTotal * 100) : 0;

        return [
            'period'       => $period,
            'agg'          => $agg,
            'split'        => $split,
            'txCount'      => $txCount,
            'top'          => $top,
            'byCategory'   => $byCategory,
            'cardFee'      => $cardFee,
            'cardNet'      => $cardNet,
            'feeRate'      => CardFees::formattedRate(),
            'reorderAlerts'=> $reorderAlerts,
            'margin'       => ComptaCalc::marginPercent($agg['profit'], $agg['ca_products']),
            'expenseTtc'   => $expenseAgg['ttc'],
            'expenseCount' => (int) ($expenseAgg['count'] ?? 0),
            'netResult'    => $agg['profit'] - $expenseAgg['ttc'],
            'noCostCa'     => $coverage['uncovered'],
            'vat'          => $vat,
            'vatTotal'     => $vatTotal,
            'lossLeaders'  => $lossLeaders,
            'invGaps'      => array_slice($invGaps, 0, 5),
            'lastImport'   => $lastImport,
            'daysSinceImport' => $daysSinceImport,
            // Niveau 1 (refonte 3 niveaux).
            'verdict'         => $verdict,
            'cartes'          => $cartes,
            'afaire'          => $afaire,
            'periode_complete'=> $periodeComplete,
            'precedent_complet'=> $precedentComplet,
            'prev_from'       => $prevFrom,
            'prev_to'         => $prevTo,
            'objectif'        => $objectif,
            // Split paiements (lot 4B).
            'splitTotal'      => $splitTotal,
            'cardPct'         => $cardPct,
        ];
    }

    /**
     * Seuil du dashboard lu en base (clé « dashboard_* ») : valeur vide ou
     * absente → constante Hyper (jamais 0, qui alerterait à tort).
     */
    private static function seuilSetting(string $key, float $defaut): float
    {
        $raw = trim((string) Setting::get($key, (string) $defaut));

        return $raw !== '' ? (float) $raw : $defaut;
    }

    /**
     * Analyse de réapprovisionnement sur une période donnée (source UNIQUE
     * partagée par les pages Réappro / Liste de courses / kiosques et par
     * le bloc « À faire » du dashboard — anciennement
     * AdminComptaController::reorderData(), déplacé sans changement).
     *
     * Chaque produit vendu dans la période d'analyse est listé avec sa
     * consommation moyenne (rapportée aux jours CALENDaires de la période,
     * week-end inclus — rythme 7 j/7) par jour / semaine / mois, et la
     * quantité à commander pour couvrir l'horizon cible (lui aussi en
     * jours calendaires). L'autonomie est donc le nombre de jours avant
     * rupture WEEK-END COMPRIS. Le stock est le THÉORIQUE de l'inventaire
     * (dernier comptage + achats − ventes − pertes) : jamais une saisie
     * manuelle qui se périme. Un achat ou une perte établit une base 0 :
     * le théorique existe même sans comptage préalable ; seuls les
     * produits sans aucune donnée (ni comptage, ni achat, ni perte)
     * apparaissent « à compter », besoin calculé sans stock déduit. Les
     * produits marqués « plus en vente » sont exclus (voir
     * ProductDiscontinued).
     *
     * FACTEUR CALENDRIER UNIVERITAIRE (mission E2) : l'horizon de couverture
     * couvre des semaines qui peuvent être sans cours — les quantités
     * suggérées (need, to_order, to_order_raw) sont alors modulées par
     * CalendarContext::facteurReappro (semaine 100 % morte → 0, semaine
     * partielle → prorata jours actifs/5, hors horizon publié → NEUTRE
     * 1.0 : on ne réduit jamais sur des données absentes). Le facteur
     * n'agit QUE sur ces quantités : les moyennes observées
     * (avg_day/week/month), l'autonomie et les COÛTS unitaires sont
     * inchangés (order_cost suit mécaniquement qté × coût inchangé). Un
     * horizon 100 % mort (facteur 0) retire AUSSI l'état « reorder » et
     * l'alerte associée : commander pendant une semaine morte est du
     * bruit — la semaine de reprise re-déclenchera l'alerte sur son
     * propre horizon.
     *
     * @param int                $targetDays Horizon de couverture, en jours calendaires.
     * @param array<string,mixed>|null $calendrier Facteur calendrier PRÉCALCULÉ
     *                                        ({facteur:float, explication:?string}, sortie
     *                                        CalendarContext::facteurReappro) — null = calculé
     *                                        ici (production). L'injection sert aux tests et
     *                                        à la trace avant/après chiffrée.
     *
     * @return array{rows:list<array<string,mixed>>, alerts:int, calendrier:array{facteur:float, explication:?string}}
     */
    public static function analyseReappro(int $targetDays, ?string $fromDay, ?string $toDay, int $calDays, ?array $calendrier = null): array
    {
        // Facteur calendrier universitaire de l'horizon de couverture
        // (injectable pour tests/trace ; panne EDT → 1.0 silencieux) :
        // module UNIQUEMENT les quantités suggérées — cf. docblock.
        if ($calendrier === null) {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
            $calendrier = CalendarContext::facteurReappro($now, max(1, $targetDays));
        }
        $fCal = min(1.0, max(0.0, (float) ($calendrier['facteur'] ?? 1.0)));

        // 100 % basé sur les ventes SumUp : chaque produit du CSV est listé,
        // sa catégorie vient du CSV, et son stock est le théorique issu de
        // l'inventaire (même source que la page Inventaire).
        $consumption = Sale::consumptionBetween($fromDay, $toDay);
        $calDays = max(1, $calDays);

        // Produits marqués « plus en vente » (saisonniers/discontinués) :
        // exclus du réappro pour ne pas encombrer l'analyse des commandes.
        // Clés normalisées : insensible à la casse/espaces, comme le lookup stock.
        $discontinued = array_flip(array_map(
            static fn(string $k): string => strtolower(trim($k)),
            ProductDiscontinued::keys()
        ));

        // Produits marqués « stock infini » (jamais réapprovisionnés) :
        // autonomie ∞, jamais proposés à la commande, même « à compter ».
        $infinite = array_flip(array_map(
            static fn(string $k): string => strtolower(trim($k)),
            ProductInfinite::keys()
        ));

        // Packs d'achat (12, 24, 32…) : « À commander » est arrondi au
        // multiple supérieur du pack — on achète par pack, pas à l'unité.
        $packs = ProductPack::sizesMap();

        // Stock théorique + dernier comptage, indexés en minuscules pour un
        // rapprochement insensible à la casse (ex. « Red bull » == « Red Bull »).
        $theoreticalLower = [];
        foreach (InventoryCount::theoreticalStocksMap() as $k => $v) {
            $theoreticalLower[strtolower(trim((string) $k))] = (int) $v;
        }
        $lastCountsLower = [];
        foreach (InventoryCount::lastCountsMap() as $k => $c) {
            $lastCountsLower[strtolower(trim((string) $k))] = $c;
        }

        $rows = [];
        $alerts = 0;

        foreach ($consumption as $key => $data) {
            $key = (string) $key;
            if ($key === '' || isset($discontinued[strtolower(trim($key))])) {
                continue;
            }

            $qty      = (int) ($data['qty'] ?? 0);
            // Rythme 7 j/7 : les ventes du week-end sont incluses dans la
            // quantité ET dans le nombre de jours — week-end couvert.
            $avgDay   = $qty / $calDays;
            $avgWeek  = $avgDay * 7.0;
            $avgMonth = $avgDay * 30.44;

            $lookupKey = strtolower(trim($key));
            $hasStock = array_key_exists($lookupKey, $theoreticalLower);
            $stock    = $hasStock ? $theoreticalLower[$lookupKey] : null;
            $countedAt = $lastCountsLower[$lookupKey]['at'] ?? null;

            // Autonomie : jours CALENDaires avant rupture, week-end inclus
            // (arrondie à la baisse ; un stock négatif signifie rupture
            // déjà atteinte).
            $autonomy = ($stock !== null && $avgDay > 0)
                ? max(0, (int) floor($stock / $avgDay))
                : null;

            // Besoin de l'horizon MODULÉ par le calendrier universitaire
            // (facteur 0 = semaine morte : besoin nul) ; un horizon 100 %
            // mort n'ordonne RIEN, pas même la reconstitution d'un stock
            // négatif — la semaine de reprise le couvrira sur son propre
            // horizon. Les moyennes observées et l'autonomie (faits)
            // restent à l'échelle pleine.
            $need   = (int) ceil($avgDay * $targetDays * $fCal);
            $toOrderRaw = $fCal <= 0.0 ? 0 : max(0, $need - ($stock ?? 0));

            // Pack d'achat : arrondi au multiple supérieur (besoin brut 2,
            // pack de 12 → commander 12). 0/absent = commande à l'unité.
            $packSize = $packs[$lookupKey] ?? 0;
            if ($packSize > 1 && $toOrderRaw > 0) {
                $toOrder = (int) ceil($toOrderRaw / $packSize) * $packSize;
            } else {
                $toOrder = $toOrderRaw;
            }

            // Stock déclaré infini : autonomie ∞ et jamais à commander,
            // même si le produit n'a jamais été compté (« à compter »).
            $infiniteStock = isset($infinite[$lookupKey]);
            if ($infiniteStock) {
                $autonomy = null;
                $toOrder  = 0;
            }

            // Coût unitaire actuel (lot en cours à aujourd'hui) et coût
            // estimé de la ligne (qté à commander × coût unitaire).
            $unitCostRaw = ProductCost::costAt($key, date('Y-m-d'));
            $unitCost = $unitCostRaw !== null ? (float) $unitCostRaw : null;
            $orderCost = $unitCost !== null ? round($toOrder * $unitCost, 2) : null;

            if ($infiniteStock) {
                $state = 'ok';
            } elseif ($stock === null) {
                $state = 'unknown';
            } elseif ($fCal <= 0.0) {
                // Horizon de couverture 100 % mort : aucune suggestion,
                // aucune alerte (cf. docblock — le bruit de vacances n'a
                // pas à réclamer une commande).
                $state = 'ok';
            } elseif ($stock <= 0 || ($autonomy !== null && $autonomy < 7)) {
                $state = 'reorder';
            } else {
                $state = 'ok';
            }
            $isAlert = ($state === 'reorder');
            if ($isAlert) {
                $alerts++;
            }

            $rows[] = [
                'name'       => $key,
                'category'   => (string) ($data['category'] ?? '—'),
                'qty'        => $qty,
                'stock'      => $stock,
                'counted_at' => $countedAt,
                'avg_day'    => $avgDay,
                'avg_week'   => $avgWeek,
                'avg_month'  => $avgMonth,
                'unit_cost'  => $unitCost,
                'order_cost' => $orderCost,
                'autonomy'   => $autonomy,
                'need'       => $need,
                'to_order'   => $toOrder,
                'to_order_raw' => $toOrderRaw,
                'pack'       => $packSize,
                'state'      => $state,
                'is_alert'   => $isAlert,
                'infinite'   => $infiniteStock,
            ];
        }

        // Tri : quantité à commander décroissante (les plus urgents d'abord).
        usort($rows, static function (array $a, array $b): int {
            if ($b['to_order'] !== $a['to_order']) {
                return $b['to_order'] <=> $a['to_order'];
            }
            $aa = $a['autonomy'] ?? PHP_INT_MAX;
            $bb = $b['autonomy'] ?? PHP_INT_MAX;

            return $aa <=> $bb;
        });

        return [
            'rows'       => $rows,
            'alerts'     => $alerts,
            'calendrier' => ['facteur' => $fCal, 'explication' => $calendrier['explication'] ?? null],
        ];
    }
}
