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

$sections = [
    'Tableau de bord' => [
        'Tableau de bord' => '/admin',
    ],
    'Contenu' => [
        'Événements'   => '/admin/events',
        'Pages'        => '/admin/pages',
        'Équipe'       => '/admin/team',
        'Médias'       => '/admin/media',
    ],
    'Cafétéria' => [
        'Produits'     => '/admin/cafeteria',
        'Catégories'   => '/admin/cafeteria/categories',
        'Commandes'    => '/admin/cafeteria/commandes',
        'Caisse (POS)' => '/admin/cafeteria/pos',
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
    ],
    'Système' => [
        'Utilisateurs' => '/admin/users',
        'Paramètres'   => '/admin/settings',
    ],
];

// Le rôle TRESORERIE n'a accès qu'à la comptabilité : on masque le reste.
if (($user['role'] ?? null) === 'TRESORERIE') {
    $sections = ['Comptabilité' => $sections['Comptabilité']];
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
    <link rel="stylesheet" href="<?= e(asset('css/base.css')) ?>">
    <link rel="stylesheet" href="<?= e(APP_URL . '/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= e(APP_URL . '/css/compta.css') ?>">
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
                        <?php $active = str_starts_with($currentPath, $path); ?>
                        <a class="admin-link<?= $active ? ' is-active' : '' ?>" href="<?= e(url($path)) ?>"><?= e($label) ?></a>
                    <?php endforeach; ?>
                </nav>
            <?php endforeach; ?>

            <div class="admin-sidebar-foot">
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
</body>
</html>
