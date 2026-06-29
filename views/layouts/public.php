<?php

declare(strict_types=1);

/**
 * Layout public AEIC.
 *
 * @var string $content HTML rendu par la vue.
 * @var string $title
 * @var string $description
 */

use App\Core\Auth;
use App\Models\Setting;

$siteName     = Setting::get('site_name', 'AEIC');
$siteDesc     = Setting::get('site_description', 'Association Étudiante Informatique de Calais.');
$currentYear  = date('Y');
$currentPath  = $_SERVER['REQUEST_URI'] ?? '/';
$canonical    = APP_URL . strtok($currentPath, '?');
$user         = Auth::check() ? Auth::user() : null;

// SEO : titres et descriptions par défaut + Open Graph.
$pageTitle    = $title ?? $siteName;
$pageDesc     = $description ?? $siteDesc;
$ogImage      = !empty($ogImage)
    ? (is_absolute_url($ogImage) ? $ogImage : APP_URL . '/' . ltrim($ogImage, '/'))
    : (!empty(Setting::get('og_image'))
        ? (is_absolute_url(Setting::get('og_image')) ? Setting::get('og_image') : APP_URL . '/' . ltrim(Setting::get('og_image'), '/'))
        : APP_URL . asset('img/og-default.svg'));
$twitterHandle = Setting::get('twitter_handle', '');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#08172d">
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="<?= e($pageDesc) ?>">
    <link rel="canonical" href="<?= e($canonical) ?>">

    <!-- Open Graph -->
    <meta property="og:type" content="<?= e($ogType ?? 'website') ?>">
    <meta property="og:title" content="<?= e($pageTitle) ?>">
    <meta property="og:description" content="<?= e($pageDesc) ?>">
    <meta property="og:url" content="<?= e($canonical) ?>">
    <meta property="og:site_name" content="<?= e($siteName) ?>">
    <meta property="og:image" content="<?= e($ogImage) ?>">
    <meta property="og:locale" content="fr_FR">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= e($pageTitle) ?>">
    <meta name="twitter:description" content="<?= e($pageDesc) ?>">
    <meta name="twitter:image" content="<?= e($ogImage) ?>">
    <?php if ($twitterHandle !== ''): ?>
        <meta name="twitter:site" content="<?= e($twitterHandle) ?>">
    <?php endif; ?>

    <?php if (!empty($jsonLd)): ?>
        <script type="application/ld+json"><?= $jsonLd ?></script>
    <?php endif; ?>

    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/base.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/pages.css')) ?>">
    <link rel="stylesheet" href="<?= e(rootAssetVersioned('/css/payments.css')) ?>">
</head>
<body>
    <a class="skip-link" href="#contenu">Aller au contenu</a>

    <header class="site-header">
        <div class="container nav-bar">
            <a class="brand" href="<?= e(url('/')) ?>" aria-label="<?= e($siteName) ?> — accueil">
                <span class="brand-logo" aria-hidden="true">AE</span>
                <span class="brand-text">
                    <span class="brand-sub">Étudiants · Calais</span>
                    <span class="brand-name"><?= e($siteName) ?></span>
                </span>
            </a>

            <nav class="main-nav" aria-label="Navigation principale">
                <a class="nav-link<?= $currentPath === '/' ? ' is-active' : '' ?>" href="<?= e(url('/')) ?>">Accueil</a>
                <a class="nav-link<?= str_starts_with($currentPath, '/events') ? ' is-active' : '' ?>" href="<?= e(url('/events')) ?>">Événements</a>
                <a class="nav-link<?= $currentPath === '/presentation' ? ' is-active' : '' ?>" href="<?= e(url('/presentation')) ?>">L'association</a>
                <a class="nav-link<?= $currentPath === '/team' ? ' is-active' : '' ?>" href="<?= e(url('/team')) ?>">Équipe</a>
            </nav>

            <div class="nav-actions">
                <?php if ($user !== null): ?>
                    <?php if (($user['role'] ?? '') === 'ADMIN' || ($user['role'] ?? '') === 'TRESORERIE'): ?>
                        <a class="btn btn-primary btn-sm" href="<?= e(url('/admin')) ?>">Admin</a>
                    <?php endif; ?>
                    <a class="btn btn-outline btn-sm" href="<?= e(url('/account/privacy')) ?>"><?= e($user['prenom'] ?? 'Mon compte') ?></a>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('/logout')) ?>">Déconnexion</a>
                <?php else: ?>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('/login')) ?>">Connexion</a>
                    <a class="btn btn-primary btn-sm" href="<?= e(url('/register')) ?>">S'inscrire</a>
                <?php endif; ?>
            </div>

            <button class="nav-toggle" type="button" aria-label="Ouvrir le menu"
                    aria-expanded="false" aria-controls="mobile-nav">
                <span></span><span></span><span></span>
            </button>
        </div>

        <nav id="mobile-nav" class="mobile-nav" aria-label="Navigation mobile">
            <a href="<?= e(url('/')) ?>">Accueil</a>
            <a href="<?= e(url('/events')) ?>">Événements</a>
            <a href="<?= e(url('/presentation')) ?>">L'association</a>
            <a href="<?= e(url('/team')) ?>">Équipe</a>
            <hr>
            <?php if ($user !== null): ?>
                <?php if (($user['role'] ?? '') === 'ADMIN' || ($user['role'] ?? '') === 'TRESORERIE'): ?>
                    <a class="btn btn-primary" href="<?= e(url('/admin')) ?>">Admin</a>
                <?php endif; ?>
                <a class="btn btn-outline" href="<?= e(url('/account/privacy')) ?>">Mes données</a>
                <a class="btn btn-ghost" href="<?= e(url('/logout')) ?>">Déconnexion</a>
            <?php else: ?>
                <a class="btn btn-ghost" href="<?= e(url('/login')) ?>">Connexion</a>
                <a class="btn btn-primary" href="<?= e(url('/register')) ?>">S'inscrire</a>
            <?php endif; ?>
        </nav>
    </header>

    <main id="contenu">
        <?php require AEIC_VIEWS . '/partials/flash_messages.php'; ?>
        <?= $content ?>
    </main>

    <footer class="site-footer">
        <div class="container footer-grid">
            <div>
                <p class="footer-name"><?= e($siteName) ?></p>
                <p class="footer-sub">Association Étudiante Informatique de Calais</p>
                <p class="footer-tag">100 % étudiant.</p>
            </div>
            <nav class="footer-links" aria-label="Pied de page">
                <a href="<?= e(url('/events')) ?>">Événements</a>
                <a href="<?= e(url('/presentation')) ?>">Association</a>
                <a href="<?= e(url('/team')) ?>">Équipe</a>
                <a href="<?= e(url('/legal')) ?>">Mentions légales</a>
                <a href="<?= e(url('/privacy')) ?>">Confidentialité</a>
                <a href="<?= e(url('/cgu')) ?>">CGU</a>
            </nav>
            <p class="footer-copy">© <?= e($currentYear) ?> <?= e($siteName) ?> · Fait par les étudiants, pour les étudiants.</p>
        </div>
    </footer>

    <script>
        // Menu mobile minimal (vanilla).
        (function () {
            var btn = document.querySelector('.nav-toggle');
            var nav = document.getElementById('mobile-nav');
            if (!btn || !nav) return;
            btn.addEventListener('click', function () {
                var open = nav.classList.toggle('is-open');
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        })();
    </script>
</body>
</html>
