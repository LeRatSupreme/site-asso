<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Mailer;
use App\Core\Permissions;
use App\Core\SidebarOrder;
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
        'sumup_default_link', 'sumup_enabled', 'sumup_fee_rate',
        'maintenance_mode', 'orders_enabled', 'registrations_enabled',
        'og_image', 'twitter_handle', 'csp_directives',
        'facebook_url', 'instagram_url', 'linkedin_url',
        'discord_webhook_url', 'discord_enabled',
        'membership_price', 'membership_enabled', 'membership_season',
        'reco_marge_min', 'reco_stock_dormant_semaines', 'reco_stock_dormant_min',
        'reco_echantillon_min_unites', 'reco_duo_remise_max',
        'reco_immobilise_seuil_eur', 'reco_max_idees', 'reco_max_corrections',
        'dashboard_baisse_attention_pct', 'dashboard_baisse_alerte_pct',
    ];

    /**
     * Clés connues avec leur groupe et valeur par défaut : une clé absente
     * de la base est quand même AFFICHÉE sur la page Paramètres (sinon il
     * serait impossible de la créer via l'interface — le formulaire ne
     * génère que les champs rendus).
     *
     * @var array<string,array{0:string,1:string}>
     */
    private const KNOWN_DEFAULTS = [
        'site_name'              => ['general', 'AEIC'],
        'site_description'       => ['general', ''],
        'contact_email'          => ['contact', ''],
        'address'                => ['contact', ''],
        'map_lat'                => ['contact', ''],
        'map_lon'                => ['contact', ''],
        'mailer_from'            => ['email', ''],
        'mailer_from_name'       => ['email', ''],
        'smtp_host'              => ['email', ''],
        'smtp_port'              => ['email', ''],
        'smtp_user'              => ['email', ''],
        'smtp_pass'              => ['email', ''],
        'smtp_encryption'        => ['email', ''],
        'brevo_api_key'          => ['email', ''],
        'sumup_default_link'     => ['sumup', ''],
        'sumup_enabled'          => ['sumup', '0'],
        'sumup_fee_rate'         => ['sumup', '1.75'],
        'maintenance_mode'       => ['features', '0'],
        'orders_enabled'         => ['features', '1'],
        'registrations_enabled'  => ['features', '1'],
        'og_image'               => ['seo', ''],
        'twitter_handle'         => ['seo', ''],
        'csp_directives'         => ['seo', ''],
        'facebook_url'           => ['social', ''],
        'instagram_url'          => ['social', ''],
        'linkedin_url'           => ['social', ''],
        'discord_webhook_url'    => ['social', ''],
        'discord_enabled'        => ['social', '0'],
        'membership_price'       => ['cafeteria', ''],
        'membership_enabled'     => ['cafeteria', '0'],
        'membership_season'      => ['cafeteria', ''],
        'reco_marge_min'              => ['recommandations', '0.15'],
        'reco_stock_dormant_semaines' => ['recommandations', '8'],
        'reco_stock_dormant_min'      => ['recommandations', '10'],
        'reco_echantillon_min_unites' => ['recommandations', '20'],
        'reco_duo_remise_max'         => ['recommandations', '0.10'],
        'reco_immobilise_seuil_eur'   => ['recommandations', '30'],
        'reco_max_idees'              => ['recommandations', '3'],
        'reco_max_corrections'        => ['recommandations', '5'],
        'dashboard_baisse_attention_pct' => ['dashboard', '-10'],
        'dashboard_baisse_alerte_pct'    => ['dashboard', '-25'],
    ];

    public function index(): void
    {
        $this->guardSystemOrPage('settings');

        $settings = Setting::all();

        // Fusionne les clés connues absentes de la base (valeur par défaut) :
        // sans ça, une clé jamais créée n'apparaît pas et ne peut pas être
        // enregistrée depuis l'interface.
        $present = [];
        foreach ($settings as $s) {
            $present[(string) $s['key']] = true;
        }
        foreach (self::KNOWN_DEFAULTS as $key => [$group, $default]) {
            if (!isset($present[$key])) {
                $settings[] = [
                    'id'    => 'set_default_' . $key,
                    'key'   => $key,
                    'value' => $default,
                    'type'  => 'text',
                    'label' => $key,
                    'group' => $group,
                ];
            }
        }
        unset($s);
        usort($settings, static fn(array $a, array $b): int =>
            strcasecmp((string) $a['group'], (string) $b['group'])
            ?: strcasecmp((string) $a['key'], (string) $b['key'])
        );

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
        $this->guardSystemOrPage('settings');

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
     * AJAX : enregistre l'ordre de la sidebar admin (drag & drop).
     *
     * Corps JSON : {"order": {"sections": [...], "items": {...},
     * "subgroups": {...}}} ou {"reset": true} (retour à l'ordre par
     * défaut : stocke '{}'). Réponse {ok:true} ; erreurs 400/403 en JSON
     * (jamais de HTML pour ce endpoint).
     *
     * Garde : même accès que les autres actions settings ci-dessus
     * (guardSystemOrPage('settings')) — répliquée ici en réponse JSON,
     * car la garde standard redirige ou rend du HTML. CSRF : vérifié en
     * amont par le routeur pour tout POST /admin/* (Csrf::checkRequest,
     * header X-CSRF-Token ou champ _csrf).
     */
    public function sidebarOrder(): void
    {
        if (!Auth::check()
            || !in_array(Auth::role(), Permissions::adminRoles(), true)
            || (!Permissions::isSystemAdmin() && !Permissions::userHasExtraPage('settings'))) {
            $this->json(['ok' => false, 'error' => 'Accès refusé.'], 403);
        }

        $corps = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($corps)) {
            $this->json(['ok' => false, 'error' => 'Corps JSON invalide.'], 400);
        }

        if (($corps['reset'] ?? null) === true) {
            SidebarOrder::save([]);
            $this->audit('settings.sidebar_order', 'settings', null, ['reset' => true]);
            $this->json(['ok' => true]);
        }

        if (!array_key_exists('order', $corps) || !is_array($corps['order'])) {
            $this->json(['ok' => false, 'error' => 'Payload « order » manquant ou invalide.'], 400);
        }

        $ordre = SidebarOrder::sanitize($corps['order']);
        try {
            SidebarOrder::save($ordre);
        } catch (\InvalidArgumentException $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        $this->audit('settings.sidebar_order', 'settings', null, ['reset' => $ordre === []]);
        $this->json(['ok' => true]);
    }

    /**
     * Envoie un e-mail de test à l'adresse saisie, en utilisant la configuration
     * SMTP courante. Affiche le résultat (succès / erreur) via un flash.
     */
    public function testEmail(): void
    {
        $this->guardSystemOrPage('settings');

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
            $this->setFlash('success', sprintf('E-mail de test envoyé à %s (transport : %s).', $to, $host));
        } else {
            $this->setFlash('error', sprintf('Échec de l\'envoi à %s. Vérifiez la configuration SMTP.', $to));
        }

        redirect(url('/admin/settings'));
    }
}
