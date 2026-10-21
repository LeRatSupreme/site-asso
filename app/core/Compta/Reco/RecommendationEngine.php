<?php

declare(strict_types=1);

namespace App\Core\Compta\Reco;

/**
 * Moteur de recommandations — SOURCE UNIQUE du cœur pur : enchaîne les
 * règles R1..R9 + R11 (R10 étant ASSEMBLÉ à part via R10Objectif quand
 * l'objectif hebdomadaire est en risque), valide chaque candidat via
 * GuardRails, classe via Scoring et rend des Recommendation prêtes à
 * l'affichage, plus une baseline d'EFFET MESURÉ à appeler par le
 * contrôleur à l'application. Aucun accès DB, aucune horloge externe,
 * déterministe : la même entrée produit toujours la même sortie.
 */
final class RecommendationEngine
{
    /** Règles évaluées par défaut — historique suffisant (R10 assemblé séparément). */
    private const REGLES_COMPLETES = ['R1', 'R2', 'R3', 'R4', 'R5', 'R6', 'R7', 'R8', 'R9', 'R11'];

    /** Règles limitées — historique insuffisant (< Hyper::HIST_MIN_JOURS_OUVERTS). */
    private const REGLES_HISTORIQUE_COURT = ['R2', 'R8', 'R11'];

    /** Règles sources éligibles à l'assemblage R10. */
    private const REGLES_SOURCES_R10 = ['R1', 'R4', 'R5', 'R6'];

    /** Classe de chaque règle évaluable (R10 exclu : utilitaire sans evaluer()). */
    private const CLASSES = [
        'R1'  => R1StockDormant::class,
        'R2'  => R2Rupture::class,
        'R3'  => R3MargeFaible::class,
        'R4'  => R4MargeSousVendue::class,
        'R5'  => R5Duos::class,
        'R6'  => R6Panier::class,
        'R7'  => R7Rythme::class,
        'R8'  => R8Gamme::class,
        'R9'  => R9Tendance::class,
        'R11' => R11QualiteDonnees::class,
    ];

    /**
     * Produit la liste de recommandations du jour.
     *
     * @param RecoDataset                      $data Instantané pur des données de la fenêtre.
     * @param \DateTimeImmutable               $now  Instant de référence (fingerprint/refus, horodatage).
     * @param array{parametres?:array<string,mixed>,regles?:list<string>} $opts
     *         'parametres' : surcharges « reco_<nom> » fusionnées DANS le dataset ;
     *         'regles' : restriction de la liste des règles évaluées (tests).
     *
     * @return array{idees:list<Recommendation>,meta:array<string,mixed>} meta : audit complet
     *         (creuse, historique, rejets, paramètres résolus, horodatage Europe/Paris).
     */
    public static function recommend(RecoDataset $data, \DateTimeImmutable $now, array $opts = []): array
    {
        // 0. Paramètres : surcharges $opts['parametres'] fusionnées dans le dataset.
        $parametres = array_merge(
            $data->parametres,
            is_array($opts['parametres'] ?? null) ? $opts['parametres'] : []
        );
        $ds = new RecoDataset(
            $data->produits,
            $data->paniers,
            $data->qtyPrec,
            $data->facteurCreuse,
            $data->periodeCreuseActive,
            $data->joursOuvertsFenetre,
            $data->forecast,
            $data->historique,
            $parametres
        );
        $ctx = new RecoContext($ds, $now);
        $margeMin = (float) $ctx->param('marge_min', Hyper::MARGE_MIN);
        $probMin = (float) $ctx->param('prob_objectif_min', Hyper::PROB_OBJECTIF_MIN);

        $produitsParCle = [];
        foreach ($ds->produits as $p) {
            $produitsParCle[$p->key] = $p;
        }

        // 1. Règles actives : historique insuffisant → R2/R8/R11 seulement.
        $insuffisant = $ds->joursOuvertsFenetre < Hyper::HIST_MIN_JOURS_OUVERTS;
        $actives = $insuffisant ? self::REGLES_HISTORIQUE_COURT : self::REGLES_COMPLETES;
        $demandees = is_array($opts['regles'] ?? null) ? array_values(array_filter($opts['regles'], 'is_string')) : null;
        $actives = $demandees === null ? $actives : array_values(array_intersect($actives, $demandees));

        // 2. Évaluation + garde-fous (rejets silencieux, comptés).
        $candidats = [];
        $rejets = 0;
        foreach ($actives as $regle) {
            $classe = self::CLASSES[$regle] ?? null;
            if ($classe === null) {
                continue;
            }
            foreach ($classe::evaluer($ctx) as $c) {
                if (!is_array($c)) {
                    $rejets++;
                    continue;
                }
                $keys = is_array($c['keys'] ?? null) ? array_values(array_filter($c['keys'], 'is_string')) : [];
                if ($keys === [] || !is_string($c['regle'] ?? null)) {
                    $rejets++;
                    continue;
                }
                $c['fingerprint'] = $ctx->fingerprint($c['regle'], $keys);
                if (GuardRails::candidatValide($c, $produitsParCle, $margeMin)) {
                    $candidats[] = $c;
                } else {
                    $rejets++;
                }
            }
        }

        // 3. R10 assemblé : objectif hebdo en risque, sur les sources R1/R4/R5/R6
        //    DÉJÀ VALIDÉES — ajouté aux candidats AVANT la coupe finale.
        $semaine = $ds->forecast['semaine'] ?? null;
        if (is_array($semaine)) {
            $sources = array_values(array_filter(
                $candidats,
                static fn (array $c): bool => in_array($c['regle'], self::REGLES_SOURCES_R10, true)
            ));
            $r10 = R10Objectif::construire(
                $semaine,
                $sources,
                static fn (string $r, array $k): string => $ctx->fingerprint($r, $k),
                $probMin
            );
            if ($r10 !== null) {
                if (GuardRails::candidatValide($r10, $produitsParCle, $margeMin)) {
                    $candidats[] = $r10;
                } else {
                    $rejets++;
                }
            }
        }

        // 4. Coupe finale : historique (actifs/refus), tri, dédoublonnage, cap.
        $retenus = Scoring::classe($candidats, $ds->historique, $now);

        // 5. Mapping en Recommendation (garde-fous déjà passés → garde_fous_ok = true).
        $idees = [];
        foreach ($retenus as $c) {
            $gain = is_array($c['gain'] ?? null) ? $c['gain'] : [];
            $produits = [];
            foreach ((array) ($c['produits'] ?? []) as $pr) {
                if (is_array($pr) && is_string($pr['key'] ?? null)) {
                    $produits[] = ['key' => $pr['key'], 'label' => (string) ($pr['label'] ?? '')];
                }
            }
            $idees[] = new Recommendation(
                id: (string) ($c['fingerprint'] ?? $ctx->fingerprint((string) $c['regle'], (array) $c['keys'])),
                regle: (string) $c['regle'],
                titre: (string) ($c['titre'] ?? ''),
                pourquoi: (string) ($c['pourquoi'] ?? ''),
                action: (string) ($c['action'] ?? ''),
                gainHebdo: GuardRails::fourchette(
                    (float) ($gain['bas'] ?? 0.0),
                    (float) ($gain['median'] ?? 0.0),
                    (float) ($gain['haut'] ?? 0.0)
                ),
                confiance: (string) ($c['confiance'] ?? 'faible'),
                effort: (int) ($c['effort'] ?? 1),
                hypotheses: array_values(array_filter((array) ($c['hypotheses'] ?? []), 'is_string')),
                produits: $produits,
                gardeFousOk: true,
                commentMesurer: (string) ($c['comment_mesurer'] ?? ''),
                statut: 'proposée'
            );
        }

        $meta = [
            'creuse_active'          => $ds->periodeCreuseActive,
            'facteur_creuse'         => $ds->facteurCreuse,
            'historique_insuffisant' => $insuffisant,
            'jours_ouverts_fenetre'  => $ds->joursOuvertsFenetre,
            'n_paniers'              => $ctx->nPaniers(),
            'genere_le'              => $now->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d H:i'),
            'parametres'             => ['marge_min' => $margeMin, 'prob_objectif_min' => $probMin],
            'rejets'                 => $rejets,
        ];

        return ['idees' => $idees, 'meta' => $meta];
    }

    /**
     * Baseline d'EFFET MESURé d'une reco appliquée : CA attendu par jour
     * OUVERT à partir de $debut (les $joursOuverts premiers), pour les
     * produits de la reco. Aucun nouveau modèle : dowRatio = n(dow) ÷
     * moyenne(n des jours ouverts de forecast['jours_semaine'], repli 1.0
     * si absent/plat) ; attenduJour = Σ unités×prix ÷ 7 × dowRatio
     * (produits sans prix fiable exclus) ; lo/hi = mêmes ratios produits
     * (prevLo/prevHi ÷ attendu, pondérés par l'attendu). facteur = le
     * facteur de période creuse du dataset, consommé par EffetMesure.
     *
     * @param list<string>       $produitsKeys Clés des produits de la reco appliquée.
     * @param RecoDataset        $data         Dataset (forecast['jours_semaine'] + produits).
     * @param \DateTimeImmutable $debut        Premier jour (calendaire) de la période mesurée.
     * @param int                $joursOuverts Nombre de jours OUVERTS à produire.
     *
     * @return list<array{date:string,attendu:float,lo:float,hi:float,facteur:float}>
     */
    public static function baseline(
        array $produitsKeys,
        RecoDataset $data,
        \DateTimeImmutable $debut,
        int $joursOuverts = Hyper::EFFET_DECLANCHEMENT_JOURS_OUVERTS
    ): array {
        $joursOuverts = max(0, $joursOuverts);

        // Jours ouverts du modèle : dow => n (forecast['jours_semaine'], is_open).
        $ouverts = [];
        foreach ((array) ($data->forecast['jours_semaine'] ?? []) as $j) {
            if (is_array($j)
                && ($j['is_open'] ?? null) === true
                && is_numeric($j['dow'] ?? null)
                && is_numeric($j['n'] ?? null)) {
                $ouverts[(int) $j['dow']] = (float) $j['n'];
            }
        }
        if ($joursOuverts === 0 || $ouverts === []) {
            return [];
        }

        // Sommes produits : attendu (unités×prix) et bornes lo/hi price-scalées.
        $attenduProdTotal = 0.0;
        $loTotal = 0.0;
        $hiTotal = 0.0;
        foreach ($produitsKeys as $key) {
            if (!is_string($key)) {
                continue;
            }
            $p = null;
            foreach ($data->produits as $prod) {
                if ($prod instanceof RecoProduct && $prod->key === $key) {
                    $p = $prod;
                    break;
                }
            }
            if ($p === null || $p->prix === null || $p->prix <= 0.0) {
                continue; // sans prix fiable : exclu
            }
            $attendu = $p->unitesPrevues7j * $p->prix;
            if ($attendu <= 0.0) {
                continue;
            }
            $attenduProdTotal += $attendu;
            $loTotal += $p->prevLo * $p->prix;
            $hiTotal += $p->prevHi * $p->prix;
        }

        // Ratios agrégés (pondérés par l'attendu) ; repli lo=hi=attendu si ≤ 0.
        $moyenne = array_sum($ouverts) / count($ouverts);
        $ratioLo = $attenduProdTotal > 0.0 ? $loTotal / $attenduProdTotal : 0.0;
        $ratioHi = $attenduProdTotal > 0.0 ? $hiTotal / $attenduProdTotal : 0.0;

        $lignes = [];
        $d = $debut;
        while (count($lignes) < $joursOuverts) {
            $dow = (int) $d->format('N') - 1;
            if (isset($ouverts[$dow])) {
                $dowRatio = $moyenne > 0.0 ? $ouverts[$dow] / $moyenne : 1.0;
                $attenduJour = $attenduProdTotal / 7.0 * $dowRatio;
                $lo = $ratioLo > 0.0 ? $attenduJour * $ratioLo : $attenduJour;
                $hi = $ratioHi > 0.0 ? $attenduJour * $ratioHi : $attenduJour;
                $lignes[] = [
                    'date'    => $d->format('Y-m-d'),
                    'attendu' => $attenduJour,
                    'lo'      => $lo,
                    'hi'      => $hi,
                    'facteur' => $data->facteurCreuse,
                ];
            }
            $d = $d->modify('+1 day');
        }

        return $lignes;
    }
}
