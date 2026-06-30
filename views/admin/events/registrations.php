<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $event
 * @var int $count
 * @var list<array<string,mixed>> $regs
 * @var list<array<string,mixed>> $variants
 * @var list<array<string,mixed>> $waitlist
 * @var string $checkinUrl
 */

$event      = $event ?? [];
$regs       = $regs ?? [];
$variants   = $variants ?? [];
$waitlist   = $waitlist ?? [];
$checkinUrl = $checkinUrl ?? '';
$slug       = (string) ($event['slug'] ?? '');
?>
<header class="admin-section-head">
    <p><a href="<?= e(url('/admin/events')) ?>">← Événements</a></p>
    <h2 class="card-title">Inscriptions — <?= e($event['title'] ?? '') ?></h2>
    <p class="card-meta"><?= e((string) $count) ?> inscrit(s)<?php if (!empty($waitlist)): ?> · <?= e((string) count($waitlist)) ?> en attente<?php endif; ?></p>
    <p><a class="btn btn-primary btn-sm" href="<?= e($checkinUrl) ?>">📱 Ouvrir le check-in (scanner QR)</a></p>
</header>

<div class="card surface glass table-wrap">
    <table class="table">
        <thead><tr><th>Prénom</th><th>Nom</th><th>Présence</th><th>Action</th></tr></thead>
        <tbody>
            <?php if (empty($regs)): ?>
                <tr><td colspan="4" class="card-meta">Aucun inscrit.</td></tr>
            <?php else: ?>
                <?php foreach ($regs as $r): ?>
                    <?php
                    $present = !empty($r['checked_in']);
                    $uid = (string) ($r['user_id'] ?? '');
                    ?>
                    <tr data-user="<?= e($uid) ?>">
                        <td><?= e($r['prenom'] ?? '') ?></td>
                        <td><?= e($r['nom'] ?? '') ?></td>
                        <td>
                            <span class="checkin-badge <?= $present ? 'is-present' : 'is-absent' ?>">
                                <?= $present ? '✅ Présent' : '⬜ Absent' ?>
                            </span>
                        </td>
                        <td>
                            <button type="button"
                                    class="btn btn-sm btn-outline toggle-checkin"
                                    data-url="<?= e(url('/admin/events/' . rawurlencode($slug) . '/toggle-checkin')) ?>"
                                    data-user="<?= e($uid) ?>">
                                <?= $present ? 'Marquer absent' : 'Marquer présent' ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if (!empty($waitlist)): ?>
    <section class="card surface glass">
        <h2 class="card-title">Liste d'attente (<?= e((string) count($waitlist)) ?>)</h2>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>#</th><th>Prénom</th><th>Nom</th><th>Date</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($waitlist as $w): ?>
                        <tr>
                            <td><?= e((string) ($w['position'] ?? '')) ?></td>
                            <td><?= e($w['prenom'] ?? '') ?></td>
                            <td><?= e($w['nom'] ?? '') ?></td>
                            <td><?= e(formatDateTime($w['created_at'] ?? '')) ?></td>
                            <td>
                                <form method="post" action="<?= e(url('/admin/events/' . rawurlencode($slug) . '/promote')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="user_id" value="<?= e((string) ($w['user_id'] ?? '')) ?>">
                                    <button type="submit" class="btn btn-sm btn-primary">Promouvoir</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php if (!empty($variants)): ?>
    <section class="card surface glass">
        <h2 class="card-title">Options / variantes</h2>
        <?php foreach ($variants as $v): ?>
            <p><strong><?= e($v['label'] ?? '') ?></strong> —
                <?= e(implode(', ', array_map(static fn ($c) => $c['label'] ?? '', $v['choices'] ?? []))) ?>
            </p>
        <?php endforeach; ?>
        <p class="card-meta">L'édition détaillée des variantes arrive dans une phase ultérieure.</p>
    </section>
<?php endif; ?>

<style>
    .checkin-badge { display: inline-block; padding: 0.2rem 0.6rem; border-radius: 999px; font-size: 0.8rem; font-weight: 700; }
    .checkin-badge.is-present { background: rgba(34,197,94,0.18); color: #4ade80; }
    .checkin-badge.is-absent  { background: rgba(148,163,184,0.18); color: #94a3b8; }
</style>

<script>
    (function () {
        document.querySelectorAll('.toggle-checkin').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var row = btn.closest('tr');
                var badge = row.querySelector('.checkin-badge');
                fetch(btn.dataset.url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': "<?= e(csrf_token()) ?>"
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ user_id: btn.dataset.user })
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.ok) { return; }
                    if (data.checked_in) {
                        badge.className = 'checkin-badge is-present';
                        badge.textContent = '✅ Présent';
                        btn.textContent = 'Marquer absent';
                    } else {
                        badge.className = 'checkin-badge is-absent';
                        badge.textContent = '⬜ Absent';
                        btn.textContent = 'Marquer présent';
                    }
                });
            });
        });
    })();
</script>
