<?php

declare(strict_types=1);

/**
 * AEIC — P2-auto (brief A2, dev-factures) : export CLI des paires
 * étiquetées NOUVELLES pour le pipeline de ré-entraînement.
 *
 * Réutilise EXCLUSIVEMENT la logique A1 (point d'assemblage unique) :
 * T1AutoRetrain::lireEtat() (borne dernier_run) + T1AutoRetrain::recolter()
 * (AuditLog::rowsForAction + T1FeedbackFeed::collecter — dédoublonnage par
 * entity_id, décision la plus récente gagne, comparaison sur les instants
 * absolus). Aucune requête d'écriture : la base est en LECTURE SEULE.
 *
 * Sortie : CSV colonnes decision,origine,a,b,score,decide_le (tri
 * déterministe decision,a,b) consommé par
 * `scripts/t1_augment.py --v2 --feedback …`.
 *
 * La dernière ligne stdout `CONSOMME_JUSQUA=<ISO+00:00>` porte la borne
 * d'export (decide_le la plus récente) : le script appelant la repasse à
 * `T1AutoRetrain::marquer(…, run: …)` pour consommer exactement ces paires —
 * celles décidées PENDANT le run restent comptées (aucune perte).
 *
 * Usage :
 *   php scripts/t1_auto_export_feedback.php --sortie=data/eval_t1/p2/auto/feedback_pairs.csv
 *   DB_HOST=127.0.0.1:3307 DB_NAME=aeic_test … php scripts/t1_auto_export_feedback.php …
 *       (validation : l'env hérité prime sur config.env — base de test)
 *
 * AEIC_P2AUTO_ETAT (env) redirige l'état lu (validation/test) ; défaut : prod.
 *
 * Codes retour : 0 = export écrit (≥ 1 paire) · 2 = aucune paire nouvelle
 * (rien à faire) · 1 = erreur (base indisponible, écriture impossible).
 */

foreach (['AEIC_P2AUTO_ETAT'] as $constante) {
    $valeur = getenv($constante);
    if (is_string($valeur) && $valeur !== '' && !defined($constante)) {
        define($constante, $valeur);
    }
}

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Compta\T1\T1AutoRetrain;
use App\Core\Compta\T1\T1FeedbackFeed;

$opts = getopt('', ['sortie:']);
$sortie = (string) ($opts['sortie'] ?? '');
if ($sortie === '') {
    fwrite(STDERR, "usage : php scripts/t1_auto_export_feedback.php --sortie=<chemin.csv>\n");
    exit(1);
}
if (!is_file(__DIR__ . '/../app/config/database.php')) {
    fwrite(STDERR, "ERREUR : configuration application introuvable\n");
    exit(1);
}

try {
    require_once __DIR__ . '/../app/config/database.php';
    db(); // sonde de connexion (LECTURE SEULE — aucun INSERT/UPDATE/DELETE ensuite)
} catch (Throwable $e) {
    fwrite(STDERR, 'ERREUR : base indisponible — ' . $e->getMessage() . "\n");
    exit(1);
}

try {
    $etat = T1AutoRetrain::lireEtat();
    $depuis = is_string($etat['dernier_run']) && $etat['dernier_run'] !== '' ? $etat['dernier_run'] : null;
    $recolte = T1AutoRetrain::recolter($depuis);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERREUR : récolte impossible — ' . $e->getMessage() . "\n");
    exit(1);
}

$total = (int) $recolte['total'];
if ($total === 0) {
    echo "AUCUNE paire nouvelle depuis " . ($depuis ?? 'le début') . " — rien à exporter\n";
    exit(2);
}

$paires = array_merge($recolte['positives'], $recolte['negatives']);
usort($paires, static fn (array $x, array $y): int => [$x['decision'], $x['a'], $x['b']] <=> [$y['decision'], $y['a'], $y['b']]);

$dossier = dirname($sortie);
if (!is_dir($dossier) && !@mkdir($dossier, 0o775, true) && !is_dir($dossier)) {
    fwrite(STDERR, "ERREUR : impossible de créer {$dossier}\n");
    exit(1);
}
$f = fopen($sortie, 'wb');
if ($f === false) {
    fwrite(STDERR, "ERREUR : écriture impossible {$sortie}\n");
    exit(1);
}
fputcsv($f, ['decision', 'origine', 'a', 'b', 'score', 'decide_le'], ',', '"', '\\', "\n");
$consomme = '';
foreach ($paires as $p) {
    fputcsv($f, [
        (string) $p['decision'],
        (string) $p['origine'],
        (string) $p['a'],
        (string) $p['b'],
        $p['score'] === null ? '' : rtrim(rtrim(number_format((float) $p['score'], 4, '.', ''), '0'), '.'),
        (string) $p['decide_le'],
    ], ',', '"', '\\', "\n");
    if ((string) $p['decide_le'] > $consomme) {
        $consomme = (string) $p['decide_le'];
    }
}
fclose($f);

// Borne d'export en ISO à décalage explicite (leçon #1 : jamais d'heure nue
// ambiguë) — created_at est stocké UTC (fuseau de session forcé par db()).
$consommeIso = '';
if (preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', $consomme, $m) === 1) {
    $consommeIso = "{$m[1]}-{$m[2]}-{$m[3]}T{$m[4]}:{$m[5]}:{$m[6]}+00:00";
}

$positives = count($recolte['positives']);
$negatives = count($recolte['negatives']);
$depuisTxt = $depuis ?? 'le début';
echo "EXPORT {$total} paires ({$positives} fusions / {$negatives} ignorés) "
    . "depuis {$depuisTxt} → {$sortie}\n";
echo "CONSOMME_JUSQUA={$consommeIso}\n";
exit(0);
