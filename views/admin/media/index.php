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
                        <?php if (!empty($m['alt'])): ?><span class="badge badge-info">📝 <?= e($m['alt']) ?></span><?php endif; ?>
                    </div>
                    <code class="media-url" id="url-<?= e((string) $m['id']) ?>"><?= e($fullUrl) ?></code>
                    <div class="media-actions">
                        <button type="button" class="btn btn-outline btn-sm copy-url" data-target="url-<?= e((string) $m['id']) ?>">Copier</button>
                        <button type="button" class="btn btn-outline btn-sm media-edit-btn" data-id="<?= e((string) $m['id']) ?>" data-name="<?= e($name) ?>" data-alt="<?= e((string)($m['alt'] ?? '')) ?>">✏️ Éditer</button>
                        <form method="post" action="<?= e(url('/admin/media/' . rawurlencode((string) $m['id']) . '/delete')) ?>" data-confirm="Supprimer ce média ? Action irréversible.">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-danger btn-sm">🗑️</button>
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
                btn.textContent = '✓';
                setTimeout(function () { btn.textContent = orig; }, 1200);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(done, function () { window.prompt('Copie :', url); });
            } else {
                window.prompt('Copie :', url);
            }
        });
    });

    // Modal d'édition.
    document.querySelectorAll('.media-edit-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var modal = document.getElementById('media-edit-modal');
            var idField = document.getElementById('edit-id');
            var nameField = document.getElementById('edit-name');
            var altField = document.getElementById('edit-alt');
            var form = document.getElementById('edit-form');

            idField.value = btn.dataset.id;
            nameField.value = btn.dataset.name || '';
            altField.value = btn.dataset.alt || '';
            form.action = '<?= e(url('/admin/media')) ?>/' + encodeURIComponent(btn.dataset.id) + '/update';

            modal.hidden = false;
        });
    });
    var closeBtn = document.getElementById('media-edit-close');
    if (closeBtn) closeBtn.addEventListener('click', function () {
        document.getElementById('media-edit-modal').hidden = true;
    });
    var overlay = document.getElementById('media-edit-overlay');
    if (overlay) overlay.addEventListener('click', function () {
        document.getElementById('media-edit-modal').hidden = true;
    });
})();
</script>

<!-- Modal d'édition -->
<div class="media-edit-overlay" id="media-edit-overlay" hidden></div>
<div class="media-edit-modal" id="media-edit-modal" hidden>
    <div class="media-edit-head">
        <h2>✏️ Éditer le média</h2>
        <button type="button" id="media-edit-close" class="btn btn-ghost btn-sm">✕</button>
    </div>
    <form method="post" id="edit-form">
        <?= csrf_field() ?>
        <input type="hidden" id="edit-id" name="id" value="">
        <div class="field">
            <label for="edit-name">Nom du fichier</label>
            <input type="text" id="edit-name" name="name" value="">
        </div>
        <div class="field">
            <label for="edit-alt">Texte alternatif (description)</label>
            <input type="text" id="edit-alt" name="alt" value="" placeholder="Ex: Photo du barbecue de rentrée">
        </div>
        <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
    </form>
</div>

<style>
.media-edit-overlay {
    position: fixed; inset: 0; z-index: 9999;
    background: rgba(0,0,0,0.5); backdrop-filter: blur(2px);
}
.media-edit-overlay[hidden] { display: none; }
.media-edit-modal {
    position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
    z-index: 10000; background: var(--card, #0f1e35);
    border: 1px solid var(--border); border-radius: 16px;
    padding: 1.75rem; width: 90%; max-width: 420px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.5);
    animation: mepop 0.25s ease;
}
.media-edit-modal[hidden] { display: none; }
@keyframes mepop { from { opacity: 0; transform: translate(-50%, -45%); } }
.media-edit-head {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 1.25rem;
}
.media-edit-head h2 { font-size: 1.1rem; font-weight: 800; margin: 0; color: var(--primary); }
.media-edit-modal .field { margin-bottom: 0.85rem; }
</style>
