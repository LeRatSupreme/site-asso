<?php

declare(strict_types=1);

/**
 * Layout espace administrateur AEIC.
 *
 * Sidebar en 6 sections + fil d'Ariane automatique + recherche globale
 * dans la barre du haut. Toutes les pages admin sont en noindex.
 *
 * Structure de la sidebar (refonte UI, cf. docs/ui-audit.md §3 et §8) :
 * les entrées conservent EXACTEMENT les conditions de visibilité issues
 * des guards serveur — module (Permissions::allows) pour les groupes,
 * « Système OU page attribuée » (guardSystemOrPage, clé de page dans
 * users.extra_pages) pour les pages protégées.
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
// « /admin/compta/ventes?period=... » doit allumer « Journal des ventes ».
$currentUrlPath = (string) (parse_url($currentPath, PHP_URL_PATH) ?: '/');
$user        = Auth::user();
$lang        = current_lang();

/* -------------------------------------------------------------------------
 * Conditions de visibilité réutilisées par entrée.
 * ---------------------------------------------------------------------- */

$viewerRole = (string) ($user['role'] ?? '');

// Module autorisé au rôle (ou attribué individuellement via extra_pages).
$moduleAllowed = static fn(string $module): bool => Permissions::allows($viewerRole, $module);

// Pages protégées type « Système » : Fondateur/SYSTEM_ADMINS, ou page
// attribuée individuellement (users.extra_pages, guardSystemOrPage()).
$isSystemAdmin = Permissions::isSystemAdmin();
$systemPageAllowed = static fn(string $key): bool => $isSystemAdmin || Permissions::userHasExtraPage($key);

// Niveau « bureau » requis pour les entrées issues de l'ancien groupe
// Système (même garde que guardSystemOrPage : rôles admin uniquement).
$isAdminRole = in_array($user['role'] ?? null, Permissions::adminRoles(), true);

// Journal d'audit : visible seulement Fondateur/ADMIN (garde actuelle).
$isFondateurOuAdmin = in_array($user['role'] ?? null, [Auth::ROLE_SUPERADMIN, Auth::ROLE_ADMIN], true);

// Bénévole (nouveau rôle) : aucun module, uniquement l'accueil, les pages
// de comptage et la liste de courses (sans chiffre financier, cf. lot 5).
// La constante Auth::ROLE_BENEVOL est posée par le
// lot rôles ; repli sur la clé littérale si le lot n'est pas encore
// déployé (le fallback disparaît de lui-même une fois la constante là).
$benevolRole = defined('App\Core\Auth::ROLE_BENEVOL') ? Auth::ROLE_BENEVOL : 'BENEVOL';
$isBenevol = ($user['role'] ?? null) === $benevolRole;

/* -------------------------------------------------------------------------
 * Construction des 6 sections. Une section n'est ajoutée que si au moins
 * une de ses entrées est visible : jamais de lien vers une page interdite.
 * ---------------------------------------------------------------------- */

$sections = [];

// 1. Accueil (tout le bureau).
$accueil = [
    'À faire aujourd\'hui' => '/admin',
    'Aide & wiki'          => '/admin/wiki',
];
if ($isFondateurOuAdmin) {
    $accueil['Journal d\'audit'] = '/admin/audit';
}
if ($isBenevol) {
    $accueil['Comptage caisse']     = '/admin/compta/caisse';
    $accueil['Comptage inventaire'] = '/admin/compta/inventaire/comptage';
    $accueil['Liste de courses']    = '/admin/compta/liste';
}
$sections['Accueil'] = $accueil;

// 2. Ventes (module compta ; « Commandes élèves » requiert la cafétéria).
$ventes = [];
if ($moduleAllowed(Permissions::MODULE_COMPTA)) {
    $ventes += [
        'Aujourd\'hui'             => '/admin/compta',
        'Analyses'                 => '/admin/analytics',
        'Idées appliquées'         => '/admin/reco/historique',
        'Bilan annuel'             => '/admin/compta/annuel',
        'Journal des ventes'       => '/admin/compta/ventes',
        'Bénéfice par produit'     => '/admin/compta/produits',
        'Bénéfice par catégorie'   => '/admin/compta/categories',
        'Importer un rapport SumUp' => '/admin/compta/import',
    ];
}
if ($moduleAllowed(Permissions::MODULE_CAFETERIA)) {
    $ventes['Commandes élèves'] = '/admin/cafeteria/commandes';
}
if ($ventes !== []) {
    $sections['Ventes'] = $ventes;
}

// 3. Stock (compta + cafétéria), sous-groupes repliables.
$stockProduits = [];
if ($moduleAllowed(Permissions::MODULE_CAFETERIA)) {
    $stockProduits += [
        'Produits'   => '/admin/cafeteria',
        'Catégories' => '/admin/cafeteria/categories',
    ];
}
if ($isAdminRole && $systemPageAllowed('costs')) {
    $stockProduits['Coûts de revient'] = '/admin/compta/couts';
}
if ($moduleAllowed(Permissions::MODULE_COMPTA)) {
    $stockProduits += [
        'Mapping libellés'        => '/admin/compta/aliases',
        'Ajouter une appellation' => '/admin/compta/aliases/new',
        'Détection de doublons'   => '/admin/compta/aliases/auto',
    ];
}

$stockReappro = [];
if ($moduleAllowed(Permissions::MODULE_COMPTA)) {
    $stockReappro = [
        'Réappro'          => '/admin/compta/reappro',
        'Liste de courses' => '/admin/compta/liste',
    ];
}

$stockInventaire = [];
if ($isAdminRole && $systemPageAllowed('inventory')) {
    $stockInventaire['Inventaire'] = '/admin/compta/inventaire';
}
if ($moduleAllowed(Permissions::MODULE_COMPTA)) {
    $stockInventaire += [
        'Comptage à l\'aveugle' => '/admin/compta/inventaire/comptage',
        'Inventaire tablette'   => '/admin/compta/inventaire-tablette',
    ];
}

$stockMouvements = [];
if ($moduleAllowed(Permissions::MODULE_COMPTA)) {
    $stockMouvements = [
        'Achats' => '/admin/compta/achats',
        'Pertes' => '/admin/compta/pertes',
    ];
}

$stock = [];
foreach ([
    'Produits'             => $stockProduits,
    'Réapprovisionnement'  => $stockReappro,
    'Inventaire'           => $stockInventaire,
    'Mouvements'           => $stockMouvements,
] as $sousGroupe => $liens) {
    if ($liens !== []) {
        $stock[$sousGroupe] = $liens;
    }
}
if ($stock !== []) {
    $sections['Stock'] = $stock;
}

// 4. Comptabilité (module compta ; Livre comptable et Caisses : pages Système).
$compta = [];
if ($moduleAllowed(Permissions::MODULE_COMPTA)) {
    $compta += [
        'Opérations'           => '/admin/compta/depenses',
        'Opérations événements' => '/admin/compta/evenements',
    ];
}
if ($isAdminRole && $systemPageAllowed('ledger')) {
    $compta['Livre comptable'] = '/admin/ledger';
}
if ($moduleAllowed(Permissions::MODULE_COMPTA)) {
    $compta['Budgets'] = '/admin/compta/budgets';
}
if ($isAdminRole && $systemPageAllowed('cash')) {
    $compta['Caisses'] = '/admin/caisses';
}
if ($compta !== []) {
    $sections['Comptabilité'] = $compta;
}

// 5. Association : événements + contenu + pages Système Membres/Adhésions,
//    avec sous-groupes « Site web » (content) et « Jeux » (games).
$association = [];
if ($moduleAllowed(Permissions::MODULE_EVENTS)) {
    $association['Événements'] = '/admin/events';
}
if ($moduleAllowed(Permissions::MODULE_CONTENT)) {
    $association['Sondages'] = '/admin/sondages';
}
if ($isAdminRole && $systemPageAllowed('users')) {
    $association += [
        'Membres'   => '/admin/users',
        'Adhésions' => '/admin/memberships',
    ];
}

$siteWeb = [];
if ($moduleAllowed(Permissions::MODULE_CONTENT)) {
    $siteWeb = [
        'Pages'      => '/admin/pages',
        'Équipe'     => '/admin/team',
        'Médias'     => '/admin/media',
        'Promotions' => '/admin/promotions',
    ];
}

$jeux = [];
if ($moduleAllowed(Permissions::MODULE_GAMES)) {
    $jeux = [
        'Vue d\'ensemble'   => '/admin/jeux',
        'Joueurs & pseudos' => '/admin/jeux/scores',
        'Mots Wordle'       => '/admin/jeux/wordle',
        'Énigmes'           => '/admin/jeux/enigmes',
    ];
}

if ($siteWeb !== []) {
    $association['Site web'] = $siteWeb;
}
if ($jeux !== []) {
    $association['Jeux'] = $jeux;
}
if ($association !== []) {
    $sections['Association'] = $association;
}

// 6. Réglages (comme l'ancien groupe Système : pages attribuables).
$reglages = [];
if ($isAdminRole) {
    foreach ([
        'Paramètres'         => ['settings', '/admin/settings'],
        'Kiosques & jetons'  => ['kiosques', '/admin/kiosques'],
        'Notifications SMS'  => ['notifications', '/admin/notifications'],
    ] as $label => [$key, $path]) {
        if ($systemPageAllowed($key)) {
            $reglages[$label] = $path;
        }
    }
}
if ($reglages !== []) {
    $sections['Réglages'] = $reglages;
}

/* -------------------------------------------------------------------------
 * Fil d'Ariane : calculé automatiquement à partir de l'arbre de menu
 * ci-dessus (section > [sous-groupe >] entrée correspondant au chemin
 * courant, même règle « meilleur match » que l'état actif : chemin exact
 * ou préfixe le plus long). Sous /admin racine : « Administration » seul.
 * ---------------------------------------------------------------------- */

$crumbMatches = static function (string $path) use ($currentUrlPath): bool {
    if ($currentUrlPath === $path) {
        return true;
    }
    // La racine /admin ne « absorbe » pas les autres pages admin.
    if ($path === '/admin') {
        return false;
    }

    return str_starts_with($currentUrlPath, $path . '/');
};

$crumbEntry = null;
$crumbLen   = 0;
foreach ($sections as $crumbSection => $crumbLinks) {
    foreach ($crumbLinks as $crumbLabel => $crumbPath) {
        if (is_array($crumbPath)) {
            foreach ($crumbPath as $crumbSubLabel => $crumbSubPath) {
                if ($crumbMatches($crumbSubPath) && strlen($crumbSubPath) > $crumbLen) {
                    $crumbLen   = strlen($crumbSubPath);
                    $crumbEntry = ['section' => $crumbSection, 'subgroup' => $crumbLabel, 'label' => $crumbSubLabel, 'path' => $crumbSubPath];
                }
            }
        } elseif ($crumbMatches($crumbPath) && strlen($crumbPath) > $crumbLen) {
            $crumbLen   = strlen($crumbPath);
            $crumbEntry = ['section' => $crumbSection, 'subgroup' => null, 'label' => $crumbLabel, 'path' => $crumbPath];
        }
    }
}

if ($currentUrlPath === '/admin' || $crumbEntry === null) {
    $crumbItems = [['label' => 'Administration', 'url' => null]];
} else {
    $crumbItems = [['label' => 'Administration', 'url' => url('/admin')]];
    $crumbItems[] = ['label' => $crumbEntry['section'], 'url' => null];
    if ($crumbEntry['subgroup'] !== null) {
        $crumbItems[] = ['label' => $crumbEntry['subgroup'], 'url' => null];
    }
    // L'entrée finale n'est un lien que si ce n'est pas la page courante
    // (sous-routes : le lien ramène vers la page de liste).
    $crumbItems[] = [
        'label' => $crumbEntry['label'],
        'url'   => $currentUrlPath === $crumbEntry['path'] ? null : url($crumbEntry['path']),
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
    <script>
        /* Restaure l'état « menu réduit » AVANT le premier rendu (pas de flash). */
        try {
            if (localStorage.getItem('aeic_admin_sidebar_collapsed') === '1') {
                document.body.classList.add('sidebar-collapsed');
            }
        } catch (e) {}
    </script>
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

            // Chemin courant utilisé pour l'état actif : chaque page
            // d'opérations (/depenses, /achats, /pertes, /evenements,
            // /inventaire-tablette) ayant désormais SA propre entrée de
            // menu, plus besoin de fusionner ces préfixes sur une entrée
            // unique comme avant la refonte.
            $activePath = $currentUrlPath;

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
                <div class="admin-topbar-text">
                    <?php require AEIC_VIEWS . '/partials/_breadcrumb.php'; ?>
                    <h1 class="admin-title"><?= e($title ?? 'Administration') ?></h1>
                </div>
                <form class="admin-search" method="get" action="<?= e(url('/admin/search')) ?>" role="search">
                    <input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>"
                           placeholder="Rechercher…"
                           aria-label="Rechercher un produit, une vente, un membre"
                           autocomplete="off">
                    <button type="submit" class="btn btn-outline admin-search-btn">Rechercher</button>
                </form>
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
</body>
</html>
