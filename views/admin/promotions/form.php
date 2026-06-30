<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $promo
 */

$promo = $promo ?? [];

$startsAt = (string) ($promo['starts_at'] ?? '');
$endsAt   = (string) ($promo['ends_at'] ?? '');

$toInput = static function (string $db): string {
    if ($db === '' || $db === '0000-00-00 00:00:00') {
        return '';
    }
    $ts = strtotime($db);

    return $ts !== false ? date('Y-m-d\TH:i', $ts) : '';
};

$startsValue = $toInput($startsAt);
$endsValue   = $toInput($endsAt);
?>
<form class="card surface glass" method="post" action="<?= e(url('/admin/promotions/save')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= e((string) ($promo['id'] ?? '')) ?>">

    <div class="field">
        <label for="title">Titre</label>
        <input type="text" id="title" name="title" value="<?= e($promo['title'] ?? '') ?>" required>
    </div>

    <div class="field">
        <label for="description">Description</label>
        <textarea id="description" name="description" rows="3"><?= e($promo['description'] ?? '') ?></textarea>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="product_key">Produit concerné (clé, optionnel)</label>
            <input type="text" id="product_key" name="product_key" value="<?= e($promo['product_key'] ?? '') ?>" placeholder="ex: coca_33cl">
        </div>
        <div class="field">
            <label for="badge">Badge</label>
            <input type="text" id="badge" name="badge" value="<?= e($promo['badge'] ?? 'PROMO') ?>" placeholder="PROMO, NOUVEAU, -20%">
        </div>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="old_price">Ancien prix (€)</label>
            <input type="text" id="old_price" name="old_price" value="<?= e($promo['old_price'] ?? '') ?>" placeholder="1,50">
        </div>
        <div class="field">
            <label for="new_price">Nouveau prix (€)</label>
            <input type="text" id="new_price" name="new_price" value="<?= e($promo['new_price'] ?? '') ?>" required placeholder="1,00">
        </div>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="image">Image (URL, optionnel)</label>
            <input type="text" id="image" name="image" value="<?= e($promo['image'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="starts_at">Début</label>
            <input type="datetime-local" id="starts_at" name="starts_at" value="<?= e($startsValue) ?>">
        </div>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="ends_at">Fin (vide = illimité)</label>
            <input type="datetime-local" id="ends_at" name="ends_at" value="<?= e($endsValue) ?>">
        </div>
        <div class="field">
            <label><input type="checkbox" name="is_active" value="1" <?= !empty($promo['is_active']) ? 'checked' : '' ?>> Active</label>
        </div>
    </div>

    <div class="admin-actions">
        <button type="submit" class="btn btn-primary">Enregistrer</button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/promotions')) ?>">Annuler</a>
    </div>
</form>
