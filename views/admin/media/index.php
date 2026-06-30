<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $medias
 */
?>
<section class="card surface glass media-upload-card">
    <h2 class="card-title">Ajouter un média</h2>
    <form method="post" action="<?= e(url('/admin/media/upload')) ?>" enctype="multipart/form-data" class="media-upload-form" id="mediaUploadForm">
        <?= csrf_field() ?>

        <label class="dropzone" for="media-file" id="mediaDropzone">
            <input type="file" id="media-file" name="file" accept="image/*" required hidden>
            <span class="dropzone-emoji">📦</span>
            <span class="dropzone-title">Clique ou dépose une image ici</span>
            <span class="dropzone-sub" id="mediaFileName">JPG, PNG, GIF, WebP, SVG — 5 Mo max</span>
        </label>

        <div class="media-upload-fields">
            <input type="text" name="alt" placeholder="Texte alternatif (description de l'image — accessibilité)">
            <button type="submit" class="btn btn-primary">Téléverser</button>
        </div>
    </form>
</section>

<?php if ($medias === []): ?>
    <div class="empty-state card surface glass">
        <p class="muted">Aucun média pour le moment. Ajoute ta première image ci-dessus.</p>
    </div>
<?php else: ?>
    <div class="media-grid">
        <?php foreach ($medias as $m):
            $rel = (string) ($m['url'] ?? '');
            $fullUrl = asset($rel);
            $name = basename($rel);
            $size = isset($m['size']) ? round((int) $m['size'] / 1024) : null;
        ?>
            <figure class="media-card surface glass">
                <div class="media-thumb">
                    <img src="<?= e($fullUrl) ?>" alt="<?= e($m['alt'] ?? '') ?>" loading="lazy">
                </div>
                <figcaption>
                    <strong class="media-name" title="<?= e($name) ?>"><?= e($name) ?></strong>
                    <div class="media-meta">
                        <?php if ($size !== null): ?><span class="badge badge-muted"><?= (int) $size ?> Ko</span><?php endif; ?>
                    </div>
                    <code class="media-url" id="url-<?= e((string) $m['id']) ?>"><?= e($fullUrl) ?></code>
                    <div class="media-actions">
                        <button type="button" class="btn btn-outline btn-sm copy-url" data-target="url-<?= e((string) $m['id']) ?>">Copier l'URL</button>
                        <form method="post" action="<?= e(url('/admin/media/' . rawurlencode((string) $m['id']) . '/delete')) ?>" data-confirm="Supprimer ce média ? Action irréversible.">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-danger btn-sm">Supprimer</button>
                        </form>
                    </div>
                </figcaption>
            </figure>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
(function () {
    // Affiche le nom du fichier choisi.
    var input = document.getElementById('media-file');
    var nameEl = document.getElementById('mediaFileName');
    if (input && nameEl) {
        input.addEventListener('change', function () {
            if (input.files && input.files[0]) {
                nameEl.textContent = '✓ ' + input.files[0].name;
            }
        });
    }

    // Drag & drop.
    var dz = document.getElementById('mediaDropzone');
    if (dz && input) {
        ['dragenter', 'dragover'].forEach(function (ev) {
            dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.add('is-drag'); });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.remove('is-drag'); });
        });
        dz.addEventListener('drop', function (e) {
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) {
                input.files = e.dataTransfer.files;
                input.dispatchEvent(new Event('change'));
            }
        });
    }

    // Copier l'URL.
    document.querySelectorAll('.copy-url').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var el = document.getElementById(btn.getAttribute('data-target'));
            if (!el) return;
            var url = el.textContent.trim();
            var done = function () {
                var orig = btn.textContent;
                btn.textContent = '✓ Copié !';
                btn.classList.add('is-done');
                setTimeout(function () { btn.textContent = orig; btn.classList.remove('is-done'); }, 1500);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(done, function () { window.prompt('Copie cette URL :', url); });
            } else {
                window.prompt('Copie cette URL :', url);
            }
        });
    });
})();
</script>
