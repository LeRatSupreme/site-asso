<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $event
 * @var int $count
 * @var list<array<string,mixed>> $regs
 * @var list<array<string,mixed>> $variants
 */
?>
<header class="admin-section-head">
    <p><a href="<?= e(url('/admin/events')) ?>">← Événements</a></p>
    <h2 class="card-title">Inscriptions — <?= e($event['title'] ?? '') ?></h2>
    <p class="card-meta"><?= e((string) $count) ?> inscrit(s)</p>
</header>

<div class="card surface glass table-wrap">
    <table class="table">
        <thead><tr><th>Prénom</th><th>Nom</th></tr></thead>
        <tbody>
            <?php if (empty($regs)): ?>
                <tr><td colspan="2" class="card-meta">Aucun inscrit.</td></tr>
            <?php else: ?>
                <?php foreach ($regs as $r): ?>
                    <tr>
                        <td><?= e($r['prenom'] ?? '') ?></td>
                        <td><?= e($r['nom'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

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
