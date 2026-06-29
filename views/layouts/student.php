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
    '/eleve'                 => 'Tableau de bord',
    '/eleve/inscriptions'    => 'Mes inscriptions',
    '/eleve/profile'         => 'Mon profil',
    '/account/privacy'       => 'Mes données (RGPD)',
];
?>
<!DOCTYPE html>
<html lang="fr">
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
                <a class="nav-link" href="<?= e(url('/')) ?>">Accueil</a>
                <a class="nav-link" href="<?= e(url('/events')) ?>">Événements</a>
                <a class="nav-link" href="<?= e(url('/presentation')) ?>">L'association</a>
                <a class="nav-link" href="<?= e(url('/team')) ?>">Équipe</a>
            </nav>

            <div class="nav-actions">
                <?php if ($user !== null): ?>
                    <a class="btn btn-outline btn-sm" href="<?= e(url('/eleve')) ?>"><?= e($user['prenom'] ?? 'Mon compte') ?></a>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('/logout')) ?>">Déconnexion</a>
                <?php else: ?>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('/login')) ?>">Connexion</a>
                    <a class="btn btn-primary btn-sm" href="<?= e(url('/register')) ?>">S'inscrire</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main id="contenu">
        <?php require AEIC_VIEWS . '/partials/flash_messages.php'; ?>
        <div class="container dashboard">
            <aside class="dash-sidebar">
                <p class="dash-hello"><?= e($user['prenom'] ?? '') ?></p>
                <nav aria-label="Espace membre">
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
                <p class="footer-sub">Association Étudiante Informatique de Calais</p>
                <p class="footer-tag">100 % étudiant.</p>
            </div>
            <nav class="footer-links" aria-label="Pied de page">
                <a href="<?= e(url('/events')) ?>">Événements</a>
                <a href="<?= e(url('/presentation')) ?>">Association</a>
                <a href="<?= e(url('/legal')) ?>">Mentions légales</a>
                <a href="<?= e(url('/privacy')) ?>">Confidentialité</a>
            </nav>
            <p class="footer-copy">© <?= e($currentYear) ?> <?= e($siteName) ?> · Fait par les étudiants, pour les étudiants.</p>
        </div>
    </footer>
</body>
</html>
