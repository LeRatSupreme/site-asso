<?php

declare(strict_types=1);

namespace App\Core\Compta\Dashboard;

/**
 * Hyperparamètres FIXES du dashboard « Niveau 1 » /admin/compta (documentés,
 * jamais optimisés automatiquement — même choix de mission que Reco\Hyper
 * et Forecast\Hyper). Chaque constante porte son unité et le rôle joué dans
 * les règles du verdict, des cartes et du bloc « À faire ».
 * Surcharge ponctuelle possible via le tableau $parametres (clé
 * « dashboard_<nom> », voir Hyper::valeur) — jamais en base, jamais dans .env.
 */
final class Hyper
{
    /** % de variation du résultat net — en dessous de 0 et jusqu'au seuil d'alerte : signal orange (« Le résultat net baisse »). */
    public const BAISSE_ATTENTION_PCT = -10.0;

    /** % de variation du résultat net — borne (inclusive) en dessous de laquelle le verdict passe au rouge (« Forte baisse »). Surcharge : « dashboard_baisse_alerte_pct ». */
    public const BAISSE_ALERTE_PCT = -25.0;

    /** % du CA/objectif — à partir de ce seuil (inclusive) l'objectif de CA est annoncé « atteint ». */
    public const OBJECTIF_SEUIL_OK_PCT = 100.0;

    /** Bloc « À faire » — nombre maximal d'items affichés après tri. Surcharge : « dashboard_a_faire_max ». */
    public const A_FAIRE_MAX = 5;

    /** Urgences par défaut des sources du bloc « À faire » (le contrôleur peut surcharger par item) : 1 = quand vous pouvez, 2 = à traiter, 3 = urgent. */
    public const A_FAIRE_URGENCES = ['rupture' => 3, 'caisse' => 3, 'import' => 2, 'inventaire' => 2, 'couts' => 2];

    /**
     * Lit un hyperparamètre surchargeable : retourne
     * $parametres['dashboard_' . $nom] si numérique (int/float/chaîne
     * numérique), sinon $defaut. Toute autre valeur (null, booléen,
     * tableau, chaîne non numérique) retombe sur $defaut.
     *
     * @param array<string,mixed> $parametres
     */
    public static function valeur(array $parametres, string $nom, float|int $defaut): float|int
    {
        $v = $parametres['dashboard_' . $nom] ?? null;
        if (is_int($v) || is_float($v)) {
            return $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (float) $v;
        }

        return $defaut;
    }
}
