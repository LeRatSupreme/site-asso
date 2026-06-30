<?php

declare(strict_types=1);

/**
 * @var array<string,list<array<string,mixed>>> $groups
 */

$groupConfig = [
    'general'   => ['icon' => '⚙️', 'label' => 'Général'],
    'contact'   => ['icon' => '📍', 'label' => 'Contact & Localisation'],
    'email'     => ['icon' => '📧', 'label' => 'Emails / SMTP'],
    'seo'       => ['icon' => '🔍', 'label' => 'SEO & Réseaux sociaux'],
    'features'  => ['icon' => '🎛️', 'label' => 'Fonctionnalités'],
    'sumup'     => ['icon' => '💳', 'label' => 'SumUp — Paiement en ligne'],
    'site'      => ['icon' => '🌐', 'label' => 'Site'],
    'cafeteria' => ['icon' => '☕', 'label' => 'Cafétéria'],
    'events'    => ['icon' => '📅', 'label' => 'Événements'],
    'social'    => ['icon' => '📱', 'label' => 'Réseaux sociaux'],
];

// Libellés lisibles par clé technique.
$niceLabels = [
    'site_name'           => 'Nom du site',
    'site_description'    => 'Description courte (meta + accueil)',
    'contact_email'       => 'Email de contact',
    'logo_url'            => 'Logo (URL)',
    'address'             => 'Adresse',
    'map_lat'             => 'Latitude (carte)',
    'map_lon'             => 'Longitude (carte)',
    'mailer_from'         => 'Adresse d\\'expédition',
    'mailer_from_name'    => 'Nom affiché (expéditeur)',
    'smtp_host'           => 'Serveur SMTP',
    'smtp_port'           => 'Port SMTP',
    'smtp_user'           => 'Identifiant SMTP',
    'smtp_pass'           => 'Mot de passe SMTP',
    'smtp_encryption'     => 'Chiffrement (tls / ssl / none)',
    'brevo_api_key'       => 'Clé API Brevo (recommandé)',
    'sumup_default_link'  => 'Lien de paiement SumUp',
    'sumup_enabled'       => 'Activer les paiements SumUp',
    'maintenance_mode'    => 'Mode maintenance',
    'orders_enabled'      => 'Commandes en ligne',
    'registrations_enabled' => 'Inscriptions aux événements',
    'og_image'            => 'Image Open Graph (partage social)',
    'twitter_handle'      => 'Compte Twitter/X',
    'facebook_url'        => 'Page Facebook',
    'instagram_url'       => 'Page Instagram',
    'linkedin_url'        => 'Page LinkedIn',
    'csp_directives'      => 'Content-Security-Policy (avancé)',
    'default_sumup_link'  => 'Lien SumUp par défaut',
];

$boolKeys = ['maintenance_mode', 'orders_enabled', 'registrations_enabled', 'sumup_enabled'];
$passwordKeys = ['smtp_pass', 'brevo_api_key'];
?>
<form method="post" action="<?= e(url('/admin/settings/save')) ?>" id="settings-form">
    <?= csrf_field() ?>

    <?php foreach ($groups as $group => $settings):
        $cfg = $groupConfig[$group] ?? ['icon' => '🔧', 'label' => ucfirst($group)];
    ?>
        <section class="card surface glass settings-group">
            <div class="settings-group-head">
                <h2 class="card-title"><?= e($cfg['icon'] . ' ' . $cfg['label']) ?></h2>
            </div>

            <?php if ($group === 'email'): ?>
                <p class="settings-help">
                    💡 Configurez <strong>la clé API Brevo</strong> (le plus simple et fiable).
                    À défaut, les champs SMTP sont utilisés. Laissez tout vide pour <code>mail()</code> natif.
                </p>
            <?php elseif ($group === 'sumup'): ?>
                <p class="settings-help">
                    💡 Colle l'URL de paiement SumUp (depuis l'app SumUp). Active ensuite le bouton ci-dessous
                    pour faire apparaître les boutons « Payer en ligne » sur le site.
                </p>
            <?php elseif ($group === 'contact'): ?>
                <p class="settings-help">📍 Coordonnées affichées sur le site et la carte interactive.</p>
            <?php endif; ?>

            <div class="settings-grid">
                <?php foreach ($settings as $s):
                    $key = (string) $s['key'];
                    $label = $niceLabels[$key] ?? $s['label'] ?? $key;
                    $value = (string) $s['value'];
                    $isBool = in_array($key, $boolKeys, true) || in_array(strtolower($value), ['0', '1', 'true', 'false'], true);
                    $isPassword = in_array($key, $passwordKeys, true);
                    $inputType = $isPassword ? 'password' : (str_contains($key, 'url') || str_contains($key, 'link') ? 'url' : 'text');
                ?>
                    <div class="field">
                        <label for="set_<?= e((string) $s['id']) ?>"><?= e($label) ?></label>

                        <?php if ($isBool): ?>
                            <?php $truthy = in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true); ?>
                            <label class="toggle-switch">
                                <input type="checkbox" id="set_<?= e((string) $s['id']) ?>"
                                       name="settings[<?= e($key) ?>]" value="1" <?= $truthy ? 'checked' : '' ?>
                                       onchange="this.value = this.checked ? '1' : '0';">
                                <span class="toggle-slider"></span>
                                <span class="toggle-label <?= $truthy ? 'is-on' : '' ?>">
                                    <?= $truthy ? '✅ Activé' : '⛔ Désactivé' ?>
                                </span>
                            </label>

                        <?php elseif ($key === 'site_description'): ?>
                            <textarea id="set_<?= e((string) $s['id']) ?>"
                                      name="settings[<?= e($key) ?>]"
                                      rows="2"><?= e($value) ?></textarea>

                        <?php else: ?>
                            <input type="<?= $inputType ?>"
                                   id="set_<?= e((string) $s['id']) ?>"
                                   name="settings[<?= e($key) ?>]"
                                   value="<?= e($value) ?>"
                                   class="<?= $isPassword ? 'input-password' : '' ?>"
                                   <?= $inputType === 'url' ? 'placeholder="https://..."' : '' ?>>
                            <?php if ($isPassword && $value !== ''): ?>
                                <button type="button" class="btn btn-ghost btn-sm toggle-pwd"
                                        onclick="var i=this.previousElementSibling; i.type = i.type==='password'?'text':'password'; this.textContent = i.type==='password'?'👁 Voir':'🙈 Masquer';">
                                    👁 Voir
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($group === 'email'): ?>
                <div class="settings-test-email">
                    <hr>
                    <div class="field" style="display:flex;align-items:flex-end;gap:0.75rem;flex-wrap:wrap;">
                        <div style="flex:1;min-width:200px;">
                            <label for="test_email">📨 Envoyer un e-mail de test à</label>
                            <input type="email" id="test_email" name="test_email" placeholder="vous@exemple.fr">
                        </div>
                        <button type="submit" formaction="<?= e(url('/admin/settings/test-email')) ?>"
                                class="btn btn-primary">Envoyer le test</button>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>

    <div class="settings-save-bar">
        <button type="submit" class="btn btn-primary btn-lg">💾 Enregistrer les paramètres</button>
    </div>
</form>

<script>
// Toggle dynamique du label (Activé/Désactivé) pour les switches.
document.querySelectorAll('.toggle-switch input[type="checkbox"]').forEach(function(cb) {
    cb.addEventListener('change', function() {
        var label = this.parentElement.querySelector('.toggle-label');
        if (label) {
            label.textContent = this.checked ? '✅ Activé' : '⛔ Désactivé';
            label.classList.toggle('is-on', this.checked);
        }
    });
});
</script>
