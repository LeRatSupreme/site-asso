<?php

declare(strict_types=1);

/**
 * Avatar rond réutilisable : photo si présente, sinon initiales stylées.
 *
 * Point de rendu UNIQUE partagé entre la page publique « Équipe »
 * (views/pages/team.php via _team_card.php) et la liste admin des
 * membres (views/admin/users/index.php) — la logique « photo sinon
 * initiale » ne doit pas être dupliquée entre les deux contextes.
 *
 * Le partial ne génère PAS le lien (<a>) : c'est le contexte appelant
 * qui décide (carte team : aucun ; liste admin : fiche publique /team#…).
 *
 * Variables attendues avant le require :
 * @var string $avatarName  nom complet (alt de l'image, initiale de secours)
 * @var string $avatarPhoto chemin d'asset (« img/… ») ou URL absolue ('' = pas de photo)
 */

$avatarName  = trim((string) ($avatarName ?? ''));
$avatarPhoto = trim((string) ($avatarPhoto ?? ''));
?>
<div class="team-avatar">
    <?php if ($avatarPhoto !== ''): ?>
        <img src="<?= e(is_absolute_url($avatarPhoto) ? $avatarPhoto : asset('img/' . ltrim($avatarPhoto, '/'))) ?>"
             alt="<?= e($avatarName) ?>"
             loading="lazy" width="96" height="96">
    <?php else: ?>
        <span class="team-initial" aria-hidden="true">
            <?= e(initial($avatarName)) ?>
        </span>
    <?php endif; ?>
</div>
