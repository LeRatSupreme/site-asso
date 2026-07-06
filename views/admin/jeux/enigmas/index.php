<?php

declare(strict_types=1);

/**
 * Liste des énigmes (admin) — recherche + filtre actif, style horizontal.
 *
 * @var string $title
 * @var list<array<string,mixed>> $enigmas
 */
?>
<div class="admin-actions">
    <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/jeux')) ?>">← Retour</a>
</div>

<!-- Barre de filtres horizontale -->
<div class="enigma-toolbar">
    <div class="enigma-search">
        <input type="text" id="enigma-search" placeholder="🔎 Rechercher une question ou réponse…" aria-label="Recherche" />
    </div>

    <select id="enigma-active-filter" aria-label="Statut">
        <option value="">Toutes</option>
        <option value="1">Actives</option>
        <option value="0">Inactives</option>
    </select>

    <a class="btn btn-primary btn-sm" href="<?= e(url('/admin/jeux/enigmes/new')) ?>">+ Nouvelle énigme</a>

    <span class="enigma-count"><span id="enigma-count"><?= count($enigmas) ?></span> énigme(s)</span>
</div>

<?php if ($enigmas === []): ?>
    <div class="card surface glass" style="text-align:center;padding:2rem;color:var(--muted);">
        Aucune énigme. Clique sur « + Nouvelle énigme ».
    </div>
<?php else: ?>
    <div class="card surface glass table-wrap">
        <table class="table" id="enigmas-table">
            <thead>
                <tr>
                    <th class="num">#</th>
                    <th>Question (FR)</th>
                    <th>Réponse</th>
                    <th>Actif</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="enigmas-body">
                <?php foreach ($enigmas as $en):
                    $search = strtolower((string) ($en['question_fr'] ?? '') . ' ' . (string) ($en['answer'] ?? '') . ' ' . (string) ($en['question_en'] ?? '')); ?>
                    <tr data-search="<?= e($search) ?>" data-active="<?= (int) $en['is_active'] ?>">
                        <td class="num"><?= (int) $en['id'] ?></td>
                        <td style="max-width:420px;"><?= e(mb_strimwidth((string) $en['question_fr'], 0, 120, '…')) ?></td>
                        <td><code style="background:rgba(255,255,255,0.06);padding:0.15rem 0.4rem;border-radius:0.3rem;"><?= e((string) $en['answer']) ?></code></td>
                        <td><?= ((int) $en['is_active']) === 1
                                ? '<span class="badge badge-success">Oui</span>'
                                : '<span class="badge badge-muted">Non</span>' ?></td>
                        <td class="row-actions">
                            <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/jeux/enigmes/' . (int) $en['id'])) ?>" title="Modifier">✏️</a>
                            <form method="post" action="<?= e(url('/admin/jeux/enigmes/' . (int) $en['id'] . '/delete')) ?>" class="inline-form"
                                  data-confirm="Supprimer cette énigme ?">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-danger btn-sm" title="Supprimer">🗑️</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<style>
.enigma-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1rem;
    padding: 0.85rem 1rem;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--border);
    border-radius: 12px;
}
.enigma-toolbar .enigma-search {
    flex: 1 1 200px;
    min-width: 180px;
}
.enigma-toolbar .enigma-search input {
    width: 100%;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid var(--border);
    border-radius: 10px;
    color: var(--foreground);
    padding: 0.5rem 0.7rem;
    font-size: 0.9rem;
}
.enigma-toolbar .enigma-search input:focus,
.enigma-toolbar select:focus {
    outline: none;
    border-color: var(--primary);
}
.enigma-toolbar select {
    width: auto;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid var(--border);
    border-radius: 10px;
    color: var(--foreground);
    padding: 0.5rem 0.7rem;
    font-size: 0.9rem;
}
.enigma-count {
    margin-left: auto;
    font-size: 0.8rem;
    color: var(--muted);
    white-space: nowrap;
}
</style>

<script>
(function() {
    var searchInput = document.getElementById('enigma-search');
    var activeFilter = document.getElementById('enigma-active-filter');
    var countEl = document.getElementById('enigma-count');
    var rows = Array.prototype.slice.call(document.querySelectorAll('#enigmas-body tr'));
    if (!searchInput) return;

    function apply() {
        var q = (searchInput.value || '').toLowerCase().trim();
        var af = activeFilter.value;
        var visible = 0;
        rows.forEach(function(tr) {
            var matchSearch = q === '' || (tr.dataset.search || '').indexOf(q) !== -1;
            var matchActive = af === '' || tr.dataset.active === af;
            var show = matchSearch && matchActive;
            tr.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        countEl.textContent = visible;
    }

    searchInput.addEventListener('input', apply);
    activeFilter.addEventListener('change', apply);
})();
</script>
