<?php

declare(strict_types=1);

/**
 * Layout espace administrateur AEIC.
 *
 * Sidebar + zone de contenu. Toutes les pages admin sont en noindex.
 *
 * @var string $content
 * @var string $title
 * @var string $description
 */

use App\Core\Auth;
use App\Models\Setting;

$siteName    = Setting::get('site_name', 'AEIC');
$currentPath = $_SERVER['REQUEST_URI'] ?? '/';
$user        = Auth::user();
$lang        = current_lang();

$sections = [
    'Tableau de bord' => [
        'Tableau de bord' => '/admin',
    ],
    'Contenu' => [
        'Événements'   => '/admin/events',
        'Pages'        => '/admin/pages',
        'Équipe'       => '/admin/team',
        'Sondages'     => '/admin/sondages',
        'Promotions'   => '/admin/promotions',
        'Médias'       => '/admin/media',
    ],
    'Cafétéria' => [
        'Produits'    => '/admin/cafeteria',
        'Catégories'  => '/admin/cafeteria/categories',
    ],
    'Comptabilité' => [
        'Dashboard'      => '/admin/compta',
        'Importer CSV'   => '/admin/compta/import',
        'Journal ventes' => '/admin/compta/ventes',
        'Produits'       => '/admin/compta/produits',
        'Catégories'     => '/admin/compta/categories',
        'Coûts de revient' => '/admin/compta/couts',
        'Mapping libellés' => '/admin/compta/aliases',
        'Réappro'        => '/admin/compta/reappro',
        'Analytics'      => '/admin/analytics',
    ],
    'SumUp' => [
        'Dashboard SumUp' => '/admin/sumup',
    ],
    'Système' => [
        'Utilisateurs' => '/admin/users',
        'Paramètres'  => '/admin/settings',
        'Wiki'        => '/admin/wiki',
    ],
];

// Le rôle TRESORERIE n'a accès qu'à la comptabilité et au dashboard SumUp.
if (($user['role'] ?? null) === 'TRESORERIE') {
    $sections = [
        'Comptabilité' => $sections['Comptabilité'],
        'SumUp' => $sections['SumUp'],
    ];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Admin') ?> — <?= e($siteName) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(assetVersioned('css/base.css')) ?>">
    <link rel="stylesheet" href="<?= e(rootAssetVersioned('/css/admin.css')) ?>">
    <link rel="stylesheet" href="<?= e(rootAssetVersioned('/css/payments.css')) ?>">
    <?php if (($loadComptaCss ?? false) || str_contains($currentPath ?? '', '/compta')): ?>
        <link rel="stylesheet" href="<?= e(rootAssetVersioned('/css/compta.css')) ?>">
    <?php endif; ?>
</head>
<body class="admin-body">
    <a class="skip-link" href="#contenu">Aller au contenu</a>

    <div class="admin-shell">
        <aside class="admin-sidebar">
            <a class="brand" href="<?= e(url('/admin')) ?>">
                <span class="brand-logo" aria-hidden="true">AE</span>
                <span class="brand-name">Admin <?= e($siteName) ?></span>
            </a>

            <?php foreach ($sections as $group => $links): ?>
                <p class="admin-group"><?= e($group) ?></p>
                <nav aria-label="<?= e($group) ?>">
                    <?php foreach ($links as $label => $path): ?>
                        <?php
                        // État actif : match exact OU sous-route directe.
                        // Évite que /admin/compta reste allumé sur /admin/compta/produits.
                        $active = false;
                        if ($currentPath === $path) {
                            $active = true;
                        } elseif (str_starts_with($currentPath, $path . '/')) {
                            // Sous-route : on ne marque actif que si c'est le parent le plus précis.
                            // On vérifie qu'aucun autre lien plus long ne matche mieux.
                            $betterMatch = false;
                            foreach ($sections as $g2 => $links2) {
                                foreach ($links2 as $l2 => $p2) {
                                    if ($p2 !== $path && strlen($p2) > strlen($path) && str_starts_with($currentPath, $p2)) {
                                        $betterMatch = true;
                                        break 2;
                                    }
                                }
                            }
                            $active = !$betterMatch;
                        }
                        // Cas spécial : /admin (tableau de bord) n'est actif que sur /admin exact.
                        if ($path === '/admin' && $currentPath !== '/admin') {
                            $active = false;
                        }
                        ?>
                        <a class="admin-link<?= $active ? ' is-active' : '' ?>" href="<?= e(url($path)) ?>"><?= e($label) ?></a>
                    <?php endforeach; ?>
                </nav>
            <?php endforeach; ?>

            <div class="admin-sidebar-foot">
                <form method="post" action="<?= e(url('/set-lang')) ?>" class="lang-switch lang-switch-admin" aria-label="Language">
                    <?= csrf_field() ?>
                    <input type="hidden" name="back" value="<?= e($currentPath) ?>">
                    <button type="submit" name="lang" value="fr" class="lang-btn<?= $lang === 'fr' ? ' is-active' : '' ?>" title="Français">🇫🇷</button>
                    <button type="submit" name="lang" value="en" class="lang-btn<?= $lang === 'en' ? ' is-active' : '' ?>" title="English">🇬🇧</button>
                </form>
                <a class="admin-link" href="<?= e(url('/')) ?>" target="_blank">Voir le site →</a>
                <a class="admin-link" href="<?= e(url('/logout')) ?>">Déconnexion (<?= e($user['prenom'] ?? '') ?>)</a>
            </div>
        </aside>

        <main id="contenu" class="admin-main">
            <?php require AEIC_VIEWS . '/partials/flash_messages.php'; ?>
            <header class="admin-topbar">
                <button class="admin-toggle" type="button" aria-label="Menu" aria-expanded="false" aria-controls="admin-sidebar"></button>
                <h1 class="admin-title"><?= e($title ?? 'Administration') ?></h1>
            </header>
            <div class="admin-content">
                <?= $content ?>
            </div>
        </main>
    </div>

    <script>
        (function () {
            var btn = document.querySelector('.admin-toggle');
            var side = document.querySelector('.admin-sidebar');
            if (!btn || !side) return;
            btn.addEventListener('click', function () {
                var open = side.classList.toggle('is-open');
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        })();
    </script>
    <script src="<?= e(rootAssetVersioned('/assets/js/confirm.js')) ?>"></script>
</body>
</html>
