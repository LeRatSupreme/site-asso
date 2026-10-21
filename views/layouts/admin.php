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
// Chemin SANS query string, pour le matching actif de la sidebar :
// « /admin/compta/ventes?period=... » doit allumer « Journal ventes ».
$currentUrlPath = (string) (parse_url($currentPath, PHP_URL_PATH) ?: '/');
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
        'Commandes'   => '/admin/cafeteria/commandes',
    ],
    'Jeux' => [
        'Vue d\'ensemble' => '/admin/jeux',
        'Joueurs & Pseudos' => '/admin/jeux/scores',
        'Mots Wordle'   => '/admin/jeux/wordle',
        'Énigmes'       => '/admin/jeux/enigmes',
    ],
    // Convention : la valeur d'un groupe est SOIT un tableau à plat
    // « libellé => URL », SOIT un tableau imbriqué « sous-groupe =>
    // (libellé => URL) » rendu avec des intertitres .admin-subgroup.
    'Comptabilité' => [
        'Général' => [
            'Dashboard'    => '/admin/compta',
            'Opérations'   => '/admin/compta/achats',
            'Bilan annuel' => '/admin/compta/annuel',
        ],
        'Analyse' => [
            'Analytics' => '/admin/analytics',
            'Idées appliquées (reco)' => '/admin/reco/historique',
            'Budgets'   => '/admin/compta/budgets',
        ],
        'Stock' => [
            'Réappro'          => '/admin/compta/reappro',
            'Liste de courses' => '/admin/compta/liste',
        ],
        'Ventes' => [
            'Importer CSV'    => '/admin/compta/import',
            'Journal ventes'  => '/admin/compta/ventes',
            'Dashboard SumUp' => '/admin/sumup',
        ],
        'Produits & coûts' => [
            'Produits'                => '/admin/compta/produits',
            'Catégories'              => '/admin/compta/categories',
            'Ajouter une appellation' => '/admin/compta/aliases/new',
            'Mapping libellés'        => '/admin/compta/aliases',
        ],
    ],
];

// Filtre les groupes de menu selon les modules autorisés au rôle :
// un rôle ne doit jamais voir un lien vers une page qui lui est interdite.
$sectionModules = [
    'Cafétéria'    => Permissions::MODULE_CAFETERIA,
    'Jeux'         => Permissions::MODULE_GAMES,
    'Comptabilité' => Permissions::MODULE_COMPTA,
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
    'Adhésions'        => ['/admin/memberships', 'users'],
    'Caisses'          => ['/admin/caisses', 'cash'],
    'Kiosques'         => ['/admin/kiosques', 'kiosques'],
    'Livre comptable'  => ['/admin/ledger', 'ledger'],
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
// Pages fusionnées « Opérations » : les quatre URL de sections (et leurs
// sous-routes, ex. détail d'un événement) allument l'entrée unique de la
// sidebar. Règle aussi les URL portant un paramètre (?period=...).
$operationsPrefixes = [
    '/admin/compta/achats',
    '/admin/compta/depenses',
    '/admin/compta/pertes',
    '/admin/compta/evenements',
    '/admin/compta/inventaire-tablette',
];
$activePath = $currentUrlPath;
foreach ($operationsPrefixes as $opPrefix) {
    if (str_starts_with($activePath, $opPrefix)) {
        $activePath = '/admin/compta/achats';
        break;
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
    <script>
        /* Restaure l'état « menu réduit » AVANT le premier rendu (pas de flash). */
        try {
            if (localStorage.getItem('aeic_admin_sidebar_collapsed') === '1') {
                document.body.classList.add('sidebar-collapsed');
            }
        } catch (e) {}
    </script>
    <div class="starfield" id="starfield" aria-hidden="true"></div>
    <a class="skip-link" href="#contenu">Aller au contenu</a>

    <div class="admin-shell">
        <div class="admin-backdrop" id="admin-backdrop" hidden></div>
        <aside class="admin-sidebar" id="admin-sidebar">
            <a class="brand" href="<?= e(url('/admin')) ?>">
                <span class="brand-logo" aria-hidden="true">AE</span>
                <span class="brand-name">Admin <?= e($siteName) ?></span>
            </a>

            <?php
            // Version à plat de tous les liens de la sidebar (en parcourant
            // les groupes imbriqués éventuels) : sert à la détection du
            // « meilleur match » de l'état actif. Liste simple : deux
            // groupes peuvent porter le même libellé (ex. « Produits »).
            $flatLinks = [];
            foreach ($sections as $groupLinks) {
                foreach ($groupLinks as $path) {
                    if (is_array($path)) {
                        foreach ($path as $subPath) {
                            $flatLinks[] = $subPath;
                        }
                    } else {
                        $flatLinks[] = $path;
                    }
                }
            }

            // État actif d'un lien, partagé par les deux niveaux de rendu.
            $isActive = function (string $label, string $path) use ($flatLinks, $activePath): bool {
                // État actif : match exact OU sous-route directe.
                // Évite que /admin/compta reste allumé sur /admin/compta/produits.
                $active = false;
                if ($activePath === $path) {
                    $active = true;
                } elseif (str_starts_with($activePath, $path . '/')) {
                    // Sous-route : on ne marque actif que si c'est le parent le plus précis.
                    // On vérifie qu'aucun autre lien plus long ne matche mieux.
                    $betterMatch = false;
                    foreach ($flatLinks as $p2) {
                        if ($p2 !== $path && strlen($p2) > strlen($path) && str_starts_with($activePath, $p2)) {
                            $betterMatch = true;
                            break;
                        }
                    }
                    $active = !$betterMatch;
                }
                // Cas spécial : /admin (tableau de bord) n'est actif que sur /admin exact.
                if ($path === '/admin' && $activePath !== '/admin') {
                    $active = false;
                }
                return $active;
            };
            ?>
            <?php
            // Identifiants stables des conteneurs de liens : slug du libellé
            // (minuscules, accents retirés via une table strtr — approche
            // retenue car iconv peut manquer selon l'hôte — puis tout
            // non-alphanumérique remplacé par « - »), et non un index, pour
            // que le DOM reste stable si on réordonne les groupes. La clé de
            // persistance JS (data-nav-key) reste le libellé EXACT, les
            // sous-groupes étant notés « Groupe/Sous-groupe ».
            $navSlug = function (string $label): string {
                $accents = [
                    'à'=>'a','á'=>'a','â'=>'a','ä'=>'a','ã'=>'a',
                    'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
                    'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
                    'ò'=>'o','ó'=>'o','ô'=>'o','ö'=>'o','õ'=>'o',
                    'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u',
                    'ç'=>'c','ñ'=>'n','œ'=>'oe','æ'=>'ae',
                    'À'=>'a','Á'=>'a','Â'=>'a','Ä'=>'a','Ã'=>'a',
                    'É'=>'e','È'=>'e','Ê'=>'e','Ë'=>'e',
                    'Ì'=>'i','Í'=>'i','Î'=>'i','Ï'=>'i',
                    'Ò'=>'o','Ó'=>'o','Ô'=>'o','Ö'=>'o','Õ'=>'o',
                    'Ù'=>'u','Ú'=>'u','Û'=>'u','Ü'=>'u',
                    'Ç'=>'c','Ñ'=>'n','Œ'=>'oe','Æ'=>'ae',
                ];
                $slug = strtolower(strtr($label, $accents));
                $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
                return trim($slug, '-') ?: 'section';
            };
            ?>
            <?php foreach ($sections as $group => $links): ?>
                <?php $groupSlug = $navSlug($group); ?>
                <button type="button" class="admin-group" data-nav-key="<?= e($group) ?>" aria-expanded="true" aria-controls="nav-<?= e($groupSlug) ?>"><?= e($group) ?></button>
                <nav id="nav-<?= e($groupSlug) ?>" aria-label="<?= e($group) ?>">
                    <?php foreach ($links as $label => $path): ?>
                        <?php if (is_array($path)): ?>
                            <?php $subId = 'nav-' . $groupSlug . '-' . $navSlug($label); ?>
                            <button type="button" class="admin-subgroup" data-nav-key="<?= e($group . '/' . $label) ?>" aria-expanded="true" aria-controls="<?= e($subId) ?>"><?= e($label) ?></button>
                            <div id="<?= e($subId) ?>">
                                <?php foreach ($path as $subLabel => $subPath): ?>
                                    <a class="admin-link<?= $isActive($subLabel, $subPath) ? ' is-active' : '' ?>" href="<?= e(url($subPath)) ?>"><?= e($subLabel) ?></a>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <a class="admin-link<?= $isActive($label, $path) ? ' is-active' : '' ?>" href="<?= e(url($path)) ?>"><?= e($label) ?></a>
                        <?php endif; ?>
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
        <script>
            /* Groupes/sous-groupes repliables : restauration de l'état
               mémorisé dans localStorage (clé « aeic_admin_sidebar_groups »,
               JSON « libellé -> replié ? », sous-groupe « Groupe/Sous-groupe »,
               clé absente = déplié). Script synchrone placé juste après la
               sidebar : même frame de peinture, aucun flash. JSON corrompu
               ou invalide = ignoré (tout est déplié). */
            (function () {
                var KEY = 'aeic_admin_sidebar_groups';
                var state = null;
                try {
                    state = JSON.parse(localStorage.getItem(KEY) || '{}');
                } catch (e) { state = null; }
                if (!state || typeof state !== 'object' || Array.isArray(state)) { state = {}; }

                var headings = document.querySelectorAll('.admin-sidebar button.admin-group, .admin-sidebar button.admin-subgroup');

                function setCollapsed(btn, collapsed) {
                    var panel = document.getElementById(btn.getAttribute('aria-controls'));
                    if (!panel) return;
                    btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                    panel.hidden = collapsed;
                }

                /* 1) Applique l'état stocké aux deux niveaux de titres. */
                headings.forEach(function (btn) {
                    setCollapsed(btn, state[btn.getAttribute('data-nav-key')] === true);
                });

                /* 2) Auto-dépli du chemin actif : le lien surligné doit
                   rester visible, mais SANS sauvegarde — la prochaine
                   visite retrouve l'état mémorisé. */
                var active = document.querySelector('.admin-sidebar .admin-link.is-active');
                if (active) {
                    headings.forEach(function (btn) {
                        var panel = document.getElementById(btn.getAttribute('aria-controls'));
                        if (panel && panel.contains(active)) { setCollapsed(btn, false); }
                    });
                }

                /* 3) Clic = bascule + sauvegarde. Le stockage est relu à
                   chaque clic pour ne pas écraser un état plus récent. */
                headings.forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var collapsed = btn.getAttribute('aria-expanded') !== 'false';
                        setCollapsed(btn, collapsed);
                        try {
                            var s = JSON.parse(localStorage.getItem(KEY) || '{}');
                            if (!s || typeof s !== 'object' || Array.isArray(s)) { s = {}; }
                            s[btn.getAttribute('data-nav-key')] = collapsed;
                            localStorage.setItem(KEY, JSON.stringify(s));
                        } catch (e) {}
                    });
                });
            })();
        </script>

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
            var backdrop = document.getElementById('admin-backdrop');
            var body = document.body;
            var mobileMq = window.matchMedia('(max-width: 960px)');
            var COLLAPSE_KEY = 'aeic_admin_sidebar_collapsed';

            function isCollapsed() { return body.classList.contains('sidebar-collapsed'); }

            /* aria-expanded du hamburger = menu visible ?
               Desktop : visible sauf si réduit. Mobile : drawer ouvert. */
            function syncToggle() {
                if (!btn) return;
                var expanded = mobileMq.matches
                    ? (side ? side.classList.contains('is-open') : false)
                    : !isCollapsed();
                btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                btn.title = expanded ? 'Réduire le menu' : 'Afficher le menu';
            }

            function setOpen(open) {
                if (!side) return;
                side.classList.toggle('is-open', open);
                if (backdrop) backdrop.hidden = !open;
                syncToggle();
            }

            function setCollapsed(collapsed) {
                body.classList.toggle('sidebar-collapsed', collapsed);
                try { localStorage.setItem(COLLAPSE_KEY, collapsed ? '1' : '0'); } catch (e) {}
                syncToggle();
            }

            if (btn && side) {
                btn.addEventListener('click', function () {
                    if (mobileMq.matches) {
                        setOpen(!side.classList.contains('is-open'));
                    } else {
                        setCollapsed(!isCollapsed());
                    }
                });
                if (backdrop) {
                    backdrop.addEventListener('click', function () { setOpen(false); });
                }
                document.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape') { setOpen(false); }
                });
                // Ferme le menu au tap sur un lien (mobile).
                side.addEventListener('click', function (e) {
                    if (e.target.closest('a')) { setOpen(false); }
                });

                // Changement de breakpoint : on referme le drawer et on
                // resynchronise l'état aria/title (la classe sidebar-collapsed
                // est sans effet en mobile : CSS scopé ≥961px).
                var onMqChange = function () {
                    setOpen(false);
                    syncToggle();
                };
                if (typeof mobileMq.addEventListener === 'function') {
                    mobileMq.addEventListener('change', onMqChange);
                } else if (typeof mobileMq.addListener === 'function') {
                    mobileMq.addListener(onMqChange);
                }
            }

            // État initial (aria + title, restauration faite en début de body).
            syncToggle();

            // Sauvegarde et restaure la position du scroll de la sidebar.
            if (side) {
                var SS_KEY = 'aeic_admin_sidebar_scroll';
                try { side.scrollTop = parseInt(sessionStorage.getItem(SS_KEY) || '0', 10) || 0; } catch (e) {}
                window.addEventListener('beforeunload', function () {
                    try { sessionStorage.setItem(SS_KEY, String(side.scrollTop)); } catch (e) {}
                });
            }
        })();
    </script>
    <script src="<?= e(rootAssetVersioned('/assets/js/confirm.js')) ?>"></script>
    <script src="<?= e(rootAssetVersioned('/assets/js/stars.js')) ?>" defer></script>
</body>
</html>
