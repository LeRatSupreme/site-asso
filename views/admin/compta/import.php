<?php

declare(strict_types=1);

use App\Models\ImportBatch;

/**
 * @var array<string,mixed>       $user
 * @var list<array<string,mixed>> $batches    Derniers lots chargés (500 max) : imports manuels + passages de synchro API.
 * @var int                       $salesTotal Nombre total de ventes en base.
 * @var list<array{payment_raw:string, payment_method:string, n:int, total:float}> $paymentRaw
 *                                             Diagnostic de classification des moyens de paiement.
 */

// Historique trié du plus récent au plus ancien. La synchro API SumUp crée
// un lot à chaque passage (toutes les minutes) : on la sépare des imports
// CSV manuels pour ne pas noyer l'historique (cf. bloc « Synchro API SumUp »).
usort($batches, static function (array $a, array $b): int {
    return strcmp((string) ($b['imported_at'] ?? ''), (string) ($a['imported_at'] ?? ''));
});

$manualBatches = [];
$syncBatches   = [];
foreach ($batches as $b) {
    if (ImportBatch::isSyncRow($b)) {
        $syncBatches[] = $b;
    } else {
        $manualBatches[] = $b;
    }
}
$manualCount   = count($manualBatches);
$manualBatches = array_slice($manualBatches, 0, 50); // les 50 plus récents suffisent à l'affichage

// Regroupement des passages de synchro par jour (date de imported_at) :
// une ligne résumée par jour, dépliable pour le détail des passages.
$syncDays = [];
foreach ($syncBatches as $b) {
    $at  = (string) ($b['imported_at'] ?? '');
    $day = $at !== '' ? substr($at, 0, 10) : '—';
    if (!isset($syncDays[$day])) {
        $syncDays[$day] = ['day' => $day, 'count' => 0, 'inserted' => 0, 'last' => '', 'rows' => []];
    }
    $syncDays[$day]['count']++;
    $syncDays[$day]['inserted'] += (int) ($b['rows_inserted'] ?? 0);
    $syncDays[$day]['rows'][] = $b;
    if ($at !== '' && $at > $syncDays[$day]['last']) {
        $syncDays[$day]['last'] = $at;
    }
}

// Passages de synchro des dernières 24 h (compteur du titre de bloc).
$syncLast24h = 0;
$cutoff24h   = date('Y-m-d H:i:s', strtotime('-24 hours'));
foreach ($syncBatches as $b) {
    if ((string) ($b['imported_at'] ?? '') >= $cutoff24h) {
        $syncLast24h++;
    }
}

// Dernière activité tous lots confondus (manuel ou synchro).
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
        <p class="kpi-label">Imports CSV</p>
        <p class="kpi-value"><?= $manualCount ?></p>
        <p class="kpi-sub">rapports traités</p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Lignes insérées</p>
        <p class="kpi-value"><?= number_format($salesTotal, 0, ',', ' ') ?></p>
        <p class="kpi-sub">ventes en base</p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Dernier import</p>
        <p class="kpi-value" style="font-size:1.15rem"><?= $lastImport !== null ? e(formatDateTime((string) $lastImport)) : '—' ?></p>
        <p class="kpi-sub"><?= $lastImport !== null ? 'tout est frais ' : 'aucun import pour l\'instant' ?></p>
    </div>
</div>

<div class="compta-grid">
    <section class="card surface glass">
        <h2 class="card-title">Déposer le rapport</h2>
        <form method="post" action="<?= e(url('/admin/compta/import')) ?>" enctype="multipart/form-data" id="import-form">
            <?= csrf_field() ?>

            <label class="dropzone" id="dropzone" for="csv">
                <span class="dropzone-icon" aria-hidden="true"></span>
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
            <?php foreach ($manualBatches as $b): ?>
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
            <?php if ($manualBatches === []): ?>
                <tr><td colspan="7" class="muted">Aucun import manuel.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($syncDays !== []): ?>
<section class="card surface glass">
    <h2 class="card-title">
        Synchro API SumUp — <?= count($syncDays) ?> jour<?= count($syncDays) > 1 ? 's' : '' ?>
        · <?= count($syncBatches) ?> synchronisation<?= count($syncBatches) > 1 ? 's' : '' ?>
        (<?= $syncLast24h ?> sur les dernières 24 h)
    </h2>
    <p class="muted" style="font-size:0.85rem">Les passages de synchro (toutes les minutes) sont regroupés par jour.</p>

    <?php foreach ($syncDays as $d): ?>
        <details class="cost-card-lots">
            <summary>
                <?= e(formatDate($d['day'])) ?> — <?= (int) $d['count'] ?> synchronisation<?= (int) $d['count'] > 1 ? 's' : '' ?>
                · <?= (int) $d['inserted'] ?> vente<?= (int) $d['inserted'] > 1 ? 's' : '' ?> importée<?= (int) $d['inserted'] > 1 ? 's' : '' ?>
                · dernière à <?= e($d['last'] !== '' ? substr($d['last'], 11, 5) : '—') ?>
            </summary>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Heure</th>
                            <th class="th-num">Lignes</th>
                            <th class="th-num">Insérées</th>
                            <th class="th-num">Ignorées</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($d['rows'] as $b): ?>
                            <?php $at = (string) ($b['imported_at'] ?? ''); ?>
                            <tr>
                                <td><?= e($at !== '' ? substr($at, 11, 8) : '—') ?></td>
                                <td class="num"><?= e((string) ($b['rows_total'] ?? '—')) ?></td>
                                <td class="num"><span class="badge badge-success">+<?= (int) ($b['rows_inserted'] ?? 0) ?></span></td>
                                <td class="num"><span class="badge badge-muted"><?= (int) ($b['rows_skipped'] ?? 0) ?> ignorées</span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($paymentRaw !== []): ?>
<section class="card surface glass">
    <h2 class="card-title">Moyens de paiement importés</h2>
    <p class="muted">
        Classement automatique : <strong>Espèces / Cash → Liquide</strong> (aucun frais SumUp),
        tout le reste → <strong>Carte</strong> (frais estimés au taux des Réglages).
        Un libellé inattendu classé « Carte » à tort gonflerait l'estimation de frais.
    </p>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Libellé SumUp</th>
                    <th>Classé</th>
                    <th class="th-num">Ventes</th>
                    <th class="th-num">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($paymentRaw as $p): ?>
                    <tr>
                        <td><code><?= $p['payment_raw'] !== '' ? e($p['payment_raw']) : '—' ?></code></td>
                        <td>
                            <span class="badge <?= $p['payment_method'] === 'CARTE' ? 'badge-info' : 'badge-success' ?>">
                                <?= e($p['payment_method'] === 'CARTE' ? 'Carte (frais 1,75 % estimés)' : 'Liquide (sans frais)') ?>
                            </span>
                        </td>
                        <td class="num"><?= e((string) $p['n']) ?></td>
                        <td class="num"><?= e(formatPrice($p['total'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

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
        main.innerHTML = '<span class="dropzone-filename">' +
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
