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
    <link rel="stylesheet" href="<?= e(assetVersioned('css/base.css')) ?>">
    <link rel="stylesheet" href="<?= e(assetVersioned('css/pages.css')) ?>">
    <link rel="stylesheet" href="<?= e(rootAssetVersioned('/css/payments.css')) ?>">
    <?php if (str_starts_with($currentPath ?? '', '/sondages')): ?>
        <link rel="stylesheet" href="<?= e(rootAssetVersioned('/css/polls.css')) ?>">
    <?php endif; ?>
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
                <a class="nav-link<?= str_starts_with($currentPath, '/sondages') ? ' is-active' : '' ?>" href="<?= e(url('/sondages')) ?>">Sondages</a>
            </nav>

            <div class="nav-actions">
                <div class="nav-search" id="nav-search">
                    <span class="nav-search-icon" aria-hidden="true">🔎</span>
                    <input type="search"
                           id="global-search-input"
                           class="nav-search-input"
                           placeholder="Rechercher…"
                           autocomplete="off"
                           aria-label="Recherche globale">
                    <div class="nav-search-results" id="global-search-results" hidden></div>
                </div>

                <?php if ($user !== null): ?>
                    <div class="nav-bell" id="nav-notif">
                        <button type="button"
                                class="nav-bell-btn"
                                id="notif-toggle"
                                aria-label="Notifications">
                            <span aria-hidden="true">🔔</span>
                            <span class="nav-bell-badge" id="notif-badge" hidden>0</span>
                        </button>
                        <div class="nav-bell-dropdown" id="notif-dropdown" hidden>
                            <div class="nav-bell-head">
                                <strong>Notifications</strong>
                                <button type="button" class="nav-bell-readall" id="notif-readall">Tout marquer comme lu</button>
                            </div>
                            <ul class="nav-bell-list" id="notif-list">
                                <li class="nav-bell-empty">Aucune notification.</li>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>

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

            <button class="nav-search-toggle" type="button" id="nav-search-toggle" aria-label="Rechercher">
                <span aria-hidden="true">🔎</span>
            </button>
        </div>

        <div class="search-overlay" id="search-overlay" hidden>
            <div class="search-overlay-inner">
                <span class="nav-search-icon" aria-hidden="true">🔎</span>
                <input type="search"
                       id="mobile-search-input"
                       class="nav-search-input"
                       placeholder="Rechercher…"
                       autocomplete="off"
                       aria-label="Recherche globale">
                <button type="button" class="search-overlay-close" id="search-overlay-close" aria-label="Fermer">✕</button>
                <div class="nav-search-results" id="mobile-search-results" hidden></div>
            </div>
        </div>

        <nav id="mobile-nav" class="mobile-nav" aria-label="Navigation mobile">
            <a href="<?= e(url('/')) ?>">Accueil</a>
            <a href="<?= e(url('/events')) ?>">Événements</a>
            <a href="<?= e(url('/presentation')) ?>">L'association</a>
            <a href="<?= e(url('/team')) ?>">Équipe</a>
            <a href="<?= e(url('/sondages')) ?>">Sondages</a>
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
        <div class="container footer-inner">
            <div class="footer-brand">
                <div class="footer-logo aeic-gradient">AE</div>
                <div>
                    <p class="footer-name"><?= e($siteName) ?></p>
                    <p class="footer-sub">Association Étudiante Informatique de Calais</p>
                </div>
            </div>

            <nav class="footer-links" aria-label="Pied de page">
                <a href="<?= e(url('/events')) ?>">📅 Événements</a>
                <a href="<?= e(url('/presentation')) ?>">🏫 Association</a>
                <a href="<?= e(url('/team')) ?>">👥 Équipe</a>
                <a href="<?= e(url('/sondages')) ?>">📊 Sondages</a>
                <a href="<?= e(url('/legal')) ?>">⚖️ Mentions légales</a>
                <a href="<?= e(url('/privacy')) ?>">🔒 Confidentialité</a>
                <a href="<?= e(url('/cgu')) ?>">📋 CGU</a>
            </nav>

            <div class="footer-bottom">
                <span class="footer-tag">🎓 100 % étudiant.</span>
                <span class="footer-copy">© <?= e($currentYear) ?> <?= e($siteName) ?> · Fait par les étudiants, pour les étudiants.</span>
            </div>
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

    <?php if (Auth::check()): ?>
        <script>window.AEIC_CSRF = <?= json_encode(csrf_token()) ?>;</script>
    <?php endif; ?>

    <script>
        (function () {
            function esc(s) {
                var d = document.createElement('div');
                d.textContent = s == null ? '' : String(s);
                return d.innerHTML;
            }

            function renderResults(container, items) {
                container.innerHTML = '';
                if (!items.length) {
                    container.hidden = true;
                    return;
                }
                items.forEach(function (it) {
                    var a = document.createElement('a');
                    a.href = it.url;
                    a.className = 'search-result';
                    a.innerHTML =
                        '<span class="search-result-main">' +
                            '<span class="search-result-title">' + esc(it.title) + '</span>' +
                            (it.excerpt ? '<span class="search-result-excerpt">' + esc(it.excerpt) + '</span>' : '') +
                        '</span>' +
                        '<span class="badge badge-muted search-result-type">' + esc(it.type) + '</span>';
                    container.appendChild(a);
                });
                container.hidden = false;
            }

            function wireSearch(input, resultsEl, onClose) {
                var timer = null;
                input.addEventListener('input', function () {
                    var q = input.value.trim();
                    clearTimeout(timer);
                    if (q.length < 2) {
                        resultsEl.hidden = true;
                        resultsEl.innerHTML = '';
                        return;
                    }
                    timer = setTimeout(function () {
                        fetch(<?= json_encode(url('/search')) ?> + '?q=' + encodeURIComponent(q), {
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        })
                            .then(function (r) { return r.json(); })
                            .then(function (items) { renderResults(resultsEl, items); })
                            .catch(function () { resultsEl.hidden = true; });
                    }, 300);
                });

                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape') {
                        resultsEl.hidden = true;
                        if (onClose) onClose();
                    }
                });
            }

            var desktopInput = document.getElementById('global-search-input');
            var desktopResults = document.getElementById('global-search-results');
            if (desktopInput && desktopResults) {
                wireSearch(desktopInput, desktopResults);
                document.addEventListener('click', function (e) {
                    var box = document.getElementById('nav-search');
                    if (box && !box.contains(e.target)) {
                        desktopResults.hidden = true;
                    }
                });
            }

            // Overlay mobile.
            var overlay = document.getElementById('search-overlay');
            var overlayToggle = document.getElementById('nav-search-toggle');
            var overlayClose = document.getElementById('search-overlay-close');
            var mobileInput = document.getElementById('mobile-search-input');
            var mobileResults = document.getElementById('mobile-search-results');
            if (overlay && overlayToggle && mobileInput && mobileResults) {
                overlayToggle.addEventListener('click', function () {
                    overlay.hidden = false;
                    setTimeout(function () { mobileInput.focus(); }, 50);
                });
                function closeOverlay() { overlay.hidden = true; mobileResults.hidden = true; mobileInput.value = ''; }
                if (overlayClose) overlayClose.addEventListener('click', closeOverlay);
                overlay.addEventListener('click', function (e) { if (e.target === overlay) closeOverlay(); });
                wireSearch(mobileInput, mobileResults, closeOverlay);
            }

            // Notifications (uniquement si connecté).
            var notifBox = document.getElementById('nav-notif');
            if (notifBox && window.AEIC_CSRF) {
                var badge = document.getElementById('notif-badge');
                var list = document.getElementById('notif-list');
                var dropdown = document.getElementById('notif-dropdown');
                var toggle = document.getElementById('notif-toggle');
                var readAll = document.getElementById('notif-readall');

                function timeAgo(iso) {
                    if (!iso) return '';
                    var then = new Date(iso.replace(' ', 'T') + 'Z');
                    var diff = Math.round((Date.now() - then.getTime()) / 60000);
                    if (diff < 1) return "à l'instant";
                    if (diff < 60) return 'il y a ' + diff + ' min';
                    if (diff < 1440) return 'il y a ' + Math.floor(diff / 60) + ' h';
                    return 'il y a ' + Math.floor(diff / 1440) + ' j';
                }

                function render(payload) {
                    var n = payload.count || 0;
                    if (n > 0) { badge.hidden = false; badge.textContent = n > 99 ? '99+' : n; }
                    else { badge.hidden = true; }

                    var items = payload.items || [];
                    if (!items.length) {
                        list.innerHTML = '<li class="nav-bell-empty">Aucune notification.</li>';
                        return;
                    }
                    list.innerHTML = '';
                    items.forEach(function (it) {
                        var li = document.createElement('li');
                        li.className = 'nav-bell-item' + (it.is_read ? ' is-read' : '');
                        var inner = '<div class="nav-bell-item-title">' + esc(it.title) + '</div>';
                        if (it.body) inner += '<div class="nav-bell-item-body">' + esc(it.body) + '</div>';
                        inner += '<div class="nav-bell-item-meta">' + timeAgo(it.created_at) + '</div>';
                        if (it.url) {
                            var a = document.createElement('a');
                            a.href = it.url;
                            a.innerHTML = inner;
                            li.appendChild(a);
                        } else {
                            li.innerHTML = inner;
                        }
                        list.appendChild(li);
                    });
                }

                function load() {
                    fetch(<?= json_encode(url('/api/notifications')) ?>, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    })
                        .then(function (r) { return r.json(); })
                        .then(render)
                        .catch(function () {});
                }

                toggle.addEventListener('click', function (e) {
                    e.stopPropagation();
                    dropdown.hidden = !dropdown.hidden;
                });
                document.addEventListener('click', function (e) {
                    if (!notifBox.contains(e.target)) dropdown.hidden = true;
                });
                document.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape') dropdown.hidden = true;
                });

                readAll.addEventListener('click', function () {
                    fetch(<?= json_encode(url('/api/notifications/read-all')) ?>, {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-Token': window.AEIC_CSRF
                        }
                    }).then(function () { load(); });
                });

                load();
                setInterval(load, 60000);
            }
        })();
    </script>
</body>
</html>
