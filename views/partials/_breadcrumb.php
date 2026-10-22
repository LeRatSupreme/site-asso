<?php

declare(strict_types=1);

/**
 * Fil d'Ariane de l'espace administrateur.
 *
 * Calculé dans le layout admin à partir de l'arbre de menu (section >
 * [sous-groupe >] entrée). Le dernier élément porte aria-current="page" ;
 * seul « Administration » pointe vers une page (les sections et
 * sous-groupes ne sont pas des pages). Sous /admin racine, l'élément
 * unique « Administration » est affiché sans lien.
 *
 * @var array<int, array{label: string, url: ?string}> $crumbItems
 */
?>
<nav class="breadcrumb" aria-label="Fil d'Ariane">
    <ol>
        <?php $crumbLast = count($crumbItems) - 1; ?>
        <?php foreach ($crumbItems as $crumbIndex => $crumbItem): ?>
            <li<?php if ($crumbIndex === $crumbLast): ?> aria-current="page"<?php endif; ?>>
                <?php if ($crumbItem['url'] !== null): ?>
                    <a href="<?= e($crumbItem['url']) ?>"><?= e($crumbItem['label']) ?></a>
                <?php else: ?>
                    <?= e($crumbItem['label']) ?>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</nav>
