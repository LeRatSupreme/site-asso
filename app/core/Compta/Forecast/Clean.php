<?php

declare(strict_types=1);

namespace App\Core\Compta\Forecast;

/**
 * Étape 0 du cœur de prévision : nettoyage / préparation du dataset
 * (jours de semaine ouverts habituels, jours atypiques, séparation
 * passé / jour en cours). Toutes les méthodes sont statiques et pures.
 *
 * Règle de mission : un jour FERMÉ n'est jamais compté comme CA = 0 —
 * il est simplement absent du dataset et n'influence aucune moyenne.
 */
final class Clean
{
    /**
     * Jours de semaine (dow 0 = lundi … 6 = dimanche) OUVRÉS HABITUELS,
     * déduits des seules données (jours ouverts observés).
     *
     * Couverture calendaire (choix simple documenté) :
     * n_weeks = nombre de LUNDIS dans [première date, dernière date]
     * observés dont la semaine lun-dim compte AU MOINS UN jour ouvert —
     * en pratique, le nombre de semaines d'ACTIVITÉ dans le span. Un dow
     * est « ouvert habituel » si son nombre de jours ouverts observés
     * AVANT $todayKey atteint max(1, ceil(0.5 × n_weeks)) — au moins une
     * occurrence, sinon la moitié des semaines. Le jour en cours (partiel)
     * ne compte ni dans la couverture ni dans les effectifs par dow.
     *
     * Correctif du 21/10/2026 (WAPE modèle 94 % en prod) : n_weeks
     * comptait auparavant TOUS les lundis du span — une fermeture
     * COMPLÈTE (vacances d'été) les gonflait (juin→octobre : 21 semaines
     * → 11 occurrences/dow exigées, plus qu'aucun dow n'en avait) et
     * openWeekdays revenait VIDE : expectedN = 0 sur chaque dow,
     * prédictions de backtest nulles. Les semaines de fermeture totale ne
     * comptent plus ; sans fermeture, la règle est IDENTIQUE à l'ancienne
     * (lundis du span, semaines partielles de tête exclues — le seuil ne
     * monte jamais au-delà de la règle d'origine).
     *
     * @param array<string,DayCurrent> $days     Jours ouverts (aujourd'hui pouvant y figurer).
     * @param string                   $todayKey 'Y-m-d' du jour en cours.
     * @return list<int> dow ouverts habituels, triés croissants.
     */
    public static function openWeekdays(array $days, string $todayKey): array
    {
        try {
            $today = new \DateTimeImmutable($todayKey);
        } catch (\Throwable) {
            return [];
        }

        // Dates strictement passées uniquement.
        $past = [];
        foreach ($days as $key => $day) {
            $dateKey = $day instanceof DayCurrent ? $day->date : (string) $key;
            try {
                $d = new \DateTimeImmutable($dateKey);
            } catch (\Throwable) {
                continue;
            }
            if ($d < $today) {
                $past[$dateKey] = $d;
            }
        }
        if ($past === []) {
            return [];
        }

        // Lundis des semaines AVEC au moins un jour ouvert ; on ne compte
        // que ceux situés dans le span observé [première, dernière] (la
        // semaine partielle de tête n'entre pas dans le seuil — sémantique
        // d'origine conservée).
        $firstKey = min(array_keys($past));
        $lastKey  = max(array_keys($past));
        $openMondays = [];
        foreach ($past as $d) {
            $mondayKey = $d->modify('-' . ((int) $d->format('N') - 1) . ' days')->format('Y-m-d');
            if ($mondayKey >= $firstKey && $mondayKey <= $lastKey) {
                $openMondays[$mondayKey] = true;
            }
        }
        $nWeeks = count($openMondays);
        $needed = max(1, (int) ceil(0.5 * $nWeeks));

        $counts = array_fill(0, 7, 0);
        foreach ($past as $d) {
            $counts[(int) $d->format('N') - 1]++;
        }

        $out = [];
        foreach ($counts as $dow => $c) {
            if ($c >= $needed) {
                $out[] = $dow;
            }
        }
        sort($out);

        return $out;
    }

    /**
     * Poids par jour : 1.0, ou Hyper::W_ATYPICAL si le CA du jour est
     * atypique au sens du z robuste — |z| > Hyper::Z_ATYPICAL avec
     * z = (CA − médiane) / (1.4826 × MAD) calculés sur les CA des jours
     * fournis. Si la MAD normalisée vaut 0 (dispersion nulle, ex. tous
     * les CA identiques), aucun jour n'est déclaré atypique.
     *
     * NB : cette méthode RETOURNE les poids ; c'est l'appelant qui les
     * repasse au modèle (le poids stocké dans DayCurrent::weight n'est
     * pas modifié ici).
     *
     * @param array<string,DayCurrent> $days
     * @return array<string,float> 'Y-m-d' => poids (1.0 ou Hyper::W_ATYPICAL).
     */
    public static function atypicalWeights(array $days): array
    {
        $cas = [];
        foreach ($days as $day) {
            $cas[] = $day->ca;
        }
        $med  = Stats::median($cas);
        $madS = Stats::madScaled($cas);

        $out = [];
        foreach ($days as $key => $day) {
            $z = Stats::robustZ($day->ca, $med, $madS);
            $out[(string) $key] = abs($z) > Hyper::Z_ATYPICAL ? Hyper::W_ATYPICAL : 1.0;
        }

        return $out;
    }

    /**
     * Sépare le dataset entre les jours STRICTEMENT avant $todayKey
     * (passé, base d'apprentissage) et le jour en cours (partiel).
     *
     * @param array<string,DayCurrent> $days
     * @return array{past:array<string,DayCurrent>, today:?DayCurrent}
     */
    public static function splitPast(array $days, string $todayKey): array
    {
        $past  = [];
        $today = null;
        foreach ($days as $key => $day) {
            $dateKey = $day->date;
            if ($dateKey < $todayKey) {
                $past[(string) $key] = $day;
            } elseif ($dateKey === $todayKey) {
                $today = $day;
            }
        }

        return ['past' => $past, 'today' => $today];
    }
}
