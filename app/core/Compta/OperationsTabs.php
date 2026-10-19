<?php

declare(strict_types=1);

namespace App\Core\Compta;

/**
 * Onglets de la page fusionnée « Opérations » : mapping section →
 * onglet de niveau 1. « pertes » (journal des pertes) et « inventaire »
 * (relevé tablette SumUp) partagent le MÊME bloc « Pertes / Inventaire »,
 * distingué par des sous-onglets de niveau 2 — deux pages distinctes,
 * rangées sous le même onglet (mêmes vues, mêmes routes).
 *
 * Classe pure (aucun accès POST/DB) : consommée par la vue
 * admin/compta/operations.php, testée unitairement (OperationsTabsTest).
 */
final class OperationsTabs
{
    public const TAB_ACHATS     = 'achats';
    public const TAB_DEPENSES   = 'depenses';
    public const TAB_PERTES     = 'pertes';
    public const TAB_EVENEMENTS = 'evenements';

    /** Sections rendues sous l'onglet fusionné « Pertes / Inventaire ». */
    public const SECTION_PERTES     = 'pertes';
    public const SECTION_INVENTAIRE = 'inventaire';

    /**
     * Onglet de niveau 1 actif pour chaque section de la page. Une
     * section dont l'onglet diffère de sa propre clé n'a PAS d'onglet
     * de niveau 1 (sous-section, cf. sous-onglets de la vue).
     *
     * @return array<string,string>
     */
    public static function tabOf(): array
    {
        return [
            'achats'     => self::TAB_ACHATS,
            'depenses'   => self::TAB_DEPENSES,
            'pertes'     => self::TAB_PERTES,
            'inventaire' => self::TAB_PERTES,
            'evenements' => self::TAB_EVENEMENTS,
        ];
    }

    /**
     * Onglet de niveau 1 d'une section (repli « achats » si inconnue —
     * même repli que la vue operations.php pour une section invalide).
     */
    public static function tabFor(string $section): string
    {
        return self::tabOf()[$section] ?? self::TAB_ACHATS;
    }

    /**
     * La section est-elle une sous-section (pas d'onglet de niveau 1
     * propre, rendue sous l'onglet d'un autre bloc) ?
     */
    public static function isSubSection(string $section): bool
    {
        return isset(self::tabOf()[$section]) && self::tabOf()[$section] !== $section;
    }
}
