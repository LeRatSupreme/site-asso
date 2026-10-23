<?php

declare(strict_types=1);

/**
 * Page « Kiosques » (Système) : tous les liens kiosque centralisés, en
 * deux catégories — Membres (à partager) et Admins (données financières).
 *
 * Les jetons ne figurent JAMAIS dans le HTML initial (mission §10 : aucune
 * capture d'écran ne doit suffire à obtenir un accès) : chaque lien est
 * masqué (« •••••••• ») et révélé à la demande par un POST CSRF vers
 * /admin/kiosques/reveal, tracé dans le journal d'audit.
 *
 * @var array<string,mixed> $user
 * @var list<array{emoji:string,label:string,desc:string,path:string,main?:bool,scope:string}> $memberPages
 * @var list<array{emoji:string,label:string,desc:string,path?:string,scope?:string,url?:string,kiosk?:bool,main?:bool}> $adminPages
 */
?>
<style>
    .kq-section { margin-bottom: 1.6rem; }
    .kq-section-title {
        display: flex; align-items: center; gap: 0.5rem;
        margin: 0 0 0.65rem; font-size: 1rem; font-weight: 900;
    }
    .kq-section-sub { margin: -0.3rem 0 0.8rem; font-size: 0.82rem; color: var(--muted, #8892a6); }
    /* Grille 2 colonnes : plus dense, tout se voit d'un coup d'œil. */
    .kq-grid {
        display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.9rem; align-items: stretch;
    }
    .kq-card {
        display: flex; flex-direction: column; gap: 0.45rem;
        padding: 1rem 1.1rem;
        background: rgba(255, 255, 255, 0.035);
        border: 1px solid rgba(255, 255, 255, 0.07);
        border-radius: 14px;
        min-width: 0;
    }
    .kq-card.is-main { grid-column: 1 / -1; border-color: rgba(72, 189, 211, 0.45); background: rgba(72, 189, 211, 0.06); }
    .kq-card.is-admin { border-left: 4px solid rgba(97, 80, 170, 0.65); }
    .kq-head { display: flex; align-items: baseline; gap: 0.5rem; flex-wrap: wrap; }
    .kq-emoji { font-size: 1.25rem; }
    .kq-label { font-size: 1.02rem; font-weight: 900; }
    .kq-badge {
        font-size: 0.68rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em;
        color: var(--primary, #48bdd3); background: rgba(72, 189, 211, 0.14);
        border-radius: 999px; padding: 0.1rem 0.55rem;
    }
    .kq-desc { margin: 0; font-size: 0.85rem; color: var(--muted, #8892a6); }
    .kq-row { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin-top: auto; }
    .kq-row input {
        flex: 1 1 200px; min-width: 0; padding: 0.5rem 0.7rem;
        border: 1px solid var(--border); border-radius: 8px;
        background: rgba(255, 255, 255, 0.04); color: var(--foreground);
        font-size: 0.8rem; font-family: monospace;
    }
    @media (max-width: 860px) {
        .kq-grid { grid-template-columns: 1fr; }
    }
    .kq-note { font-size: 0.85rem; color: var(--muted, #8892a6); margin: 0 0 1rem; max-width: 720px; }
    .kq-note strong { color: var(--foreground); }
</style>

<div class="compta-head">
    <div>
        <p class="eyebrow">Système</p>
        <h1 class="page-title">Kiosques</h1>
        <p class="muted">Tous les liens d'accès téléphone, sans connexion, en deux catégories.</p>
    </div>
</div>

<div class="card surface glass" style="padding: 0.95rem 1.1rem; margin-bottom: 1.4rem;">
    <p class="kq-note">
        L'authentification du kiosque <strong>est le lien lui-même</strong> : ne partage les liens
        « Membres » qu'aux membres du bureau. Chaque action (comptage, pause, case cochée) exige
        l'identité du membre (prénom, nom, rôle) et est tracée en son nom. Les liens « Admins »
        donnent accès aux <strong>données financières</strong> — récap complet, analytics, bilan.
        La régénération du lien <strong>révoque tous les liens d'un coup</strong>.
        Les liens sont <strong>masqués à l'écran</strong> : affiche seulement celui que tu utilises —
        chaque affichage est tracé dans le journal d'audit.
    </p>
    <form method="post" action="<?= e(url('/admin/kiosques/regenerate')) ?>"
          data-confirm="Régénérer le lien MEMBRES ? Tous les liens membres déjà partagés (hub, comptages, liste, ventes du jour) cesseront de fonctionner."
          data-confirm-button="Régénérer membres">
        <?= csrf_field() ?>
        <input type="hidden" name="scope" value="membres">
        <button type="submit" class="btn btn-outline btn-sm">Régénérer le lien membres</button>
    </form>
    <form method="post" action="<?= e(url('/admin/kiosques/regenerate')) ?>" style="margin-top: 0.5rem;"
          data-confirm="Régénérer le lien ADMIN ? Tous les liens financiers déjà copiés (hub admin, récaps) cesseront de fonctionner."
          data-confirm-button="Régénérer admins">
        <?= csrf_field() ?>
        <input type="hidden" name="scope" value="admins">
        <button type="submit" class="btn btn-outline btn-sm">Régénérer le lien admins</button>
    </form>
</div>

<section class="kq-section">
    <h2 class="kq-section-title">Membres — à partager</h2>
    <p class="kq-section-sub">Outils de saisie et suivi léger : aucun bénéfice ni donnée financière sensible.</p>

    <div class="kq-grid">
    <?php foreach ($memberPages as $p): ?>
    <div class="kq-card<?= !empty($p['main']) ? ' is-main' : '' ?>">
        <div class="kq-head">
            <span class="kq-emoji"><?= $p['emoji'] ?></span>
            <span class="kq-label"><?= e($p['label']) ?></span>
            <?php if (!empty($p['main'])): ?><span class="kq-badge">à partager</span><?php endif; ?>
        </div>
        <p class="kq-desc"><?= e($p['desc']) ?></p>
        <div class="kq-row" data-kq-scope="<?= e($p['scope']) ?>" data-kq-path="<?= e($p['path']) ?>">
            <input type="text" readonly value="••••••••" onclick="this.select()"
                   aria-label="Lien <?= e($p['label']) ?> — masqué, utilise le bouton « Afficher le lien »">
            <button type="button" class="btn btn-outline btn-sm kq-reveal" aria-expanded="false">Afficher le lien</button>
            <button type="button" class="btn btn-outline btn-sm kq-copy">Copier</button>
            <a class="btn btn-ghost btn-sm kq-open" hidden>Ouvrir ↗</a>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
</section>

<section class="kq-section">
    <h2 class="kq-section-title">Admins — pour ton téléphone</h2>
    <p class="kq-section-sub">Données financières complètes. Les pages /admin demandent la connexion admin (mémorisée sur ton téléphone) ; les liens kiosque marchent sans connexion.</p>

    <div class="kq-grid">
    <?php foreach ($adminPages as $p): ?>
    <div class="kq-card is-admin<?= !empty($p['main']) ? ' is-main' : '' ?>">
        <div class="kq-head">
            <span class="kq-emoji"><?= $p['emoji'] ?></span>
            <span class="kq-label"><?= e($p['label']) ?></span>
            <?php if (!empty($p['main'])): ?><span class="kq-badge">à garder sous la main</span><?php endif; ?>
            <?php if (empty($p['kiosk'])): ?><span class="kq-badge" style="color:#a78bfa;background:rgba(97,80,170,0.16)">connexion requise</span><?php endif; ?>
        </div>
        <p class="kq-desc"><?= e($p['desc']) ?></p>
        <?php if (!empty($p['kiosk'])): ?>
        <div class="kq-row" data-kq-scope="<?= e((string) $p['scope']) ?>" data-kq-path="<?= e((string) $p['path']) ?>">
            <input type="text" readonly value="••••••••" onclick="this.select()"
                   aria-label="Lien <?= e($p['label']) ?> — masqué, utilise le bouton « Afficher le lien »">
            <button type="button" class="btn btn-outline btn-sm kq-reveal" aria-expanded="false">Afficher le lien</button>
            <button type="button" class="btn btn-outline btn-sm kq-copy">Copier</button>
            <a class="btn btn-ghost btn-sm kq-open" hidden>Ouvrir ↗</a>
        </div>
        <?php else: ?>
        <div class="kq-row">
            <input type="text" readonly value="<?= e(url((string) $p['url'])) ?>" onclick="this.select()"
                   aria-label="Lien <?= e($p['label']) ?>">
            <button type="button" class="btn btn-outline btn-sm kq-copy" data-url="<?= e(url((string) $p['url'])) ?>">Copier</button>
            <a class="btn btn-ghost btn-sm" href="<?= e(url((string) $p['url'])) ?>" target="_blank">Ouvrir ↗</a>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
</section>

<script>
(function () {
    'use strict';
    var REVEAL_URL = <?= json_encode(url('/admin/kiosques/reveal'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var MASK = '••••••••';
    var csrfInput = document.querySelector('input[name="_csrf"]');

    function rowsOf(card) { return card.querySelectorAll('.kq-row[data-kq-path]'); }

    function applyUrl(row, url) {
        row.querySelector('input').value = url;
        row.querySelector('.kq-copy').setAttribute('data-url', url);
        var open = row.querySelector('.kq-open');
        open.setAttribute('href', url);
        open.removeAttribute('hidden');
    }

    function clearUrl(row, btn) {
        row.querySelector('input').value = MASK;
        row.querySelector('.kq-copy').removeAttribute('data-url');
        var open = row.querySelector('.kq-open');
        open.removeAttribute('href');
        open.setAttribute('hidden', '');
        btn.setAttribute('aria-expanded', 'false');
        btn.textContent = 'Afficher le lien';
    }

    function fetchUrl(row) {
        var fd = new FormData();
        fd.append('_csrf', csrfInput ? csrfInput.value : '');
        fd.append('scope', row.getAttribute('data-kq-scope') || '');
        fd.append('path', row.getAttribute('data-kq-path') || '');
        return fetch(REVEAL_URL, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                return (d && d.ok && d.url) ? String(d.url) : null;
            })
            .catch(function () { return null; });
    }

    function flash(btn, label) {
        var old = btn.textContent;
        btn.textContent = label;
        setTimeout(function () { btn.textContent = old; }, 1500);
    }

    /* Révélation : POST CSRF, l'URL complète n'arrive qu'en réponse. */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.kq-reveal');
        if (!btn) return;
        var row = btn.closest('.kq-row');
        if (!row) return;

        if (btn.getAttribute('aria-expanded') === 'true') {
            clearUrl(row, btn);
            return;
        }

        fetchUrl(row).then(function (url) {
            if (!url) {
                flash(btn, 'Indisponible');
                return;
            }
            applyUrl(row, url);
            btn.setAttribute('aria-expanded', 'true');
            btn.textContent = 'Masquer le lien';
        });
    });

    /* Copie : après révélation ; sinon révèle d'abord, copie ensuite. */
    document.addEventListener('click', function (e) {
        var b = e.target.closest('.kq-copy');
        if (!b) return;
        var url = b.getAttribute('data-url');

        var copy = function (u) {
            var done = function () { flash(b, 'Copié ✓'); };
            if (navigator.clipboard) {
                navigator.clipboard.writeText(u).then(done, done);
            } else {
                var input = b.closest('.kq-row').querySelector('input');
                input.select();
                try { document.execCommand('copy'); } catch (err) {}
                done();
            }
        };

        if (url) {
            copy(url);
            return;
        }

        var row = b.closest('.kq-row');
        if (!row || !row.hasAttribute('data-kq-path')) return;
        flash(b, 'Révélation…');
        fetchUrl(row).then(function (u) {
            if (!u) {
                flash(b, 'Indisponible');
                return;
            }
            var reveal = row.querySelector('.kq-reveal');
            if (reveal) {
                applyUrl(row, u);
                reveal.setAttribute('aria-expanded', 'true');
                reveal.textContent = 'Masquer le lien';
            }
            copy(u);
        });
    });
})();
</script>
