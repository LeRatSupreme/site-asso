<?php

declare(strict_types=1);

namespace App\Core\Compta\Dashboard;

/**
 * Bloc « À faire » du dashboard /admin/compta (statique, pur, zéro DB) :
 * tri et plafonnement d'une liste d'items DÉJÀ construite par le
 * contrôleur (les urgences par défaut viennent de Hyper::A_FAIRE_URGENCES,
 * surchargeables par item côté contrôleur), et décision de l'item budget
 * (itemBudget : quel signal budget du mois courant afficher, à partir de
 * données assemblées ailleurs). Cette classe n'accède à aucune donnée :
 * seulement tri, cap, libellés de badge et règles de présentation.
 */
final class Afaire
{
    /** Badge d'urgence par niveau (3 = urgent … 1 = quand vous pouvez) ; toute autre valeur retombe sur « À traiter ». */
    public const LIBELLES_URGENCE = [3 => 'Urgent', 2 => 'À traiter', 1 => 'Quand vous pouvez'];

    /**
     * Seuil (inclusif) de consommation d'une enveloppe de dépenses
     * au-delà duquel le bloc « À faire » signale le budget du mois
     * courant. Même seuil que le badge « Presque épuisé » de la page
     * Budgets.
     */
    public const BUDGET_ALERTE_PCT = 85.0;

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

    /**
     * Item « À faire » budget du mois courant (logique PURE, zéro DB —
     * les données sont assemblées par Data::construire depuis les mêmes
     * sources que la page Budgets) :
     *  - aucune ligne de budget pour le mois → « Aucun budget pour le
     *    mois courant » (rappel : sans lui, personne ne saisit le budget) ;
     *  - sinon, la pire catégorie de dépenses consommée à ≥ 85 % de son
     *    enveloppe → « MATIERE à 92 % de l'enveloppe ». L'objectif de CA
     *    n'est pas une enveloppe : jamais signalé ici (le dépasser est
     *    une bonne nouvelle).
     *
     * @param array<string,float> $budgets    Lignes du mois courant (Budget::forMonth).
     * @param array<string,float> $realiseCat Réalisé du mois par catégorie de dépenses (MATIERE inclut déjà les achats de stock).
     *
     * @return array{cle:string,urgence:int,texte:string}|null null = rien à signaler.
     */
    public static function itemBudget(array $budgets, array $realiseCat): ?array
    {
        if ($budgets === []) {
            return [
                'cle'     => 'budget',
                'urgence' => 2,
                'texte'   => 'Aucun budget pour le mois courant',
            ];
        }

        $pireCat = null;
        $pirePct = 0.0;
        foreach ($budgets as $cat => $planned) {
            if ($cat === 'CA' || (float) $planned <= 0.0) {
                continue;
            }
            $pct = ((float) ($realiseCat[$cat] ?? 0.0)) / (float) $planned * 100.0;
            if ($pct > $pirePct) {
                $pirePct = $pct;
                $pireCat = (string) $cat;
            }
        }

        if ($pireCat !== null && $pirePct >= self::BUDGET_ALERTE_PCT) {
            return [
                'cle'     => 'budget',
                'urgence' => 2,
                'texte'   => sprintf("%s à %d %% de l'enveloppe", $pireCat, (int) round($pirePct)),
            ];
        }

        return null;
    }
}
