<?php

declare(strict_types=1);

/**
 * AEIC — Messages programmés par SMS (API Free Mobile).
 *
 * Envoie chaque message programmé (table sms_reports, page admin
 * Notifications) aux destinataires enregistrés, selon ses jours, son
 * heure et son modèle : CA du jour, bénéfice, top produits, catégories…
 *
 * Cron : toutes les minutes (le script filtre lui-même les jours et les
 * heures — fuseau Europe/Paris) :
 *   0-59 * * * * cd /home/ubuntu/AEIC && php scripts/send_sms_report.php >> /home/ubuntu/AEIC/logs/sms_report.log 2>&1
 *
 * Garde anti-doublon : colonne last_sent_day par message.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/database.php';

use App\Core\SmsReport;

// Heure française explicite : le serveur peut être en UTC, la fenêtre
// horaire des messages doit toujours suivre le fuseau de Paris.
$now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
$day = $now->format('Y-m-d');

$due = SmsReport::dueSchedules($now);
if ($due === []) {
    exit(0);
}

$sentAny = false;

foreach ($due as $schedule) {
    $id = (string) $schedule['id'];
    $label = trim((string) ($schedule['label'] ?? '')) !== '' ? (string) $schedule['label'] : $id;

    try {
        $message = SmsReport::buildFor($schedule, $day);
        $results = SmsReport::sendToAll($message);

        $ok = 0;
        foreach ($results as $r) {
            if ($r['ok']) {
                $ok++;
            } else {
                echo '[' . $now->format('c') . '] ÉCHEC "' . $label . '" → ' . $r['label']
                    . ' (' . $r['status'] . ') : ' . ($r['error'] ?? '?') . "\n";
            }
        }

        // Marqué comme envoyé dès qu'au moins un SMS part, pour éviter les
        // doublons si le cron repasse dans la fenêtre.
        if ($ok > 0) {
            SmsReport::markScheduleSent($id, $day);
            $sentAny = true;
            echo '[' . $now->format('c') . '] "' . $label . '" envoyé à ' . $ok . '/' . count($results) . " destinataire(s).\n";
        }
    } catch (\Throwable $e) {
        echo '[' . $now->format('c') . '] ERREUR "' . $label . '" : ' . $e->getMessage() . "\n";
    }
}

if (!$sentAny) {
    echo '[' . $now->format('c') . "] Messages dus : " . count($due) . ", aucun envoi réussi.\n";
}
