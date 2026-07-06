<?php

declare(strict_types=1);

/**
 * Formulaire d'ajout/édition d'un mot Wordle (admin).
 *
 * @var string $title
 * @var array<string,mixed> $word
 */
$isNew = (int) ($word['id'] ?? 0) === 0;
?>
<div class="admin-actions">
    <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/jeux/wordle')) ?>">← Retour</a>
</div>

<form method="post" action="<?= e(url('/admin/jeux/wordle/save')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= e((string) ($word['id'] ?? 0)) ?>">

    <section class="card surface glass form-section">
        <h2 class="form-section-title"><?= $isNew ? '➕ Nouveau mot' : '✏️ Modifier le mot' ?></h2>
        <div class="field-row">
            <div class="field">
                <label for="word">Mot (5 à 7 lettres, A-Z)</label>
                <input type="text" id="word" name="word" required maxlength="7"
                       value="<?= e(strtoupper((string) ($word['word'] ?? ''))) ?>"
                       style="text-transform:uppercase;letter-spacing:0.15em;font-size:1.3rem;font-weight:800;"
                       placeholder="TABLE" />
                <p style="font-size:0.8rem;color:var(--muted);margin-top:0.3rem;">Les accents seront retirés automatiquement. La difficulté est déterminée par la longueur.</p>
            </div>
        </div>
        <div class="field-row">
            <div class="field">
                <label for="language">Langue</label>
                <select id="language" name="language">
                    <option value="fr" <?= ($word['language'] ?? 'fr') === 'fr' ? 'selected' : '' ?>>🇫🇷 Français</option>
                    <option value="en" <?= ($word['language'] ?? '') === 'en' ? 'selected' : '' ?>>🇬🇧 Anglais</option>
                </select>
            </div>
            <div class="field">
                <label>Difficulté (auto)</label>
                <input type="text" disabled value="Calculée d'après la longueur"
                       style="color:var(--muted);" />
            </div>
        </div>
        <div class="field-row">
            <div class="field">
                <label class="toggle-switch">
                    <input type="checkbox" name="is_active" value="1" <?= ((int) ($word['is_active'] ?? 1)) === 1 ? 'checked' : '' ?> />
                    <span class="toggle-slider-inline"></span>
                    <span>Mot actif (utilisable dans le jeu)</span>
                </label>
            </div>
        </div>
    </section>

    <div class="form-actions">
        <a class="btn btn-ghost" href="<?= e(url('/admin/jeux/wordle')) ?>">← Annuler</a>
        <button type="submit" class="btn btn-primary btn-lg"><?= $isNew ? 'Créer' : 'Enregistrer' ?></button>
    </div>
</form>
