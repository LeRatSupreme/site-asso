<?php

declare(strict_types=1);

/**
 * Auto-détection des doublons de libellés.
 *
 * @var list<array<string,mixed>> $rows
 */
?>
<div class="card surface glass">
    <h2 class="card-title">Suggestions de mapping</h2>
    <p class="muted">
        Chaque libellé CSV rencontré est regroupé vers une <strong>clé canonique
        suggérée</strong> (normalisation : minuscules, accents retirés, séparateurs
        uniformisés, suffixes de couleur/gout retirés). La colonne « Produit
        canonique » est <strong>éditable</strong> : corrigez avant d'appliquer.
        Les libellés déjà aliasés sont signalés ; modifier leur clé canonique
        ré-percolera sur toutes les ventes existantes.
    </p>

    <?php if ($rows === []): ?>
        <p class="muted">Aucune vente à analyser. Importez d'abord un rapport SumUp.</p>
    <?php else: ?>
        <form method="post" action="<?= e(url('/admin/compta/aliases/apply')) ?>">
            <?= csrf_field() ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Libellé CSV</th>
                            <th>Occurrences</th>
                            <th>Suggestion auto</th>
                            <th>Produit canonique (éditable)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $i => $r): ?>
                            <tr<?= !empty($r['already']) ? ' class="row-muted"' : '' ?>>
                                <td>
                                    <code><?= e((string) $r['raw']) ?></code>
                                    <?php if (!empty($r['already'])): ?>
                                        <span class="badge badge-muted">déjà mappé</span>
                                    <?php endif; ?>
                                    <input type="hidden" name="raw[<?= (int) $i ?>]" value="<?= e((string) $r['raw']) ?>">
                                </td>
                                <td><?= e((string) $r['occurrences']) ?></td>
                                <td><span class="muted"><?= e((string) $r['suggested']) ?></span></td>
                                <td>
                                    <input type="text" name="canonical[<?= (int) $i ?>]"
                                           value="<?= e((string) $r['canonical']) ?>"
                                           placeholder="<?= e((string) $r['suggested']) ?>">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="admin-actions">
                <button type="submit" class="btn btn-primary">Appliquer le mapping</button>
                <a class="btn btn-outline" href="<?= e(url('/admin/compta/aliases')) ?>">Annuler</a>
            </div>
        </form>
    <?php endif; ?>
</div>
