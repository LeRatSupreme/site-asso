<?php

declare(strict_types=1);

/**
 * AEIC — Script de rappels d'événements (24h et 1h avant).
 *
 * À exécuter via cron toutes les 15 minutes :
 *   */15 * * * * /usr/bin/php /home/ubuntu/AEIC/scripts/send_event_reminders.php >> /home/ubuntu/AEIC/logs/reminders.log 2>&1
 *
 * Vérifie les événements à venir et envoie des emails de rappel aux
 * inscrits 24h puis 1h avant l'événement. Les colonnes
 * reminder_24h_sent / reminder_1h_sent évitent les doublons.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/database.php';

use App\Core\Mailer;
use App\Models\Setting;

$siteName = Setting::get('site_name', 'AEIC');
$siteUrl  = Setting::get('app_url', getenv('APP_URL') ?: 'https://asso.aremond.ovh');
if ($siteUrl === '') {
    $siteUrl = defined('APP_URL') ? APP_URL : 'https://asso.aremond.ovh';
}

$now = date('Y-m-d H:i:s');
$sent24h = 0;
$sent1h  = 0;

// --- Rappel 24h : événements dans les 23h–25h prochaines -----------------
$rows24 = db()->query(
    "SELECT r.id AS reg_id, r.user_id, e.title, e.slug, e.date, e.location,
            u.email, u.prenom
     FROM event_registrations r
     INNER JOIN events e ON e.id = r.event_id
     INNER JOIN users u ON u.id = r.user_id
     WHERE r.reminder_24h_sent = 0
       AND u.email IS NOT NULL AND u.email != ''
       AND u.is_active = 1
       AND e.date BETWEEN DATE_ADD(NOW(), INTERVAL 23 HOUR)
                       AND DATE_ADD(NOW(), INTERVAL 25 HOUR)"
)->fetchAll();

foreach ($rows24 as $row) {
    $email = (string) $row['email'];
    $prenom = (string) $row['prenom'];
    $title = (string) $row['title'];
    $slug = (string) $row['slug'];
    $eventDate = (string) $row['date'];
    $location = (string) ($row['location'] ?? '');
    $regId = (string) $row['reg_id'];

    try {
        Mailer::send('event_reminder_24h', $email, 'Rappel : ' . $title . ' demain — ' . $siteName, [
            'prenom'     => $prenom,
            'eventTitle' => $title,
            'eventDate'  => $eventDate,
            'eventUrl'   => $siteUrl . '/events/' . $slug,
            'location'   => $location,
            'siteName'   => $siteName,
            'siteUrl'    => $siteUrl,
        ]);

        // Marquer comme envoyé.
        $stmt = db()->prepare('UPDATE event_registrations SET reminder_24h_sent = 1 WHERE id = ?');
        $stmt->execute([$regId]);
        $sent24h++;
    } catch (\Throwable $e) {
        echo '[' . date('c') . '] ERREUR 24h ' . $email . ' : ' . $e->getMessage() . "\n";
    }
}

// --- Rappel 1h : événements dans les 50min–70min prochaines ---------------
$rows1 = db()->query(
    "SELECT r.id AS reg_id, r.user_id, e.title, e.slug, e.date, e.location,
            u.email, u.prenom
     FROM event_registrations r
     INNER JOIN events e ON e.id = r.event_id
     INNER JOIN users u ON u.id = r.user_id
     WHERE r.reminder_1h_sent = 0
       AND u.email IS NOT NULL AND u.email != ''
       AND u.is_active = 1
       AND e.date BETWEEN DATE_ADD(NOW(), INTERVAL 50 MINUTE)
                       AND DATE_ADD(NOW(), INTERVAL 70 MINUTE)"
)->fetchAll();

foreach ($rows1 as $row) {
    $email = (string) $row['email'];
    $prenom = (string) $row['prenom'];
    $title = (string) $row['title'];
    $slug = (string) $row['slug'];
    $eventDate = (string) $row['date'];
    $location = (string) ($row['location'] ?? '');
    $regId = (string) $row['reg_id'];

    try {
        Mailer::send('event_reminder_1h', $email, 'Ça commence bientôt : ' . $title . ' — ' . $siteName, [
            'prenom'     => $prenom,
            'eventTitle' => $title,
            'eventDate'  => $eventDate,
            'eventUrl'   => $siteUrl . '/events/' . $slug,
            'location'   => $location,
            'siteName'   => $siteName,
            'siteUrl'    => $siteUrl,
        ]);

        $stmt = db()->prepare('UPDATE event_registrations SET reminder_1h_sent = 1 WHERE id = ?');
        $stmt->execute([$regId]);
        $sent1h++;
    } catch (\Throwable $e) {
        echo '[' . date('c') . '] ERREUR 1h ' . $email . ' : ' . $e->getMessage() . "\n";
    }
}

echo '[' . date('c') . '] Rappels envoyés : 24h=' . $sent24h . ', 1h=' . $sent1h . "\n";
