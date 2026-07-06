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
    <button type="submit" class="btn btn-primary btn-sm">Filtrer</button>
    <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/jeux/wordle')) ?>">Réinitialiser</a>
</form>

<p style="color:var(--muted);font-size:0.85rem;margin-bottom:0.5rem;">
    <?= number_format($total, 0, ',', ' ') ?> mot(s) au total
</p>

<div class="card surface glass table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>Mot</th>
                <th>Langue</th>
                <th>Longueur</th>
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

<?php if ($totalPages > 1): ?>
    <div style="display:flex;gap:0.4rem;justify-content:center;margin-top:1rem;flex-wrap:wrap;">
        <?php for ($i = 1; $i <= $totalPages; $i++):
            $params = http_build_query(['q' => $search, 'lang' => $langFilter, 'diff' => $diffFilter, 'page' => $i]); ?>
            <a class="btn <?= $i === $page ? 'btn-primary' : 'btn-outline' ?> btn-sm" href="<?= e(url('/admin/jeux/wordle?' . $params)) ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <p style="text-align:center;color:var(--muted);font-size:0.8rem;margin-top:0.5rem;">
        Page <?= $page ?> / <?= $totalPages ?> (<?= $perPage ?> par page)
    </p>
<?php endif; ?>
