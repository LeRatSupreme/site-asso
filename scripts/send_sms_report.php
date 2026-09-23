<?php

declare(strict_types=1);

/**
 * AEIC — Rapport quotidien par SMS (API Free Mobile).
 *
 * Envoie aux destinataires enregistrés (page admin Notifications) un SMS
 * avec le CA du jour, le bénéfice et le top 3 produits, les jours choisis
 * à l'heure choisie (par défaut lundi-vendredi à 20h00).
 *
 * Cron : toutes les minutes (le script sort immédiatement hors de la
 * fenêtre d'envoi — la planification se gère dans l'admin) :
 *   0-59 * * * * /usr/bin/php /home/ubuntu/AEIC/scripts/send_sms_report.php >> /home/ubuntu/AEIC/cache/sms_report.log 2>&1
 *
 * Garde anti-doublon : un seul envoi par jour (setting sms_report_last_sent).
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/database.php';

use App\Core\SmsReport;

// Heure française explicite : le serveur peut être en UTC, la fenêtre
// horaire du rapport doit toujours suivre le fuseau de Paris.
$now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
$day = $now->format('Y-m-d');

if (!SmsReport::shouldRun($now)) {
    // Rien à faire : désactivé, mauvais jour, hors fenêtre, déjà envoyé.
    exit(0);
}

$report = SmsReport::buildMessage($day);
$results = SmsReport::sendToAll($report['message']);

$ok = 0;
foreach ($results as $r) {
    if ($r['ok']) {
        $ok++;
    } else {
        echo '[' . $now->format('c') . '] ÉCHEC ' . $r['label'] . ' (' . $r['status'] . ') : ' . ($r['error'] ?? '?') . "\n";
    }
}

// Marqué comme envoyé dès qu'au moins un SMS part, pour éviter les
// doublons si le cron repasse dans la fenêtre ; les échecs sont journalisés.
if ($ok > 0) {
    SmsReport::markSent($day);
}

echo '[' . $now->format('c') . '] Rapport SMS ' . $day . ' : ' . $ok . '/' . count($results)
    . ' envoyés (CA=' . number_format($report['ca'], 2, ',', ' ')
    . '€, bénéfice=' . number_format($report['profit'], 2, ',', ' ') . "€)\n";
