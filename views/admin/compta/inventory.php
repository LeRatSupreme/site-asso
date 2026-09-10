<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $user
 * @var list<array{key:string,stock:?int,counted_at:?string,counted_qty:?int,gap:?int,theoretical:?int}> $rows
 * @var list<array<string,mixed>> $history
 * @var list<array<string,mixed>> $gaps
 */
?>
<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité</p>
        <h1 class="page-title">Inventaire</h1>
        <p class="muted">Compte le stock <strong>physique</strong> et compare au <strong>théorique</strong> (dernier comptage + achats − ventes). Un écart = perte, casse, offert ou erreur de saisie.</p>
    </div>
</div>

<?php if ($gaps !== []): ?>
<section class="card surface glass table-wrap">
    <h2 class="card-title">⚠️ Écarts détectés (30 derniers jours)</h2>
    <table class="table">
        <thead>
            <tr><th>Produit</th><th>Écart</th><th>Date</th></tr>
        </thead>
        <tbody>
            <?php foreach ($gaps as $g): $gap = (int) $g['gap']; ?>
                <tr>
                    <td><strong><?= e((string) $g['product_key']) ?></strong></td>
                    <td>
                        <?php if ($gap < 0): ?>
                            <span class="badge badge-danger"><?= $gap ?></span>
                            <span class="muted">perte</span>
                        <?php else: ?>
                            <span class="badge badge-warning">+<?= $gap ?></span>
                            <span class="muted">stock trouvé en plus</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e(formatDateTime((string) $g['counted_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>

<section class="card surface glass table-wrap">
    <h2 class="card-title">Comptage</h2>
    <form method="post" action="<?= e(url('/admin/compta/inventaire/save')) ?>">
        <?= csrf_field() ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Produit</th>
                    <th>Dernier comptage</th>
                    <th class="th-num">Stock théorique</th>
                    <th>Saisie physique</th>
                    <th>Dernier écart</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><strong><?= e($r['key']) ?></strong></td>
                        <td>
                            <?php if ($r['counted_at'] !== null): ?>
                                <?= e(formatDateTime($r['counted_at'])) ?>
                                <span class="muted">(<?= (int) $r['counted_qty'] ?>)</span>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="num">
                            <?php if ($r['theoretical'] !== null): ?>
                                <?= (int) $r['theoretical'] ?>
                            <?php else: ?>
                                <span class="badge badge-warning">À compter</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <input type="number" name="count[<?= e($r['key']) ?>]"
                                   min="0" step="1" placeholder="—" style="width:90px" inputmode="numeric">
                        </td>
                        <td>
                            <?php if ($r['gap'] === null): ?>
                                <span class="muted">—</span>
                            <?php elseif ($r['gap'] < 0): ?>
                                <span class="badge badge-danger"><?= (int) $r['gap'] ?></span>
                            <?php elseif ($r['gap'] === 0): ?>
                                <span class="badge badge-success">0</span>
                            <?php else: ?>
                                <span class="badge badge-warning">+<?= (int) $r['gap'] ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows === []): ?>
                    <tr><td colspan="5" class="muted">Aucun produit à compter. Importe d'abord un rapport SumUp.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <p class="muted">Seules les lignes renseignées sont comptées. Chaque comptage devient le nouveau point de départ du stock théorique.</p>
        <button type="submit" class="btn btn-primary">Enregistrer le comptage</button>
    </form>
</section>

<div class="card surface glass table-wrap">
    <h2 class="card-title">Historique des comptages</h2>
    <table class="table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Produit</th>
                <th class="th-num">Compté</th>
                <th class="th-num">Théorique</th>
                <th>Écart</th>
                <th>Note</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($history as $h): $gap = (int) $h['gap']; ?>
                <tr>
                    <td><?= e(formatDateTime((string) $h['counted_at'])) ?></td>
                    <td><strong><?= e((string) $h['product_key']) ?></strong></td>
                    <td class="num"><?= (int) $h['counted_qty'] ?></td>
                    <td class="num"><?= (int) $h['theoretical_qty'] ?></td>
                    <td>
                        <?php if ($gap < 0): ?>
                            <span class="badge badge-danger"><?= $gap ?></span>
                        <?php elseif ($gap === 0): ?>
                            <span class="badge badge-success">0</span>
                        <?php else: ?>
                            <span class="badge badge-warning">+<?= $gap ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= e((string) ($h['note'] ?? '') !== '' ? (string) $h['note'] : '—') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($history === []): ?>
                <tr><td colspan="6" class="muted">Aucun comptage enregistré.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
