<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\SmsReport;
use App\Models\Setting;

/**
 * Page « Notifications » (groupe Système) : rapport quotidien par SMS
 * via l'API Free Mobile — CA du jour, bénéfice et top produits.
 */
final class AdminNotificationController extends AdminBaseController
{
    public function index(): void
    {
        $this->guardSystemOrPage('notifications');

        $report = SmsReport::buildMessage();

        $this->renderAdmin('admin/notifications/index', [
            'title'      => 'Notifications SMS',
            'enabled'    => Setting::getBool('sms_report_enabled', false),
            'days'       => Setting::get('sms_report_days', SmsReport::DEFAULT_DAYS),
            'time'       => Setting::get('sms_report_time', SmsReport::DEFAULT_TIME),
            'template'   => Setting::get('sms_report_template', SmsReport::DEFAULT_TEMPLATE),
            'recipients' => SmsReport::recipients(),
            'lastSent'   => Setting::get('sms_report_last_sent', ''),
            'preview'    => $report['message'],
            'ca'         => $report['ca'],
            'profit'     => $report['profit'],
        ]);
    }

    /**
     * Enregistre la planification et le modèle du message.
     */
    public function save(): void
    {
        $this->guardSystemOrPage('notifications');

        $enabled = isset($_POST['sms_report_enabled']) ? '1' : '0';
        $days = SmsReport::normalizeDays((array) ($_POST['sms_report_days'] ?? []));

        $time = trim((string) ($_POST['sms_report_time'] ?? ''));
        if (!preg_match('/^\d{1,2}:\d{2}$/', $time)) {
            $time = SmsReport::DEFAULT_TIME;
        }

        $template = trim((string) ($_POST['sms_report_template'] ?? ''));
        if ($template === SmsReport::DEFAULT_TEMPLATE) {
            $template = '';   // modèle par défaut : on ne duplique pas en base
        }

        Setting::set('sms_report_enabled', $enabled);
        Setting::set('sms_report_days', $days);
        Setting::set('sms_report_time', $time);
        Setting::set('sms_report_template', $template);

        $this->audit('sms_report.update', 'settings', null, ['enabled' => $enabled, 'days' => $days, 'time' => $time]);
        $this->setFlash('success', 'Planification du rapport SMS enregistrée.');
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
        $res = \App\Core\FreeMobileSms::send($user, $pass, 'AEIC : cette ligne est bien enregistrée pour recevoir le rapport quotidien.');
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
     * Envoie immédiatement un rapport de test (données réelles du jour).
     */
    public function test(): void
    {
        $this->guardSystemOrPage('notifications');

        if (SmsReport::recipients() === []) {
            $this->setFlash('error', 'Ajoutez d\'abord un destinataire.');
            redirect(url('/admin/notifications'));
        }

        $message = SmsReport::buildMessage()['message'];
        $results = SmsReport::sendToAll($message);

        $this->audit('sms_report.test', 'settings', null, $results);

        $ok = count(array_filter($results, static fn (array $r): bool => $r['ok']));
        $total = count($results);
        if ($ok === $total) {
            $this->setFlash('success', "Rapport de test envoyé à $total destinataire(s).");
        } elseif ($ok === 0) {
            $first = $results[0]['error'] ?? ('erreur ' . ($results[0]['status'] ?? '?'));
            $this->setFlash('error', 'Échec de l\'envoi : ' . $first);
        } else {
            $this->setFlash('error', "Envoyé à $ok/$total destinataire(s) — vérifiez les identifiants en erreur.");
        }
        redirect(url('/admin/notifications'));
    }
}
