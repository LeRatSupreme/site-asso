<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $events
 */
?>
<div class="admin-actions">
    <a class="btn btn-primary" href="<?= e(url('/admin/events/new')) ?>">+ Nouvel événement</a>
</div>

<div class="card surface glass table-wrap">
    <table class="table">
        <thead>
            <tr><th>Titre</th><th>Date</th><th>Lieu</th><th>Statut</th><th>Inscrits</th><th>Action</th></tr>
        </thead>
        <tbody>
            <?php foreach ($events as $ev):
                $slug = (string) ($ev['slug'] ?? '');
                $regCount = \App\Models\Event::registrationsCount((string) $ev['id']);
            ?>
                <tr>
                    <td>
                        <strong><?= e($ev['title'] ?? '') ?></strong>
                        <?php if (!empty($ev['category'])): ?>
                            <span class="badge badge-info" style="font-size:0.68rem;vertical-align:middle;"><?= e($ev['category']) ?></span>
                        <?php endif; ?>
                        <br><span class="card-meta">/<?= e($slug) ?></span>
                    </td>
                    <td><?= e(formatDateTime((string) ($ev['date'] ?? ''))) ?></td>
                    <td><?= e($ev['location'] ?? '') ?></td>
                    <td>
                        <?php if (!empty($ev['is_published'])): ?>
                            <span class="badge badge-success">Publié</span>
                        <?php else: ?>
                            <span class="badge badge-muted">Brouillon</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?= e(url('/admin/events/' . rawurlencode($slug) . '/registrations')) ?>">
                            <?= e((string) $regCount) ?>
                        </a>
                    </td>
                    <td>
                        <div class="ev-actions-dropdown">
                            <button type="button" class="btn btn-outline btn-sm ev-actions-toggle" onclick="evToggleDropdown(this)">
                                ⋯ Actions
                            </button>
                            <div class="ev-actions-menu" hidden>
                                <a href="<?= e(url('/admin/events/' . rawurlencode($slug))) ?>" class="ev-menu-item">✏️ Éditer</a>
                                <a href="<?= e(url('/admin/events/' . rawurlencode($slug) . '/registrations')) ?>" class="ev-menu-item">📋 Inscrits (<?= e((string) $regCount) ?>)</a>
                                <a href="<?= e(url('/admin/events/' . rawurlencode($slug) . '/checkin')) ?>" class="ev-menu-item">📱 Check-in QR</a>
                                <a href="<?= e(url('/events/' . rawurlencode($slug))) ?>" target="_blank" class="ev-menu-item">👁️ Voir sur le site</a>
                                <form method="post" action="<?= e(url('/admin/events/' . rawurlencode($slug) . '/delete')) ?>"
                                      onsubmit="return confirm('Supprimer « <?= e($ev['title'] ?? '') ?> » ?');">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="ev-menu-item ev-menu-danger">🗑️ Supprimer</button>
                                </form>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<style>
.ev-actions-dropdown { position: relative; display: inline-block; }
.ev-actions-toggle { white-space: nowrap; }
.ev-actions-menu {
    position: absolute;
    right: 0;
    top: calc(100% + 4px);
    z-index: 50;
    min-width: 200px;
    background: var(--card, #0c1d36);
    border: 1px solid var(--border);
    border-radius: 10px;
    box-shadow: 0 12px 30px rgba(0,0,0,0.4);
    overflow: hidden;
    padding: 0.3rem;
}
.ev-actions-menu[hidden] { display: none; }
.ev-menu-item {
    display: block;
    width: 100%;
    padding: 0.55rem 0.8rem;
    color: var(--foreground);
    font-size: 0.88rem;
    text-decoration: none;
    background: none;
    border: none;
    text-align: left;
    cursor: pointer;
    border-radius: 6px;
    transition: background 0.12s;
}
.ev-menu-item:hover { background: rgba(72,189,211,0.12); }
.ev-menu-danger { color: #ef4444; }
.ev-menu-danger:hover { background: rgba(239,68,68,0.12); }
.ev-actions-menu form { margin: 0; }
</style>

<script>
function evToggleDropdown(btn) {
    var menu = btn.nextElementSibling;
    var isHidden = menu.hasAttribute('hidden');
    // Ferme tous les autres.
    document.querySelectorAll('.ev-actions-menu').forEach(function(m) { m.setAttribute('hidden', ''); });
    if (isHidden) { menu.removeAttribute('hidden'); }
}
// Ferme au clic extérieur.
document.addEventListener('click', function(e) {
    if (!e.target.closest('.ev-actions-dropdown')) {
        document.querySelectorAll('.ev-actions-menu').forEach(function(m) { m.setAttribute('hidden', ''); });
    }
});
</script>
