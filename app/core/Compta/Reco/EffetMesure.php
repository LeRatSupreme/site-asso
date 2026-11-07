<?php

declare(strict_types=1);

namespace App\Core\Compta\Reco;

/**
 * Mesure d'effet d'une recommandation appliquée (statique, pur) :
 * compare le CA réel observé au jour à la baseline d'EFFET MESURÉ
 * produite par RecommendationEngine::baseline(), sans aucun nouveau
 * modèle. Le réel est désaisonnalisé par le facteur de période creuse
 * de chaque jour (facteur < 1 → ventes observées ramenées À LA HAUSSE
 * ÷ facteur ; facteur ≤ 0 → 1.0). L'effet est concluant quand zéro est
 * HORS de l'intervalle [réel − hi ; réel − lo] ; sinon non concluant.
 * Aucun accès DB, déterministe.
 */
final class EffetMesure
{
    /**
     * Calcule l'état de mesure d'effet.
     *
     * @param array<int,array{date:string,attendu:float,lo:float,hi:float,facteur:float}> $baseline          Lignes produites par RecommendationEngine::baseline().
     * @param array<string,float>                                                          $reelParJour     CA réel par date ('Y-m-d' => €).
     * @param int                                                                          $joursOuvertsEcoules Jours ouverts écoulés depuis l'application.
     * @param bool                                                                         $chevauchement   true = autre promo active sur les mêmes produits.
     *
     * @return array{etat:string,effet:?float,bas:?float,haut:?float,jours:int,note:string}
     *         etat ∈ {'attente','insuffisant','non_concluant','concluant'} ; effet/bas/haut
     *         null avant le seuil de mesure ; jours = lignes de baseline couvertes.
     */
    public static function calculer(
        array $baseline,
        array $reelParJour,
        int $joursOuvertsEcoules,
        bool $chevauchement = false
    ): array {
        $nb = max(0, min($joursOuvertsEcoules, count($baseline)));

        // Pas encore assez de jours couverts pour chiffrer quoi que ce soit.
        if ($nb < Hyper::EFFET_MIN_JOURS_OUVERTS) {
            return self::resultat(
                'insuffisant',
                null,
                null,
                null,
                $nb,
                sprintf(
                    'Mesure insuffisante : %d jours ouverts couverts (minimum %d).',
                    $nb,
                    Hyper::EFFET_MIN_JOURS_OUVERTS
                )
            );
        }

        // Délai de déclenchement : la mesure démarre après EFFET_DECLANCHEMENT_JOURS_OUVERTS.
        if ($joursOuvertsEcoules < Hyper::EFFET_DECLANCHEMENT_JOURS_OUVERTS) {
            return self::resultat(
                'attente',
                null,
                null,
                null,
                $nb,
                sprintf(
                    'Mesure dans %d jours ouverts (délai post-déclenchement de %d jours).',
                    Hyper::EFFET_DECLANCHEMENT_JOURS_OUVERTS - $joursOuvertsEcoules,
                    Hyper::EFFET_DECLANCHEMENT_JOURS_OUVERTS
                )
            );
        }

        $couverts = array_slice($baseline, 0, $nb);
        $attenduTotal = 0.0;
        $loTotal = 0.0;
        $hiTotal = 0.0;
        $reelTotal = 0.0;
        foreach ($couverts as $ligne) {
            $attenduTotal += (float) ($ligne['attendu'] ?? 0.0);
            $loTotal += (float) ($ligne['lo'] ?? 0.0);
            $hiTotal += (float) ($ligne['hi'] ?? 0.0);
            $reelTotal += self::reelDesaison($reelParJour[(string) ($ligne['date'] ?? '')] ?? null, $ligne['facteur'] ?? null);
        }

        $effet = $reelTotal - $attenduTotal;
        $bas = $reelTotal - $hiTotal;
        $haut = $reelTotal - $loTotal;
        $etat = ($bas <= 0.0 && 0.0 <= $haut) ? 'non_concluant' : 'concluant';

        return self::resultat($etat, $effet, $bas, $haut, $nb, '', $chevauchement);
    }

    /**
     * Libellé FR d'un résultat de mesure : effet chiffré avec intervalle
     * (2 décimales, virgule décimale, signe explicite), verdict non
     * concluant, ou la note d'attente/insuffisance telle quelle.
     *
     * @param array{etat:string,effet:?float,bas:?float,haut:?float,jours:int,note:string} $resultat
     */
    public static function libelle(array $resultat): string
    {
        $etat = is_string($resultat['etat'] ?? null) ? $resultat['etat'] : '';
        if ($etat === 'concluant' && is_float($resultat['effet'] ?? null) && is_float($resultat['bas'] ?? null) && is_float($resultat['haut'] ?? null)) {
            return sprintf(
                'Effet mesuré %s € [%s ; %s] €/période',
                self::signe($resultat['effet']),
                number_format($resultat['bas'], 2, ',', ''),
                number_format($resultat['haut'], 2, ',', '')
            );
        }
        if ($etat === 'non_concluant') {
            return 'Non concluant (l\'intervalle contient zéro)';
        }

        return is_string($resultat['note'] ?? null) ? $resultat['note'] : '';
    }

    /**
     * CA réel désaisonnalisé d'un jour : reel ÷ facteur (facteur < 1 en
     * creuse → ramené à la hausse) ; facteur ≤ 0 ou reel absent → 1.0 / 0.0.
     */
    private static function reelDesaison(?float $reel, mixed $facteur): float
    {
        if ($reel === null) {
            return 0.0;
        }
        $f = is_numeric($facteur) ? (float) $facteur : 1.0;

        return $f > 0.0 ? $reel / $f : $reel;
    }

    /**
     * Note éventuelle de chevauchement : l'état est inchangé, seule
     * l'attribution de l'effet est remise en cause.
     */
    private static function resultat(string $etat, ?float $effet, ?float $bas, ?float $haut, int $jours, string $note, bool $chevauchement = false): array
    {
        if ($chevauchement) {
            $note = 'Effet non attribuable : chevauchement avec une autre promo sur les mêmes produits';
        }

        return ['etat' => $etat, 'effet' => $effet, 'bas' => $bas, 'haut' => $haut, 'jours' => $jours, 'note' => $note];
    }

    /** Montant signé à 2 décimales, virgule décimale (« +210,00 », « -140,00 »). */
    private static function signe(float $v): string
    {
        return ($v >= 0.0 ? '+' : '') . number_format($v, 2, ',', '');
    }
}
