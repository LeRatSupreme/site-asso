<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $batches
 */
?>
<div class="card surface glass">
    <h2 class="card-title">Importer un rapport SumUp</h2>
    <p class="muted">Importez le fichier CSV exporté depuis le tableau de bord SumUp (format français). Les doublons sont automatiquement ignorés : réimporter le même fichier n'ajoute aucune ligne.</p>

    <form method="post" action="<?= e(url('/admin/compta/import')) ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <p>
            <label for="csv">Fichier CSV :</label><br>
            <input type="file" id="csv" name="csv" accept=".csv,text/csv,text/plain" required>
        </p>
        <p>
            <button type="submit" class="btn btn-primary">Importer</button>
        </p>
    </form>
</div>

<div class="card surface glass table-wrap">
    <h2 class="card-title">Historique des imports</h2>
    <table class="table">
        <thead><tr><th>Date</th><th>Fichier</th><th>Période</th><th>Lignes</th><th>Insérées</th><th>Ignorées</th><th>Par</th></tr></thead>
        <tbody>
            <?php foreach ($batches as $b): ?>
                <tr>
                    <td><?= e(formatDateTime($b['imported_at'] ?? null)) ?></td>
                    <td><?= e((string) ($b['filename'] ?? '—')) ?></td>
                    <td><?= e(formatDate($b['period_start'] ?? null)) ?> → <?= e(formatDate($b['period_end'] ?? null)) ?></td>
                    <td><?= e((string) ($b['rows_total'] ?? '—')) ?></td>
                    <td><?= e((string) ($b['rows_inserted'] ?? '—')) ?></td>
                    <td><?= e((string) ($b['rows_skipped'] ?? '—')) ?></td>
                    <td><?= e((string) ($b['imported_by'] ?? '—')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($batches === []): ?>
                <tr><td colspan="7" class="muted">Aucun import pour le moment.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
