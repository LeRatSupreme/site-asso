<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

/**
 * Fabrique de tableaux purs pour les tests du cœur Dashboard (utilitaire
 * statique, PAS une classe de test, aucune DB) : agrégats d'une période
 * (même forme que Sale::aggregatesBetween + Expense::aggregatesBetween,
 * « ttc » renommé « expenses ») et items du bloc « À faire ».
 */
final class DashboardScenario
{
    /**
     * Agrégats d'une période, tels que les passera le contrôleur.
     *
     * @return array{ca:float, profit:float, qty:float, expenses:float}
     */
    public static function chiffres(float $ca, float $profit, float $qty, float $expenses): array
    {
        return ['ca' => $ca, 'profit' => $profit, 'qty' => $qty, 'expenses' => $expenses];
    }

    /**
     * Agrégats de la période précédente (ou null si indisponible).
     *
     * @return array{ca:float, profit:float, qty:float, expenses:float}|null
     */
    public static function precedent(?float $ca, ?float $profit, ?float $qty, ?float $expenses): ?array
    {
        if ($ca === null || $profit === null || $qty === null || $expenses === null) {
            return null;
        }

        return self::chiffres($ca, $profit, $qty, $expenses);
    }

    /**
     * Item « À faire » minimal, telle que le contrôleur le produit.
     *
     * @return array{cle:string, urgence:int, texte:string, lien:string}
     */
    public static function item(string $cle, int $urgence, string $texte = '', string $lien = '/admin/compta'): array
    {
        return [
            'cle'     => $cle,
            'urgence' => $urgence,
            'texte'   => $texte === '' ? 'Texte ' . $cle : $texte,
            'lien'    => $lien,
        ];
    }
}
