<?php

declare(strict_types=1);

/**
 * Carte d'un membre du bureau (partial).
 *
 * @var array<string,mixed> $member
 */

/** @var array<string,mixed>|null $member */
$member = $member ?? null;
if (!is_array($member)) {
    return;
}

$fullName = trim(($member['prenom'] ?? '') . ' ' . ($member['nom'] ?? ''));
$role     = tc((string) ($member['role'] ?? ''));
$pole     = tc((string) ($member['pole'] ?? ''));
$bio      = tc((string) ($member['bio'] ?? ''));
$photo    = (string) ($member['photo'] ?? '');
?>
<article class="team-card card surface glass card-hover">
    <div class="team-avatar">
        <?php if ($photo !== ''): ?>
            <img src="<?= e(is_absolute_url($photo) ? $photo : asset('img/' . ltrim($photo, '/'))) ?>"
                 alt="<?= e($fullName) ?>"
                 loading="lazy" width="96" height="96">
        <?php else: ?>
            <span class="team-initial aeic-gradient" aria-hidden="true">
                <?= e(initial($fullName)) ?>
            </span>
        <?php endif; ?>
    </div>
    <h3 class="card-title"><?= e($fullName) ?></h3>
    <?php if ($role !== ''): ?>
        <p class="team-role"><?= e($role) ?></p>
    <?php endif; ?>
    <?php if ($pole !== ''): ?>
        <p class="team-pole"><span class="badge badge-secondary"><?= e($pole) ?></span></p>
    <?php endif; ?>
    <?php if ($bio !== ''): ?>
        <p class="team-bio"><?= e($bio) ?></p>
    <?php endif; ?>
</article>
