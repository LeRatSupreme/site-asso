<?php

declare(strict_types=1);

namespace App\Core\Compta\Dashboard;

/**
 * Bloc « À faire » du dashboard /admin/compta (statique, pur, zéro DB) :
 * tri et plafonnement d'une liste d'items DÉJÀ construite par le
 * contrôleur (les urgences par défaut viennent de Hyper::A_FAIRE_URGENCES,
 * surchargeables par item côté contrôleur). Cette classe n'accède à
 * aucune donnée : seulement tri, cap et libellés de badge.
 */
final class Afaire
{
    /** Badge d'urgence par niveau (3 = urgent … 1 = quand vous pouvez) ; toute autre valeur retombe sur « À traiter ». */
    public const LIBELLES_URGENCE = [3 => 'Urgent', 2 => 'À traiter', 1 => 'Quand vous pouvez'];

    /**
     * Trie les items par urgence DÉCROISSANTE puis par clé CROISSANTE
     * (déterminisme total), plafonne à Hyper::A_FAIRE_MAX (surcharge
     * « dashboard_a_faire_max ») et ajoute le libellé de badge
     * « urgence_label » à chaque item conservé.
     *
     * @param list<array{cle:string, urgence:int, texte:string, lien:string}> $items Items produits par le contrôleur.
     * @param array<string,mixed> $parametres Surcharges « dashboard_<nom> ».
     *
     * @return list<array{cle:string, urgence:int, texte:string, lien:string, urgence_label:string}>
     */
    public static function trier(array $items, array $parametres): array
    {
        $max = (int) Hyper::valeur($parametres, 'a_faire_max', Hyper::A_FAIRE_MAX);
        if ($max < 0) {
            $max = 0;
        }

        usort($items, static function (array $a, array $b): int {
            $parUrgence = (int) $b['urgence'] <=> (int) $a['urgence'];
            if ($parUrgence !== 0) {
                return $parUrgence;
            }

            return (string) $a['cle'] <=> (string) $b['cle'];
        });

        $items = array_slice($items, 0, $max);

        foreach ($items as $i => $item) {
            $items[$i]['urgence_label'] = self::LIBELLES_URGENCE[(int) ($item['urgence'] ?? 0)] ?? 'À traiter';
        }

        return $items;
    }
}
