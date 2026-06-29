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
$currentYear  = date('Y');
$currentPath  = $_SERVER['REQUEST_URI'] ?? '/';
$canonical    = APP_URL . strtok($currentPath, '?');
$user         = Auth::check() ? Auth::user() : null;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? $siteName) ?></title>
    <meta name="description" content="<?= e($description ?? '') ?>">
    <link rel="canonical" href="<?= e($canonical) ?>">

    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= e($title ?? $siteName) ?>">
    <meta property="og:description" content="<?= e($description ?? '') ?>">
    <meta property="og:url" content="<?= e($canonical) ?>">
    <meta property="og:site_name" content="<?= e($siteName) ?>">

    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/base.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/pages.css')) ?>">
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
                    <a class="btn btn-outline btn-sm" href="<?= e(url('/')) ?>"><?= e($user['prenom'] ?? 'Mon compte') ?></a>
                <?php else: ?>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('/')) ?>">Connexion</a>
                    <a class="btn btn-primary btn-sm" href="<?= e(url('/')) ?>">S'inscrire</a>
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
            <a class="btn btn-primary" href="<?= e(url('/')) ?>">S'inscrire</a>
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
