<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Compta\Forecast\ForecastEngine;
use App\Core\Compta\Forecast\SlowPeriods;
use App\Core\Compta\Reco\Basket;
use App\Core\Compta\Reco\RecoDataset;
use App\Core\Compta\Reco\RecoProduct;

/**
 * Couche d'EXTRACTION de données pour le moteur de recommandations
 * commerciales (consomme les DTO de App\Core\Compta\Reco).
 *
 * FLUX COURANT = ventes « normales » d'une journée d'ouverture, même
 * définition qu'AnalyticsForecastData (is_custom_amount = 0, hors
 * « Événement », price_ttc > 0) restreinte AUX PRODUITS CLÉS connus
 * (product_key IS NOT NULL) : une reco s'applique toujours à un produit
 * identifiable, jamais à une ligne libre.
 *
 * AUCUN second modèle de demande : la prévision 7 j par produit vient
 * du SEUL ForecastEngine (même fenêtre que le dashboard Analytics),
 * consommation identique à buildPage. Toutes les lectures sont PDO
 * préparé, try/catch tolérantes ([] / 0 en cas de panne ou de table
 * absente), style AnalyticsForecastData.
 */
final class RecoData extends Model
{
    protected static string $table = 'sales';

    /**
     * Fragment WHERE du flux courant (fenêtre temporelle en plus) :
     * même définition qu'AnalyticsForecastData, restreinte aux produits
     * clés connus (product_key IS NOT NULL).
     */
    private const FLOW_WHERE = "is_custom_amount = 0 AND COALESCE(category, '') NOT IN ('Événement') AND price_ttc > 0 AND product_key IS NOT NULL";

    /**
     * Paniers anonymisés de la fenêtre : un panier = (date, transaction_ref),
     * lignes agrégées par produit. Sert aux règles de structuration
     * (associations R6, panier moyen R8) via RecoContext.
     *
     * @return list<Basket>
     */
    public static function paniers(string $from, string $to): array
    {
        $sql = 'SELECT DATE(sold_at) AS d, transaction_ref, product_key,
                       COALESCE(SUM(price_ttc), 0) AS m,
                       COALESCE(SUM(quantity), 0) AS q
                FROM sales
                WHERE ' . self::FLOW_WHERE . '
                  AND DATE(sold_at) >= ? AND DATE(sold_at) <= ?
                GROUP BY d, transaction_ref, product_key
                ORDER BY d ASC, transaction_ref ASC';

        try {
            $stmt = self::pdo()->prepare($sql);
            $stmt->execute([$from, $to]);
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        // Assemblage AVANT instanciation : les DTO sont readonly.
        $parPanier = [];
        foreach ($rows as $r) {
            $d   = (string) ($r['d'] ?? '');
            $ref = (string) ($r['transaction_ref'] ?? '');
            if ($d === '' || $ref === '' || (string) ($r['product_key'] ?? '') === '') {
                continue;
            }
            $parPanier[$d . '|' . $ref][] = [
                'date'   => $d,
                'key'     => (string) $r['product_key'],
                'montant' => (float) ($r['m'] ?? 0),
                'qty'     => (int) ($r['q'] ?? 0),
            ];
        }

        $out = [];
        foreach ($parPanier as $lignes) {
            $out[] = new Basket(
                date: (string) $lignes[0]['date'],
                lignes: array_map(
                    static fn (array $l): array => ['key' => $l['key'], 'montant' => $l['montant'], 'qty' => $l['qty']],
                    $lignes
                ),
            );
        }

        return $out;
    }

    /**
     * Quantités vendues par produit sur la fenêtre (SUM(quantity)).
     *
     * @return array<string,float>
     */
    public static function qtyParProduit(string $from, string $to): array
    {
        try {
            $stmt = self::pdo()->prepare(
                'SELECT product_key, COALESCE(SUM(quantity), 0) AS q
                 FROM sales
                 WHERE ' . self::FLOW_WHERE . '
                   AND DATE(sold_at) >= ? AND DATE(sold_at) <= ?
                 GROUP BY product_key'
            );
            $stmt->execute([$from, $to]);
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $key = (string) ($r['product_key'] ?? '');
            if ($key !== '') {
                $out[$key] = (float) ($r['q'] ?? 0);
            }
        }

        return $out;
    }

    /**
     * CA réel par jour OUVERT pour les produits donnés (mesure d'effet).
     * Les jours sans vente du flux courant sont simplement absents.
     *
     * @param list<string> $keys Clés produits de la reco appliquée.
     * @return array<string,float> Date 'Y-m-d' => CA (€).
     */
    public static function ventesParJour(array $keys, string $from, string $to): array
    {
        $keys = array_values(array_filter($keys, static fn ($k): bool => is_string($k) && $k !== ''));
        if ($keys === []) {
            return [];
        }

        $ph = implode(', ', array_fill(0, count($keys), '?'));
        try {
            $stmt = self::pdo()->prepare(
                'SELECT DATE(sold_at) AS d, COALESCE(SUM(price_ttc), 0) AS ca
                 FROM sales
                 WHERE ' . self::FLOW_WHERE . '
                   AND product_key IN (' . $ph . ')
                   AND DATE(sold_at) >= ? AND DATE(sold_at) <= ?
                 GROUP BY d
                 ORDER BY d ASC'
            );
            $stmt->execute(array_merge($keys, [$from, $to]));
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $d = (string) ($r['d'] ?? '');
            if ($d !== '') {
                $out[$d] = (float) ($r['ca'] ?? 0);
            }
        }

        return $out;
    }

    /**
     * Nombre de jours OUVERTS (avec au moins une vente du flux courant)
     * dans la fenêtre — seuil d'historique suffisant du moteur.
     */
    public static function nbJoursOuverts(string $from, string $to): int
    {
        try {
            $stmt = self::pdo()->prepare(
                'SELECT COUNT(DISTINCT DATE(sold_at))
                 FROM sales
                 WHERE ' . self::FLOW_WHERE . '
                   AND DATE(sold_at) >= ? AND DATE(sold_at) <= ?'
            );
            $stmt->execute([$from, $to]);

            return (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Orchestration complète : assemble le RecoDataset du moteur de
     * recommandations du jour — fenêtre de 30 jours calendaires finissant
     * $todayKey, prévision issue du SEUL ForecastEngine (aucun second
     * modèle de demande), historique des décisions humaines et surcharges
     * de paramètres lues en base (clés « reco_* »).
     *
     * @param string              $todayKey Aujourd'hui 'Y-m-d' (Europe/Paris).
     * @param \DateTimeImmutable  $now      Instant de référence.
     * @param array<string,mixed> $opts     Réservé (aucune option aujourd'hui).
     *
     * @return array{reco:RecoDataset, forecast:array<string,mixed>, fenetre:array{from:string,to:string,jours_ouverts:int}}
     */
    public static function dataset(string $todayKey, \DateTimeImmutable $now, array $opts = []): array
    {
        // Fenêtre d'analyse : 30 jours CALENDAIRES finissant aujourd'hui.
        $from = $now->modify('-29 days')->format('Y-m-d');

        // Prévision : UNE SEULE source de vérité, consommation identique
        // à AdminAnalyticsController::buildPage().
        $dsForecast = AnalyticsForecastData::dataset($from, $todayKey);
        $creuse     = SlowPeriods::parse(trim((string) Setting::get('analytics_periode_creuse', '')));
        $forecast   = ForecastEngine::forecast($dsForecast, $now, $now->format('H:i'), [
            'horizon'       => 7,
            'goal_weekly'   => (float) Setting::get('analytics_weekly_goal', '500'),
            'slow_periods'  => $creuse,
            'backtest_days' => AnalyticsForecastData::currentFlowDays(AnalyticsForecastData::firstSaleDate(), $todayKey),
        ]);

        // Produits : TOUS les ProductStat de la fenêtre, même sans ligne
        // de prévision (unités prévues à 0). coutRaison explique pourquoi
        // le coût n'est PAS utilisable pour une règle tarifaire.
        $prevParCle = is_array($forecast['produits'] ?? null) ? $forecast['produits'] : [];
        $produits = [];
        foreach (AnalyticsForecastData::productStats($from, $todayKey) as $ps) {
            $cout   = $ps->unitCost;
            $raison = null;
            if ($cout === null) {
                $raison = 'cout_inconnu';
            } elseif ($cout <= 0.0) {
                $raison = 'cout_zero';
            } elseif ($ps->unitPrice !== null && $ps->unitPrice < $cout) {
                $raison = 'prix_inferieur_au_cout';
            }
            $prev = is_array($prevParCle[$ps->key] ?? null) ? $prevParCle[$ps->key] : [];
            $produits[] = new RecoProduct(
                key: $ps->key,
                label: $ps->label,
                category: $ps->category,
                qty30: (float) $ps->qty,
                ca30: (float) $ps->ca,
                prix: $ps->unitPrice,
                cout: $cout,
                coutRaison: $raison,
                stock: $ps->stock,
                unitesPrevues7j: (float) ($prev['unites'] ?? 0.0),
                prevLo: (float) ($prev['lo'] ?? 0.0),
                prevHi: (float) ($prev['hi'] ?? 0.0),
            );
        }

        // Quantités du mois PRÉCÉDENT (fenêtre glissante de 30 jours juste
        // avant la fenêtre d'analyse) — réservé aux règles de tendance.
        $qtyPrec = self::qtyParProduit(
            $now->modify('-59 days')->format('Y-m-d'),
            $now->modify('-30 days')->format('Y-m-d')
        );

        $facteurCreuse = SlowPeriods::facteurPour($creuse, $now);
        $joursOuverts  = self::nbJoursOuverts($from, $todayKey);

        // Historique des décisions humaines (le cœur évite de re-proposer
        // une idée active, refusée récemment, ou qui chevauche une reco
        // appliquée en cours de mesure).
        $historique = [
            'actifs'     => Recommendation::actifs(),
            'refus'      => Recommendation::refusRecents(),
            'appliquees' => array_map(
                static function (array $a): array {
                    $dec = json_decode((string) ($a['produits_json'] ?? '[]'), true);
                    $keys = is_array($dec) ? array_column($dec, 'key') : [];

                    return [
                        'fingerprint' => (string) ($a['id'] ?? ''),
                        'produits'    => array_values(array_filter($keys, 'is_string')),
                    ];
                },
                Recommendation::appliqueesEnCours()
            ),
        ];

        return [
            'reco' => new RecoDataset(
                produits: $produits,
                paniers: self::paniers($from, $todayKey),
                qtyPrec: $qtyPrec,
                facteurCreuse: $facteurCreuse,
                periodeCreuseActive: $facteurCreuse < 1.0,
                joursOuvertsFenetre: $joursOuverts,
                forecast: $forecast,
                historique: $historique,
                parametres: self::parametres(),
            ),
            'forecast' => $forecast,
            'fenetre'  => ['from' => $from, 'to' => $todayKey, 'jours_ouverts' => $joursOuverts],
        ];
    }

    /**
     * Surcharges « reco_* » lues en base (page Paramètres) : valeurs
     * numériques valides uniquement, sinon la constante du cœur fait foi.
     *
     * @return array<string,float>
     */
    public static function parametres(): array
    {
        $defauts = [
            'reco_marge_min'              => '0.15',
            'reco_stock_dormant_semaines' => '8',
            'reco_stock_dormant_min'      => '10',
            'reco_echantillon_min_unites' => '20',
            'reco_duo_remise_max'         => '0.10',
            'reco_immobilise_seuil_eur'   => '30',
            'reco_max_actives'            => '5',
        ];

        $out = [];
        foreach ($defauts as $cle => $defaut) {
            try {
                $brut = trim((string) Setting::get($cle, $defaut));
                $out[$cle] = is_numeric($brut) ? (float) $brut : (float) $defaut;
            } catch (\Throwable) {
                $out[$cle] = (float) $defaut;
            }
        }

        return $out;
    }
}
