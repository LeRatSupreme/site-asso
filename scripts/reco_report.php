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
use App\Core\Compta\Reco\RecommendationEngine;
use App\Core\Compta\Reco\Scoring;
use App\Models\Recommendation;
use App\Models\RecoData;

// ── Options CLI ──────────────────────────────────────────────────────────────
$opts = getopt('', ['json', 'statuts', 'fenetre::', 'limit::']);
$asJson = array_key_exists('json', $opts);
$avecStatuts = array_key_exists('statuts', $opts);
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

    // ── Sortie ───────────────────────────────────────────────────────────────
    if ($asJson) {
        echo json_encode($rapport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
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

    exit(0);
} catch (PDOException $e) {
    fwrite(STDERR, 'ERREUR : base indisponible (config.env ou MySQL : ' . $e->getMessage() . ")\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERREUR : rapport des recommandations impossible — ' . $e->getMessage() . "\n");
    exit(1);
}
