<?php

declare(strict_types=1);

/**
 * @var array<string,mixed>      $user
 * @var list<array<string,mixed>> $batches
 */

// Historique trié du plus récent au plus ancien + statistiques rapides.
usort($batches, static function (array $a, array $b): int {
    return strcmp((string) ($b['imported_at'] ?? ''), (string) ($a['imported_at'] ?? ''));
});
$totalInserted = 0;
foreach ($batches as $b) {
    $totalInserted += (int) ($b['rows_inserted'] ?? 0);
}
$lastImport = $batches[0]['imported_at'] ?? null;
?>
<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité</p>
        <h1 class="page-title">Importer un rapport SumUp</h1>
        <p class="muted">Dépose le CSV exporté depuis SumUp — import <strong>blindé</strong> : un fichier déjà importé est <strong>refusé</strong> (empreinte), les doublons sont <strong>ignorés</strong>, les lignes invalides sont <strong>écartées avec leur motif</strong> et le dédoublonnage est <strong>garanti en base</strong> : réimporter ne crée jamais de doublon.</p>
    </div>
</div>

<div class="compta-kpis">
    <div class="card surface glass kpi">
        <p class="kpi-label">Imports</p>
        <p class="kpi-value"><?= count($batches) ?></p>
        <p class="kpi-sub">rapports traités</p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Lignes insérées</p>
        <p class="kpi-value"><?= number_format($totalInserted, 0, ',', ' ') ?></p>
        <p class="kpi-sub">ventes en base</p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Dernier import</p>
        <p class="kpi-value" style="font-size:1.15rem"><?= $lastImport !== null ? e(formatDateTime((string) $lastImport)) : '—' ?></p>
        <p class="kpi-sub"><?= $lastImport !== null ? 'tout est frais 👌' : 'aucun import pour l\'instant' ?></p>
    </div>
</div>

<div class="compta-grid">
    <section class="card surface glass">
        <h2 class="card-title">Déposer le rapport</h2>
        <form method="post" action="<?= e(url('/admin/compta/import')) ?>" enctype="multipart/form-data" id="import-form">
            <?= csrf_field() ?>

            <label class="dropzone" id="dropzone" for="csv">
                <span class="dropzone-icon" aria-hidden="true">📄</span>
                <span class="dropzone-main" id="dz-main">Glisse le fichier CSV ici
                    <small>ou clique pour choisir un fichier</small>
                </span>
                <span class="dropzone-hint">Format SumUp français — doit contenir la colonne « Prix (TTC) »</span>
            </label>
            <input type="file" id="csv" name="csv" accept=".csv,text/csv,text/plain" required hidden>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary" id="dz-submit" disabled>Importer</button>
                <button type="button" class="btn btn-ghost" id="dz-reset" hidden>Changer de fichier</button>
            </div>
        </form>
    </section>

    <section class="card surface glass">
        <h2 class="card-title">D'où vient ce fichier ?</h2>
        <ol class="import-steps">
            <li>Ouvre <strong>SumUp</strong> (app ou dashboard) → onglet <strong>Reports</strong> / Rapports</li>
            <li>Choisis la <strong>période</strong> voulue → <strong>Export CSV</strong> (format français)</li>
            <li>Dépose le fichier ici — un <strong>fichier déjà importé est refusé</strong> (empreinte), mais un export différent couvrant une période similaire ne crée aucun doublon : les lignes identiques sont <strong>ignorées</strong></li>
        </ol>
        <p class="muted" style="font-size:0.85rem">Après l'import, les ventes alimentent automatiquement le Dashboard, le Journal, les Produits, les Catégories, les Événements et les rapports.</p>
    </section>
</div>

<div class="card surface glass table-wrap">
    <h2 class="card-title">Historique des imports</h2>
    <table class="table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Fichier</th>
                <th>Période</th>
                <th class="th-num">Lignes</th>
                <th class="th-num">Insérées</th>
                <th class="th-num">Ignorées</th>
                <th>Par</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($batches as $b): ?>
                <tr>
                    <td><?= e(formatDateTime($b['imported_at'] ?? null)) ?></td>
                    <td><strong><?= e((string) ($b['filename'] ?? '—')) ?></strong></td>
                    <td><?= e(formatDate($b['period_start'] ?? null)) ?> → <?= e(formatDate($b['period_end'] ?? null)) ?></td>
                    <td class="num"><?= e((string) ($b['rows_total'] ?? '—')) ?></td>
                    <td class="num"><span class="badge badge-success">+<?= (int) ($b['rows_inserted'] ?? 0) ?></span></td>
                    <td class="num"><span class="badge badge-muted"><?= (int) ($b['rows_skipped'] ?? 0) ?> ignorées</span></td>
                    <td><?= e((string) ($b['imported_by'] ?? '—')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($batches === []): ?>
                <tr><td colspan="7" class="muted">Aucun import pour le moment — dépose ton premier rapport ci-dessus.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
(function () {
    var zone = document.getElementById('dropzone');
    var input = document.getElementById('csv');
    var main = document.getElementById('dz-main');
    var submit = document.getElementById('dz-submit');
    var reset = document.getElementById('dz-reset');
    if (!zone || !input) return;

    function humanSize(bytes) {
        if (!bytes && bytes !== 0) return '';
        if (bytes < 1024) return bytes + ' o';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(0) + ' Ko';
        return (bytes / (1024 * 1024)).toFixed(1) + ' Mo';
    }

    function showFile(file) {
        if (!file) return;
        zone.classList.add('has-file');
        main.innerHTML = '<span class="dropzone-filename">✅ ' +
            (typeof file.name === 'string' ? file.name.replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            }) : 'fichier') +
            '</span><small>' + humanSize(file.size) + ' — prêt à importer</small>';
        if (submit) submit.disabled = false;
        if (submit) submit.textContent = 'Importer le fichier';
        if (reset) reset.hidden = false;
    }

    input.addEventListener('change', function () {
        if (input.files && input.files[0]) showFile(input.files[0]);
    });

    ['dragenter', 'dragover'].forEach(function (evt) {
        zone.addEventListener(evt, function (e) {
            e.preventDefault();
            zone.classList.add('is-dragover');
        });
    });
    ['dragleave', 'drop'].forEach(function (evt) {
        zone.addEventListener(evt, function (e) {
            e.preventDefault();
            zone.classList.remove('is-dragover');
        });
    });
    zone.addEventListener('drop', function (e) {
        if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) {
            try {
                input.files = e.dataTransfer.files;
            } catch (err) {
                // Fallback silencieux : certains navigateurs interdisent
                // l'affectation directe ; l'utilisateur passera par le clic.
            }
            showFile(e.dataTransfer.files[0]);
        }
    });

    if (reset) {
        reset.addEventListener('click', function () {
            input.value = '';
            zone.classList.remove('has-file');
            main.innerHTML = 'Glisse le fichier CSV ici<small>ou clique pour choisir un fichier</small>';
            submit.disabled = true;
            submit.textContent = 'Importer';
            reset.hidden = true;
        });
    }
})();
</script>
