<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Compta\Forecast\ForecastEngine;
use App\Core\Compta\Forecast\SlowPeriods;

/**
 * DEMANDE DE RÉAPPROVISIONNEMENT — point d'appel UNIQUE du moteur de
 * prévision pour toutes les pages réappro (exigence du 21/10/2026 :
 * « la liste ne fait que besoin(N) − stock, à partir de LA fonction de
 * demande »).
 *
 * Avant : la carte Analytics appelait ForecastEngine (fenêtre fw, période
 * creuse, calibration CA) tandis que la liste de courses recalculait une
 * consommation observée sur 14 j (Sale::consumptionBetween) — deux
 * demandes différentes pour le même produit. Désormais les deux pages
 * consomment CE calcul : dataset AnalyticsForecastData (fenêtre fw jours
 * finissant aujourd'hui, stock théorique via StockBook) → ForecastEngine
 * (horizon 7 j ouverts, objectif hebdo, période creuse, backtest complet).
 *
 * La carte Réappro Analytics affiche forecast.produits tel quel ; la liste
 * de courses décline chaque ligne via BesoinListe (besoin N semaines −
 * stock, arrondi pack). À couverture 7 j, besoin == safety_demand :
 * cohérence liste ↔ réappro PAR CONSTRUCTION.
 *
 * meta() décrit la base affichée sur chaque page (« demande : prévision,
 * période creuse ×0,8 · stock : théorique inventaire au 21/10/2026 »).
 */
final class ReapproDemande extends Model
{
    /** Objectif hebdomadaire par défaut (€/semaine), cf. AdminAnalyticsController::GOAL_DEFAULT. */
    public const GOAL_DEFAUT = 500.0;

    /**
     * Calcule la demande de réappro partagée.
     *
     * @param ?string               $fwRaw      Fenêtre d'apprentissage brute (GET « fw » : 7/14/30 ; null = défaut 30).
     * @param ?\DateTimeImmutable   $now        Instant de référence (null = maintenant, Europe/Paris).
     * @param ?float                $goalWeekly Objectif hebdo (€) ; null = setting analytics_weekly_goal, sinon défaut.
     *
     * @return array{fw:int,now:\DateTimeImmutable,todayKey:string,trackFrom:string,dataset:\App\Core\Compta\Forecast\ForecastDataset,creuse:?array,creuseRaw:string,forecast:array,meta:array<string,mixed>}
     */
    public static function calculer(?string $fwRaw = null, ?\DateTimeImmutable $now = null, ?float $goalWeekly = null): array
    {
        $now = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));

        $fw        = self::fenetre($fwRaw);
        $todayKey  = $now->format('Y-m-d');
        $trackFrom = $now->modify('-' . ($fw - 1) . ' days')->format('Y-m-d');

        $dataset = AnalyticsForecastData::dataset($trackFrom, $todayKey);

        // Période creuse saisie à la main (setting) ; neutre si absente,
        // null si invalide (sans ajustement) — cf. SlowPeriods::parse.
        $creuseRaw = trim((string) Setting::get('analytics_periode_creuse', ''));
        $creuse    = SlowPeriods::parse($creuseRaw);

        // Backtest sur l'historique complet (première vente → hier).
        $histDays = AnalyticsForecastData::currentFlowDays(AnalyticsForecastData::firstSaleDate(), $todayKey);

        $goal = $goalWeekly;
        if ($goal === null || $goal <= 0.0) {
            $goal = (float) Setting::get('analytics_weekly_goal', (string) self::GOAL_DEFAUT);
        }
        if ($goal <= 0.0) {
            $goal = self::GOAL_DEFAUT;
        }

        $forecast = ForecastEngine::forecast($dataset, $now, $now->format('H:i'), [
            'horizon'       => 7,
            'goal_weekly'   => $goal,
            'backtest_days' => $histDays,
            'slow_periods'  => $creuse,
        ]);

        return [
            'fw'        => $fw,
            'now'       => $now,
            'todayKey'  => $todayKey,
            'trackFrom' => $trackFrom,
            'dataset'   => $dataset,
            'creuse'    => $creuse,
            'creuseRaw' => $creuseRaw,
            'forecast'  => $forecast,
            'meta'      => self::meta($fw, $creuse, $now, $forecast),
        ];
    }

    /**
     * Base de calcul affichée sur les pages réappro (bandeau « demande /
     * stock / date ») : une seule vérité, énoncée.
     *
     * @param ?array<string,mixed> $creuse
     *
     * @return array{demande:string,fenetre:string,creuse:?string,stock:string,date_stock:?string}
     */
    public static function meta(int $fw, ?array $creuse, \DateTimeImmutable $now, array $forecast): array
    {
        $creuseActive = null;
        if ($creuse !== null && SlowPeriods::plagesActives($creuse, $now, $now->modify('+7 days')) !== []) {
            $creuseActive = '×' . SlowPeriods::facteurFr((float) ($creuse['facteur'] ?? 1.0));
        }

        return [
            'demande'    => 'prévision du moteur (horizon 7 jours ouverts)',
            'fenetre'    => sprintf('apprentissage %d j', $fw),
            'creuse'     => $creuseActive,
            'stock'      => 'théorique inventaire (dernier comptage + achats − ventes − pertes)',
            'date_stock' => AnalyticsForecastData::stockBook()->derniereDate(),
        ];
    }

    /**
     * Fenêtre d'apprentissage : 7/14/30, défaut 30. SOURCE UNIQUE de la
     * règle — AdminAnalyticsController::fenetreApprentissage délègue ici
     * (testée via ce point d'entrée), la liste de courses l'utilise aussi.
     */
    public static function fenetre(?string $fwRaw): int
    {
        $raw = strtolower(trim((string) $fwRaw));

        return in_array($raw, ['7', '14', '30'], true) ? (int) $raw : 30;
    }
}
