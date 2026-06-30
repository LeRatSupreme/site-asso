<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Analytics;

/**
 * Dashboard analytics avec graphiques Chart.js (Fonctionnalité 14).
 */
final class AdminAnalyticsController extends AdminBaseController
{
    public function index(): void
    {
        // Accessible à ADMIN et TRESORERIE : données compta agrégées (lecture seule).
        $this->guardCompta();

        // Évolution du CA mensuel (12 derniers mois).
        $revenue = Analytics::monthlyRevenue(12);

        // Top 10 produits (CA) sur 12 mois.
        $topProducts = Analytics::topProducts(12, 10);

        // Répartition par catégorie (donut).
        $byCategory = Analytics::revenueByCategory(12);

        // Inscriptions par mois (6 derniers mois).
        $registrations = Analytics::registrationsByMonth(6);

        // Nouveaux membres par mois (6 derniers mois).
        $members = Analytics::newMembersByMonth(6);

        // On passe les données en JSON à la vue (séries prêtes à consommer).
        $charts = [
            'revenue' => [
                'labels' => array_column($revenue, 'label'),
                'values' => array_column($revenue, 'value'),
            ],
            'topProducts' => [
                'labels' => array_column($topProducts, 'label'),
                'values' => array_column($topProducts, 'ca'),
            ],
            'byCategory' => [
                'labels' => array_column($byCategory, 'label'),
                'values' => array_column($byCategory, 'ca'),
            ],
            'activity' => [
                'labels' => array_column($registrations, 'label'),
                'registrations' => array_column($registrations, 'value'),
                'members'        => array_column($members, 'value'),
            ],
        ];

        $this->renderAdmin('admin/analytics/index', [
            'title'  => 'Analytics',
            'charts' => $charts,
            'json'   => json_encode(
                $charts,
                JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
        ]);
    }
}
