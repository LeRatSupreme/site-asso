<?php

declare(strict_types=1);

/**
 * Layout espace élève AEIC.
 *
 * Reprend l'en-tête / pied de page public et ajoute une barre latérale
 * de navigation membre autour du contenu.
 *
 * @var string $content HTML rendu par la vue.
 * @var string $title
 * @var string $description
 */

use App\Core\Auth;
use App\Models\Setting;

$siteName     = Setting::get('site_name', 'AEIC');
$currentYear  = date('Y');
$currentPath  = $_SERVER['REQUEST_URI'] ?? '/';
$canonical    = APP_URL . strtok($currentPath, '?');
$user         = Auth::check() ? Auth::user() : null;

$nav = [
    '/eleve'                 => t('dash.menu.dashboard'),
    '/eleve/inscriptions'    => t('dash.menu.inscriptions'),
    '/eleve/profile'         => t('dash.menu.profile'),
    '/account/privacy'       => t('dash.menu.data'),
];
?>
<!DOCTYPE html>
<html lang="<?= e(current_lang()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? $siteName) ?></title>
    <meta name="description" content="<?= e($description ?? '') ?>">
    <link rel="canonical" href="<?= e($canonical) ?>">
    <meta name="robots" content="noindex, follow">

    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/base.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/pages.css')) ?>">
    <link rel="stylesheet" href="<?= e(rootAssetVersioned('/css/payments.css')) ?>">
</head>
<body>
    <a class="skip-link" href="#contenu"><?= e(t('nav.skip')) ?></a>

    <header class="site-header">
        <div class="container nav-bar">
            <a class="brand" href="<?= e(url('/')) ?>" aria-label="<?= e(tt('brand.aria.home', ['{name}' => $siteName])) ?>">
                <span class="brand-logo" aria-hidden="true">AE</span>
                <span class="brand-text">
                    <span class="brand-sub"><?= e(t('brand.sub')) ?></span>
                    <span class="brand-name"><?= e($siteName) ?></span>
                </span>
            </a>

            <nav class="main-nav" aria-label="<?= e(t('nav.main.aria')) ?>">
                <a class="nav-link" href="<?= e(url('/')) ?>"><?= e(t('nav.home')) ?></a>
                <a class="nav-link" href="<?= e(url('/events')) ?>"><?= e(t('nav.events')) ?></a>
                <a class="nav-link" href="<?= e(url('/presentation')) ?>"><?= e(t('nav.about')) ?></a>
                <a class="nav-link" href="<?= e(url('/team')) ?>"><?= e(t('nav.team')) ?></a>
            </nav>

            <div class="nav-actions">
                <?php if ($user !== null): ?>
                    <a class="btn btn-outline btn-sm" href="<?= e(url('/eleve')) ?>"><?= e($user['prenom'] ?? t('nav.account')) ?></a>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('/logout')) ?>"><?= e(t('nav.logout')) ?></a>
                <?php else: ?>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('/login')) ?>"><?= e(t('nav.login')) ?></a>
                    <a class="btn btn-primary btn-sm" href="<?= e(url('/register')) ?>"><?= e(t('nav.register')) ?></a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main id="contenu">
        <?php require AEIC_VIEWS . '/partials/flash_messages.php'; ?>
        <div class="container dashboard">
            <aside class="dash-sidebar">
                <p class="dash-hello"><?= e($user['prenom'] ?? '') ?></p>
                <nav aria-label="<?= e(t('dash.eyebrow.member')) ?>">
                    <?php foreach ($nav as $path => $label): ?>
                        <?php $active = $path === '/eleve' ? $currentPath === $path : str_starts_with($currentPath, $path); ?>
                        <a class="dash-link<?= $active ? ' is-active' : '' ?>" href="<?= e(url($path)) ?>"><?= e($label) ?></a>
                    <?php endforeach; ?>
                </nav>
            </aside>

            <div class="dash-content">
                <?= $content ?>
            </div>
        </div>
    </main>

    <footer class="site-footer">
        <div class="container footer-grid">
            <div>
                <p class="footer-name"><?= e($siteName) ?></p>
                <p class="footer-sub"><?= e(t('footer.association')) ?></p>
                <p class="footer-tag"><?= e(t('footer.tag')) ?></p>
            </div>
            <nav class="footer-links" aria-label="<?= e(t('footer.aria')) ?>">
                <a href="<?= e(url('/events')) ?>"><?= e(t('nav.events')) ?></a>
                <a href="<?= e(url('/presentation')) ?>"><?= e(t('nav.about')) ?></a>
                <a href="<?= e(url('/legal')) ?>"><?= e(t('footer.legal')) ?></a>
                <a href="<?= e(url('/privacy')) ?>"><?= e(t('footer.privacy')) ?></a>
            </nav>
            <p class="footer-copy">© <?= e($currentYear) ?> <?= e($siteName) ?> · <?= e(t('footer.copy')) ?></p>
        </div>
    </footer>
</body>
</html>
