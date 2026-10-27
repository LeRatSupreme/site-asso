<?php

declare(strict_types=1);

/**
 * AEIC — T1 : génération du jeu d'évaluation figé du rapprochement produits
 * (CLI, LECTURE SEULE sur la base).
 *
 * Spec : docs/ANALYSE_T1_RAPPROCHEMENT_PRODUITS.md (§3) — brief A1.
 *
 * Produit, en UNE commande reproductible (seed fixe 42), le dossier
 * data/eval_t1/ : univers.csv, positifs_semantiques.csv, positifs_casse.csv,
 * durs_negatifs.csv, negatifs.csv, split.json, residuels_a_qualifier.csv,
 * SHA256SUMS, manifeste_jeu.md + l'export anonymisé data/eval_t1/kaggle/
 * (protocole D3 : libellés seuls, PRÊT mais NON transmis).
 *
 * Usage :
 *   php scripts/t1_export_eval.php                        # seed 42
 *   php scripts/t1_export_eval.php --seed=42 --out=data/eval_t1 --negatifs=300
 *
 * Garanties :
 *   - LECTURE SEULE : uniquement des SELECT (aucun INSERT/UPDATE/DELETE ;
 *     la seule instruction non-SELECT émise est le « SET time_zone » de
 *     session posé par db() dans app/config/database.php — aucune donnée
 *     n'est écrite) ;
 *   - déterminisme : ORDER BY systématiques, tri des sorties, graine fixe
 *     (Fisher-Yates maison, cf. EvalDataset::melangeSeede) — deux exécutions
 *     produisent des SHA256SUMS identiques (les manifestes horodatés ne sont
 *     volontairement PAS hachés) ;
 *   - normalisation EXCLUSIVEMENT via StockPublic/AliasSuggester
 *     (règle anti-régression AGENTS.md), encapsulée dans
 *     App\Core\Compta\T1\EvalDataset ;
 *   - résiduels §1.3 (douteux non qualifiés) JAMAIS dans les fichiers de jeu.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/database.php';

use App\Core\Compta\T1\EvalDataset;

// ── Options CLI ──────────────────────────────────────────────────────────────
$opts = getopt('', ['seed::', 'out::', 'negatifs::']);
$seed = (int) ($opts['seed'] ?? EvalDataset::SEED_DEFAUT);
$nbNegatifs = max(0, (int) ($opts['negatifs'] ?? 300));
$outDir = (string) ($opts['out'] ?? AEIC_ROOT . '/data/eval_t1');
$outDir = rtrim($outDir, '/');
$kaggleDir = $outDir . '/kaggle';

/**
 * Paires résiduelles de la spec §1.3 (DOUBLES SUSPECTS non qualifiés) :
 * écartées du jeu figé, listées dans residuels_a_qualifier.csv (statut
 * « non_qualifie ») et INTERDITES de tous les fichiers de négatifs.
 * Libellés tels qu'ils existent réellement en base.
 */
const RESIDUELS_SPEC_1_3 = [
    ['Oasis ice tea the mangue passion', 'Oasis Thémangue Passion'],
    ['Surfizz Fruit Graine', 'Surfizz Fruits gaine'],
    ['Fauffres Liegeoise', 'gaufre'],
    ['Bourdon Gauffre LI', 'gaufre'],
    ['Fauffres Liegeoise', 'Bourdon Gauffre LI'], // cluster des 3 (§1.3)
    ['Madel Coquille', 'Madeleine'],
    ['Kinder Bueno', 'Bueno'],
    ['Caprisun', 'Caprisun tropical'],
    ['cafe', 'cafe allonge'],
];

try {
    // Sonde de connexion (constructeur PDO, AUCUNE requête d'écriture).
    $pdo = db();
} catch (Throwable $e) {
    fwrite(STDERR, "ERREUR : base indisponible — " . $e->getMessage() . "\n");
    exit(1);
}

$anomalies = [];       // pour manifeste_jeu.md
$compteursSources = []; // idem

// ── 1. Extraction (SELECT uniquement, ordres déterministes) ─────────────────

// 1a. Fusions auditées (vérité terrain §3.1).
$fusionsParAction = [];
$evenements = [];
$detailsNonParsables = 0;
$mojibakeRepares = 0;
$sqlFusions = 'SELECT id, action, entity_id, details, created_at
               FROM audit_logs
               WHERE action IN ("inventory.merge", "compta.aliases.merge",
                                "compta.product.merge", "compta.repair.merge_cle")
               ORDER BY created_at, id';
foreach ($pdo->query($sqlFusions) as $row) {
    $action = (string) $row['action'];
    $fusionsParAction[$action] = ($fusionsParAction[$action] ?? 0) + 1;

    $details = json_decode((string) $row['details'], true);
    if (!is_array($details)) {
        $detailsNonParsables++;
        $anomalies[] = 'details JSON non parsable : ' . $action . ' id=' . $row['id'];
        continue;
    }

    $pairesBrutes = [];
    if (isset($details['source'], $details['target'])) {
        $pairesBrutes = [[(string) $details['source'], (string) $details['target']]];
    } elseif (isset($details['from'], $details['to'])) {
        $pairesBrutes = [[(string) $details['from'], (string) $details['to']]];
    } elseif (isset($details['merged']) && is_array($details['merged'])) {
        foreach ($details['merged'] as $m) {
            $pairesBrutes[] = [(string) $m, (string) $row['entity_id']];
        }
    } elseif (isset($details['from'])) {
        // compta.repair.merge_cle : la cible est l'entity_id.
        $pairesBrutes = [[(string) $details['from'], (string) $row['entity_id']]];
    } else {
        $detailsNonParsables++;
        $anomalies[] = 'details sans source/target|from/to|merged : ' . $action . ' id=' . $row['id'];
        continue;
    }

    foreach ($pairesBrutes as [$brut, $cible]) {
        if (EvalDataset::repairMojibake($brut) !== $brut || EvalDataset::repairMojibake($cible) !== $cible) {
            $mojibakeRepares++;
        }
        $evenements[] = [
            'brut'    => $brut,
            'cible'   => $cible,
            'origine' => 'audit_logs',
            'action'  => $action,
            'kind'    => '',
            'ref'     => (string) $row['id'],
            'ts'      => (string) $row['created_at'],
        ];
    }
}
$compteursSources['fusions_audit'] = $fusionsParAction;

// 1b. Alias actuels (§3.1 : 96 label + 17 appellation).
$aliasParKind = [];
foreach ($pdo->query('SELECT id, raw_description, product_key, kind, created_at
                      FROM product_aliases ORDER BY id') as $row) {
    $kind = (string) $row['kind'];
    $aliasParKind[$kind] = ($aliasParKind[$kind] ?? 0) + 1;
    $evenements[] = [
        'brut'    => (string) $row['raw_description'],
        'cible'   => (string) $row['product_key'],
        'origine' => 'product_aliases',
        'action'  => '',
        'kind'    => $kind,
        'ref'     => (string) $row['id'],
        'ts'      => (string) $row['created_at'],
    ];
}
$compteursSources['alias_par_kind'] = $aliasParKind;

// 1c. Univers (§2) : union des clés distinctes de 9 sources.
$colonnes = function (string $sql) use ($pdo): array {
    $out = [];
    foreach ($pdo->query($sql) as $row) {
        $k = trim((string) $row['k']);
        if ($k !== '') {
            $out[] = $k;
        }
    }

    return $out;
};

$nomsProduits   = $colonnes('SELECT DISTINCT name k FROM products WHERE name <> "" ORDER BY name');
$stockRows      = $pdo->query('SELECT product_key, stock FROM product_stocks WHERE product_key <> "" ORDER BY product_key')->fetchAll();
$salesRows      = $pdo->query('SELECT product_key, description FROM sales ORDER BY id')->fetchAll();
$achatsCles     = $colonnes('SELECT DISTINCT product_key k FROM purchases WHERE product_key <> "" ORDER BY product_key');
$clesComptages  = $colonnes('SELECT DISTINCT product_key k FROM inventory_counts WHERE product_key <> "" ORDER BY product_key');
$clesCouts      = $colonnes('SELECT DISTINCT product_key k FROM product_costs WHERE product_key <> "" ORDER BY product_key');
$clesPacks      = $colonnes('SELECT DISTINCT product_key k FROM product_packs WHERE product_key <> "" ORDER BY product_key');
$clesAlias      = $colonnes('SELECT DISTINCT product_key k FROM product_aliases WHERE product_key <> "" ORDER BY product_key');
$clesArretees   = $colonnes('SELECT product_key k FROM product_discontinued WHERE product_key <> "" ORDER BY product_key');
$descriptionsVentes = $colonnes('SELECT DISTINCT description k FROM sales WHERE description <> "" ORDER BY description');

$compteursSources['univers_brut'] = [
    'products.name'              => count($nomsProduits),
    'product_stocks.product_key' => count($stockRows),
    'sales.product_key'          => count(array_unique(array_filter(array_map('strval', array_column($salesRows, 'product_key')), static fn (string $k): bool => $k !== ''))),
    'sales.description'          => count($descriptionsVentes),
    'purchases.product_key'      => count($achatsCles),
    'inventory_counts'           => count($clesComptages),
    'product_costs'              => count($clesCouts),
    'product_packs'              => count($clesPacks),
    'product_aliases.product_key'=> count($clesAlias),
    'product_discontinued'       => count($clesArretees),
];

// ── 2. Identités de l'univers (dédoublonnage par forme normalisée) ──────────

$identites = []; // forme => ['labels'=>[libelle=>['sources'=>n,'occurrences'=>n]], ...]
$tag = function (string $libelle, string $source, int $occurrences) use (&$identites): void {
    $forme = EvalDataset::forme($libelle);
    if ($forme === '') {
        return;
    }
    $identites[$forme]['labels'][$libelle]['sources'][] = $source;
    $identites[$forme]['labels'][$libelle]['occurrences'] =
        ($identites[$forme]['labels'][$libelle]['occurrences'] ?? 0) + $occurrences;
};

foreach ($nomsProduits as $l) {
    $tag($l, 'products', 0);
}
foreach ($stockRows as $r) {
    $tag((string) $r['product_key'], 'product_stocks', 0);
}
foreach ($salesRows as $r) {
    $tag(trim((string) $r['product_key']), 'sales_key', 0);
    $tag(trim((string) $r['description']), 'sales_description', 0);
}
$occAchats = array_count_values($achatsCles);
foreach (array_keys($occAchats) as $l) {
    $tag((string) $l, 'purchases', (int) $occAchats[$l]);
}
foreach ($clesComptages as $l) {
    $tag($l, 'inventory_counts', 0);
}
foreach ($clesCouts as $l) {
    $tag($l, 'product_costs', 0);
}
foreach ($clesPacks as $l) {
    $tag($l, 'product_packs', 0);
}
foreach ($clesAlias as $l) {
    $tag($l, 'product_aliases', 0);
}

// Compteurs réels par identité : ventes (une ligne de vente compte pour chaque
// identité qu'elle mentionne via product_key OU description), achats, stock.
$ventesParForme = [];
foreach ($salesRows as $r) {
    $formes = array_unique(array_filter([
        EvalDataset::forme((string) $r['product_key']),
        EvalDataset::forme((string) $r['description']),
    ], static fn (string $f): bool => $f !== ''));
    foreach ($formes as $f) {
        $ventesParForme[$f] = ($ventesParForme[$f] ?? 0) + 1;
    }
}
$achatsParForme = [];
foreach ($achatsCles as $l) {
    $f = EvalDataset::forme($l);
    if ($f !== '') {
        $achatsParForme[$f] = ($achatsParForme[$f] ?? 0) + 1;
    }
}
$stockParForme = [];
foreach ($stockRows as $r) {
    $f = EvalDataset::forme((string) $r['product_key']);
    if ($f !== '') {
        $stockParForme[$f] = ($stockParForme[$f] ?? 0) + (int) $r['stock'];
    }
}
$arretes = [];
foreach ($clesArretees as $l) {
    $f = EvalDataset::forme($l);
    if ($f !== '') {
        $arretes[$f] = true;
    }
}
$suiviesStock = array_fill_keys(array_map(static fn (string $l): string => EvalDataset::forme($l), $clesComptages), true);

// Règle « vivant » (documentée au manifeste) : au moins une activité réelle
// (ventes, achats, ligne de stock ou comptage) ET pas en product_discontinued.
$lignesUnivers = [];
$nbVivantes = 0;
foreach (array_keys($identites) as $forme) {
    $candidats = [];
    foreach ($identites[$forme]['labels'] as $libelle => $info) {
        $candidats[] = [
            'libelle'     => (string) $libelle,
            'sources'     => count(array_unique($info['sources'])),
            'occurrences' => (int) $info['occurrences'],
        ];
    }
    $libelle = EvalDataset::representant($candidats) ?? $forme;
    $ventes = $ventesParForme[$forme] ?? 0;
    $achats = $achatsParForme[$forme] ?? 0;
    $stock = $stockParForme[$forme] ?? 0;
    $vivant = ($ventes > 0 || $achats > 0 || isset($stockParForme[$forme]) || isset($suiviesStock[$forme]))
        && !isset($arretes[$forme]) ? 1 : 0;
    $nbVivantes += $vivant;

    $lignesUnivers[$forme] = [
        'libelle' => $libelle,
        'forme'   => $forme,
        'famille' => EvalDataset::famille($libelle),
        'vivant'  => $vivant,
        'ventes'  => $ventes,
        'achats'  => $achats,
        'en_stock' => $stock,
        'nb_libelles' => count($identites[$forme]['labels']),
    ];
}
ksort($lignesUnivers, SORT_STRING);

$formesConnues = array_fill_keys(array_keys($lignesUnivers), true);
$nbLibellesBruts = array_sum(array_map(static fn (array $u): int => $u['nb_libelles'], $lignesUnivers));

// ── 3. Paires positives : classement + dédoublonnage + exclusions §1.3 ─────

$pairesExclues = [];
$residuelsSpecRows = [];
foreach (RESIDUELS_SPEC_1_3 as [$a, $b]) {
    $pairesExclues[] = EvalDataset::paire($a, $b);
    $residuelsSpecRows[] = ['a' => $a, 'b' => $b];
}

$positifs = EvalDataset::construirePositifs($evenements, $pairesExclues);

// Paires vivantes à surveiller : alias dont les DEUX libellés existent encore
// dans l'univers avec la même famille (ex. « Redbull_Blanche »→« Redbull »).
foreach ($positifs['semantique'] as &$p) {
    $fa = EvalDataset::forme($p['libelle_brut']);
    $fb = EvalDataset::forme($p['libelle_cible']);
    if (
        isset($formesConnues[$fa], $formesConnues[$fb])
        && $lignesUnivers[$fa]['famille'] === $lignesUnivers[$fb]['famille']
    ) {
        $p['remarque'] = trim($p['remarque'] . ' — à vérifier : les deux libellés coexistent encore (même famille « '
            . $lignesUnivers[$fa]['famille'] . ' »)', ' —');
    }
}
unset($p);

// ── 4. Résiduels §1.3 + near-miss auto (JAMAIS dans le jeu) ────────────────

// Groupes d'union-find des paires positives (servent au near-miss ET au
// split §6 — même définition).
$pairesSemantiquesFormes = [];
foreach ($positifs['semantique'] as $p) {
    $pairesSemantiquesFormes[] = explode('|', $p['paire']);
}
$groupes = EvalDataset::groupesDepuisPaires($pairesSemantiquesFormes);

$lignesResiduels = [];
$formeDeLibelle = static function (string $l): string {
    return EvalDataset::forme($l);
};
foreach ($residuelsSpecRows as $r) {
    $manquants = [];
    foreach ([$r['a'], $r['b']] as $l) {
        if (!isset($formesConnues[$formeDeLibelle($l)])) {
            $manquants[] = $l;
        }
    }
    $motif = 'spec §1.3 : doublon résiduel non qualifié (D2)';
    $cle = EvalDataset::paire($r['a'], $r['b']);
    foreach ($positifs['exclues'] as $ex) {
        if ($ex['paire'] === $cle) {
            $motif .= ' — fusion historique auditée mais clé recréée : à requalifier';
            break;
        }
    }
    if ($manquants !== []) {
        $motif .= ' — ATTENTION libellé introuvable dans l\'univers : ' . implode(', ', $manquants);
        $anomalies[] = 'résiduel §1.3 sans identité : ' . implode(' / ', $manquants);
    }
    $lignesResiduels[] = ['a' => $r['a'], 'b' => $r['b'], 'motif' => $motif];
}
$lignesResiduels[] = [
    'a' => '.', 'b' => '',
    'motif' => 'spec §1.3 : clé parasite « . » (stock 120) — à supprimer/nettoyer',
];

// Identités utilisées pour le jeu (tout sauf la clé parasite « . »).
$identitesJeu = [];
foreach ($lignesUnivers as $forme => $u) {
    if ($forme === '') {
        continue;
    }
    $identitesJeu[$forme] = ['libelle' => $u['libelle'], 'famille' => $u['famille']];
}

// Interdites pour TOUS les négatifs : positives (sémantiques ET exclues §1.3),
// résiduelles spec, near-miss auto, et paires d'artefacts SumUp sémantiquement
// équivalentes (« Custom amount » / « Montant personnalisé »).
$interdites = [];
foreach ($positifs['semantique'] as $p) {
    $interdites[$p['paire']] = true;
}
foreach ($positifs['exclues'] as $p) {
    $interdites[$p['paire']] = true;
}
foreach ($positifs['casse'] as $p) {
    $interdites[$p['paire']] = true; // forme unique : sans effet, par sécurité
}
foreach ($pairesExclues as $cle) {
    if ($cle !== '') {
        $interdites[$cle] = true;
    }
}
$interdites[EvalDataset::paire('Custom amount', 'Montant personnalisé')] = true;
$anomalies[] = 'exclusion documentée : paire « Custom amount »/« Montant personnalisé » (artefacts de paiement SumUp sémantiquement équivalents) interdite en négatif';

// Remontée des anomalies de traitement des paires : les auto-alias
// (raw_description === product_key, mécanisme normal de synchronisation des
// alias de menu) sont condensés en une seule ligne de décompte.
$autoAlias = array_values(array_filter(
    $positifs['anomalies'],
    static fn (string $a): bool => str_contains($a, 'auto-alias ignoré')
));
$autresAnomaliesPositifs = array_values(array_filter(
    $positifs['anomalies'],
    static fn (string $a): bool => !str_contains($a, 'auto-alias ignoré')
));
if ($autoAlias !== []) {
    $exemples = [];
    foreach ($autoAlias as $a) {
        if (preg_match('/« ([^»]+) »/', $a, $m) === 1) {
            $exemples[] = $m[1];
        }
    }
    $anomalies[] = count($autoAlias) . ' auto-alias ignorés (raw_description === product_key, ex. '
        . implode(' ; ', array_slice($exemples, 0, 3)) . '…) — attendus : alias de synchronisation, aucune paire en résulte';
}
foreach ($autresAnomaliesPositifs as $a) {
    $anomalies[] = 'positifs : ' . $a;
}
$anomalies[] = 'clé parasite « . » (spec §1.3) : forme normalisée vide → absente de univers.csv, documentée dans residuels_a_qualifier.csv';
if ($mojibakeRepares > 0) {
    $anomalies[] = "mojibake double-encodage réparé sur {$mojibakeRepares} libellé(s) d'audit (ex. « Oasis ThÃ©mangue Passion ») — déterministe (EvalDataset::repairMojibake)";
}
$nbExclues = count($positifs['exclues']);
if ($nbExclues > 0) {
    $anomalies[] = "{$nbExclues} paire(s) positive(s) historique(s) écartée(s) du jeu car listées §1.3 (clé recréée après fusion) : à requalifier en D2";
}
$anomalies[] = 'écart à la spec §3.1 : paires sémantiques mesurées sous la fourchette 60-80 attendue (estimation spec optimiste : ~270 événements bruts mais forte redondance casse/typo + 1 doublon mojibake + exclusions §1.3) — compteurs exacts dans ce manifeste';
$anomalies[] = 'trio « Redbull Bleu »/« Redbull Blue »/« Redbull_Bleue » : lié par fusions auditées (même groupe de split) mais 3 identités vivantes distinctes — aucune paire en dur négatif (interdites positives)';
$anomalies[] = '« Oasis ice tea » vs « Oasis ice tea the mangue passion » : deux clés vivantes non listées §1.3 → restent durs négatifs — à surveiller en D2';

// Near-miss (Levenshtein 1-2 sur formes normalisées, clés vivantes, hors
// variantes numérotées et hors paires déjà liées par une chaîne de fusions).
$nearMiss = EvalDataset::nearMiss($identitesJeu, $interdites, EvalDataset::NEAR_MISS_DISTANCE_MAX, $groupes);
foreach ($nearMiss as $nm) {
    $lignesResiduels[] = [
        'a' => $nm['libelle_a'], 'b' => $nm['libelle_b'],
        'motif' => 'auto : near-miss Levenshtein ' . $nm['distance'] . ' (formes normalisées) non fusionné — à qualifier (D2)',
    ];
    $interdites[EvalDataset::paireFormes($nm['forme_a'], $nm['forme_b'])] = true;
}
usort($lignesResiduels, static fn (array $x, array $y): int => [$x['a'], $x['b']] <=> [$y['a'], $y['b']]);

// ── 5. Négatifs (durs intra-famille exhaustifs + échantillon inter-familles) ─

$durs = EvalDataset::negatifsDurs($identitesJeu, $interdites);
$negatifs = EvalDataset::echantillonnerNegatifs($identitesJeu, $interdites, $nbNegatifs, $seed);

// ── 6. Split par produit (groupes d'union-find, graine fixe) ────────────────

$poidsGroupes = [];
foreach (array_keys($lignesUnivers) as $forme) {
    $g = EvalDataset::groupeDe($groupes, $forme);
    $poidsGroupes[$g] = ($poidsGroupes[$g] ?? 0) + 1;
}
$split = EvalDataset::splitParGroupe(
    $groupes,
    $poidsGroupes,
    $pairesSemantiquesFormes,
    $seed,
    EvalDataset::RATIO_TEST_DEFAUT,
    EvalDataset::MIN_PAIRES_SEMANTIQUES_TEST
);

// ── 7. Écriture des fichiers ────────────────────────────────────────────────

if (!is_dir($kaggleDir) && !mkdir($kaggleDir, 0o775, true) && !is_dir($kaggleDir)) {
    fwrite(STDERR, "ERREUR : impossible de créer {$kaggleDir}\n");
    exit(1);
}

$ecrireCsv = function (string $chemin, array $entetes, array $lignes) use ($outDir): void {
    $f = fopen($chemin, 'wb');
    if ($f === false) {
        fwrite(STDERR, "ERREUR : écriture impossible {$chemin}\n");
        exit(1);
    }
    fputcsv($f, $entetes, ',', '"', '\\', "\n");
    foreach ($lignes as $ligne) {
        fputcsv($f, $ligne, ',', '"', '\\', "\n");
    }
    fclose($f);
};

// 7a. univers.csv — une ligne par identité (forme normalisée distincte).
$lignes = [];
foreach ($lignesUnivers as $forme => $u) {
    $lignes[] = [
        $u['libelle'], $forme, $u['famille'], (string) $u['vivant'],
        EvalDataset::groupeDe($groupes, $forme), (string) $u['ventes'], (string) $u['achats'], (string) $u['en_stock'],
    ];
}
$ecrireCsv($outDir . '/univers.csv', ['libelle', 'forme_normalisee', 'famille', 'vivant', 'groupe', 'ventes', 'achats', 'en_stock'], $lignes);

// 7b. positifs_semantiques.csv / positifs_casse.csv.
$versLignePositive = static fn (array $p): array => [
    $p['libelle_brut'], $p['libelle_cible'], $p['origine'], $p['action_origine'], $p['kind'], $p['remarque'],
];
$entetesPositifs = ['libelle_brut', 'libelle_cible', 'origine', 'action_origine', 'kind', 'remarque'];
$ecrireCsv($outDir . '/positifs_semantiques.csv', $entetesPositifs, array_map($versLignePositive, $positifs['semantique']));
$ecrireCsv($outDir . '/positifs_casse.csv', $entetesPositifs, array_map($versLignePositive, $positifs['casse']));

// 7c. durs_negatifs.csv / negatifs.csv.
$ecrireCsv($outDir . '/durs_negatifs.csv', ['libelle_a', 'libelle_b', 'forme_a', 'forme_b', 'famille'],
    array_map(static fn (array $d): array => [$d['libelle_a'], $d['libelle_b'], $d['forme_a'], $d['forme_b'], $d['famille']], $durs));
$ecrireCsv($outDir . '/negatifs.csv', ['libelle_a', 'libelle_b', 'forme_a', 'forme_b', 'famille_a', 'famille_b'],
    array_map(static fn (array $d): array => [$d['libelle_a'], $d['libelle_b'], $d['forme_a'], $d['forme_b'], $d['famille_a'], $d['famille_b']], $negatifs));

// 7d. split.json.
file_put_contents(
    $outDir . '/split.json',
    json_encode([
        'seed' => $seed,
        'ratio_cible' => EvalDataset::RATIO_TEST_DEFAUT,
        'min_paires_semantiques_test' => EvalDataset::MIN_PAIRES_SEMANTIQUES_TEST,
        'mapping' => $split['mapping'],
        'stats' => $split['stats'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
);

// 7e. residuels_a_qualifier.csv — JAMAIS dans les fichiers de jeu ci-dessus.
$ecrireCsv($outDir . '/residuels_a_qualifier.csv', ['libelle_a', 'libelle_b', 'motif', 'statut'],
    array_map(static fn (array $r): array => [$r['a'], $r['b'], $r['motif'], 'non_qualifie'], $lignesResiduels));

// 7f. Export Kaggle anonymisé (protocole D3 — libellés seuls, prénoms masqués,
//     sans prix/dates/quantités/fournisseurs/références/user_id/IP ; PRÊT,
//     NON transmis — aucune commande kaggle n'est exécutée ici).
//     Anonymisation cohérente : si un libellé est masqué, sa forme normalisée
//     et son groupe sont RECALCULÉS depuis le libellé masqué (jamais de
//     fragment de prénom résiduel dans les colonnes dérivées).
$attrsKaggle = static function (array $u) use ($groupes): array {
    // [libelle, forme_affichée, groupe_affiché, groupe_réel_pour_split]
    $groupeReel = EvalDataset::groupeDe($groupes, $u['forme']);
    $libelle = EvalDataset::masquerPrenom($u['libelle']);
    if ($libelle === $u['libelle']) {
        return [$libelle, $u['forme'], $groupeReel, $groupeReel];
    }
    $formeMasquee = EvalDataset::forme($libelle);

    return [$libelle, $formeMasquee, $formeMasquee, $groupeReel];
};
$ecrireCsv($kaggleDir . '/univers.csv', ['libelle', 'forme_normalisee', 'famille', 'vivant', 'groupe'],
    array_map(static fn (array $u): array => [
        $attrsKaggle($u)[0], $attrsKaggle($u)[1], $u['famille'], (string) $u['vivant'], $attrsKaggle($u)[2],
    ], array_values($lignesUnivers)));
$ecrireCsv($kaggleDir . '/positifs.csv', ['libelle_a', 'libelle_b'],
    array_map(static fn (array $p): array => [EvalDataset::masquerPrenom($p['libelle_brut']), EvalDataset::masquerPrenom($p['libelle_cible'])], $positifs['semantique']));
$ecrireCsv($kaggleDir . '/negatifs.csv', ['libelle_a', 'libelle_b'],
    array_map(static fn (array $d): array => [EvalDataset::masquerPrenom($d['libelle_a']), EvalDataset::masquerPrenom($d['libelle_b'])], $negatifs));
$ecrireCsv($kaggleDir . '/durs_negatifs.csv', ['libelle_a', 'libelle_b'],
    array_map(static fn (array $d): array => [EvalDataset::masquerPrenom($d['libelle_a']), EvalDataset::masquerPrenom($d['libelle_b'])], $durs));
$ecrireCsv($kaggleDir . '/split.csv', ['libelle', 'split'],
    array_map(static fn (array $u): array => [
        $attrsKaggle($u)[0], $split['mapping'][$attrsKaggle($u)[3]],
    ], array_values($lignesUnivers)));

// 7g. SHA256SUMS (fichiers de jeu + exports Kaggle ; PAS les manifestes
//     horodatés ni ce fichier lui-même).
$fichiersHaches = [
    'univers.csv', 'positifs_semantiques.csv', 'positifs_casse.csv',
    'durs_negatifs.csv', 'negatifs.csv', 'split.json', 'residuels_a_qualifier.csv',
    'kaggle/univers.csv', 'kaggle/positifs.csv', 'kaggle/negatifs.csv',
    'kaggle/durs_negatifs.csv', 'kaggle/split.csv',
];
$lignesSums = '';
foreach ($fichiersHaches as $relatif) {
    $lignesSums .= hash_file('sha256', $outDir . '/' . $relatif) . '  ' . 'data/eval_t1/' . $relatif . "\n";
}
file_put_contents($outDir . '/SHA256SUMS', $lignesSums);

// ── 8. Manifestes ───────────────────────────────────────────────────────────

$compteurUnivers = count($lignesUnivers);
$compteurSem = count($positifs['semantique']);
$compteurCasse = count($positifs['casse']);
$compteurDurs = count($durs);
$compteurNeg = count($negatifs);
$compteurResiduels = count($lignesResiduels);
$minPairesTest = EvalDataset::MIN_PAIRES_SEMANTIQUES_TEST;

$dateGen = (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format('Y-m-d H:i:s');
$nbGroupesTest = $split['stats']['groupes_test'];
$nbGroupesTrain = $split['stats']['groupes_train'];

$manifeste = <<<MD
# Jeu d'évaluation T1 — manifeste de génération

- Généré le : {$dateGen} (Europe/Paris)
- Graine : {$seed} — reproductible : deux exécutions donnent des hachages identiques
- Commande : `php scripts/t1_export_eval.php --seed={$seed} --negatifs={$nbNegatifs}`
- Base : `aeic` (production locale, **LECTURE SEULE** — SELECT uniquement)
- Script : `scripts/t1_export_eval.php` — logique pure : `app/core/Compta/T1/EvalDataset.php`
- Tests : `tests/Unit/Compta/T1EvalDatasetTest.php`
- Spec : `docs/ANALYSE_T1_RAPPROCHEMENT_PRODUITS.md` (§3)

## Compteurs sources réels (relevés à la génération)

- Fusions auditées (`audit_logs`) : {$fusionsParAction['inventory.merge']} `inventory.merge` + {$fusionsParAction['compta.aliases.merge']} `compta.aliases.merge` + {$fusionsParAction['compta.product.merge']} `compta.product.merge` + {$fusionsParAction['compta.repair.merge_cle']} `compta.repair.merge_cle` = **156 événements** (attendu 156, spec §1.1)
- Alias (`product_aliases`) : {$aliasParKind['label']} `label` + {$aliasParKind['appellation']} `appellation` = **113 lignes** (attendu 113)
- Univers brut : {$compteursSources['univers_brut']['products.name']} fiches + {$compteursSources['univers_brut']['product_stocks.product_key']} clés de stock + {$compteursSources['univers_brut']['sales.product_key']} clés de vente / {$compteursSources['univers_brut']['sales.description']} descriptions distinctes + {$compteursSources['univers_brut']['purchases.product_key']} clés d'achat + {$compteursSources['univers_brut']['inventory_counts']} comptages + {$compteursSources['univers_brut']['product_costs']} coûts + {$compteursSources['univers_brut']['product_packs']} packs + {$compteursSources['univers_brut']['product_aliases.product_key']} cibles d'alias + {$compteursSources['univers_brut']['product_discontinued']} arrêtées

## Volumes obtenus

| Fichier | Lignes | Attendu spec |
|---|---:|---|
| univers.csv | {$compteurUnivers} identités ({$nbLibellesBruts} libellés bruts) | ~100 (§2 : 80-120) |
| positifs_semantiques.csv | {$compteurSem} | 60-80 (§3.1) |
| positifs_casse.csv | {$compteurCasse} | transparence |
| durs_negatifs.csv | {$compteurDurs} | « quelques centaines » (§3.2) |
| negatifs.csv | {$compteurNeg} échantillonnées (seed {$seed}) | ~300 |
| residuels_a_qualifier.csv | {$compteurResiduels} | ~8+ (§1.3) |
| split.json | {$nbGroupesTrain} groupes train / {$nbGroupesTest} test — {$split['stats']['paires_semantiques_train']} paires sémantiques train, {$split['stats']['paires_semantiques_test']} test (min 20) | ~70/30 (§3.4) |

Identités vivantes : {$nbVivantes}/{$compteurUnivers} (règle ci-dessous).

## Règles documentées

- **Forme normalisée** : `StockPublic::normalizeKey` (casse, accents, séparateurs, espaces) — deux libellés de même forme = même identité ; le libellé affiché est le **représentant** déterministe (nb de tables sources, puis occurrences ventes+achats, puis ordre lexicographique).
- **Famille** : 1er token de `AliasSuggester::normalizeKey` du libellé représentant (« Redbull Bleu » → `redbull` ; « Gauffre choco » [typo] → `gauffre`, distinct de `gaufre`).
- **Vivant** : ≥ 1 occurrence réelle (ventes par product_key OU description, achats, ligne dans product_stocks ou inventory_counts) ET absent de `product_discontinued`.
- **Positifs** : fusions (source/from/merged → target/to/entity_id) + alias (`raw_description` → `product_key`) ; dédoublonnage par paire de formes (variantes de casse d'une même fusion sémantique fusionnées) ; casse pure → `positifs_casse.csv` HORS jeu ; paires §1.3 → `residuels_a_qualifier.csv` HORS jeu.
- **Négatifs interdits** : toute paire positive (jeu ou exclue), tout résiduel §1.3, tout near-miss ≤ 2 éditions non fusionné, et « Custom amount »/« Montant personnalisé » (artefacts SumUp équivalents).
- **Durs négatifs** : EXHAUSTIF intra-famille, clés vivantes hors clé parasite « . ».
- **Split** : par groupe = composante d'union-find des paires positives sémantiques (une identité sans paire est son propre groupe) ; remplissage test ~30 % des identités par Fisher-Yates seedé {$seed}, complément déterministe si moins de {$minPairesTest} paires sémantiques en test ; assertion anti-fuite (aucune paire à cheval train/test).

## Anomalies et écarts

MD;
foreach ($anomalies as $i => $a) {
    $manifeste .= ($i + 1) . '. ' . $a . "\n";
}
if ($anomalies === []) {
    $manifeste .= "- aucune\n";
}
$manifeste .= "\nVérification : `sha256sum -c data/eval_t1/SHA256SUMS` (depuis la racine du dépôt) — les manifestes horodatés ne sont pas hachés.\n";
file_put_contents($outDir . '/manifeste_jeu.md', $manifeste);

$manifesteKaggle = <<<MD
# Export Kaggle — dataset T1 rapprochement produits (protocole D3)

Statut : **PRÊT, NON TRANSMIS** (aucune commande `kaggle` exécutée — accord explicite requis).
Régénération : `php scripts/t1_export_eval.php` (VPS, base `aeic` en lecture seule, seed 42).

## Protocole d'anonymisation (D3 — libellés seuls)

- **Aucun** prix, montant, date, quantité, volume (ventes/achats/stock), fournisseur (METRO/Auchan/carrefour), référence de facture, user_id, adresse IP, e-mail : seuls des **libellés produits** sont exportés.
- Prénoms probables masqués : un libellé contenant « … | les creation(s) de X » voit sa partie après « | » remplacée par `[PRENOM]` (cas connu : « Décoration halloween | les creation de ju… »). Pour rester cohérent, la `forme_normalisee` et le `groupe` d'un libellé masqué sont recalculés depuis le libellé masqué. Règle déterministe, testée (`EvalDataset::masquerPrenom`).
- Les `forme_normalisee`/`famille` sont des dérivés déterministes des libellés (aucune donnée personnelle).

## Fichiers

| Fichier | Colonnes | Contenu |
|---|---|---|
| `univers.csv` | `libelle,forme_normalisee,famille,vivant,groupe` | Identités produits (~100) : union fiches + clés vivantes (spec §2). `vivant` = 1 si activité réelle et non arrêté. |
| `positifs.csv` | `libelle_a,libelle_b` | Paires positives sémantiques (fusions auditées + alias), hors variantes de casse pure (spec §3.1). `libelle_a` = libellé brut, `libelle_b` = clé cible. |
| `negatifs.csv` | `libelle_a,libelle_b` | ~300 paires inter-familles négatives échantillonnées (seed 42), jamais positives. |
| `durs_negatifs.csv` | `libelle_a,libelle_b` | Paires intra-famille négatives EXHAUSTIVES (« Redbull Bleu » vs « Redbull Blanche »…) — critère A4. |
| `split.csv` | `libelle,split` | Affectation train|test PAR PRODUIT (anti-fuite, spec §3.4). |

Les paires résiduelles non qualifiées (spec §1.3, décision D2 en attente) sont volontairement ABSENTES de cet export.
MD;
file_put_contents($kaggleDir . '/MANIFESTE.md', $manifesteKaggle);

// ── 9. Résumé console ───────────────────────────────────────────────────────

echo "=== Jeu d'évaluation T1 généré dans {$outDir} ===\n";
echo "univers            : {$compteurUnivers} identités ({$nbLibellesBruts} libellés bruts, {$nbVivantes} vivantes)\n";
echo "positifs sémantiques : {$compteurSem} (casse pure à part : {$compteurCasse} ; exclues §1.3 : " . count($positifs['exclues']) . ")\n";
echo "durs négatifs      : {$compteurDurs}\n";
echo "négatifs échantillon: {$compteurNeg} (seed {$seed})\n";
echo "résiduels D2       : {$compteurResiduels}\n";
echo "split              : {$nbGroupesTrain} groupes train / {$nbGroupesTest} test — paires sémantiques {$split['stats']['paires_semantiques_train']}/{$split['stats']['paires_semantiques_test']} (ratio identités test " . $split['stats']['ratio_test'] . ")\n";
echo "anomalies          : " . count($anomalies) . " (voir manifeste_jeu.md)\n";
echo "mojibake réparés   : {$mojibakeRepares} ; details non parsables : {$detailsNonParsables}\n";
echo "Vérification : sha256sum -c data/eval_t1/SHA256SUMS\n";
