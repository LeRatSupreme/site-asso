<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Mailer;
use App\Core\Validator;
use App\Models\Setting;

/**
 * Paramètres du site (regroupés par groupe).
 */
final class AdminSettingController extends AdminBaseController
{
    public function index(): void
    {
        $this->guard();

        $settings = Setting::all();

        // Regroupement par `group`.
        $groups = [];
        foreach ($settings as $s) {
            $groups[(string) ($s['group'] ?: 'general')][] = $s;
        }

        $this->renderAdmin('admin/settings/index', [
            'title'   => 'Paramètres',
            'groups'  => $groups,
        ]);
    }

    public function save(): void
    {
        $this->guard();

        foreach (($_POST['settings'] ?? []) as $key => $value) {
            $key = (string) $key;
            if ($key === '') {
                continue;
            }
            Setting::set($key, (string) $value);
        }

        $this->audit('settings.update', 'settings', null);
        $this->setFlash('success', 'Paramètres enregistrés.');
        redirect(url('/admin/settings'));
    }

    /**
     * Envoie un e-mail de test à l'adresse saisie, en utilisant la configuration
     * SMTP courante. Affiche le résultat (succès / erreur) via un flash.
     */
    public function testEmail(): void
    {
        $this->guard();

        $to = trim((string) ($_POST['test_email'] ?? ''));

        if (!Validator::isValidEmail($to)) {
            $this->setFlash('error', 'Adresse e-mail de test invalide.');
            redirect(url('/admin/settings'));
        }

        $host = Mailer::isSmtpConfigured() ? Mailer::config()['host'] : '(mail() natif)';

        try {
            $ok = Mailer::sendRaw(
                $to,
                'Test d\'envoi — AEIC',
                "Ceci est un e-mail de test envoyé depuis les paramètres de l'AEIC.\n\n"
                . "Transport : " . $host . "\n"
                . "Si vous lisez ce message, la configuration fonctionne."
            );
        } catch (\Throwable $e) {
            $ok = false;
        }

        if ($ok) {
            $this->audit('settings.test_email', 'settings', null, ['to' => $to]);
            $this->setFlash('success', sprintf('E-mail de test envoyé à %s (transport : %s).', e($to), e($host)));
        } else {
            $this->setFlash('error', sprintf('Échec de l\'envoi à %s. Vérifiez la configuration SMTP.', e($to)));
        }

        redirect(url('/admin/settings'));
    }
}
