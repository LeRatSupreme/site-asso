<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Permissions;

/**
 * @var list<array<string,mixed>> $users
 * @var string $currentId
 */
$roleLabels = Permissions::roles();
$roleIcons = [
    'SUPERADMIN'    => '🛡️',
    'ADMIN'         => '👑',
    'TRESORERIE'    => '💰',
    'COMMUNICATION' => '📣',
    'CAFETERIA'     => '🥤',
    'JEUX'          => '🎮',
    'ELEVE'         => '🎓',
];
?>
<!-- Barre de filtres horizontale -->
<div class="user-filters">
    <input type="text" id="user-search" class="user-filter-search" placeholder="🔎 Rechercher…" autocomplete="off">
    <select id="user-role-filter" class="user-filter-select">
        <option value="">Tous les rôles</option>
        <?php foreach ($roleLabels as $value => $label): ?>
            <option value="<?= e(strtolower($value)) ?>"><?= e(($roleIcons[$value] ?? '•') . ' ' . $label) ?></option>
        <?php endforeach; ?>
    </select>
    <select id="user-status-filter" class="user-filter-select">
        <option value="">Tous</option>
        <option value="active">✅ Actif</option>
        <option value="inactive">⛔ Inactif</option>
    </select>
    <span class="user-filter-count muted" id="user-count"></span>
</div>

<div class="card surface glass table-wrap">
    <table class="table table-users">
        <thead><tr><th>Nom</th><th>Email</th><th>Rôle</th><th>Pages</th><th>Statut</th><th>Inscription</th><th>Actions</th></tr></thead>
        <tbody>
            <?php foreach ($users as $u): ?>
                <?php $isSelf = ($u['id'] ?? '') === $currentId; ?>
                <?php
                $roleKey = strtolower((string)($u['role'] ?? ''));
                $isActive = !empty($u['is_active']);

                // Rôles sensibles :
                //  - FONDATEUR : jamais attribuable ni modifiable depuis le site ;
                //  - TRÉSORERIE : visible et attribuable par un ADMIN,
                //    mais sa modification reste réservée au Fondateur.
                // Verrous partagés par les cellules Nom / Rôle / Pages +
                // (calculés ici, en tête de ligne).
                $viewerRole = Auth::role();
                $viewerIsFondateur = $viewerRole === Auth::ROLE_SUPERADMIN;
                $isFondateurRow = ($u['role'] ?? '') === Auth::ROLE_SUPERADMIN;
                $isTresorierRow = ($u['role'] ?? '') === Auth::ROLE_TRESORERIE;

                $selectLock = '';
                if ($isSelf) {
                    $selectLock = 'disabled title="Vous ne pouvez pas modifier votre propre rôle"';
                } elseif (!$viewerIsFondateur && ($isFondateurRow || $isTresorierRow)) {
                    $selectLock = 'disabled title="Réservé au Fondateur"';
                }
                ?>
                <tr<?= $isSelf ? ' class="row-self"' : '' ?>
                    data-name="<?= e(strtolower(trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? '') . ' ' . ($u['email'] ?? '')))) ?>"
                    data-role="<?= e($roleKey) ?>"
                    data-status="<?= $isActive ? 'active' : 'inactive' ?>">
                    <td>
                        <?php if ($selectLock === '' && !$isFondateurRow): ?>
                            <!-- Édition inline du nom (users.prenom + users.nom) :
                                 mêmes verrous que le select de rôle. -->
                            <form method="post" action="<?= e(url('/admin/users/' . rawurlencode((string) $u['id']) . '/name')) ?>" class="inline-form name-form">
                                <?= csrf_field() ?>
                                <input type="text" name="prenom" value="<?= e((string) ($u['prenom'] ?? '')) ?>" required maxlength="255"
                                       class="name-edit name-prenom" autocomplete="off"
                                       title="Prénom — modifier puis cliquer ailleurs (ou Entrée) pour enregistrer"
                                       onchange="this.form.submit()">
                                <input type="text" name="nom" value="<?= e((string) ($u['nom'] ?? '')) ?>" required maxlength="255"
                                       class="name-edit name-nom" autocomplete="off"
                                       title="Nom — modifier puis cliquer ailleurs (ou Entrée) pour enregistrer"
                                       onchange="this.form.submit()">
                            </form>
                        <?php else: ?>
                            <strong><?= e(trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? ''))) ?></strong>
                        <?php endif; ?>
                        <?= $isSelf ? '<span class="badge badge-info">vous</span>' : '' ?>
                    </td>
                    <td><?= e($u['email'] ?? '') ?></td>
                    <td>
                        <form method="post" action="<?= e(url('/admin/users/' . rawurlencode((string) $u['id']) . '/role')) ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <select name="role" onchange="this.form.submit()" class="role-select" <?= $selectLock ?>>
                                <?php foreach ($roleLabels as $val => $label): ?>
                                    <?php if ($val === Auth::ROLE_SUPERADMIN && !$isFondateurRow) continue; ?>
                                    <option value="<?= e($val) ?>" <?= ($u['role'] ?? '') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </td>
                    <td>
                        <?php
                        // Pages supplémentaires attribuées individuellement
                        // (users.extra_pages) : mêmes verrous que le select
                        // de rôle ci-dessus.
                        $grantedPages = Permissions::grantedPages(isset($u['extra_pages']) ? (string) $u['extra_pages'] : null);
                        $pagesLock = '';
                        if ($isSelf) {
                            $pagesLock = 'disabled title="Vous ne pouvez pas modifier vos propres pages"';
                        } elseif (!$viewerIsFondateur && ($isFondateurRow || $isTresorierRow)) {
                            $pagesLock = 'disabled title="Réservé au Fondateur"';
                        }
                        $allExtraPages = Permissions::extraPages();
                        $modulePageKeys = ['compta', 'events', 'content', 'cafeteria', 'games'];
                        $systemPageKeys = ['inventory', 'costs', 'cash', 'users', 'settings'];
                        ?>
                        <form method="post" action="<?= e(url('/admin/users/' . rawurlencode((string) $u['id']) . '/pages')) ?>" class="inline-form pages-form">
                            <?= csrf_field() ?>
                            <details class="pages-details">
                                <summary class="pages-chip" title="Pages supplémentaires">🔑 Pages <b>(<?= count($grantedPages) ?>)</b></summary>
                                <div class="pages-panel">
                                    <p class="pages-group-title">Modules</p>
                                    <div class="pages-checks">
                                        <?php foreach ($modulePageKeys as $pageKey): ?>
                                            <label class="pages-check">
                                                <input type="checkbox" name="pages[]" value="<?= e($pageKey) ?>" <?= in_array($pageKey, $grantedPages, true) ? 'checked' : '' ?> <?= $pagesLock ?>>
                                                <?= e($allExtraPages[$pageKey] ?? $pageKey) ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="pages-group-title">Système</p>
                                    <div class="pages-checks">
                                        <?php foreach ($systemPageKeys as $pageKey): ?>
                                            <label class="pages-check">
                                                <input type="checkbox" name="pages[]" value="<?= e($pageKey) ?>" <?= in_array($pageKey, $grantedPages, true) ? 'checked' : '' ?> <?= $pagesLock ?>>
                                                <?= e($allExtraPages[$pageKey] ?? $pageKey) ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="pages-panel-actions">
                                        <?php if ($pagesLock === ''): ?>
                                            <button type="button" class="btn btn-ghost btn-sm" onclick="pagesCheckAll(this, true)">Tout</button>
                                            <button type="button" class="btn btn-ghost btn-sm" onclick="pagesCheckAll(this, false)">Aucun</button>
                                        <?php endif; ?>
                                        <button type="submit" class="btn btn-primary btn-sm" <?= $pagesLock ?>>Enregistrer</button>
                                    </p>
                                </div>
                            </details>
                        </form>
                    </td>
                    <td>
                        <?php if ($isActive): ?>
                            <span class="badge badge-success">Actif</span>
                        <?php else: ?>
                            <span class="badge badge-muted">Désactivé</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e(formatDate((string) ($u['created_at'] ?? ''))) ?></td>
                    <td class="row-actions">
                        <form method="post" action="<?= e(url('/admin/users/' . rawurlencode((string) $u['id']) . '/toggle-active')) ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-outline btn-sm icon-btn" <?= $isSelf ? 'disabled title="Vous ne pouvez pas vous désactiver"' : 'title="' . ($isActive ? 'Désactiver ce compte' : 'Réactiver ce compte') . '"' ?>>
                                <?= $isActive ? '⏸' : '▶' ?>
                            </button>
                        </form>
                        <form method="post" action="<?= e(url('/admin/users/' . rawurlencode((string) $u['id']) . '/reset-password')) ?>" class="inline-form"
                              data-confirm="Réinitialiser le mot de passe de <?= e(trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? ''))) ?> ? Un mot de passe temporaire sera envoyé par email.">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-outline btn-sm icon-btn" title="Renvoyer un mot de passe temporaire par email">🔑</button>
                        </form>
                        <form method="post" action="<?= e(url('/admin/users/' . rawurlencode((string) $u['id']) . '/delete')) ?>" class="inline-form"
                              data-confirm="Supprimer définitivement le compte de <?= e(trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? ''))) ?> ? Action irréversible." data-preserve-scroll>
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-danger btn-sm icon-btn" <?= $isSelf ? 'disabled title="Vous ne pouvez pas supprimer votre propre compte ici"' : 'title="Supprimer définitivement"' ?>>
                                🗑
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<p class="card-meta">Le dernier administrateur actif ne peut être ni rétrogradé, ni désactivé, ni supprimé. Chaque action est journalisée (audit log).</p>

<style>
.user-filters {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    margin-bottom: 1rem;
    flex-wrap: nowrap;
    overflow-x: auto;
    padding-bottom: 0.25rem;
}
.user-filter-search {
    flex: 1;
    min-width: 180px;
    background: rgba(255,255,255,0.05);
    border: 1px solid var(--border);
    border-radius: 8px;
    color: var(--foreground);
    padding: 0.5rem 0.7rem;
    font-size: 0.88rem;
    white-space: nowrap;
}
.user-filter-search:focus { outline: none; border-color: var(--primary); }
.user-filter-select {
    background: rgba(255,255,255,0.05);
    border: 1px solid var(--border);
    border-radius: 8px;
    color: var(--foreground);
    padding: 0.5rem 0.5rem;
    font-size: 0.82rem;
    white-space: nowrap;
}
.user-filter-count {
    font-size: 0.78rem;
    white-space: nowrap;
    flex-shrink: 0;
}
.pages-form { display: flex; }
.pages-details { position: relative; display: inline-block; }
.pages-details > summary { list-style: none; cursor: pointer; }
.pages-details > summary::-webkit-details-marker { display: none; }
.pages-panel {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    z-index: 40;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 0.5rem 0.8rem 0.7rem;
    min-width: 220px;
    box-shadow: 0 12px 32px rgba(0, 0, 0, 0.35);
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
}
.pages-group-title {
    margin: 0.4rem 0 0.15rem;
    font-size: 0.68rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--muted);
}
.pages-group-title:first-child { margin-top: 0.1rem; }
.pages-form .pages-check { display: flex; align-items: center; gap: 0.35rem; white-space: nowrap; cursor: pointer; }
.pages-form .pages-check input { margin: 0; }
.pages-panel-actions { display: flex; align-items: center; gap: 0.4rem; margin: 0.55rem 0 0; }

/* Table utilisateurs compacte */
.table-users td { padding: 0.4rem 0.55rem; font-size: 0.85rem; vertical-align: middle; }
.table-users th { font-size: 0.72rem; }
.row-actions { white-space: nowrap; }
.row-actions .inline-form { display: inline-flex; margin: 0; }

/* Champs « fantômes » : ressemblent à du texte, s'éclairent au survol/focus */
.name-form { display: flex; gap: 0.2rem; align-items: baseline; }
.name-edit, .role-select {
    background: transparent;
    border: 1px solid transparent;
    border-radius: 6px;
    color: var(--foreground);
    padding: 0.15rem 0.35rem;
    font-size: 0.85rem;
}
.name-edit:hover, .role-select:hover { border-color: var(--border); background: rgba(255,255,255,0.04); }
.name-edit:focus, .role-select:focus {
    outline: none;
    border-color: var(--primary);
    background: rgba(255,255,255,0.05);
}
.name-prenom { width: 9ch; min-width: 7ch; }
.name-nom { width: 12ch; min-width: 8ch; }
.role-select { cursor: pointer; max-width: 11ch; }

/* Pastille Pages */
.pages-chip {
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    border: 1px solid var(--border);
    border-radius: 999px;
    padding: 0.15rem 0.55rem;
    font-size: 0.75rem;
    color: var(--foreground);
    background: rgba(255,255,255,0.04);
    list-style: none;
}
.pages-chip::-webkit-details-marker { display: none; }
.pages-chip:hover { border-color: var(--primary); }
.pages-chip b { color: var(--primary); }

/* Panneau : 2 colonnes de cases pour rester bas */
.pages-checks { display: grid; grid-template-columns: 1fr 1fr; gap: 0.1rem 0.9rem; }
.pages-form .pages-check { font-size: 0.76rem; }

/* Actions en icônes */
.icon-btn { padding: 0.2rem 0.45rem; font-size: 0.95rem; line-height: 1; }

@media (max-width: 700px) {
    .table-users th:nth-child(6), .table-users td:nth-child(6) { display: none; } /* Inscription */
}
@media (max-width: 600px) {
    .user-filters { flex-wrap: wrap; }
    .user-filter-search { min-width: 100%; }
}
</style>

<script>
(function () {
    var search = document.getElementById('user-search');
    var roleFilter = document.getElementById('user-role-filter');
    var statusFilter = document.getElementById('user-status-filter');
    var countEl = document.getElementById('user-count');
    var rows = Array.prototype.slice.call(document.querySelectorAll('.table tbody tr'));
    var total = rows.length;
    if (!search) return;

    function norm(s) { return s.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim(); }

    function apply() {
        var q = norm(search.value);
        var role = roleFilter.value;
        var status = statusFilter.value;
        var shown = 0;

        rows.forEach(function (tr) {
            var okSearch = q === '' || norm(tr.getAttribute('data-name')).indexOf(q) !== -1;
            var okRole = role === '' || tr.getAttribute('data-role') === role;
            var okStatus = status === '' || tr.getAttribute('data-status') === status;
            var visible = okSearch && okRole && okStatus;
            tr.style.display = visible ? '' : 'none';
            if (visible) shown++;
        });

        countEl.textContent = shown + ' / ' + total + ' utilisateur' + (total > 1 ? 's' : '');
    }

    search.addEventListener('input', apply);
    roleFilter.addEventListener('change', apply);
    statusFilter.addEventListener('change', apply);
    apply();
})();

// Panneau « Pages + » : coche / décoche toutes les cases du panel parent.
function pagesCheckAll(btn, checked) {
    var panel = btn.closest('.pages-panel');
    if (!panel) return;
    var boxes = panel.querySelectorAll('input[name="pages[]"]');
    for (var i = 0; i < boxes.length; i++) {
        if (!boxes[i].disabled) boxes[i].checked = checked;
    }
}
</script>
