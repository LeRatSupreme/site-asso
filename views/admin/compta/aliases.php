<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $aliases
 * @var list<array<string,mixed>> $unmapped
 */
?>
<div class="compta-grid">
    <div class="card surface glass">
        <h2 class="card-title">File « à classer »</h2>
        <p class="muted">Libellés CSV rencontrés non encore rattachés à un produit canonique. Rattachez-les une fois pour toutes : ils seront résolus automatiquement aux prochains imports.</p>
        <p class="admin-actions">
            <a class="btn btn-primary" href="<?= e(url('/admin/compta/aliases/auto')) ?>">Auto-détecter les doublons</a>
        </p>
        <?php if ($unmapped === []): ?>
            <p class="muted">Aucun libellé à classer. 🎉</p>
        <?php endif; ?>
        <?php foreach ($unmapped as $u): ?>
            <form method="post" action="<?= e(url('/admin/compta/aliases/save')) ?>" class="alias-form">
                <?= csrf_field() ?>
                <div class="alias-row">
                    <div>
                        <code><?= e((string) $u['description']) ?></code>
                        <span class="muted">(<?= e((string) $u['occurrences']) ?> fois)</span>
                    </div>
                    <div>
                        <input type="hidden" name="raw_description" value="<?= e((string) $u['description']) ?>">
                        <input type="text" name="product_key" placeholder="ex: Bueno" required>
                        <input type="text" name="category" placeholder="Nourriture">
                        <button type="submit" class="btn btn-primary btn-sm">Rattacher</button>
                    </div>
                </div>
            </form>
        <?php endforeach; ?>
    </div>

    <div class="card surface glass table-wrap">
        <h2 class="card-title">Alias existants</h2>
        <table class="table">
            <thead><tr><th>Libellé CSV</th><th>Produit canonique</th><th>Catégorie</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($aliases as $a): ?>
                    <tr>
                        <td><code><?= e((string) $a['raw_description']) ?></code></td>
                        <td><strong><?= e((string) $a['product_key']) ?></strong></td>
                        <td><?= e((string) ($a['category'] ?? '—')) ?></td>
                        <td>
                            <form method="post" action="<?= e(url('/admin/compta/aliases/' . rawurlencode((string) $a['id']) . '/delete')) ?>" onsubmit="return confirm('Supprimer cet alias ?');">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-destructive btn-sm">Supprimer</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($aliases === []): ?>
                    <tr><td colspan="4" class="muted">Aucun alias défini.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
