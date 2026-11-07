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
// Ancre individuelle (id team_members) : cible des liens vers la fiche
// publique du membre (ex. liste admin /admin/users → /team#tm_…).
?>
<article id="<?= e((string) ($member['id'] ?? '')) ?>" class="team-card card surface glass card-hover">
    <?php
    // Avatar (photo sinon initiales) : rendu mutualisé dans le partial
    // _avatar.php avec la liste admin des membres.
    $avatarName  = $fullName;
    $avatarPhoto = $photo;
    require AEIC_VIEWS . '/partials/_avatar.php';
    ?>
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
