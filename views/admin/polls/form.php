<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $poll
 * @var list<array<string,mixed>> $options
 */

$poll        = $poll ?? [];
$options     = $options ?? [];
?>
<form class="card surface glass" method="post" action="<?= e(url('/admin/sondages/save')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= e((string) ($poll['id'] ?? '')) ?>">

    <div class="field-row">
        <div class="field">
            <label for="title">Titre</label>
            <input type="text" id="title" name="title" value="<?= e($poll['title'] ?? '') ?>" required>
        </div>
        <div class="field">
            <label for="slug">Slug (URL, vide = auto)</label>
            <input type="text" id="slug" name="slug" value="<?= e($poll['slug'] ?? '') ?>">
        </div>
    </div>

    <div class="field">
        <label for="description">Description</label>
        <textarea id="description" name="description" rows="4"><?= e($poll['description'] ?? '') ?></textarea>
    </div>

    <div class="field">
        <label>Réponses possibles</label>
        <div id="poll-options-list" class="poll-options-admin">
            <?php foreach ($options as $i => $option): ?>
                <div class="poll-option-admin">
                    <input type="text" name="options[]" value="<?= e($option['label'] ?? '') ?>"
                           placeholder="Réponse <?= e((string) ($i + 1)) ?>" required>
                    <button type="button" class="btn btn-ghost btn-sm poll-option-remove" aria-label="Supprimer">✕</button>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-outline btn-sm" id="poll-option-add">+ Ajouter une option</button>
    </div>

    <div class="field-row">
        <?php datetime_selects_field('closes_at', 'closes_at', $poll['closes_at'] ?? null, 'Fermeture', 'vide = jamais'); ?>
        <div class="field">
            <label><input type="checkbox" name="is_multiple" value="1" <?= !empty($poll['is_multiple']) ? 'checked' : '' ?>> Choix multiple</label>
            <label><input type="checkbox" name="is_published" value="1" <?= !empty($poll['is_published']) ? 'checked' : '' ?>> Publié</label>
        </div>
    </div>

    <div class="admin-actions">
        <button type="submit" class="btn btn-primary">Enregistrer</button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/sondages')) ?>">Annuler</a>
    </div>
</form>

<script>
(function () {
    var list = document.getElementById('poll-options-list');
    var addBtn = document.getElementById('poll-option-add');
    if (!list || !addBtn) return;

    function removeHandler(btn) {
        btn.addEventListener('click', function () {
            var row = btn.closest('.poll-option-admin');
            if (row) row.remove();
        });
    }

    list.querySelectorAll('.poll-option-remove').forEach(removeHandler);

    addBtn.addEventListener('click', function () {
        var idx = list.querySelectorAll('.poll-option-admin').length + 1;
        var row = document.createElement('div');
        row.className = 'poll-option-admin';
        row.innerHTML =
            '<input type="text" name="options[]" placeholder="Réponse ' + idx + '" required>' +
            '<button type="button" class="btn btn-ghost btn-sm poll-option-remove" aria-label="Supprimer">✕</button>';
        list.appendChild(row);
        removeHandler(row.querySelector('.poll-option-remove'));
        var input = row.querySelector('input');
        if (input) input.focus();
    });
})();
</script>
