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
                        $pagesLockTitle = '';
                        if ($isSelf) {
                            $pagesLockTitle = 'Vous ne pouvez pas modifier vos propres pages';
                        } elseif (!$viewerIsFondateur && ($isFondateurRow || $isTresorierRow)) {
                            $pagesLockTitle = 'Réservé au Fondateur';
                        }
                        $allExtraPages = Permissions::extraPages();
                        $modulePageKeys = ['compta', 'events', 'content', 'cafeteria', 'games'];
                        $systemPageKeys = ['inventory', 'costs', 'cash', 'users', 'settings'];
                        $pageIcons = [
                            'compta' => '💰', 'events' => '📅', 'content' => '📣', 'cafeteria' => '☕', 'games' => '🎮',
                            'inventory' => '📦', 'costs' => '🧮', 'cash' => '🏦', 'users' => '👥', 'settings' => '⚙️',
                        ];
                        $fullName = e(trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? '')));
                        $dialogDomId = preg_replace('#[^A-Za-z0-9_-]#', '', (string) $u['id']);
                        ?>
                        <form method="post" action="<?= e(url('/admin/users/' . rawurlencode((string) $u['id']) . '/pages')) ?>" class="inline-form pages-form">
                            <?= csrf_field() ?>
                            <?php if ($pagesLockTitle !== ''): ?>
                                <span class="pages-chip is-muted" title="<?= e($pagesLockTitle) ?>">🔑 <?= count($grantedPages) ?></span>
                            <?php else: ?>
                                <button type="button" class="pages-chip" title="Pages supplémentaires" onclick="document.getElementById('pages-dialog-<?= e($dialogDomId) ?>').showModal()">🔑 <?= count($grantedPages) ?></button>
                                <dialog id="pages-dialog-<?= e($dialogDomId) ?>" class="pages-dialog">
                                    <div class="pages-dialog-head">
                                        <div>
                                            <p class="pages-dialog-title">🔑 Pages supplémentaires</p>
                                            <p class="pages-dialog-sub"><?= $fullName ?> · <b><span class="pages-count"><?= count($grantedPages) ?></span> sélectionnée(s)</b></p>
                                        </div>
                                        <button type="button" class="pages-dialog-close" onclick="this.closest('dialog').close()" title="Fermer">✕</button>
                                    </div>
                                    <div class="pages-dialog-body">
                                        <p class="pages-group-title">Modules</p>
                                        <div class="pages-checks">
                                            <?php foreach ($modulePageKeys as $pageKey): ?>
                                                <label class="pages-check">
                                                    <input type="checkbox" name="pages[]" value="<?= e($pageKey) ?>" <?= in_array($pageKey, $grantedPages, true) ? 'checked' : '' ?>>
                                                    <span class="pages-ico"><?= $pageIcons[$pageKey] ?? '📄' ?></span>
                                                    <span class="pages-label"><?= e($allExtraPages[$pageKey] ?? $pageKey) ?></span>
                                                    <span class="pages-tick">✓</span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                        <p class="pages-group-title">Système</p>
                                        <div class="pages-checks">
                                            <?php foreach ($systemPageKeys as $pageKey): ?>
                                                <label class="pages-check">
                                                    <input type="checkbox" name="pages[]" value="<?= e($pageKey) ?>" <?= in_array($pageKey, $grantedPages, true) ? 'checked' : '' ?>>
                                                    <span class="pages-ico"><?= $pageIcons[$pageKey] ?? '📄' ?></span>
                                                    <span class="pages-label"><?= e($allExtraPages[$pageKey] ?? $pageKey) ?></span>
                                                    <span class="pages-tick">✓</span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="pages-dialog-foot">
                                        <button type="button" class="btn btn-ghost btn-sm" onclick="pagesCheckAll(this, true)">Tout</button>
                                        <button type="button" class="btn btn-ghost btn-sm" onclick="pagesCheckAll(this, false)">Aucun</button>
                                        <span class="pages-dialog-spacer"></span>
                                        <button type="submit" class="btn btn-primary btn-sm">Enregistrer</button>
                                    </div>
                                </dialog>
                            <?php endif; ?>
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
.pages-form { display: inline-flex; }
.pages-chip {
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    border: 1px solid var(--border);
    border-radius: 999px;
    padding: 0.18rem 0.6rem;
    font-size: 0.75rem;
    color: var(--foreground);
    background: rgba(255,255,255,0.04);
}
.pages-chip b, .pages-chip { line-height: 1.2; }
.pages-chip:hover { border-color: var(--primary); color: var(--primary); }
.pages-chip.is-muted { opacity: 0.55; cursor: help; }

.pages-dialog {
    /* Centrage explicite : le reset global « * { margin: 0 } » du site
       écrase le « margin: auto » natif des <dialog> modaux, ce qui les
       colle en haut à gauche. */
    position: fixed;
    inset: 0;
    margin: auto;
    height: fit-content;
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 0;
    background: var(--card);
    color: var(--foreground);
    width: min(640px, 94vw);
    max-height: 82vh;
    box-shadow: 0 24px 64px rgba(0,0,0,0.45);
}
.pages-dialog::backdrop { background: rgba(0,0,0,0.55); backdrop-filter: blur(3px); }
.pages-dialog[open] { animation: pages-pop 0.14s ease-out; }
@keyframes pages-pop { from { opacity: 0; transform: translateY(6px) scale(0.98); } to { opacity: 1; transform: none; } }

.pages-dialog-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.6rem;
    padding: 0.9rem 1rem 0.7rem;
    border-bottom: 1px solid var(--border);
}
.pages-dialog-title { margin: 0; font-size: 0.95rem; font-weight: 700; }
.pages-dialog-sub { margin: 0.15rem 0 0; font-size: 0.76rem; color: var(--muted); }
.pages-dialog-sub .pages-count { color: var(--primary); font-size: 0.82rem; }
.pages-dialog-close {
    background: transparent; border: none; color: var(--muted);
    font-size: 1rem; cursor: pointer; line-height: 1; padding: 0.2rem 0.35rem; border-radius: 6px;
}
.pages-dialog-close:hover { color: var(--foreground); background: rgba(255,255,255,0.07); }

.pages-dialog-body { padding: 0.7rem 1rem 0.4rem; overflow-y: auto; max-height: 52vh; }
.pages-group-title {
    margin: 0.5rem 0 0.3rem;
    font-size: 0.66rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--muted);
    padding: 0 0.6rem; /* aligne les titres sur le texte des cases */
}
.pages-checks { display: grid; grid-template-columns: repeat(auto-fill, minmax(118px, 1fr)); gap: 0.45rem; }
.pages-check {
    position: relative;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 0.35rem;
    padding: 0.6rem 0.4rem 0.5rem;
    border-radius: 10px;
    font-size: 0.78rem;
    text-align: center;
    cursor: pointer;
    border: 1px solid var(--border);
    background: rgba(255,255,255,0.03);
    transition: border-color 0.12s ease, background 0.12s ease;
}
.pages-check:hover { background: rgba(255,255,255,0.06); }
.pages-check:has(input:checked) { border-color: var(--primary); background: rgba(255,255,255,0.07); }
.pages-check:has(input:focus-visible) { outline: 2px solid var(--primary); outline-offset: 1px; }
.pages-check input { position: absolute; opacity: 0; pointer-events: none; margin: 0; width: 15px; height: 15px; accent-color: var(--primary); }
.pages-ico {
    width: 30px; height: 30px;
    display: grid; place-items: center;
    font-size: 1.05rem;
    background: rgba(255,255,255,0.06);
    border-radius: 9px;
}
.pages-check:has(input:checked) .pages-ico { background: rgba(255,255,255,0.1); }
.pages-label { line-height: 1.25; } /* centré par le text-align du parent, jamais tronqué */
.pages-tick {
    position: absolute; top: 5px; right: 7px;
    display: none;
    width: 16px; height: 16px;
    font-size: 0.62rem; font-weight: 700; line-height: 16px; text-align: center;
    color: #fff; background: var(--primary); border-radius: 999px;
}
.pages-check:has(input:checked) .pages-tick { display: block; }

.pages-dialog-foot {
    display: flex; align-items: center; flex-wrap: wrap; gap: 0.4rem;
    padding: 0.75rem 1.1rem 1rem;
    border-top: 1px solid var(--border);
}
.pages-dialog-foot .btn { white-space: nowrap; }
.pages-dialog-spacer { flex: 1; }

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

// Modale « Pages + » : coche / décoche toutes les cases du dialog parent
// et met à jour le compteur de l'en-tête.
function pagesCountOf(dialog) {
    var n = 0;
    var boxes = dialog.querySelectorAll('input[name="pages[]"]');
    for (var i = 0; i < boxes.length; i++) if (boxes[i].checked) n++;
    return n;
}

function pagesRefreshCount(dialog) {
    var el = dialog.querySelector('.pages-count');
    if (el) el.textContent = pagesCountOf(dialog);
}

function pagesCheckAll(btn, checked) {
    var dialog = btn.closest('.pages-dialog');
    if (!dialog) return;
    var boxes = dialog.querySelectorAll('input[name="pages[]"]');
    for (var i = 0; i < boxes.length; i++) boxes[i].checked = checked;
    pagesRefreshCount(dialog);
}

var pageDialogs = document.querySelectorAll('.pages-dialog');
for (var pd = 0; pd < pageDialogs.length; pd++) {
    pageDialogs[pd].addEventListener('change', function (e) {
        if (e.target && e.target.name === 'pages[]') pagesRefreshCount(this);
    });
}
</script>
