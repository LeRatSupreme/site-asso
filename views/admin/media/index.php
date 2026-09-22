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
            <span class="dropzone-sub" id="mediaFileName">JPG, PNG, GIF, WebP — 5 Mo max</span>
        </label>

        <div class="media-upload-fields">
            <input type="text" name="alt" placeholder="Texte alternatif (description de l'image — accessibilité)">
            <button type="submit" class="btn btn-primary">Téléverser</button>
        </div>
    </form>
</section>

<?php if ($medias === []): ?>
    <div class="empty-state card surface glass">
        <p class="muted">Aucun média pour le moment.</p>
    </div>
<?php else: ?>
    <div class="media-grid">
        <?php foreach ($medias as $m):
            $rel = (string) ($m['url'] ?? '');
            $fullUrl = asset($rel);
            $name = basename($rel);
            $size = isset($m['size']) ? round((int) $m['size'] / 1024) : null;
            $id = (string) $m['id'];
            // Identifiant DOM sûr (les ids médias sont med_hex, mais restons
            // robustes — même assainissement que les dialogs « Pages + »).
            $domId = preg_replace('#[^A-Za-z0-9_-]#', '', $id);
        ?>
            <figure class="media-card surface glass">
                <div class="media-thumb">
                    <img src="<?= e($fullUrl) ?>" alt="<?= e((string) ($m['alt'] ?? '')) ?>" loading="lazy">
                </div>
                <figcaption>
                    <strong class="media-name" title="<?= e($name) ?>"><?= e($name) ?></strong>
                    <div class="media-meta">
                        <?php if ($size !== null): ?><span class="badge badge-muted"><?= (int) $size ?> Ko</span><?php endif; ?>
                        <?php if (!empty($m['alt'])): ?><span class="badge badge-info" title="Texte alternatif">📝 <?= e((string) $m['alt']) ?></span><?php endif; ?>
                    </div>
                    <code class="media-url" id="media-url-<?= e($domId) ?>"><?= e($fullUrl) ?></code>
                    <div class="media-actions">
                        <button type="button" class="media-icon-btn copy-url" data-target="media-url-<?= e($domId) ?>" title="Copier l'URL">📋</button>
                        <form method="post" action="<?= e(url('/admin/media/' . rawurlencode($id) . '/update')) ?>" class="inline-form media-edit-form">
                            <?= csrf_field() ?>
                            <button type="button" class="media-icon-btn" title="Éditer" onclick="document.getElementById('media-dialog-<?= e($domId) ?>').showModal()">✏️</button>
                            <dialog id="media-dialog-<?= e($domId) ?>" class="media-dialog">
                                <div class="media-dialog-head">
                                    <div>
                                        <p class="media-dialog-title">✏️ Éditer le média</p>
                                        <p class="media-dialog-sub"><?= e($name) ?></p>
                                    </div>
                                    <button type="button" class="media-dialog-close" onclick="this.closest('dialog').close()" title="Fermer">✕</button>
                                </div>
                                <div class="media-dialog-body">
                                    <div class="field">
                                        <label for="media-edit-name-<?= e($domId) ?>">Nom du fichier</label>
                                        <input type="text" id="media-edit-name-<?= e($domId) ?>" name="name" value="<?= e($name) ?>" maxlength="255" required>
                                    </div>
                                    <div class="field">
                                        <label for="media-edit-alt-<?= e($domId) ?>">Texte alternatif (description)</label>
                                        <input type="text" id="media-edit-alt-<?= e($domId) ?>" name="alt" value="<?= e((string) ($m['alt'] ?? '')) ?>" maxlength="255" placeholder="Ex : Photo du barbecue de rentrée">
                                    </div>
                                </div>
                                <div class="media-dialog-foot">
                                    <button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('dialog').close()">Annuler</button>
                                    <span class="media-dialog-spacer"></span>
                                    <button type="submit" class="btn btn-primary btn-sm">💾 Enregistrer</button>
                                </div>
                            </dialog>
                        </form>
                        <form method="post" action="<?= e(url('/admin/media/' . rawurlencode($id) . '/delete')) ?>" class="inline-form media-delete-form"
                              data-confirm="Supprimer définitivement « <?= e($name) ?> » ? Action irréversible." data-preserve-scroll>
                            <?= csrf_field() ?>
                            <button type="submit" class="media-icon-btn is-danger" title="Supprimer">🗑️</button>
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

    // Copier l'URL : feedback « is-done » 1,2 s, prompt de secours sinon.
    document.querySelectorAll('.copy-url').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var el = document.getElementById(btn.getAttribute('data-target'));
            if (!el) return;
            var url = el.textContent.trim();
            var done = function () {
                btn.classList.add('is-done');
                btn.textContent = '✓';
                setTimeout(function () {
                    btn.classList.remove('is-done');
                    btn.textContent = '📋';
                }, 1200);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(done, function () { window.prompt('Copie :', url); });
            } else {
                window.prompt('Copie :', url);
            }
        });
    });
})();
</script>
