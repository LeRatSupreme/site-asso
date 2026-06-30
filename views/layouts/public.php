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
$lang         = current_lang();

$langs       = available_langs();
$currentFlag = lang_flag($lang);

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
<html lang="<?= e($lang) ?>">
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
    <meta property="og:locale" content="<?= e(lang_locale($lang)) ?>">

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
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="<?= e(asset('img/icon.svg')) ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="AEIC">
    <link rel="stylesheet" href="<?= e(assetVersioned('css/base.css')) ?>">
    <link rel="stylesheet" href="<?= e(assetVersioned('css/pages.css')) ?>">
    <link rel="stylesheet" href="<?= e(rootAssetVersioned('/css/payments.css')) ?>">
    <?php if (str_starts_with($currentPath ?? '', '/sondages')): ?>
        <link rel="stylesheet" href="<?= e(rootAssetVersioned('/css/polls.css')) ?>">
    <?php endif; ?>

    <!-- Thème clair/sombre : appliqué avant le rendu pour éviter tout flash. -->
    <script>
        (function () {
            try {
                var t = localStorage.getItem('aeic-theme');
                if (t === 'light' || t === 'dark') {
                    document.documentElement.setAttribute('data-theme', t);
                }
            } catch (e) {}
        })();
    </script>
</head>
<body>
    <a class="skip-link" href="#contenu"><?= e(t('nav.skip')) ?></a>

    <header class="site-header">
        <div class="container nav-bar">
            <a class="brand" href="<?= e(url('/')) ?>" aria-label="<?= e($siteName) ?> — <?= e(t('nav.home')) ?>">
                <span class="brand-logo" aria-hidden="true">AE</span>
                <span class="brand-text">
                    <span class="brand-sub">Étudiants · Calais</span>
                    <span class="brand-name"><?= e($siteName) ?></span>
                </span>
            </a>

            <nav class="main-nav" aria-label="<?= e(t('nav.main.aria')) ?>">
                <a class="nav-link<?= $currentPath === '/' ? ' is-active' : '' ?>" href="<?= e(url('/')) ?>"><?= e(t('nav.home')) ?></a>
                <a class="nav-link<?= str_starts_with($currentPath, '/events') ? ' is-active' : '' ?>" href="<?= e(url('/events')) ?>"><?= e(t('nav.events')) ?></a>
                <a class="nav-link<?= $currentPath === '/presentation' ? ' is-active' : '' ?>" href="<?= e(url('/presentation')) ?>"><?= e(t('nav.about')) ?></a>
                <a class="nav-link<?= $currentPath === '/team' ? ' is-active' : '' ?>" href="<?= e(url('/team')) ?>"><?= e(t('nav.team')) ?></a>
                <a class="nav-link<?= str_starts_with($currentPath, '/sondages') ? ' is-active' : '' ?>" href="<?= e(url('/sondages')) ?>"><?= e(t('nav.polls')) ?></a>
                <a class="nav-link<?= str_starts_with($currentPath, '/galerie') ? ' is-active' : '' ?>" href="<?= e(url('/galerie')) ?>"><?= e(t('nav.gallery')) ?></a>
            </nav>

            <div class="nav-actions">
                <div class="lang-dropdown" id="lang-dropdown">
                    <button type="button" class="lang-current" id="lang-current"
                            aria-haspopup="true" aria-expanded="false" aria-controls="lang-menu"
                            title="<?= e(t('nav.lang.change')) ?>">
                        <span class="lang-flag" aria-hidden="true"><?= $currentFlag ?></span>
                        <span class="lang-name"><?= e(strtoupper($lang)) ?></span>
                        <span class="lang-caret" aria-hidden="true">▾</span>
                    </button>
                    <ul class="lang-menu" id="lang-menu" role="menu" hidden>
                        <?php foreach ($langs as $code): ?>
                            <li role="none">
                                <a role="menuitemradio" aria-checked="<?= $code === $lang ? 'true' : 'false' ?>"
                                   class="lang-menu-item<?= $code === $lang ? ' is-active' : '' ?>"
                                   href="<?= e(url('/set-lang?lang=' . $code . '&redirect=' . rawurlencode($currentPath))) ?>">
                                    <span class="lang-flag" aria-hidden="true"><?= lang_flag($code) ?></span>
                                    <span class="lang-name"><?= e(t('lang.' . $code)) ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <button type="button" class="nav-theme-btn" id="theme-toggle"
                        aria-label="<?= e(t('nav.theme.toggle')) ?>" title="<?= e(t('nav.theme.light.dark')) ?>">
                    <span class="theme-icon-dark" aria-hidden="true">☀️</span>
                    <span class="theme-icon-light" aria-hidden="true">🌙</span>
                </button>

                <?php if ($user !== null): ?>
                    <div class="nav-bell" id="nav-notif">
                        <button type="button"
                                class="nav-bell-btn"
                                id="notif-toggle"
                                aria-label="<?= e(t('nav.notifications')) ?>">
                            <span aria-hidden="true">🔔</span>
                            <span class="nav-bell-badge" id="notif-badge" hidden>0</span>
                        </button>
                        <div class="nav-bell-dropdown" id="notif-dropdown" hidden>
                            <div class="nav-bell-head">
                                <strong><?= e(t('nav.notifications')) ?></strong>
                                <button type="button" class="nav-bell-readall" id="notif-readall"><?= e(t('nav.notifications.markall')) ?></button>
                            </div>
                            <ul class="nav-bell-list" id="notif-list">
                                <li class="nav-bell-empty"><?= e(t('nav.notifications.empty')) ?></li>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($user !== null): ?>
                    <?php if (($user['role'] ?? '') === 'ADMIN' || ($user['role'] ?? '') === 'TRESORERIE'): ?>
                        <a class="btn btn-primary btn-sm" href="<?= e(url('/admin')) ?>"><?= e(t('nav.admin')) ?></a>
                    <?php endif; ?>
                    <a class="btn btn-outline btn-sm" href="<?= e(url('/account/privacy')) ?>"><?= e($user['prenom'] ?? t('nav.account')) ?></a>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('/logout')) ?>"><?= e(t('nav.logout')) ?></a>
                <?php else: ?>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('/login')) ?>"><?= e(t('nav.login')) ?></a>
                    <a class="btn btn-primary btn-sm" href="<?= e(url('/register')) ?>"><?= e(t('nav.register')) ?></a>
                <?php endif; ?>
            </div>

            <button class="nav-toggle" type="button" aria-label="<?= e(t('nav.open_menu')) ?>"
                    aria-expanded="false" aria-controls="mobile-nav">
                <span></span><span></span><span></span>
            </button>
        </div>

        <nav id="mobile-nav" class="mobile-nav" aria-label="<?= e(t('nav.main.aria')) ?>">
            <a href="<?= e(url('/')) ?>"><?= e(t('nav.home')) ?></a>
            <a href="<?= e(url('/events')) ?>"><?= e(t('nav.events')) ?></a>
            <a href="<?= e(url('/presentation')) ?>"><?= e(t('nav.about')) ?></a>
            <a href="<?= e(url('/team')) ?>"><?= e(t('nav.team')) ?></a>
            <a href="<?= e(url('/sondages')) ?>"><?= e(t('nav.polls')) ?></a>
            <a href="<?= e(url('/galerie')) ?>"><?= e(t('nav.gallery')) ?></a>
            <hr>
            <div class="lang-switch-mobile">
                <?php foreach ($langs as $code): ?>
                    <a class="btn btn-outline btn-sm<?= $code === $lang ? ' is-active' : '' ?>"
                       href="<?= e(url('/set-lang?lang=' . $code . '&redirect=' . rawurlencode($currentPath))) ?>">
                        <?= lang_flag($code) ?> <?= e(strtoupper($code)) ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <hr>
            <button type="button" class="btn btn-ghost" id="theme-toggle-mobile">🌙 <?= e(t('nav.theme')) ?></button>
            <hr>
            <?php if ($user !== null): ?>
                <?php if (($user['role'] ?? '') === 'ADMIN' || ($user['role'] ?? '') === 'TRESORERIE'): ?>
                    <a class="btn btn-primary" href="<?= e(url('/admin')) ?>"><?= e(t('nav.admin')) ?></a>
                <?php endif; ?>
                <a class="btn btn-outline" href="<?= e(url('/account/privacy')) ?>"><?= e(t('nav.data')) ?></a>
                <a class="btn btn-ghost" href="<?= e(url('/logout')) ?>"><?= e(t('nav.logout')) ?></a>
            <?php else: ?>
                <a class="btn btn-ghost" href="<?= e(url('/login')) ?>"><?= e(t('nav.login')) ?></a>
                <a class="btn btn-primary" href="<?= e(url('/register')) ?>"><?= e(t('nav.register')) ?></a>
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

            <nav class="footer-links" aria-label="<?= e(t('footer.aria')) ?>">
                <a href="<?= e(url('/events')) ?>">📅 <?= e(t('nav.events')) ?></a>
                <a href="<?= e(url('/presentation')) ?>">🏫 <?= e(t('nav.about')) ?></a>
                <a href="<?= e(url('/team')) ?>">👥 <?= e(t('nav.team')) ?></a>
                <a href="<?= e(url('/sondages')) ?>">📊 <?= e(t('nav.polls')) ?></a>
                <a href="<?= e(url('/galerie')) ?>">📷 <?= e(t('nav.gallery')) ?></a>
                <a href="<?= e(url('/legal')) ?>">⚖️ <?= e(t('footer.legal')) ?></a>
                <a href="<?= e(url('/privacy')) ?>">🔒 <?= e(t('footer.privacy')) ?></a>
                <a href="<?= e(url('/cgu')) ?>">📋 <?= e(t('footer.cgu')) ?></a>
            </nav>

            <div class="footer-bottom">
                <span class="footer-tag">🎓 <?= e(t('footer.tag')) ?></span>
                <span class="footer-copy">© <?= e($currentYear) ?> <?= e($siteName) ?> · <?= e(t('footer.copy')) ?></span>
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

        // Toggle thème clair / sombre (persistance localStorage).
        (function () {
            var STORAGE_KEY = 'aeic-theme';
            var root = document.documentElement;

            function current() {
                return root.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
            }

            function apply(theme) {
                root.setAttribute('data-theme', theme);
                try { localStorage.setItem(STORAGE_KEY, theme); } catch (e) {}
                document.querySelectorAll('[data-theme-active]').forEach(function (el) {
                    el.setAttribute('data-theme-active', theme);
                });
            }

            apply(current());

            function toggle() { apply(current() === 'light' ? 'dark' : 'light'); }

            var btn = document.getElementById('theme-toggle');
            if (btn) btn.addEventListener('click', toggle);
            var btnMobile = document.getElementById('theme-toggle-mobile');
            if (btnMobile) btnMobile.addEventListener('click', toggle);
        })();
    </script>

    <?php if (Auth::check()): ?>
        <script>window.AEIC_CSRF = <?= json_encode(csrf_token()) ?>;</script>
    <?php endif; ?>
    <script>window.AEIC_I18N = {
        notifEmpty: <?= json_encode(t('nav.notifications.empty')) ?>,
        justNow: <?= json_encode($lang === 'fr' ? "à l'instant" : ($lang === 'en' ? 'just now' : ($lang === 'de' ? 'gerade eben' : ($lang === 'es' ? 'justo ahora' : ($lang === 'zh' ? '刚刚' : ($lang === 'ja' ? 'たった今' : 'przed chwilą')))))) ?>,
        minutesAgo: <?= json_encode($lang === 'fr' ? 'il y a %d min' : ($lang === 'en' ? '%d min ago' : ($lang === 'de' ? 'vor %d Min.' : ($lang === 'es' ? 'hace %d min' : ($lang === 'zh' ? '%d 分钟前' : ($lang === 'ja' ? '%d分前' : '%d min temu')))))) ?>,
        hoursAgo: <?= json_encode($lang === 'fr' ? 'il y a %d h' : ($lang === 'en' ? '%d h ago' : ($lang === 'de' ? 'vor %d Std.' : ($lang === 'es' ? 'hace %d h' : ($lang === 'zh' ? '%d 小时前' : ($lang === 'ja' ? '%d時間前' : '%d godz. temu')))))) ?>,
        daysAgo: <?= json_encode($lang === 'fr' ? 'il y a %d j' : ($lang === 'en' ? '%d d ago' : ($lang === 'de' ? 'vor %d Tg.' : ($lang === 'es' ? 'hace %d d' : ($lang === 'zh' ? '%d 天前' : ($lang === 'ja' ? '%d日前' : '%d dni temu')))))) ?>
    };</script>

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
                    var i18n = window.AEIC_I18N || {};
                    if (diff < 1) return i18n.justNow || '';
                    if (diff < 60) return (i18n.minutesAgo || '').replace('%d', diff);
                    if (diff < 1440) return (i18n.hoursAgo || '').replace('%d', Math.floor(diff / 60));
                    return (i18n.daysAgo || '').replace('%d', Math.floor(diff / 1440));
                }

                function render(payload) {
                    var n = payload.count || 0;
                    if (n > 0) { badge.hidden = false; badge.textContent = n > 99 ? '99+' : n; }
                    else { badge.hidden = true; }

                    var items = payload.items || [];
                    if (!items.length) {
                        list.innerHTML = '<li class="nav-bell-empty">' + ((window.AEIC_I18N || {}).notifEmpty || '') + '</li>';
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
    <script src="<?= e(rootAssetVersioned('/assets/js/confirm.js')) ?>"></script>

    <!-- Dropdown de langue : ouverture, fermeture au clic extérieur et à Échap. -->
    <script>
        (function () {
            var box = document.getElementById('lang-dropdown');
            var btn = document.getElementById('lang-current');
            var menu = document.getElementById('lang-menu');
            if (!box || !btn || !menu) return;

            function open() {
                menu.hidden = false;
                btn.setAttribute('aria-expanded', 'true');
            }
            function close() {
                menu.hidden = true;
                btn.setAttribute('aria-expanded', 'false');
            }
            function toggle() { menu.hidden ? open() : close(); }

            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                toggle();
            });
            document.addEventListener('click', function (e) {
                if (!box.contains(e.target)) close();
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') close();
            });
        })();
    </script>

    <!-- PWA : enregistrement du service worker -->
    <script>if ('serviceWorker' in navigator) { window.addEventListener('load', function () { navigator.serviceWorker.register('/sw.js'); }); }</script>
</body>
</html>
