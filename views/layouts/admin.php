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
use App\Core\Permissions;
use App\Models\Setting;

$siteName    = Setting::get('site_name', 'AEIC');
$currentPath = $_SERVER['REQUEST_URI'] ?? '/';
$user        = Auth::user();
$lang        = current_lang();

$sections = [
    'Tableau de bord' => [
        'Tableau de bord' => '/admin',
        'Wiki'        => '/admin/wiki',
    ],
    'Comptage' => [
        'Comptage caisse'     => '/admin/compta/caisse',
        'Comptage inventaire' => '/admin/compta/inventaire/comptage',
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
    'Jeux' => [
        'Vue d\'ensemble' => '/admin/jeux',
        'Joueurs & Pseudos' => '/admin/jeux/scores',
        'Mots Wordle'   => '/admin/jeux/wordle',
        'Énigmes'       => '/admin/jeux/enigmes',
    ],
    'Comptabilité' => [
        'Dashboard'       => '/admin/compta',
        'Bilan annuel'    => '/admin/compta/annuel',
        'Analytics'       => '/admin/analytics',
    ],
    'Ventes' => [
        'Importer CSV'    => '/admin/compta/import',
        'Journal ventes'  => '/admin/compta/ventes',
        'Dashboard SumUp' => '/admin/sumup',
    ],
    'Produits & coûts' => [
        'Produits'         => '/admin/compta/produits',
        'Catégories'       => '/admin/compta/categories',
        'Mapping libellés' => '/admin/compta/aliases',
    ],
    'Trésorerie' => [
        'Dépenses'   => '/admin/compta/depenses',
        'Budgets'    => '/admin/compta/budgets',
        'Événements' => '/admin/compta/evenements',
    ],
    'Stock' => [
        'Achats & stock' => '/admin/compta/achats',
        'Pertes'         => '/admin/compta/pertes',
        'Réappro'        => '/admin/compta/reappro',
    ],
];

// Filtre les groupes de menu selon les modules autorisés au rôle :
// un rôle ne doit jamais voir un lien vers une page qui lui est interdite.
$sectionModules = [
    'Cafétéria'        => Permissions::MODULE_CAFETERIA,
    'Jeux'             => Permissions::MODULE_GAMES,
    'Comptabilité'     => Permissions::MODULE_COMPTA,
    'Ventes'           => Permissions::MODULE_COMPTA,
    'Produits & coûts' => Permissions::MODULE_COMPTA,
    'Trésorerie'       => Permissions::MODULE_COMPTA,
    'Stock'            => Permissions::MODULE_COMPTA,
];
$viewerRole = (string) ($user['role'] ?? '');
foreach ($sectionModules as $group => $module) {
    if (!Permissions::allows($viewerRole, $module)) {
        unset($sections[$group]);
    }
}

// Groupe « Contenu » : filtrage PAR LIEN (les pages appartiennent à des
// modules distincts : Événements = events, le reste = content).
$contentLinkModules = [
    'Événements' => Permissions::MODULE_EVENTS,
    'Pages'      => Permissions::MODULE_CONTENT,
    'Équipe'     => Permissions::MODULE_CONTENT,
    'Sondages'   => Permissions::MODULE_CONTENT,
    'Promotions' => Permissions::MODULE_CONTENT,
    'Médias'     => Permissions::MODULE_CONTENT,
];
foreach ($contentLinkModules as $label => $module) {
    if (isset($sections['Contenu'][$label]) && !Permissions::allows($viewerRole, $module)) {
        unset($sections['Contenu'][$label]);
    }
}
if (isset($sections['Contenu']) && $sections['Contenu'] === []) {
    unset($sections['Contenu']);
}

// Groupe « Système » : réservé au Fondateur et aux ADMIN listés dans
// SYSTEM_ADMINS, plus les pages attribuées individuellement à un membre
// du bureau (users.extra_pages, gérées depuis Utilisateurs, voir
// AdminBaseController::guardSystemOrPage()). Chaque entrée est associée
// à sa clé de page (null n'existe plus : tout est attribuable).
$systemEntries = [
    'Utilisateurs'     => ['/admin/users', 'users'],
    'Caisses'          => ['/admin/caisses', 'cash'],
    'Inventaire'       => ['/admin/compta/inventaire', 'inventory'],
    'Coûts de revient' => ['/admin/compta/couts', 'costs'],
    'Notifications'    => ['/admin/notifications', 'notifications'],
    'Paramètres'       => ['/admin/settings', 'settings'],
];
if (in_array($user['role'] ?? null, Permissions::adminRoles(), true)) {
    $system = [];
    foreach ($systemEntries as $label => [$path, $key]) {
        // Système OU attribution individuelle (users.extra_pages).
        $allowed = Permissions::isSystemAdmin() || Permissions::userHasExtraPage($key);

        if ($allowed) {
            $system[$label] = $path;
        }
    }
    if ($system !== []) {
        $sections['Système'] = $system;
    }
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
    <div class="starfield" id="starfield" aria-hidden="true"></div>
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
                    <button type="submit" name="lang" value="fr" class="lang-btn<?= $lang === 'fr' ? ' is-active' : '' ?>" title="Français">FR</button>
                    <button type="submit" name="lang" value="en" class="lang-btn<?= $lang === 'en' ? ' is-active' : '' ?>" title="English">EN</button>
                </form>
                <a class="admin-link" href="<?= e(url('/')) ?>" target="_blank">Voir le site →</a>
                <a class="admin-link" href="<?= e(url('/logout') . '?t=' . csrf_token()) ?>">Déconnexion (<?= e($user['prenom'] ?? '') ?>)</a>
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

            // Sauvegarde et restaure la position du scroll de la sidebar.
            var SS_KEY = 'aeic_admin_sidebar_scroll';
            try { side.scrollTop = parseInt(sessionStorage.getItem(SS_KEY) || '0', 10) || 0; } catch (e) {}
            window.addEventListener('beforeunload', function () {
                try { sessionStorage.setItem(SS_KEY, String(side.scrollTop)); } catch (e) {}
            });
        })();
    </script>
    <script src="<?= e(rootAssetVersioned('/assets/js/confirm.js')) ?>"></script>
    <script src="<?= e(rootAssetVersioned('/assets/js/stars.js')) ?>" defer></script>
</body>
</html>
