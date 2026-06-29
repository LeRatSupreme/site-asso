<?php

declare(strict_types=1);

/**
 * @var array<string,list<array<string,mixed>>> $groups
 */
$groupLabels = [
    'site'      => 'Site',
    'general'   => 'Général',
    'cafeteria' => 'Cafétéria',
    'events'    => 'Événements',
    'features'  => 'Fonctionnalités',
    'email'     => 'Emails / SMTP',
    'seo'       => 'SEO',
    'sumup'     => 'SumUp (paiement en ligne)',
];
?>
<form method="post" action="<?= e(url('/admin/settings/save')) ?>">
    <?= csrf_field() ?>
    <?php foreach ($groups as $group => $settings): ?>
        <section class="card surface glass">
            <h2 class="card-title"><?= e($groupLabels[$group] ?? ucfirst($group)) ?></h2>
            <?php if ($group === 'email'): ?>
                <p class="card-meta">
                    Laissez l'hôte SMTP vide pour utiliser la fonction <code>mail()</code> native du serveur.
                    Renseignez ces champs pour envoyer via SMTP (recommandé en production). Chiffrement&nbsp;:
                    <code>tls</code> (STARTTLS, port 587), <code>ssl</code> (implicite, port 465),
                    <code>none</code> ou vide (auto).
                </p>
            <?php endif; ?>
            <?php if ($group === 'sumup'): ?>
                <p class="card-meta">
                    Paiement <strong>par lien SumUp</strong> : collez ici l'URL de paiement SumUp
                    (générée depuis l'app/dashboard SumUp). Aucune intégration API : le site se
                    contente d'ouvrir ce lien dans un nouvel onglet (détail événement, cafétéria, POS).
                    Activez le bouton « sumup_enabled » pour faire apparaître les boutons de paiement.
                </p>
            <?php endif; ?>
            <?php foreach ($settings as $s): ?>
                <div class="field">
                    <label for="set_<?= e((string) $s['id']) ?>">
                        <code><?= e($s['key']) ?></code>
                        <?php if (!empty($s['description'])): ?>
                            <span class="card-meta">— <?= e($s['description']) ?></span>
                        <?php endif; ?>
                    </label>
                    <?php $truthy = in_array(strtolower((string) $s['value']), ['1', 'true', 'on', 'yes'], true); ?>
                    <?php if (in_array(strtolower((string) $s['value']), ['0', '1', 'true', 'false'], true)): ?>
                        <select id="set_<?= e((string) $s['id']) ?>" name="settings[<?= e($s['key']) ?>]">
                            <option value="1" <?= $truthy ? 'selected' : '' ?>>Activé (1)</option>
                            <option value="0" <?= !$truthy ? 'selected' : '' ?>>Désactivé (0)</option>
                        </select>
                    <?php else: ?>
                        <?php $inputType = match ($s['key']) {
                            'smtp_pass' => 'password',
                            'sumup_default_link' => 'url',
                            default => 'text',
                        }; ?>
                        <input type="<?= $inputType ?>"
                               id="set_<?= e((string) $s['id']) ?>"
                               name="settings[<?= e($s['key']) ?>]"
                               value="<?= e($s['value']) ?>"
                               <?= $inputType === 'url' ? 'placeholder="https://pay.sumup.com/..."' : '' ?>>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php if ($group === 'email'): ?>
                <div class="field admin-actions">
                    <hr>
                    <label for="test_email">Envoyer un e-mail de test à&nbsp;:</label>
                    <input type="email" id="test_email" name="test_email" placeholder="vous@exemple.fr">
                    <button type="submit" formaction="<?= e(url('/admin/settings/test-email')) ?>"
                            class="btn btn-secondary">Envoyer un e-mail de test</button>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>

    <div class="admin-actions">
        <button type="submit" class="btn btn-primary">Enregistrer les paramètres</button>
    </div>
</form>
