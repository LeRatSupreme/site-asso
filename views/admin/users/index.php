<?php

declare(strict_types=1);

use App\Core\Auth;

/**
 * @var list<array<string,mixed>> $users
 * @var string $currentId
 */
$roleLabels = [
    Auth::ROLE_ADMIN      => 'Administrateur',
    Auth::ROLE_TRESORERIE => 'Trésorerie',
    Auth::ROLE_ELEVE      => 'Élève',
];
?>
<!-- Barre de filtres horizontale -->
<div class="user-filters">
    <input type="text" id="user-search" class="user-filter-search" placeholder="🔎 Rechercher…" autocomplete="off">
    <select id="user-role-filter" class="user-filter-select">
        <option value="">Tous les rôles</option>
        <option value="admin">👑 Admin</option>
        <option value="eleve">🎓 Élève</option>
        <option value="tresorerie">💰 Trésorerie</option>
    </select>
    <select id="user-status-filter" class="user-filter-select">
        <option value="">Tous</option>
        <option value="active">✅ Actif</option>
        <option value="inactive">⛔ Inactif</option>
    </select>
    <span class="user-filter-count muted" id="user-count"></span>
</div>

<div class="card surface glass table-wrap">
    <table class="table">
        <thead><tr><th>Nom</th><th>Email</th><th>Rôle</th><th>Statut</th><th>Inscription</th><th>Actions</th></tr></thead>
        <tbody>
            <?php foreach ($users as $u): ?>
                <?php $isSelf = ($u['id'] ?? '') === $currentId; ?>
                <?php
                $roleKey = strtolower((string)($u['role'] ?? ''));
                $isActive = !empty($u['is_active']);
                ?>
                <tr<?= $isSelf ? ' class="row-self"' : '' ?>
                    data-name="<?= e(strtolower(trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? '') . ' ' . ($u['email'] ?? '')))) ?>"
                    data-role="<?= e($roleKey) ?>"
                    data-status="<?= $isActive ? 'active' : 'inactive' ?>">
                    <td>
                        <strong><?= e(trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? ''))) ?></strong>
                        <?= $isSelf ? '<span class="badge badge-info">vous</span>' : '' ?>
                    </td>
                    <td><?= e($u['email'] ?? '') ?></td>
                    <td>
                        <form method="post" action="<?= e(url('/admin/users/' . rawurlencode((string) $u['id']) . '/role')) ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <select name="role" onchange="this.form.submit()" <?= $isSelf ? 'disabled title="Vous ne pouvez pas modifier votre propre rôle"' : '' ?>>
                                <?php foreach ($roleLabels as $val => $label): ?>
                                    <option value="<?= e($val) ?>" <?= ($u['role'] ?? '') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
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
                            <button type="submit" class="btn btn-outline btn-sm" <?= $isSelf ? 'disabled title="Vous ne pouvez pas vous désactiver"' : '' ?>>
                                <?= $isActive ? 'Désactiver' : 'Activer' ?>
                            </button>
                        </form>
                        <form method="post" action="<?= e(url('/admin/users/' . rawurlencode((string) $u['id']) . '/reset-password')) ?>" class="inline-form"
                              data-confirm="Réinitialiser le mot de passe de <?= e(trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? ''))) ?> ? Un mot de passe temporaire sera envoyé par email.">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-outline btn-sm">Reset MDP</button>
                        </form>
                        <form method="post" action="<?= e(url('/admin/users/' . rawurlencode((string) $u['id']) . '/delete')) ?>" class="inline-form"
                              data-confirm="Supprimer définitivement le compte de <?= e(trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? ''))) ?> ? Action irréversible.">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-danger btn-sm" <?= $isSelf ? 'disabled title="Vous ne pouvez pas supprimer votre propre compte ici"' : '' ?>>
                                Supprimer
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
</script>
