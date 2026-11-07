<?php

declare(strict_types=1);

/**
 * AEIC — P2-auto (brief A2, dev-factures) : marque l'issue d'un run du
 * pipeline dans etat.json — UNIQUE écrivain autorisé :
 * T1AutoRetrain::marquer() (verrou flock <etat>.lock, écriture atomique,
 * journal data/eval_t1/p2/auto/journal.md tenu au passage).
 *
 * Usage (appelé par scripts/t1_auto_retrain.sh) :
 *   php scripts/t1_auto_retrain_etat.php --marquer=echec    --detail="kaggle injoignable"
 *   php scripts/t1_auto_retrain_etat.php --marquer=remplace --modele="…" --run=2026-…T…+00:00
 *   php scripts/t1_auto_retrain_etat.php --marquer=consserve --run=2026-…T…+00:00 [--detail="…"]
 *
 * Sémantique (T1AutoRetrain::marquer) : echec → dernier_run INCHANGÉ (les
 * paires restent comptées, la boucle A1 réessaie après l'anti-tempête) ;
 * remplace/consserve → paires consommées (dernier_run = --run = borne
 * d'export, compteur remis à 0, recompté au prochain passage).
 *
 * AEIC_P2AUTO_ETAT / AEIC_P2AUTO_JOURNAL (env) redirigent l'état/le journal
 * (validation sur aeic_test, tests) ; défaut : chemins production.
 *
 * Codes retour : 0 = marqué · 1 = erreur d'usage/persistance.
 */

foreach (['AEIC_P2AUTO_ETAT', 'AEIC_P2AUTO_JOURNAL'] as $constante) {
    $valeur = getenv($constante);
    if (is_string($valeur) && $valeur !== '' && !defined($constante)) {
        define($constante, $valeur);
    }
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/config.php';   // AEIC_ROOT (constantes de chemins T1AutoRetrain)

use App\Core\Compta\T1\T1AutoRetrain;

$opts = getopt('', ['marquer:', 'run::', 'modele::', 'detail::']);
$statut = (string) ($opts['marquer'] ?? '');
if (!in_array($statut, [T1AutoRetrain::STATUT_ECHEC, T1AutoRetrain::STATUT_REMPLACE, T1AutoRetrain::STATUT_CONSSERVE], true)) {
    fwrite(STDERR, "usage : php scripts/t1_auto_retrain_etat.php --marquer=echec|remplace|consserve [--run=ISO] [--modele=…] [--detail=…]\n");
    exit(1);
}
$run = isset($opts['run']) && is_string($opts['run']) && $opts['run'] !== '' ? $opts['run'] : null;
$modele = isset($opts['modele']) && is_string($opts['modele']) && $opts['modele'] !== '' ? $opts['modele'] : null;
$detail = isset($opts['detail']) && is_string($opts['detail']) && $opts['detail'] !== '' ? $opts['detail'] : null;

if (!T1AutoRetrain::marquer($statut, $run, $modele, $detail)) {
    fwrite(STDERR, "ERREUR : marquage {$statut} impossible (état illisible ou verrou indisponible)\n");
    exit(1);
}

echo "ETAT={$statut}" . ($run !== null ? " run={$run}" : '') . "\n";
exit(0);
