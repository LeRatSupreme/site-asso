<?php

declare(strict_types=1);

/**
 * Liste des mots Wordle (admin) — recherche, filtres, pagination, CRUD.
 *
 * @var string $title
 * @var list<array<string,mixed>> $words
 * @var int $total
 * @var string $search
 * @var string $langFilter
 * @var string $diffFilter
 * @var int $page
 * @var int $totalPages
 * @var int $perPage
 */

/**
 * Construit une fenêtre de pages autour de la page courante.
 * Retourne un tableau mélangeant des numéros (int) et des '…' (string).
 *
 * @return list<int|string>
 */
function wordlePageWindow(int $current, int $total): array
{
    if ($total <= 9) {
        return range(1, $total);
    }

    $window = [];
    $window[] = 1; // toujours la première

    $start = max(2, $current - 2);
    $end = min($total - 1, $current + 2);

    if ($start > 2) {
        $window[] = '…';
    }
    for ($i = $start; $i <= $end; $i++) {
        $window[] = $i;
    }
    if ($end < $total - 1) {
        $window[] = '…';
    }

    $window[] = $total; // toujours la dernière
    return $window;
}
?>
<div class="admin-actions">
    <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/jeux')) ?>">← Retour</a>
    <a class="btn btn-primary btn-sm" href="<?= e(url('/admin/jeux/wordle/new')) ?>">+ Nouveau mot</a>
</div>

<!-- Filtres -->
<form method="get" class="card surface glass" style="display:flex;gap:0.6rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:1rem;padding:0.8rem;">
    <div style="flex:1;min-width:180px;">
        <label style="font-size:0.75rem;color:var(--muted);display:block;margin-bottom:0.2rem;">Recherche</label>
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="ex : TABLE"
               style="width:100%;padding:0.4rem 0.6rem;border-radius:0.3rem;border:1px solid var(--border-strong);background:rgba(255,255,255,0.04);color:var(--foreground);" />
    </div>
    <div>
        <label style="font-size:0.75rem;color:var(--muted);display:block;margin-bottom:0.2rem;">Langue</label>
        <select name="lang">
            <option value="">Toutes</option>
            <option value="fr" <?= $langFilter === 'fr' ? 'selected' : '' ?>>🇫🇷 FR</option>
            <option value="en" <?= $langFilter === 'en' ? 'selected' : '' ?>>🇬🇧 EN</option>
        </select>
    </div>
    <div>
        <label style="font-size:0.75rem;color:var(--muted);display:block;margin-bottom:0.2rem;">Difficulté</label>
        <select name="diff">
            <option value="">Toutes</option>
            <option value="facile" <?= $diffFilter === 'facile' ? 'selected' : '' ?>>Facile (5)</option>
            <option value="moyen" <?= $diffFilter === 'moyen' ? 'selected' : '' ?>>Moyen (6)</option>
            <option value="difficile" <?= $diffFilter === 'difficile' ? 'selected' : '' ?>>Difficile (7)</option>
        </select>
    </div>
    <div>
        <label style="font-size:0.75rem;color:var(--muted);display:block;margin-bottom:0.2rem;">Par page</label>
        <select name="perPage">
            <?php foreach ([50, 100, 200, 500] as $n): ?>
                <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Filtrer</button>
    <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/jeux/wordle')) ?>">Réinitialiser</a>
</form>

<p style="color:var(--muted);font-size:0.85rem;margin-bottom:0.5rem;">
    <strong><?= number_format($total, 0, ',', ' ') ?></strong> mot(s)
    <?php if ($search !== '' || $langFilter !== '' || $diffFilter !== ''): ?>
        · filtré(s)
    <?php endif; ?>
</p>

<?php if ($words === []): ?>
    <div class="card surface glass" style="text-align:center;padding:2rem;color:var(--muted);">
        Aucun mot ne correspond à ces critères.
    </div>
<?php else: ?>
    <div class="card surface glass table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Mot</th>
                    <th>Langue</th>
                    <th class="num">Long.</th>
                    <th>Difficulté</th>
                    <th>Actif</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($words as $w): ?>
                    <tr>
                        <td><strong style="letter-spacing:0.1em;"><?= e((string) $w['word']) ?></strong></td>
                        <td><?= $w['language'] === 'en' ? '🇬🇧 EN' : '🇫🇷 FR' ?></td>
                        <td class="num"><?= (int) $w['length'] ?></td>
                        <td><?= e(ucfirst((string) $w['difficulty'])) ?></td>
                        <td><?= ((int) $w['is_active']) === 1
                                ? '<span class="badge badge-success">Oui</span>'
                                : '<span class="badge badge-muted">Non</span>' ?></td>
                        <td class="row-actions">
                            <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/jeux/wordle/' . (int) $w['id'])) ?>" title="Modifier">✏️</a>
                            <form method="post" action="<?= e(url('/admin/jeux/wordle/' . (int) $w['id'] . '/delete')) ?>" class="inline-form"
                                  data-confirm="Supprimer le mot « <?= e((string) $w['word']) ?> » ?">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-danger btn-sm" title="Supprimer">🗑️</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1):
        $window = wordlePageWindow($page, $totalPages);
        $buildUrl = function ($p) use ($search, $langFilter, $diffFilter, $perPage) {
            return url('/admin/jeux/wordle?' . http_build_query([
                'q' => $search, 'lang' => $langFilter, 'diff' => $diffFilter,
                'perPage' => $perPage, 'page' => $p,
            ]));
        };
    ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a class="btn btn-outline btn-sm" href="<?= e($buildUrl($page - 1)) ?>">← Préc.</a>
            <?php else: ?>
                <span class="btn btn-outline btn-sm" style="opacity:0.4;pointer-events:none;">← Préc.</span>
            <?php endif; ?>

            <?php foreach ($window as $item):
                if ($item === '…'): ?>
                    <span class="pagination-ellipsis">…</span>
                <?php elseif ($item === $page): ?>
                    <span class="btn btn-primary btn-sm pagination-current"><?= $item ?></span>
                <?php else: ?>
                    <a class="btn btn-outline btn-sm" href="<?= e($buildUrl($item)) ?>"><?= $item ?></a>
                <?php endif;
            endforeach; ?>

            <?php if ($page < $totalPages): ?>
                <a class="btn btn-outline btn-sm" href="<?= e($buildUrl($page + 1)) ?>">Suiv. →</a>
            <?php else: ?>
                <span class="btn btn-outline btn-sm" style="opacity:0.4;pointer-events:none;">Suiv. →</span>
            <?php endif; ?>
        </div>

        <!-- Saut rapide à une page -->
        <form method="get" style="display:flex;gap:0.4rem;justify-content:center;align-items:center;margin-top:0.6rem;flex-wrap:wrap;">
            <?php foreach (['q' => $search, 'lang' => $langFilter, 'diff' => $diffFilter, 'perPage' => $perPage] as $k => $v):
                if ($v !== ''): ?>
                    <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>">
                <?php endif;
            endforeach; ?>
            <span style="color:var(--muted);font-size:0.85rem;">Aller à la page</span>
            <input type="number" name="page" min="1" max="<?= $totalPages ?>" value="<?= $page ?>"
                   style="width:80px;padding:0.3rem;border-radius:0.3rem;border:1px solid var(--border-strong);background:rgba(255,255,255,0.04);color:var(--foreground);text-align:center;" />
            <span style="color:var(--muted);font-size:0.85rem;">/ <?= $totalPages ?></span>
            <button type="submit" class="btn btn-outline btn-sm">OK</button>
        </form>
    <?php endif; ?>
<?php endif; ?>

<style>
.pagination {
    display: flex;
    gap: 0.3rem;
    justify-content: center;
    margin-top: 1rem;
    flex-wrap: wrap;
    align-items: center;
}
.pagination-ellipsis {
    color: var(--muted);
    padding: 0 0.3rem;
    user-select: none;
}
.pagination-current {
    pointer-events: none;
}
</style>
