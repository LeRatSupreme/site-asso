<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $member
 */
?>
<form class="card surface glass" method="post" action="<?= e(url('/admin/team/save')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= e((string) ($member['id'] ?? '')) ?>">

    <div class="field-row">
        <div class="field">
            <label for="prenom">Prénom</label>
            <input type="text" id="prenom" name="prenom" value="<?= e($member['prenom'] ?? '') ?>" required>
        </div>
        <div class="field">
            <label for="nom">Nom</label>
            <input type="text" id="nom" name="nom" value="<?= e($member['nom'] ?? '') ?>" required>
        </div>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="role">Rôle / fonction</label>
            <input type="text" id="role" name="role" value="<?= e($member['role'] ?? '') ?>" placeholder="Président, Trésorier…">
        </div>
        <div class="field">
            <label for="pole">Pôle</label>
            <input type="text" id="pole" name="pole" value="<?= e($member['pole'] ?? 'bureau') ?>">
        </div>
    </div>

    <div class="field">
        <label for="bio">Bio / description</label>
        <textarea id="bio" name="bio" rows="3"><?= e($member['bio'] ?? '') ?></textarea>
    </div>
    <div class="field">
        <label for="photo">Photo (URL ou chemin)</label>
        <input type="text" id="photo" name="photo" value="<?= e($member['photo'] ?? '') ?>">
    </div>

    <div class="field field-row">
        <div class="field">
            <label for="order">Ordre d'affichage</label>
            <input type="number" id="order" name="order" value="<?= e((string) ($member['order'] ?? 0)) ?>">
        </div>
        <label><input type="checkbox" name="is_highlight" value="1" <?= !empty($member['is_highlight']) ? 'checked' : '' ?>> Bureau restreint (mis en avant)</label>
        <label><input type="checkbox" name="is_active" value="1" <?= !empty($member['is_active']) ? 'checked' : '' ?>> Actif</label>
    </div>

    <div class="admin-actions">
        <button type="submit" class="btn btn-primary">Enregistrer</button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/team')) ?>">Annuler</a>
    </div>
</form>
