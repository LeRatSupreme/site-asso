<?php

declare(strict_types=1);

namespace App\Core\Compta\Edt;

/**
 * CONTEXTE CALENDRIER UNIVERSITAIRE pour l'analytique — point d'assemblage
 * UNIQUE des dérivés d'EdtCalendar (règle AGENTS : une donnée partagée par
 * plusieurs pages vient d'un seul point). Trois questions, trois entrées :
 *
 *  - facteurReappro(now, horizon) : de combien moduler les QUANTITÉS
 *    suggérées de réappro sur l'horizon de couverture (semaines sans
 *    cours → 0, semaines partielles → prorata des jours actifs) ;
 *  - pourPeriode(from, to) : contexte d'ANNOTATION du dashboard analytics
 *    (semaines mortes de la période affichée + prochain jour de cours) ;
 *  - ligneRecap(now) : ligne factuelle pour le récap quotidien
 *    (kiosque/SMS — « Vacances universitaires : reprise des cours le
 *    02/11 »), AUCUN nom de vacances en dur, tout est dérivé des données.
 *
 * POLITIQUE DE MODULATION (documentée, testée) :
 *  - une semaine ENTIÈREMENT publiée (lundi ≥ début publié, vendredi ≤
 *    fin publiée — fermetures comprises) et SANS cours → facteur 0.0 :
 *    ne PAS suggérer du tout pour une semaine 100 % morte ;
 *  - une semaine entièrement publiée avec 1..4 jours actifs → facteur
 *    jours_actifs / 5 : réduction au prorata des jours de cours ;
 *  - une semaine 100 % active (5/5) → 1.0, aucune modulation ;
 *  - une semaine NON ENTIÈREMENT publiée (hors horizon ou partiellement
 *    publiée) → 1.0, PRUDENCE : des données absentes ne sont jamais des
 *    vacances, on ne réduit jamais sur du vide ;
 *  - le facteur global d'un horizon = moyenne des facteurs de semaine
 *    PONDÉRÉE PAR LES JOURS OUVRÉS (lun-ven) couverts : les week-ends ne
 *    portent jamais de cours, leur poids dans la demande de kiosque est
 *    déjà modélisé par les effets de jour de semaine du moteur — les
 *    pondérer ici réduirait deux fois le même effet.
 *
 * Le facteur n'agit QUE sur les quantités suggérées (transmises au
 * ForecastEngine via l'option « calendar » et appliquées dans
 * Dashboard\Data::analyseReappro) : JAMAIS sur les prévisions de CA, les
 * prix, les coûts unitaires ou les lots de coût.
 *
 * FUSEAU : toutes les dates sont manipulées en Europe/Paris (le campus
 * est à Calais) — EdtCalendar binne déjà les évènements en heure locale
 * et les bornes de semaine (lundi → vendredi) sont calculées ici sur des
 * dates locales, alignées sur le moteur de prévision (Hyper::TZ).
 *
 * Contexte test (APP_TESTING) : injecteurs par défaut déroutés vers un
 * calendrier vide HORS-LIGNE (aucun appel réseau depuis une suite de
 * tests) — tout est alors hors horizon → facteur 1.0, aucune semaine
 * morte, aucune ligne : comportement NEUTRE et déterministe. Les tests
 * ciblés injectent leurs propres fixtures (fetchUrl + cacheDir).
 */
final class CalendarContext
{
    /** Fuseau de référence (campus de Calais), aligné sur EdtCalendar/Hyper. */
    private const TZ = 'Europe/Paris';

    /** Nombre de jours ouvrés d'une semaine (lun-ven) — base du prorata. */
    public const JOURS_OUVRES_SEMAINE = 5;

    /**
     * Facteur de modulation des suggestions de réappro sur l'horizon de
     * couverture (les $horizonJours prochains jours calendaires, demain
     * inclus — la journée en cours n'est pas re-commandable).
     *
     * @param \DateTimeImmutable    $now          Instant de référence (forcé Europe/Paris).
     * @param int                   $horizonJours Jours couverts par les suggestions (7 = une semaine).
     * @param callable|null         $fetchUrl     Injecteur HTTP (tests) — défaut curl.
     * @param string|null           $cacheDir     Dossier cache EDT (tests) — défaut cache/edt.
     *
     * @return array{facteur:float, explication:?string,
     *               semaines:list<array{lundi:string, vendredi:string, debut_fr:string, fin_fr:string,
     *                                    label:string, avec_cours:bool, jours_actifs:int,
     *                                    hors_horizon:bool, facteur:float, poids:int}>,
     *               jours_ouvrables:int, jours_sans_cours:int, stale:bool}
     *               facteur ∈ [0 ; 1] (1.0 = aucune modulation) ; explication = ligne
     *               humaine (« Semaine sans cours du 26/10 au 30/10 :
     *               suggestions annulées ») ou null si rien à dire ; semaines =
     *               une entrée par semaine chevauchant l'horizon, avec son
     *               facteur et son poids (jours ouvrés couverts) — trace du
     *               facteur appliqué.
     */
    public static function facteurReappro(\DateTimeImmutable $now, int $horizonJours = 7, ?callable $fetchUrl = null, ?string $cacheDir = null): array
    {
        $now = $now->setTimezone(new \DateTimeZone(self::TZ));
        $horizonJours = max(1, $horizonJours);

        try {
            [$fetch, $dir] = self::injecteurs($fetchUrl, $cacheDir);
            $publiee = EdtCalendar::plagePubliee($fetch, $dir);

            // Semaines chevauchant [demain ; demain+horizon-1], une seule
            // lecture chacune (mémo EdtCalendar par requête).
            $premier = $now->modify('+1 day');
            $dernier = $now->modify('+' . $horizonJours . ' days');
            $lundis = [];
            for ($d = $premier; $d <= $dernier; $d = $d->modify('+1 day')) {
                $lundis[$d->modify('monday this week')->format('Y-m-d')] = true;
            }
            ksort($lundis);

            $semaines = [];
            $somme = 0.0;
            $ouvrables = 0;
            $sansCours = 0;
            foreach (array_keys($lundis) as $lundi) {
                $s = EdtCalendar::semaineInfos($lundi, $fetch, $dir);
                $f = self::facteurSemaine($s, $publiee['fin']);
                // Poids = jours ouvrés (lun-ven) de la semaine DANS l'horizon.
                $poids = self::nbOuvresDans($lundi, $premier, $dernier);
                $semaines[] = self::entree($s, $f, $poids);
                $somme += $f * $poids;
                $ouvrables += $poids;
                if ($f <= 0.0) {
                    $sansCours += $poids;
                }
            }

            $facteur = $ouvrables > 0 ? $somme / $ouvrables : 1.0;
            $facteur = round(min(1.0, max(0.0, $facteur)), 4);

            return [
                'facteur'          => $facteur,
                'explication'      => self::explication($semaines, $facteur),
                'semaines'         => $semaines,
                'jours_ouvrables'  => $ouvrables,
                'jours_sans_cours' => $sansCours,
                'stale'            => (bool) $publiee['stale'],
            ];
        } catch (\Throwable) {
            // Jamais d'exception au consommateur (page analytics, cron SMS) :
            // politique prudente par défaut = NE PAS moduler.
            return self::neutre();
        }
    }

    /**
     * Contexte calendrier d'une PÉRIODE AFFICHÉE (dashboard analytics) :
     * semaines mortes évaluables de la période + journée courante (cours ?
     * prochain jour de cours). Contrat consommé par la vue/le JS pour
     * annoter les graphiques — AUCUN calcul côté vue.
     *
     * Une semaine morte n'est listée que si elle est ENTIÈREMENT publiée
     * (lundi ≥ début publié, vendredi ≤ fin publié) : les semaines
     * couvertes par la période mais hors de la plage publiée du cache EDT
     * ( passé plus vieux que la marge J-14, ou futur au-delà de l'horizon)
     * ne sont JAMAIS déclarées mortes sur des données absentes — la plage
     * publiée est exposée pour que la vue puisse l'indiquer.
     *
     * @param string             $from     Début affiché 'Y-m-d'.
     * @param string             $to       Fin affichée 'Y-m-d'.
     * @param ?\DateTimeImmutable $now     Instant de référence (défaut : maintenant, Paris).
     *
     * @return array{plage_publiee:array{debut:?string, fin:?string, stale:bool},
     *               semaines_mortes:list<array{lundi:string, vendredi:string, debut_fr:string, fin_fr:string,
     *                                          label:string, hors_horizon:bool}>,
     *               journee:array{date:string, cours:bool, creneaux:int,
     *                             prochain_cours:?string, prochain_cours_fr:?string}}
     *               hors_horizon est TOUJOURS false sur une semaine listée
     *               (une semaine non publiée n'est jamais listée morte) —
     *               la clé reste au contrat pour distinguer « vacances
     *               connues » d'une future extension « non publié ».
     */
    public static function pourPeriode(string $from, string $to, ?\DateTimeImmutable $now = null, ?callable $fetchUrl = null, ?string $cacheDir = null): array
    {
        $now = ($now ?? new \DateTimeImmutable('now', new \DateTimeZone(self::TZ)))->setTimezone(new \DateTimeZone(self::TZ));

        try {
            [$fetch, $dir] = self::injecteurs($fetchUrl, $cacheDir);
            $publiee = EdtCalendar::plagePubliee($fetch, $dir);

            $mortes = [];
            $debutPub = $publiee['debut'];
            $finPub = $publiee['fin'];
            try {
                $cur = (new \DateTimeImmutable($from))->modify('monday this week');
                $fin = (new \DateTimeImmutable($to))->modify('monday this week');
            } catch (\Throwable) {
                $cur = null;
                $fin = null;
            }
            while ($cur !== null && $fin !== null && $cur <= $fin) {
                $lundi = $cur->format('Y-m-d');
                $vendredi = $cur->modify('+4 days')->format('Y-m-d');
                $s = EdtCalendar::semaineInfos($lundi, $fetch, $dir);
                $evaluable = $debutPub !== null && $finPub !== null
                    && strcmp($lundi, $debutPub) >= 0 && strcmp($vendredi, $finPub) <= 0;
                if (!$s['avec_cours'] && $evaluable) {
                    $mortes[] = [
                        'lundi'        => $lundi,
                        'vendredi'     => $vendredi,
                        'debut_fr'     => self::frCourt($lundi),
                        'fin_fr'       => self::frCourt($vendredi),
                        'label'        => (string) $s['label_auto'],
                        'hors_horizon' => false,
                    ];
                }
                $cur = $cur->modify('+7 days');
            }

            $aujourdhui = $now->format('Y-m-d');
            $jour = EdtCalendar::jourInfos($aujourdhui, $fetch, $dir);
            $prochain = EdtCalendar::prochainJourCours($aujourdhui, $fetch, $dir);

            return [
                'plage_publiee' => ['debut' => $debutPub, 'fin' => $finPub, 'stale' => (bool) $publiee['stale']],
                'semaines_mortes' => $mortes,
                'journee' => [
                    'date'              => $aujourdhui,
                    'cours'             => (bool) $jour['cours'],
                    'creneaux'          => (int) $jour['creneaux'],
                    'prochain_cours'    => $prochain,
                    'prochain_cours_fr' => $prochain !== null ? self::frLong($prochain) : null,
                ],
            ];
        } catch (\Throwable) {
            return [
                'plage_publiee'   => ['debut' => null, 'fin' => null, 'stale' => true],
                'semaines_mortes' => [],
                'journee'         => [
                    'date'              => $now->format('Y-m-d'),
                    'cours'             => false,
                    'creneaux'          => 0,
                    'prochain_cours'    => null,
                    'prochain_cours_fr' => null,
                ],
            ];
        }
    }

    /**
     * Ligne de contexte FACTUELLE du récap quotidien (kiosque « Récap du
     * jour » + SMS existant) : émise quand la semaine EN COURS est morte
     * (publiée sans cours) ou que le prochain jour de cours n'est pas
     * demain. Formulation dérivée des données, jamais de nom de vacances
     * en dur. Retourne null (aucune ligne) en semaine normale.
     */
    public static function ligneRecap(\DateTimeImmutable $now, ?callable $fetchUrl = null, ?string $cacheDir = null): ?string
    {
        $now = $now->setTimezone(new \DateTimeZone(self::TZ));

        try {
            [$fetch, $dir] = self::injecteurs($fetchUrl, $cacheDir);
            $aujourdhui = $now->format('Y-m-d');
            $demain = $now->modify('+1 day')->format('Y-m-d');

            $s = EdtCalendar::semaineInfos($now->modify('monday this week')->format('Y-m-d'), $fetch, $dir);
            $finPub = EdtCalendar::plagePubliee($fetch, $dir)['fin'];
            $morte = !$s['avec_cours'] && self::facteurSemaine($s, $finPub) <= 0.0;
            $prochain = EdtCalendar::prochainJourCours($aujourdhui, $fetch, $dir);

            if ($morte) {
                return $prochain !== null
                    ? sprintf('Vacances universitaires : reprise des cours le %s.', self::frCourt($prochain))
                    : 'Vacances universitaires : aucune reprise publiée pour l\'instant.';
            }
            if ($prochain !== null && strcmp($prochain, $demain) > 0) {
                return sprintf('Prochain jour de cours : %s.', self::frLong($prochain));
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * POLITIQUE PURE par semaine (testable sans calendrier) :
     *  - hors horizon OU non entièrement publiée → 1.0 (prudence : des
     *    données absentes ne sont pas des vacances) ;
     *  - semaine entièrement publiée sans cours → 0.0 (100 % morte) ;
     *  - semaine entièrement publiée → jours_actifs / 5 (prorata).
     *
     * @param array<string,mixed> $semaine     Sortie de EdtCalendar::semaineInfos.
     * @param string|null         $finPubliee  Dernier jour publié (fermetures comprises).
     */
    public static function facteurSemaine(array $semaine, ?string $finPubliee): float
    {
        if ((bool) ($semaine['hors_horizon'] ?? false)) {
            return 1.0;
        }
        try {
            $vendredi = (new \DateTimeImmutable((string) ($semaine['lundi'] ?? '')))->modify('+4 days')->format('Y-m-d');
        } catch (\Throwable) {
            return 1.0; // lundi illisible : prudent, pas de modulation.
        }
        if ($finPubliee === null || strcmp($vendredi, $finPubliee) > 0) {
            return 1.0; // semaine non entièrement publiée.
        }
        $actifs = (int) ($semaine['jours_actifs'] ?? 0);
        if ($actifs <= 0) {
            return 0.0;
        }

        return min(1.0, $actifs / self::JOURS_OUVRES_SEMAINE);
    }

    /**
     * Libellé humain du facteur appliqué : une ligne par semaine non
     * neutre PESANT dans l'horizon (poids > 0 — une semaine morte déjà
     * passée ne dit plus rien), verbe final selon le facteur global.
     * Null si aucune modulation (facteur 1.0, rien à dire).
     *
     * @param list<array<string,mixed>> $semaines
     */
    private static function explication(array $semaines, float $facteur): ?string
    {
        $parts = [];
        foreach ($semaines as $s) {
            if ((int) ($s['poids'] ?? 0) <= 0) {
                continue; // hors de l'horizon pondéré : rien à dire.
            }
            $f = (float) $s['facteur'];
            if ($f <= 0.0) {
                $parts[] = sprintf('Semaine sans cours du %s au %s', $s['debut_fr'], $s['fin_fr']);
            } elseif ($f < 1.0) {
                $parts[] = sprintf(
                    'Semaine partielle du %s au %s (%d jour%s de cours sur %d)',
                    $s['debut_fr'],
                    $s['fin_fr'],
                    (int) $s['jours_actifs'],
                    (int) $s['jours_actifs'] > 1 ? 's' : '',
                    self::JOURS_OUVRES_SEMAINE
                );
            }
        }
        if ($parts === []) {
            return null;
        }
        if ($facteur <= 0.0) {
            return implode(' ; ', $parts) . ' : suggestions annulées';
        }

        return implode(' ; ', $parts) . sprintf(' : suggestions réduites ×%s', self::frNombre($facteur));
    }

    /**
     * @param array<string,mixed> $s Sortie de EdtCalendar::semaineInfos.
     *
     * @return array{lundi:string, vendredi:string, debut_fr:string, fin_fr:string,
     *               label:string, avec_cours:bool, jours_actifs:int, hors_horizon:bool,
     *               facteur:float, poids:int}
     */
    private static function entree(array $s, float $f, int $poids): array
    {
        $lundi = (string) $s['lundi'];
        $vendredi = (new \DateTimeImmutable($lundi))->modify('+4 days')->format('Y-m-d');

        return [
            'lundi'        => $lundi,
            'vendredi'     => $vendredi,
            'debut_fr'     => self::frCourt($lundi),
            'fin_fr'       => self::frCourt($vendredi),
            'label'        => (string) $s['label_auto'],
            'avec_cours'   => (bool) $s['avec_cours'],
            'jours_actifs' => (int) $s['jours_actifs'],
            'hors_horizon' => (bool) $s['hors_horizon'],
            'facteur'      => $f,
            'poids'        => $poids,
        ];
    }

    /** Jours ouvrés (lun-ven) de la semaine $lundi dans [$premier ; $dernier]
     * (comparaison de DATES Europe/Paris — jamais d'instants mixtes UTC/
     * Paris, le vendredi de bord serait sinon exclu par un décalage d'h). */
    private static function nbOuvresDans(string $lundi, \DateTimeImmutable $premier, \DateTimeImmutable $dernier): int
    {
        $tz = new \DateTimeZone(self::TZ);
        $p = $premier->setTimezone($tz)->format('Y-m-d');
        $d = $dernier->setTimezone($tz)->format('Y-m-d');
        $n = 0;
        for ($i = 0; $i < self::JOURS_OUVRES_SEMAINE; $i++) {
            $j = (new \DateTimeImmutable($lundi))->modify('+' . $i . ' days')->format('Y-m-d');
            if (strcmp($j, $p) >= 0 && strcmp($j, $d) <= 0) {
                $n++;
            }
        }

        return $n;
    }

    /** « 26/10 » */
    private static function frCourt(string $date): string
    {
        try {
            return (new \DateTimeImmutable($date))->format('d/m');
        } catch (\Throwable) {
            return $date;
        }
    }

    /** « mar. 03/11 » */
    private static function frLong(string $date): string
    {
        try {
            $d = new \DateTimeImmutable($date);

            return ['lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'][(int) $d->format('N') - 1] . ' ' . $d->format('d/m');
        } catch (\Throwable) {
            return $date;
        }
    }

    /** « 0,6 » (virgule française, 2 décimales max). */
    private static function frNombre(float $x): string
    {
        return rtrim(rtrim(number_format($x, 2, ',', ' '), '0'), ',');
    }

    /**
     * @return array{facteur:float, explication:null, semaines:list<never>, jours_ouvrables:int, jours_sans_cours:int, stale:bool}
     */
    private static function neutre(): array
    {
        return ['facteur' => 1.0, 'explication' => null, 'semaines' => [], 'jours_ouvrables' => 0, 'jours_sans_cours' => 0, 'stale' => true];
    }

    /**
     * Injecteurs par défaut : explicites (tests ciblés) sinon, en contexte
     * APP_TESTING, calendrier vide HORS-LIGNE (fetch → null, cache sous
     * tests/cache) — une suite de tests ne fait JAMAIS d'appel réseau et
     * obtient un contexte neutre déterministe. En production : défauts
     * EdtCalendar (curl + cache/edt).
     *
     * PUBLIC (partagé) : tout consommateur des données EDT côté requête
     * (CalendarContext, AssistantToolsController::edtIut) récupère ses
     * injecteurs ICI — la politique « hors-ligne en test, défauts en
     * production » n'est définie qu'à UN seul endroit.
     *
     * @return array{0:?callable(string):?string, 1:?string}
     */
    public static function injecteurs(?callable $fetchUrl, ?string $cacheDir): array
    {
        if ($fetchUrl !== null || $cacheDir !== null) {
            return [$fetchUrl, $cacheDir];
        }
        if (\defined('APP_TESTING') && \constant('APP_TESTING')) {
            return [static fn (string $url): ?string => null, AEIC_ROOT . '/tests/cache/edt'];
        }

        return [null, null];
    }
}
