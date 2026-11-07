<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\FreeMobileSms;
use App\Core\SmsReport;
use App\Models\SmsSchedule;
use App\Models\Setting;

/**
 * Page « Notifications » (groupe Système) : messages programmés par SMS
 * via l'API Free Mobile — CA du jour, bénéfice, top produits, catégories…
 * Chaque message a ses propres jours, heure et modèle.
 */
final class AdminNotificationController extends AdminBaseController
{
    public function index(): void
    {
        $this->guardSystemOrPage('notifications');

        // Migration douce : si la table est vide mais qu'une config unique
        // existe (ancienne version), on la convertit en premier message.
        if (SmsSchedule::all() === [] && Setting::get('sms_report_enabled', '') !== '') {
            SmsSchedule::create([
                'label'      => 'Rapport quotidien',
                'is_enabled' => Setting::getBool('sms_report_enabled', false),
                'days'       => Setting::get('sms_report_days', SmsReport::DEFAULT_DAYS),
                'send_time'  => Setting::get('sms_report_time', SmsReport::DEFAULT_TIME),
                'template'   => Setting::get('sms_report_template', ''),
            ]);
        }

        $vars = SmsReport::varsFor();
        $schedules = [];
        foreach (SmsSchedule::all() as $s) {
            $template = trim((string) ($s['template'] ?? ''));
            $schedules[] = $s + [
                'time_hm' => substr((string) ($s['send_time'] ?? '20:00'), 0, 5),
                'preview' => SmsReport::renderTemplate(
                    $template !== '' ? $template : SmsReport::DEFAULT_TEMPLATE,
                    $vars
                ),
            ];
        }

        $this->renderAdmin('admin/notifications/index', [
            'title'      => 'Notifications SMS',
            'schedules'  => $schedules,
            'recipients' => SmsReport::recipients(),
            'vars'       => $vars,
            'varGroups'  => SmsReport::variableGroups(),
        ]);
    }

    /**
     * Crée un nouveau message (valeurs par défaut) et rouvre la page.
     */
    public function create(): void
    {
        $this->guardSystemOrPage('notifications');

        $label = trim((string) ($_POST['label'] ?? ''));
        if ($label === '') {
            $label = 'Message ' . (count(SmsSchedule::all()) + 1);
        }

        SmsSchedule::create(['label' => $label]);
        $this->audit('sms_report.create', 'sms_reports', null, ['label' => $label]);
        $this->setFlash('success', 'Message créé — configure-le puis active-le.');
        redirect(url('/admin/notifications'));
    }

    /**
     * Enregistre un message (libellé, activation, jours, heure, modèle).
     */
    public function save(string $id): void
    {
        $this->guardSystemOrPage('notifications');

        $schedule = SmsSchedule::find($id);
        if ($schedule === null) {
            $this->setFlash('error', 'Message introuvable.');
            redirect(url('/admin/notifications'));
        }

        $days = SmsReport::normalizeDays((array) ($_POST['days'] ?? []));

        $time = trim((string) ($_POST['send_time'] ?? ''));
        if (!preg_match('/^\d{1,2}:\d{2}$/', $time)) {
            $time = SmsReport::DEFAULT_TIME;
        }

        $template = trim((string) ($_POST['template'] ?? ''));
        if ($template === SmsReport::DEFAULT_TEMPLATE) {
            $template = '';   // modèle par défaut : on ne duplique pas en base
        }

        SmsSchedule::updateRow($id, [
            'label'      => (string) ($_POST['label'] ?? ''),
            'is_enabled' => isset($_POST['is_enabled']),
            'days'       => $days,
            'send_time'  => $time,
            'template'   => $template,
        ]);

        $this->audit('sms_report.update', 'sms_reports', $id, ['days' => $days, 'time' => $time]);
        $this->setFlash('success', 'Message enregistré.');
        redirect(url('/admin/notifications'));
    }

    /**
     * Supprime un message programmé.
     */
    public function delete(string $id): void
    {
        $this->guardSystemOrPage('notifications');

        SmsSchedule::delete($id);
        $this->audit('sms_report.delete', 'sms_reports', $id);
        $this->setFlash('success', 'Message supprimé.');
        redirect(url('/admin/notifications'));
    }

    /**
     * Ajoute un destinataire (ligne Free Mobile) et envoie un SMS de bienvenue.
     */
    public function addRecipient(): void
    {
        $this->guardSystemOrPage('notifications');

        $label = trim((string) ($_POST['label'] ?? ''));
        $user = preg_replace('/\D/', '', (string) ($_POST['user'] ?? '')) ?? '';
        $pass = trim((string) ($_POST['pass'] ?? ''));

        if ($user === '' || $pass === '') {
            $this->setFlash('error', 'Identifiant et clé Free Mobile obligatoires.');
            redirect(url('/admin/notifications'));
        }

        $recipients = SmsReport::recipients();
        foreach ($recipients as $r) {
            if ($r['user'] === $user) {
                $this->setFlash('error', 'Cet identifiant Free Mobile existe déjà.');
                redirect(url('/admin/notifications'));
            }
        }

        $recipients[] = ['label' => mb_substr($label, 0, 40), 'user' => $user, 'pass' => $pass];
        SmsReport::saveRecipients($recipients);

        // SMS de confirmation immédiat pour valider la configuration.
        $res = FreeMobileSms::send($user, $pass, 'AEIC : cette ligne est bien enregistrée pour recevoir les rapports.');
        $this->audit('sms_report.recipient.add', 'settings', $user, ['ok' => $res['ok']]);

        if ($res['ok']) {
            $this->setFlash('success', 'Destinataire ajouté, SMS de confirmation envoyé.');
        } else {
            $this->setFlash('error', 'Destinataire ajouté, mais le SMS de test a échoué (' . ($res['error'] ?? 'erreur ' . $res['status']) . ').');
        }
        redirect(url('/admin/notifications'));
    }

    /**
     * Supprime un destinataire (index dans la liste).
     */
    public function deleteRecipient(string $index): void
    {
        $this->guardSystemOrPage('notifications');

        $recipients = SmsReport::recipients();
        $i = (int) $index;
        if (!isset($recipients[$i])) {
            $this->setFlash('error', 'Destinataire introuvable.');
            redirect(url('/admin/notifications'));
        }

        $removed = $recipients[$i];
        unset($recipients[$i]);
        SmsReport::saveRecipients(array_values($recipients));

        $this->audit('sms_report.recipient.delete', 'settings', (string) $removed['user']);
        $this->setFlash('success', 'Destinataire supprimé.');
        redirect(url('/admin/notifications'));
    }

    /**
     * Envoie immédiatement un message de test (données réelles du jour).
     */
    public function test(string $id): void
    {
        $this->guardSystemOrPage('notifications');

        $schedule = SmsSchedule::find($id);
        if ($schedule === null) {
            $this->setFlash('error', 'Message introuvable.');
            redirect(url('/admin/notifications'));
        }

        if (SmsReport::recipients() === []) {
            $this->setFlash('error', 'Ajoutez d\'abord un destinataire.');
            redirect(url('/admin/notifications'));
        }

        $message = SmsReport::buildFor($schedule);
        $results = SmsReport::sendToAll($message);
        $this->audit('sms_report.test', 'sms_reports', $id, $results);

        $ok = count(array_filter($results, static fn (array $r): bool => $r['ok']));
        $total = count($results);
        if ($ok === $total) {
            $this->setFlash('success', "Message de test envoyé à $total destinataire(s).");
        } elseif ($ok === 0) {
            $first = $results[0]['error'] ?? ('erreur ' . ($results[0]['status'] ?? '?'));
            $this->setFlash('error', 'Échec de l\'envoi : ' . $first);
        } else {
            $this->setFlash('error', "Envoyé à $ok/$total destinataire(s) — vérifiez les identifiants en erreur.");
        }
        redirect(url('/admin/notifications'));
    }
}
