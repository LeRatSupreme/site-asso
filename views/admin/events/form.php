<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $event
 */
?>
<form class="card surface glass" method="post" action="<?= e(url('/admin/events/save')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= e((string) ($event['id'] ?? '')) ?>">

    <div class="field-row">
        <div class="field">
            <label for="title">Titre</label>
            <input type="text" id="title" name="title" value="<?= e($event['title'] ?? '') ?>" required>
        </div>
        <div class="field">
            <label for="slug">Slug (URL)</label>
            <input type="text" id="slug" name="slug" value="<?= e($event['slug'] ?? '') ?>" required>
        </div>
    </div>

    <div class="field">
        <label for="excerpt">Extrait (carte + SEO)</label>
        <input type="text" id="excerpt" name="excerpt" maxlength="500" value="<?= e($event['excerpt'] ?? '') ?>">
    </div>

    <div class="field">
        <label for="description">Description (HTML)</label>
        <textarea id="description" name="description" rows="8"><?= e($event['description'] ?? '') ?></textarea>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="date">Date</label>
            <input type="datetime-local" id="date" name="date"
                   value="<?= e(!empty($event['date']) ? date('Y-m-d\TH:i', strtotime((string) $event['date'])) : '') ?>">
        </div>
        <div class="field">
            <label for="location">Lieu</label>
            <input type="text" id="location" name="location" value="<?= e($event['location'] ?? '') ?>">
            <p class="field-help">Adresse utilisée pour la carte (si activée ci-dessous).</p>
        </div>
        <div class="field">
            <label class="checkbox-inline">
                <input type="checkbox" name="show_map" value="1" <?= !empty($event['show_map']) ? 'checked' : '' ?>>
                Afficher une carte du lieu
            </label>
            <p class="field-help">Coche pour afficher une carte interactive sur la page de l'événement (le lieu est géocodé automatiquement à l'enregistrement).</p>
        </div>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="price">Prix (€, vide = gratuit)</label>
            <input type="text" id="price" name="price" value="<?= e($event['price'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="max_capacity">Capacité max (vide = illimité)</label>
            <input type="number" id="max_capacity" name="max_capacity" value="<?= e($event['max_capacity'] ?? '') ?>">
        </div>
    </div>

    <div class="field">
        <label for="image">Image (URL ou chemin)</label>
        <input type="text" id="image" name="image" value="<?= e($event['image'] ?? '') ?>">
    </div>
    <div class="field">
        <label for="sumup_link">Lien de paiement SumUp</label>
        <input type="url" id="sumup_link" name="sumup_link" value="<?= e($event['sumup_link'] ?? '') ?>">
    </div>

    <div class="field field-row">
        <label><input type="checkbox" name="is_featured" value="1" <?= !empty($event['is_featured']) ? 'checked' : '' ?>> Mis en avant</label>
        <label><input type="checkbox" name="is_published" value="1" <?= !empty($event['is_published']) ? 'checked' : '' ?>> Publié</label>
    </div>

    <div class="admin-actions">
        <button type="submit" class="btn btn-primary">Enregistrer</button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/events')) ?>">Annuler</a>
    </div>
</form>
