<?php

declare(strict_types=1);

/**
 * AEIC — Rapport des idées de recommandations sur les données réelles (CLI, LECTURE SEULE).
 *
 * Objet : générer les idées du moteur de recommandations (R1–R11) sur les
 * données RÉELLES de la base — le dump AVANT/APRÈS demandé par la mission
 * (état des idées avant intégration, puis vérification après branchement du
 * contrôleur). Le moteur est pur : ce script se contente d'assembler le
 * dataset via RecoData::dataset(), d'appeler RecommendationEngine::recommend()
 * et d'afficher le résultat.
 *
 * Usage :
 *   php scripts/reco_report.php                       # rapport texte
 *   php scripts/reco_report.php --json                # même contenu en JSON
 *   php scripts/reco_report.php --diag                # ajoute la section « Diagnostic R5/R6/R7 » (stats descriptives)
 *   php scripts/reco_report.php --fenetre=30          # fenêtre d'analyse en jours (défaut 30)
 *   php scripts/reco_report.php --statuts             # ajoute les lignes de la table recommendations
 *   php scripts/reco_report.php --statuts --limit=20  # taille de l'historique affiché (défaut 20)
 *
 * Garanties LECTURE SEULE :
 *   - AUCUN INSERT / UPDATE / DELETE n'est émis (ni ici ni via le moteur,
 *     qui est pur et sans accès DB) ;
 *   - seules les lectures SQL `Recommendation::proposees()` et
 *     `Recommendation::historique()` sont appelées (SELECT), et UNIQUEMENT
 *     avec l'option --statuts ;
 *   - pas d'appel à Recommendation::upsert / decision / saveBaseline /
 *     saveEffet : les décisions humaines ne sont jamais touchées.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/database.php';

use App\Core\Compta\Reco\EffetMesure;
use App\Core\Compta\Reco\Hyper;
use App\Core\Compta\Reco\RecoContext;
use App\Core\Compta\Reco\RecoProduct;
use App\Core\Compta\Reco\RecommendationEngine;
use App\Core\Compta\Reco\Scoring;
use App\Models\Recommendation;
use App\Models\RecoData;

// ── Options CLI ──────────────────────────────────────────────────────────────
$opts = getopt('', ['json', 'statuts', 'diag', 'fenetre::', 'limit::']);
$asJson = array_key_exists('json', $opts);
$avecStatuts = array_key_exists('statuts', $opts);
$avecDiag = array_key_exists('diag', $opts);
$fenetre = max(1, (int) ($opts['fenetre'] ?? 30));
$limitHist = max(1, min(200, (int) ($opts['limit'] ?? 20)));

// ── L'intégration est-elle en place ? (contrat : RecoData::dataset) ─────────
if (!class_exists(RecoData::class)) {
    fwrite(STDERR, "RecoData indisponible — l'intégration est en cours.\n");
    exit(1);
}

$eur = static fn (float $v): string => number_format($v, 2, ',', '');

try {
    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Paris'));
    $todayKey = $now->format('Y-m-d');

    // Sonde de connexion (simple PDO::__construct, AUCUNE requête) : sans
    // base joignable, le rapport serait vide et trompeur — on échoue
    // proprement (« base indisponible ») au lieu d'imprimer des zéros.
    db();

    // Dataset réel + moteur (source unique). Aucune écriture.
    $data = RecoData::dataset($todayKey, $now, ['fenetre' => $fenetre]);
    $ds = $data['reco'];
    $res = RecommendationEngine::recommend($ds, $now);
    $meta = is_array($res['meta'] ?? null) ? $res['meta'] : [];
    $depuis = (string) ($data['fenetre']['from'] ?? $todayKey);
    $jusque = (string) ($data['fenetre']['to'] ?? $todayKey);

    // Idées : règle | titre | gain | confiance | effort | score | produits.
    $idees = [];
    foreach (($res['idees'] ?? []) as $r) {
        $idees[] = [
            'regle'     => $r->regle,
            'titre'     => $r->titre,
            'gain_hebdo' => [
                'bas'    => $r->gainHebdo['bas'],
                'median' => $r->gainHebdo['median'],
                'haut'   => $r->gainHebdo['haut'],
            ],
            'confiance' => $r->confiance,
            'effort'    => $r->effort,
            // Même formule que le classement du moteur (Scoring::score).
            'score'     => Scoring::score([
                'gain'      => ['median' => $r->gainHebdo['median']],
                'confiance' => $r->confiance,
                'effort'    => $r->effort,
            ]),
            'produits'  => array_map(static fn (array $p): string => $p['label'], $r->produits),
        ];
    }

    $rapport = [
        'genere_le' => $now->setTimezone(new DateTimeZone('Europe/Paris'))->format('Y-m-d H:i'),
        'creneau'   => ['from' => $depuis, 'to' => $jusque, 'jours' => $fenetre],
        'meta'      => [
            'jours_ouverts_fenetre'  => (int) ($meta['jours_ouverts_fenetre'] ?? 0),
            'historique_insuffisant' => (bool) ($meta['historique_insuffisant'] ?? false),
            'creuse_active'          => (bool) ($meta['creuse_active'] ?? false),
            'facteur_creuse'         => (float) ($meta['facteur_creuse'] ?? 1.0),
            'n_paniers'              => (int) ($meta['n_paniers'] ?? 0),
            'rejets'                 => (int) ($meta['rejets'] ?? 0),
        ],
        'idees'     => $idees,
    ];

    if ($avecStatuts) {
        $proposees = [];
        foreach (Recommendation::proposees() as $row) {
            $proposees[] = [
                'regle'      => (string) ($row['regle'] ?? ''),
                'titre'      => (string) ($row['titre'] ?? ''),
                'statut'     => (string) ($row['statut'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }

        $historique = [];
        foreach (array_slice(Recommendation::historique(), 0, $limitHist) as $row) {
            $effet = json_decode((string) ($row['effet_json'] ?? ''), true);
            $historique[] = [
                'regle'      => (string) ($row['regle'] ?? ''),
                'titre'      => (string) ($row['titre'] ?? ''),
                'applied_at' => (string) ($row['applied_at'] ?? ''),
                'conclusion' => $row['effet_conclusion'] !== null ? (string) $row['effet_conclusion'] : null,
                // Libellé FR officiel du cœur (« Effet mesuré +X € [a ; b] », « Non concluant… »).
                'effet'      => is_array($effet) ? EffetMesure::libelle($effet) : null,
            ];
        }

        $rapport['statuts'] = ['proposees' => $proposees, 'historique' => $historique];
    }

    // ── Diagnostic R5/R6/R7 (--diag) : stats descriptives, LECTURE SEULE ──────
    // Toutes les valeurs proviennent du dataset/moteur existants (aucune
    // logique métier nouvelle, aucune écriture) : portes des règles,
    // co-occurrences, parts horaires, relance par règle du moteur.
    $diag = [];
    if ($avecDiag) {
        $ctxD = new RecoContext($ds, $now);
        $paniersD = $ds->paniers;
        $nD = count($paniersD);
        $pctD = static fn (float $p, int $dec = 1): string => number_format($p * 100.0, $dec, ',', '');
        $nb2D = static fn (float $v, int $dec = 2): string => number_format($v, $dec, ',', '');
        $truncD = static fn (string $s, int $t = 30): string => mb_strlen($s) > $t ? mb_substr($s, 0, $t - 1) . '…' : $s;
        $coutTxt = static function (RecoProduct $p): string {
            if ($p->coutUtilisable()) {
                return 'coût ' . number_format((float) $p->cout, 2, ',', '') . ' € / prix ' . number_format((float) $p->prix, 2, ',', '') . ' €';
            }

            return 'coût inconnu [' . ($p->coutRaison ?? ($p->prix === null ? 'prix_inconnu' : 'non_eligible')) . ']';
        };

        // Produits filtrés (comme les règles) + éligibles à coût utilisable.
        $prodFD = $ctxD->produitsFiltres();
        $parCleD = [];
        $eligD = [];
        foreach ($prodFD as $p) {
            $parCleD[$p->key] = $p;
            if ($p->coutUtilisable()) {
                $eligD[$p->key] = $p;
            }
        }

        // Paniers : 1 ligne / multi / distribution.
        $uneLigneD = 0;
        $distD = ['1' => 0, '2' => 0, '3+' => 0];
        foreach ($paniersD as $b) {
            $mL = count($b->lignes);
            if ($mL === 1) {
                ++$uneLigneD;
                ++$distD['1'];
            } elseif ($mL === 2) {
                ++$distD['2'];
            } else {
                ++$distD['3+'];
            }
        }
        $multiD = $ctxD->paniersMulti();

        // Co-occurrences par paire (même comptage que R5, sans filtre de coût).
        $nbPD = [];
        $coD = [];
        foreach ($paniersD as $b) {
            $cles = [];
            foreach ($b->lignes as $l) {
                $k = $l['key'] ?? null;
                if (is_string($k) && isset($parCleD[$k])) {
                    $cles[$k] = true;
                }
            }
            $cles = array_keys($cles);
            sort($cles);
            foreach ($cles as $k) {
                $nbPD[$k] = ($nbPD[$k] ?? 0) + 1;
            }
            $mC = count($cles);
            for ($i = 0; $i < $mC; ++$i) {
                for ($j = $i + 1; $j < $mC; ++$j) {
                    $pkD = $cles[$i] . '|' . $cles[$j];
                    $coD[$pkD] = ($coD[$pkD] ?? 0) + 1;
                }
            }
        }
        arsort($coD);

        $statsD = [];
        foreach ($coD as $paire => $nbc) {
            [$aP, $bP] = explode('|', $paire);
            $naP = $nbPD[$aP] ?? 0;
            $nP2 = $nbPD[$bP] ?? 0;
            if ($nD === 0 || $naP === 0 || $nP2 === 0) {
                continue;
            }
            $supD = $nbc / $nD;
            $confD = $nbc / $naP;
            $statsD[] = ['a' => $aP, 'b' => $bP, 'co' => $nbc, 'support' => $supD, 'conf' => $confD, 'lift' => $confD / ($nP2 / $nD)];
        }
        usort($statsD, static fn (array $x, array $y): int => $y['co'] <=> $x['co']);

        // Verdicts R5 : seuils FORTE vs VARIANTE (lus depuis Hyper via les
        // MÊMES clés de surcharge que R5Duos — plus de valeurs hardcodées
        // pré-itération-2), blocages coût.
        $liftFD = (float) $ctxD->param('assoc_lift_min', Hyper::ASSOC_LIFT_MIN);
        $supportFD = (float) $ctxD->param('assoc_support_min', Hyper::ASSOC_SUPPORT_MIN);
        $confFD = (float) $ctxD->param('assoc_conf_min', Hyper::ASSOC_CONF_MIN);
        $liftVD = (float) $ctxD->param('r5_lift_variante_min', Hyper::R5_LIFT_VARIANTE_MIN);
        $supportVD = (float) $ctxD->param('r5_support_variante_min', Hyper::R5_SUPPORT_VARIANTE_MIN);
        $confVD = (float) $ctxD->param('r5_conf_variante_min', Hyper::R5_CONF_VARIANTE_MIN);
        $part1VD = (float) $ctxD->param('r6_part1_variante_min', Hyper::R6_PART1_VARIANTE_MIN);
        $passCourantD = 0;
        $passAssoupliD = 0;
        $nonEligAssoupliD = 0;
        $bloqueCoutInconnuD = 0;
        foreach ($statsD as $sp) {
            $bothE = isset($eligD[$sp['a']], $eligD[$sp['b']]);
            if ($sp['lift'] > $liftFD && $sp['support'] >= $supportFD && $sp['conf'] >= $confFD && $bothE) {
                ++$passCourantD;
            }
            if ($sp['lift'] > $liftVD && $sp['support'] >= $supportVD && $sp['conf'] >= $confVD) {
                ++$passAssoupliD;
                if (!$bothE) {
                    ++$nonEligAssoupliD;
                    $raisons = [];
                    foreach ([$sp['a'], $sp['b']] as $kP) {
                        if (!$parCleD[$kP]->coutUtilisable()) {
                            $raisons[] = $parCleD[$kP]->coutRaison ?? 'prix_inconnu';
                        }
                    }
                    if (!in_array('cout_zero', $raisons, true)
                        && !in_array('prix_inferieur_au_cout', $raisons, true)
                        && !in_array('prix_inconnu', $raisons, true)) {
                        ++$bloqueCoutInconnuD;
                    }
                }
            }
        }

        // Top paire à coûts utilisables + paniers multi qui la contiennent.
        $topPairElig = null;
        foreach ($coD as $paire => $nbc) {
            [$aP, $bP] = explode('|', $paire);
            if (isset($eligD[$aP], $eligD[$bP])) {
                $topPairElig = [$aP, $bP, $nbc];
                break;
            }
        }
        $multiTopPair = 0;
        if ($topPairElig !== null) {
            foreach ($multiD as $b) {
                $ks = array_column($b->lignes, 'key');
                if (in_array($topPairElig[0], $ks, true) && in_array($topPairElig[1], $ks, true)) {
                    ++$multiTopPair;
                }
            }
        }

        $diag[] = '-- Paniers --';
        $diag[] = 'total : ' . $nD . ' · à 1 ligne : ' . $uneLigneD . ' (' . ($nD > 0 ? $pctD($uneLigneD / $nD) : '—') . ' %)'
            . ' · multi (≥2 lignes) : ' . count($multiD) . ' (seuil R5 chemin associations : ≥ 30) '
            . '→ porte paniers-multi R5 : ' . (count($multiD) >= (int) $ctxD->param('assoc_paniers_min', Hyper::ASSOC_PANIERS_MIN) ? 'PASSÉE' : 'NON PASSÉE');
        $diag[] = 'multi où les 2 produits du top co-occurrence (coût utilisable) figurent : ' . $multiTopPair
            . ($topPairElig !== null
                ? ' — paire « ' . $truncD($parCleD[$topPairElig[0]]->label) . ' » + « ' . $truncD($parCleD[$topPairElig[1]]->label) . ' » (' . $topPairElig[2] . ' paniers communs)'
                : ' — aucune paire éligible à coûts utilisables');

        $diag[] = '-- R5 (duos) : top 10 paires co-occurrentes --';
        foreach (array_slice($statsD, 0, 10) as $sp) {
            $pa = $parCleD[$sp['a']];
            $pb = $parCleD[$sp['b']];
            $diag[] = '  ' . $truncD($pa->label) . ' + ' . $truncD($pb->label)
                . ' | communs ' . $sp['co']
                . ' | support ' . $pctD($sp['support']) . ' %'
                . ' | confiance ' . $pctD($sp['conf']) . ' %'
                . ' | lift ' . $nb2D($sp['lift'])
                . ' | A : ' . $coutTxt($pa)
                . ' | B : ' . $coutTxt($pb);
        }
        $diag[] = 'verdict : ' . $passCourantD . ' paire(s) passent les seuils FORTE (lift>' . $nb2D($liftFD)
            . ' · support≥' . $pctD($supportFD) . ' % · confiance≥' . $pctD($confFD) . ' % · 2 coûts utilisables)';
        $diag[] = 'seuils VARIANTES (lift>' . $nb2D($liftVD) . ' · support≥' . $pctD($supportVD)
            . ' % · confiance≥' . $pctD($confVD) . ' %, coût inconnu toléré) : ' . $passAssoupliD . ' paire(s)';
        $diag[] = 'paires assouplissables à coût non utilisable : ' . $nonEligAssoupliD . ' · dont bloquées par le SEUL coût inconnu : ' . $bloqueCoutInconnuD;

        $diag[] = '-- R6 (seuil cadeau) --';
        $seuil1D = (float) $ctxD->param('panier_1article_seuil', Hyper::PANIER_1ARTICLE_SEUIL);
        $part1D = $nD > 0 ? $uneLigneD / $nD : 0.0;
        $cadeauMaxD = (float) $ctxD->param('cout_cadeau_max', Hyper::COUT_CADEAU_MAX);
        $cadeauxD = [];
        foreach ($prodFD as $p) {
            if ($p->cout === null || $p->cout > $cadeauMaxD) {
                continue;
            }
            if (!($p->stock === null || $p->stock > 10)) {
                continue;
            }
            $cadeauxD[] = $p;
        }
        usort($cadeauxD, static fn (RecoProduct $x, RecoProduct $y): int => (float) $x->cout <=> (float) $y->cout);
        $cadeauxOkD = count(array_filter($cadeauxD, static fn (RecoProduct $p): bool => $p->coutUtilisable()));
        $diag[] = 'part réelle paniers 1 ligne : ' . $pctD($part1D) . ' % vs seuils forte > ' . $pctD($seuil1D)
            . ' % / variante > ' . $pctD($part1VD) . ' % → porte : '
            . ($part1D > $seuil1D ? 'FORTE PASSÉE' : ($part1D > $part1VD ? 'VARIANTE PASSÉE' : 'NON PASSÉE'));
        $diag[] = 'candidats cadeaux (coût ≤ ' . $nb2D($cadeauMaxD) . ' €, stock > 10 ou non géré) : ' . count($cadeauxD)
            . ' · dont coût utilisable (garde-fou 2) : ' . $cadeauxOkD;
        foreach (array_slice($cadeauxD, 0, 3) as $cg) {
            $diag[] = '  cadeau : ' . $truncD($cg->label) . ' | coût ' . $nb2D((float) $cg->cout) . ' €'
                . ' | stock ' . ($cg->stock === null ? 'non géré' : (string) $cg->stock)
                . ($cg->coutUtilisable() ? '' : ' — rejet garde-fou 2 (coût suspect)');
        }
        $diag[] = 'distribution lignes/panier : 1 → ' . $distD['1'] . ' · 2 → ' . $distD['2'] . ' · 3+ → ' . $distD['3+'];

        $diag[] = '-- R7 (happy hour) --';
        $sH = $ds->forecast['horaire']['s'] ?? null;
        $partsD = $ctxD->partsHoraires();
        if (!is_array($sH) || $sH === []) {
            $diag[] = 'forecast[horaire][s] ABSENT → porte non passée (aucune part horaire)';
        } elseif ($partsD === null) {
            $diag[] = 'forecast[horaire][s] présent mais PLAT (somme des parts ≤ 0) → porte non passée';
        } else {
            $posD = array_values(array_filter($partsD, static fn ($v): bool => (float) $v > 0.0));
            $moyD = $posD !== [] ? array_sum($posD) / count($posD) : 0.0;
            $creuxD = 0;
            $picsD = 0;
            foreach ($partsD as $hD => $pD) {
                if ((float) $pD < 0.4 * $moyD) {
                    ++$creuxD;
                }
                if ((float) $pD > 1.5 * $moyD) {
                    ++$picsD;
                }
            }
            $triD = $partsD;
            asort($triD);
            $plusBas = array_slice($triD, 0, 3, true);
            $plusHaut = array_slice($triD, -3, 3, true);
            krsort($plusHaut);
            $fmtH = static fn (array $m): string => implode(' · ', array_map(
                static fn ($h, $v): string => $h . 'h → ' . number_format((float) $v * 100.0, 2, ',', '') . ' %',
                array_keys($m),
                array_values($m)
            ));
            $ecartD = (float) max($partsD) - (float) min($partsD);
            $diag[] = 'heures : ' . count($partsD) . ' · moyenne parts positives : ' . number_format($moyD * 100.0, 2, ',', '') . ' %';
            $diag[] = 'creux (<0,4×moy) : ' . $creuxD . ' · pics (>1,5×moy) : ' . $picsD
                . ' → porte creux+pic : ' . ($creuxD > 0 && $picsD > 0 ? 'PASSÉE' : 'NON PASSÉE');
            $diag[] = '3 parts les plus faibles : ' . $fmtH($plusBas);
            $diag[] = '3 parts les plus fortes : ' . $fmtH($plusHaut);
            $diag[] = 'écart max parts : ' . number_format($ecartD * 100.0, 2, ',', '') . ' points → S(h) ' . ($ecartD <= 0.0 ? 'PLAT' : 'non plat');
        }
        $diag[] = 'produits à coût utilisable (porte produits de R7, ≥1 requis) : ' . count($eligD);

        $diag[] = '-- Candidats écartés (relance moteur PAR RÈGLE = variante sans cap global) --';
        $maxIdeesD = (int) $ctxD->param('max_idees', Hyper::MAX_IDEES);
        $maxCorrectionsD = (int) $ctxD->param('max_corrections', Hyper::MAX_CORRECTIONS);
        $diag[] = 'note : relance PAR RÈGLE, caps blocs courants reco_max_idees=' . $maxIdeesD
            . ' / reco_max_corrections=' . $maxCorrectionsD . ' (MAX_ACTIVES obsolète, DIVERSITÉ ≤ '
            . Hyper::IDEES_MAX_PAR_REGLE . ' idées par règle) ; rejets = garde-fous.';
        $joD = (int) $ds->joursOuvertsFenetre;
        $diag[] = 'jours ouverts fenêtre : ' . $joD . ' → ' . ($joD < Hyper::HIST_MIN_JOURS_OUVERTS
            ? 'historique INSUFFISANT : le moteur n\'évalue que R2/R8/R11 — R5/R6/R7 JAMAIS évaluées'
            : 'historique suffisant (toutes règles évaluées)');
        foreach (['R1', 'R2', 'R3', 'R4', 'R5', 'R6', 'R7', 'R8', 'R9', 'R11'] as $rD) {
            $runD = RecommendationEngine::recommend($ds, $now, ['regles' => [$rD]]);
            $diag[] = '  ' . $rD . ' : ' . count($runD['idees']) . ' idée(s) retenue(s) · '
                . (int) ($runD['meta']['rejets'] ?? 0) . ' candidat(s) rejeté(s) (garde-fous)';
        }
    }

    $printDiag = static function () use ($diag): void {
        echo "\n=== Diagnostic R5/R6/R7 ===\n";
        foreach ($diag as $ligneD) {
            echo $ligneD, "\n";
        }
    };

    // ── Sortie ───────────────────────────────────────────────────────────────
    if ($asJson) {
        echo json_encode($rapport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
        if ($avecDiag) {
            $printDiag();
        }
        exit(0);
    }

    echo '=== Idées de recommandations — AEIC (générées le ' . $rapport['genere_le'] . ' Paris) ===', "\n";
    echo 'Créneau analysé : ' . $depuis . ' → ' . $jusque
        . ' (' . $fenetre . ' j)'
        . ' · ' . $rapport['meta']['jours_ouverts_fenetre'] . ' jours ouverts'
        . ' · historique insuffisant : ' . ($rapport['meta']['historique_insuffisant'] ? 'oui' : 'non')
        . ' · période creuse : ' . ($rapport['meta']['creuse_active'] ? 'oui' : 'non')
        . ' (facteur ' . $rapport['meta']['facteur_creuse'] . ')'
        . ' · paniers : ' . $rapport['meta']['n_paniers']
        . ' · candidats rejetés par les garde-fous : ' . $rapport['meta']['rejets']
        . "\n\n";

    if ($idees === []) {
        echo "— Aucune idée au-dessus des garde-fous pour ce créneau.\n";
    }
    $i = 0;
    foreach ($idees as $idee) {
        ++$i;
        echo $i . ') ' . $idee['regle']
            . ' | ' . $idee['titre']
            . ' | gain ' . $eur($idee['gain_hebdo']['bas'])
            . '–' . $eur($idee['gain_hebdo']['median'])
            . '–' . $eur($idee['gain_hebdo']['haut']) . ' €/sem'
            . ' | confiance ' . $idee['confiance']
            . ' | effort ' . $idee['effort']
            . ' | score ' . $eur($idee['score'])
            . ' | produits : ' . ($idee['produits'] === [] ? '—' : implode(', ', $idee['produits']))
            . "\n";
    }

    if ($avecStatuts) {
        echo "\n== Idées proposées (en attente de décision) ==\n";
        if (($rapport['statuts']['proposees'] ?? []) === []) {
            echo "— aucune.\n";
        }
        foreach ($rapport['statuts']['proposees'] as $p) {
            echo '- [' . $p['regle'] . '] ' . $p['titre']
                . ' (' . $p['statut'] . ' le ' . $p['created_at'] . ")\n";
        }
        echo "\n== Applications (historique + effets, max " . $limitHist . ") ==\n";
        if (($rapport['statuts']['historique'] ?? []) === []) {
            echo "— aucune.\n";
        }
        foreach ($rapport['statuts']['historique'] as $h) {
            echo '- [' . $h['regle'] . '] ' . $h['titre']
                . ' — appliquée le ' . $h['applied_at']
                . ' · effet : ' . ($h['effet'] ?? 'pas encore mesuré')
                . ($h['conclusion'] !== null ? ' (' . $h['conclusion'] . ')' : '')
                . "\n";
        }
    }

    if ($avecDiag) {
        $printDiag();
    }

    exit(0);
} catch (PDOException $e) {
    fwrite(STDERR, 'ERREUR : base indisponible (config.env ou MySQL : ' . $e->getMessage() . ")\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERREUR : rapport des recommandations impossible — ' . $e->getMessage() . "\n");
    exit(1);
}
