<?php

declare(strict_types=1);

/**
 * Joueurs & Pseudos (admin) — modifier les pseudos, voir les stats, réinitialiser.
 *
 * @var string $title
 * @var list<array<string,mixed>> $players
 */
?>
<div class="admin-actions">
    <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/jeux')) ?>">← Retour</a>
</div>

<?php if ($players === []): ?>
    <div class="card surface glass" style="text-align:center;padding:2rem;color:var(--muted);">
        Aucun joueur n'a encore joué au Wordle quotidien.
    </div>
<?php else: ?>

    <!-- Barre de filtres horizontale -->
    <div class="wordle-toolbar" style="margin-bottom:1rem;padding:0.85rem 1rem;background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:12px;display:flex;flex-wrap:wrap;align-items:center;gap:0.75rem;">
        <div style="flex:1 1 200px;min-width:180px;">
            <input type="text" id="player-search" placeholder="🔎 Nom, email ou pseudo…"
                   style="width:100%;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:10px;color:var(--foreground);padding:0.5rem 0.7rem;font-size:0.9rem;" />
        </div>

        <select id="player-pseudo-filter" aria-label="Pseudo"
                style="width:auto;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:10px;color:var(--foreground);padding:0.5rem 0.7rem;font-size:0.9rem;">
            <option value="">Tous pseudos</option>
            <option value="with">Avec pseudo</option>
            <option value="without">Sans pseudo</option>
        </select>

        <select id="player-sort" aria-label="Trier"
                style="width:auto;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:10px;color:var(--foreground);padding:0.5rem 0.7rem;font-size:0.9rem;">
            <option value="streak">Série en cours ↓</option>
            <option value="max">Record ↓</option>
            <option value="played">Parties ↓</option>
            <option value="won">Victoires ↓</option>
            <option value="name">Nom A→Z</option>
        </select>

        <span style="margin-left:auto;font-size:0.8rem;color:var(--muted);white-space:nowrap;">
            <span id="player-count"><?= count($players) ?></span> joueur(s)
        </span>
    </div>

    <div class="card surface glass table-wrap">
        <table class="table" id="players-table">
            <thead>
                <tr>
                    <th>Joueur</th>
                    <th>Pseudo</th>
                    <th class="num">Série 🔥</th>
                    <th class="num">Record 🏆</th>
                    <th class="num">Victoires</th>
                    <th class="num">Parties</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="players-body">
                <?php foreach ($players as $p):
                    $pseudo = ($p['pseudo'] ?? null) !== null && $p['pseudo'] !== '' ? (string) $p['pseudo'] : '';
                    $displayName = strtolower($p['prenom'] . ' ' . $p['nom'] . ' ' . $p['email'] . ' ' . $pseudo); ?>
                    <tr data-search="<?= e($displayName) ?>"
                        data-has-pseudo="<?= $pseudo !== '' ? '1' : '0' ?>"
                        data-streak="<?= (int) $p['currentStreak'] ?>"
                        data-max="<?= (int) $p['maxStreak'] ?>"
                        data-won="<?= (int) $p['won'] ?>"
                        data-played="<?= (int) $p['played'] ?>"
                        data-name="<?= e(strtolower($p['prenom'] . ' ' . $p['nom'])) ?>">
                        <td>
                            <strong><?= e($p['prenom'] . ' ' . $p['nom']) ?></strong><br>
                            <span style="color:var(--muted);font-size:0.8rem;"><?= e($p['email']) ?></span>
                        </td>
                        <td>
                            <form method="post" action="<?= e(url('/admin/jeux/set-pseudo')) ?>" class="inline-form" style="display:flex;gap:0.3rem;align-items:center;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="user_id" value="<?= e((string) $p['id']) ?>">
                                <input type="text" name="pseudo" value="<?= e($pseudo) ?>" maxlength="20"
                                       placeholder="—" style="width:130px;padding:0.3rem 0.5rem;border-radius:0.3rem;border:1px solid var(--border-strong);background:rgba(255,255,255,0.04);color:var(--foreground);font-size:0.85rem;" />
                                <button type="submit" class="btn btn-outline btn-sm" title="Enregistrer le pseudo">💾</button>
                            </form>
                        </td>
                        <td class="num"><strong style="color:var(--primary);"><?= (int) $p['currentStreak'] ?></strong></td>
                        <td class="num"><?= (int) $p['maxStreak'] ?></td>
                        <td class="num"><?= (int) $p['won'] ?></td>
                        <td class="num"><?= (int) $p['played'] ?></td>
                        <td>
                            <form method="post" action="<?= e(url('/admin/jeux/reset-player')) ?>" class="inline-form"
                                  data-confirm="Réinitialiser tous les scores de <?= e($p['prenom'] . ' ' . $p['nom']) ?> ?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="user_id" value="<?= e((string) $p['id']) ?>">
                                <button type="submit" class="btn btn-danger btn-sm" title="Réinitialiser les scores">🗑️ Scores</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p style="color:var(--muted);font-size:0.85rem;margin-top:1rem;">
        💡 Astuce : pour <strong>effacer</strong> un pseudo, vide le champ puis clique sur 💾.
        Le pseudo doit faire 3 à 20 caractères (lettres, chiffres, espaces, <code>- _ .</code>) et être unique.
    </p>

<script>
(function() {
    var searchInput = document.getElementById('player-search');
    var pseudoFilter = document.getElementById('player-pseudo-filter');
    var sortSelect = document.getElementById('player-sort');
    var body = document.getElementById('players-body');
    var countEl = document.getElementById('player-count');
    if (!body) return;

    var rows = Array.prototype.slice.call(body.querySelectorAll('tr'));

    function applyFilters() {
        var q = (searchInput.value || '').toLowerCase().trim();
        var pf = pseudoFilter.value;
        var sortBy = sortSelect.value;
        var visible = 0;

        // Filtrage.
        rows.forEach(function(tr) {
            var matchSearch = q === '' || (tr.dataset.search || '').indexOf(q) !== -1;
            var matchPseudo = pf === '' ||
                (pf === 'with' && tr.dataset.hasPseudo === '1') ||
                (pf === 'without' && tr.dataset.hasPseudo === '0');
            var show = matchSearch && matchPseudo;
            tr.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        countEl.textContent = visible;

        // Tri.
        var visibleRows = rows.filter(function(tr) { return tr.style.display !== 'none'; });
        visibleRows.sort(function(a, b) {
            switch (sortBy) {
                case 'max':    return (parseInt(b.dataset.max,10)||0) - (parseInt(a.dataset.max,10)||0);
                case 'played': return (parseInt(b.dataset.played,10)||0) - (parseInt(a.dataset.played,10)||0);
                case 'won':    return (parseInt(b.dataset.won,10)||0) - (parseInt(a.dataset.won,10)||0);
                case 'name':   return (a.dataset.name||'').localeCompare(b.dataset.name||'');
                case 'streak':
                default:       return (parseInt(b.dataset.streak,10)||0) - (parseInt(a.dataset.streak,10)||0);
            }
        });

        // Réinsère les lignes triées.
        visibleRows.forEach(function(tr) { body.appendChild(tr); });
    }

    searchInput.addEventListener('input', applyFilters);
    pseudoFilter.addEventListener('change', applyFilters);
    sortSelect.addEventListener('change', applyFilters);
    applyFilters();
})();
</script>

<?php endif; ?>
