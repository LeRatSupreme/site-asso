<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $medias
 */
?>
<section class="card surface glass">
    <h2 class="card-title">Ajouter un média</h2>
    <form method="post" action="<?= e(url('/admin/media/upload')) ?>" enctype="multipart/form-data" class="inline-form">
        <?= csrf_field() ?>
        <input type="file" name="file" accept="image/*" required>
        <input type="text" name="alt" placeholder="Texte alternatif (accessibilité)">
        <button type="submit" class="btn btn-primary btn-sm">Téléverser</button>
    </form>
    <p class="card-meta">JPG, PNG, GIF, WebP, SVG — 5 Mo max.</p>
</section>

<div class="media-grid">
    <?php foreach ($medias as $m): ?>
        <figure class="media-card surface glass">
            <img src="<?= e(asset('assets/' . ($m['url'] ?? ''))) ?>" alt="<?= e($m['alt'] ?? '') ?>" loading="lazy">
            <figcaption>
                <code><?= e($m['url'] ?? '') ?></code>
                <form method="post" action="<?= e(url('/admin/media/' . rawurlencode((string) $m['id']) . '/delete')) ?>" onsubmit="return confirm('Supprimer ce média ?');">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-destructive btn-sm">Supprimer</button>
                </form>
            </figcaption>
        </figure>
    <?php endforeach; ?>
</div>
