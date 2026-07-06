<?php

declare(strict_types=1);

/**
 * Formulaire d'ajout/édition d'une énigme (admin).
 *
 * @var string $title
 * @var array<string,mixed> $enigma
 */
$isNew = (int) ($enigma['id'] ?? 0) === 0;
?>
<div class="admin-actions">
    <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/jeux/enigmes')) ?>">← Retour</a>
</div>

<form method="post" action="<?= e(url('/admin/jeux/enigmes/save')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= e((string) ($enigma['id'] ?? 0)) ?>">

    <section class="card surface glass form-section">
        <h2 class="form-section-title"><?= $isNew ? '➕ Nouvelle énigme' : '✏️ Modifier l\'énigme' ?></h2>

        <div class="field-row">
            <div class="field">
                <label for="question_fr">Question (français) *</label>
                <textarea id="question_fr" name="question_fr" rows="3" required
                          placeholder="Je suis grand le matin et petit à midi. Qui suis-je ?"
                          style="width:100%;padding:0.5rem;border-radius:0.4rem;border:1px solid var(--border-strong);background:rgba(255,255,255,0.04);color:var(--foreground);font-size:1rem;"><?= e((string) ($enigma['question_fr'] ?? '')) ?></textarea>
            </div>
        </div>
        <div class="field-row">
            <div class="field">
                <label for="question_en">Question (anglais)</label>
                <textarea id="question_en" name="question_en" rows="3"
                          placeholder="I am tall in the morning and short at noon. What am I ?"
                          style="width:100%;padding:0.5rem;border-radius:0.4rem;border:1px solid var(--border-strong);background:rgba(255,255,255,0.04);color:var(--foreground);font-size:1rem;"><?= e((string) ($enigma['question_en'] ?? '')) ?></textarea>
                <p style="font-size:0.8rem;color:var(--muted);margin-top:0.3rem;">Laisser vide = reprend la question FR.</p>
            </div>
        </div>
        <div class="field-row">
            <div class="field">
                <label for="answer">Réponse(s) acceptée(s) *</label>
                <input type="text" id="answer" name="answer" required
                       value="<?= e((string) ($enigma['answer'] ?? '')) ?>"
                       placeholder="ombre|shadow" />
                <p style="font-size:0.8rem;color:var(--muted);margin-top:0.3rem;">Séparer plusieurs variantes par <code>|</code>. La comparaison ignore la casse et les accents.</p>
            </div>
        </div>
        <div class="field-row">
            <div class="field">
                <label for="hint_fr">Indice (français)</label>
                <input type="text" id="hint_fr" name="hint_fr"
                       value="<?= e((string) ($enigma['hint_fr'] ?? '')) ?>"
                       placeholder="Optionnel" />
            </div>
            <div class="field">
                <label for="hint_en">Indice (anglais)</label>
                <input type="text" id="hint_en" name="hint_en"
                       value="<?= e((string) ($enigma['hint_en'] ?? '')) ?>"
                       placeholder="Optionnel" />
            </div>
        </div>
        <div class="field-row">
            <div class="field">
                <label class="toggle-switch">
                    <input type="checkbox" name="is_active" value="1" <?= ((int) ($enigma['is_active'] ?? 1)) === 1 ? 'checked' : '' ?> />
                    <span class="toggle-slider-inline"></span>
                    <span>Énigme active (sélectionnable comme énigme du jour)</span>
                </label>
            </div>
        </div>
    </section>

    <div class="form-actions">
        <a class="btn btn-ghost" href="<?= e(url('/admin/jeux/enigmes')) ?>">← Annuler</a>
        <button type="submit" class="btn btn-primary btn-lg"><?= $isNew ? 'Créer' : 'Enregistrer' ?></button>
    </div>
</form>
