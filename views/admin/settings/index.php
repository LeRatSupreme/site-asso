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
];
?>
<form method="post" action="<?= e(url('/admin/settings/save')) ?>">
    <?= csrf_field() ?>
    <?php foreach ($groups as $group => $settings): ?>
        <section class="card surface glass">
            <h2 class="card-title"><?= e($groupLabels[$group] ?? ucfirst($group)) ?></h2>
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
                        <input type="text" id="set_<?= e((string) $s['id']) ?>" name="settings[<?= e($s['key']) ?>]" value="<?= e($s['value']) ?>">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>

    <div class="admin-actions">
        <button type="submit" class="btn btn-primary">Enregistrer les paramètres</button>
    </div>
</form>
