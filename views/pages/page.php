<?php

declare(strict_types=1);

/**
 * Page CMS générique (/p/{slug}).
 *
 * @var array<string,mixed>|null $page
 */
?>
<?php if ($page === null || empty($page['content'])): ?>
<header class="page-hero">
    <div class="halo halo-violet" aria-hidden="true"></div>
    <div class="container">
        <span class="eyebrow"><?= e(t('page.generic.eyebrow')) ?></span>
        <h1 class="page-title"><?= e($page['title'] ?? t('page.generic.eyebrow')) ?></h1>
    </div>
</header>
<section class="section">
    <div class="container narrow">
        <div class="empty-state surface glass">
            <p><?= e(t('page.placeholder')) ?></p>
        </div>
    </div>
</section>
<?php else: ?>
<header class="page-hero">
    <div class="halo halo-violet" aria-hidden="true"></div>
    <div class="container">
        <span class="eyebrow"><?= e(t('page.generic.eyebrow')) ?></span>
        <h1 class="page-title"><?= e($page['title'] ?? t('page.generic.eyebrow')) ?></h1>
    </div>
</header>
<section class="section">
    <div class="container narrow">
        <div class="prose surface glass"><?= $page['content'] ?></div>
    </div>
</section>
<?php endif; ?>
