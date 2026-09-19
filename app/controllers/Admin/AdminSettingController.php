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
    /**
     * Whitelist des clés éditables via le formulaire admin
     * (cf. views/admin/settings/index.php). Toute autre clé
     * soumise est ignorée.
     */
    private const EDITABLE_KEYS = [
        'site_name', 'site_description', 'contact_email', 'logo_url',
        'address', 'map_lat', 'map_lon',
        'mailer_from', 'mailer_from_name',
        'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_encryption',
        'brevo_api_key',
        'sumup_default_link', 'sumup_enabled', 'default_sumup_link',
        'maintenance_mode', 'orders_enabled', 'registrations_enabled',
        'og_image', 'twitter_handle', 'csp_directives',
        'facebook_url', 'instagram_url', 'linkedin_url',
        'discord_webhook_url', 'discord_enabled',
        'membership_price', 'membership_enabled', 'membership_season',
    ];

    public function index(): void
    {
        $this->guardSystem();

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
        $this->guardSystem();

        foreach (($_POST['settings'] ?? []) as $key => $value) {
            $key = (string) $key;
            if ($key === '' || !in_array($key, self::EDITABLE_KEYS, true)) {
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
        $this->guardSystem();

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
