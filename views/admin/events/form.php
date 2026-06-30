<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $event
 */
$isNew = empty($event['id']);
?>
<form method="post" action="<?= e(url('/admin/events/save')) ?>" id="event-form">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= e((string) ($event['id'] ?? '')) ?>">

    <!-- Titre + Slug -->
    <section class="card surface glass form-section">
        <h2 class="form-section-title">📋 Informations générales</h2>
        <div class="field-row">
            <div class="field">
                <label for="title">Titre de l'événement</label>
                <input type="text" id="title" name="title" value="<?= e($event['title'] ?? '') ?>" required placeholder="Ex: Soirée d'intégration">
            </div>
            <div class="field">
                <label for="slug">URL (slug)</label>
                <input type="text" id="slug" name="slug" value="<?= e($event['slug'] ?? '') ?>" required placeholder="soiree-integration">
            </div>
        </div>
        <div class="field">
            <label for="category">Catégorie</label>
            <input type="text" id="category" name="category" list="event-categories" value="<?= e($event['category'] ?? '') ?>" placeholder="Choisir une catégorie…">
            <datalist id="event-categories">
                <option value="Soirée">
                <option value="Afterwork">
                <option value="Barbecue">
                <option value="Tournoi / LAN">
                <option value="Conférence">
                <option value="Sortie">
                <option value="Atelier">
                <option value="Nuit de l'Info">
                <option value="Autre">
            </datalist>
        </div>
        <div class="field">
            <label for="excerpt">Extrait (carte + SEO)</label>
            <textarea id="excerpt" name="excerpt" rows="2" maxlength="500" placeholder="Résumé court affiché sur les cartes."><?= e($event['excerpt'] ?? '') ?></textarea>
        </div>
        <div class="field">
            <label for="description">Description (HTML)</label>
            <textarea id="description" name="description" rows="8" placeholder="<p>Détails de l'événement…</p>"><?= e($event['description'] ?? '') ?></textarea>
        </div>
    </section>

    <!-- Date + Lieu -->
    <section class="card surface glass form-section">
        <h2 class="form-section-title">📅 Date & Lieu</h2>
        <div class="field-row">
            <div class="field">
                <label for="date">Date et heure</label>
                <input type="datetime-local" id="date" name="date"
                       value="<?= e(!empty($event['date']) ? date('Y-m-d\TH:i', strtotime((string) $event['date'])) : '') ?>">
            </div>
            <div class="field">
                <label for="location">Lieu</label>
                <input type="text" id="location" name="location" value="<?= e($event['location'] ?? '') ?>" placeholder="Ex: IUT de Calais — Amphi A">
            </div>
        </div>
        <div class="field">
            <label class="toggle-switch">
                <input type="checkbox" name="show_map" value="1" <?= !empty($event['show_map']) ? 'checked' : '' ?>
                       onchange="this.value = this.checked ? '1' : '0';">
                <span class="toggle-slider-inline"></span>
                <span>Afficher une carte du lieu</span>
            </label>
            <p class="field-help">Le lieu est géocodé automatiquement à l'enregistrement.</p>
        </div>
    </section>

    <!-- Prix + Capacité -->
    <section class="card surface glass form-section">
        <h2 class="form-section-title">💰 Tarif & Places</h2>
        <div class="field-row">
            <div class="field">
                <label for="price">Prix (€)</label>
                <input type="text" id="price" name="price" value="<?= e($event['price'] ?? '') ?>" placeholder="Vide = gratuit">
            </div>
            <div class="field">
                <label for="max_capacity">Capacité max</label>
                <input type="number" id="max_capacity" name="max_capacity" value="<?= e($event['max_capacity'] ?? '') ?>" placeholder="Vide = illimité">
            </div>
        </div>
        <div class="field">
            <label for="sumup_link">Lien de paiement SumUp</label>
            <input type="url" id="sumup_link" name="sumup_link" value="<?= e($event['sumup_link'] ?? '') ?>" placeholder="https://pay.sumup.com/...">
            <p class="field-help">Laissez vide si pas de paiement en ligne.</p>
        </div>
    </section>

    <!-- Image + Options -->
    <section class="card surface glass form-section">
        <h2 class="form-section-title">🖼️ Image & Options</h2>
        <div class="field">
            <label for="image">Image de couverture (URL)</label>
            <input type="text" id="image" name="image" value="<?= e($event['image'] ?? '') ?>" placeholder="/assets/uploads/...">
        </div>
        <div class="field form-toggles">
            <label class="toggle-switch">
                <input type="checkbox" name="is_featured" value="1" <?= !empty($event['is_featured']) ? 'checked' : '' ?>
                       onchange="this.value = this.checked ? '1' : '0';">
                <span class="toggle-slider-inline"></span>
                <span>⭐ Mis en avant</span>
            </label>
            <label class="toggle-switch">
                <input type="checkbox" name="is_published" value="1" <?= !empty($event['is_published']) ? 'checked' : '' ?>
                       onchange="this.value = this.checked ? '1' : '0';">
                <span class="toggle-slider-inline"></span>
                <span>✅ Publié</span>
            </label>
        </div>
    </section>

    <!-- Actions -->
    <div class="form-actions">
        <a class="btn btn-ghost" href="<?= e(url('/admin/events')) ?>">← Retour</a>
        <button type="submit" class="btn btn-primary btn-lg"><?= $isNew ? '🎉 Créer l\'événement' : '💾 Enregistrer' ?></button>
    </div>
</form>

<style>
.form-section { margin-bottom: 1.25rem; padding: 1.5rem 1.6rem; }
.form-section-title {
    font-size: 1rem; font-weight: 700; margin: 0 0 1.25rem;
    padding-bottom: 0.6rem; border-bottom: 1px solid var(--border);
    color: var(--primary);
}
.form-toggles { display: flex; gap: 2rem; flex-wrap: wrap; }
.form-actions {
    display: flex; align-items: center; justify-content: space-between;
    gap: 1rem; padding: 1rem 0;
}
.toggle-slider-inline {
    display: inline-block;
    width: 40px; height: 22px;
    background: rgba(255,255,255,.1);
    border-radius: 999px;
    position: relative;
    transition: background .2s;
    cursor: pointer;
}
.toggle-switch input { position: absolute; opacity: 0; pointer-events: none; }
.toggle-switch input:checked ~ .toggle-slider-inline {
    background: rgba(72,189,211,.4);
}
.toggle-slider-inline::after {
    content: ''; position: absolute;
    width: 16px; height: 16px;
    border-radius: 50%; background: #fff;
    top: 3px; left: 3px;
    transition: transform .2s;
}
.toggle-switch input:checked ~ .toggle-slider-inline::after {
    transform: translateX(18px);
    background: var(--primary);
}
.toggle-switch { display: inline-flex; align-items: center; gap: .6rem; cursor: pointer; user-select: none; }
</style>
