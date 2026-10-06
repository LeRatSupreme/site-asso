<?php

declare(strict_types=1);

/**
 * Liste de courses : produits à acheter uniquement, quantités arrondies
 * aux packs d'achat. ULTRA SIMPLE — une grille de carrés : coche, chiffre,
 * nom. Utilisable en kiosque (layout kiosk, sans navigation) sur téléphone.
 *
 * @var list<array<string,mixed>> $items
 * @var array<string,array{label:string,days:int}> $covers
 * @var string $coverKey
 * @var int    $totalUnits
 * @var float  $totalCost
 * @var bool   $kiosk
 * @var string $kioskUrl
 * @var string $kioskToken
 * @var string $adminWho
 */

use App\Models\Setting;
?>
<style>
    .shop-seg { display: flex; gap: 0.4rem; flex-wrap: wrap; margin: 0 0 1.2rem; }
    .shop-seg a {
        padding: 0.5rem 1rem; border-radius: 999px;
        border: 1px solid var(--border, rgba(255,255,255,0.15));
        background: rgba(255, 255, 255, 0.04); color: var(--foreground, inherit);
        font-weight: 700; font-size: 0.88rem; text-decoration: none; line-height: 1.3;
        transition: background 0.15s, border-color 0.15s, color 0.15s;
    }
    .shop-seg a:hover { border-color: var(--primary, #48bdd3); color: var(--primary, #48bdd3); }
    .shop-seg a.is-active {
        background: var(--primary, #48bdd3); border-color: var(--primary, #48bdd3);
        color: #06222b;
    }

    .shop-total {
        display: flex; align-items: center; gap: 1.4rem; flex-wrap: wrap;
        padding: 0.75rem 1.2rem; margin-bottom: 1.2rem;
        background: linear-gradient(135deg, rgba(72, 189, 211, 0.28), rgba(72, 189, 211, 0.08));
        border: 1px solid rgba(72, 189, 211, 0.35);
        border-radius: 14px; font-size: 0.95rem;
    }
    .shop-total strong { font-size: 1.1rem; color: var(--primary, #48bdd3); }

    .shop-grid {
        list-style: none; margin: 0; padding: 0;
        display: grid; grid-template-columns: repeat(auto-fill, minmax(165px, 1fr));
        gap: 0.7rem;
    }
    .shop-grid > .shop-item { min-width: 0; }
    .shop-item {
        position: relative;
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        gap: 0.3rem; text-align: center;
        aspect-ratio: 1 / 1; padding: 0.8rem 0.6rem;
        background: rgba(255, 255, 255, 0.035);
        border: 1px solid rgba(255, 255, 255, 0.07);
        border-radius: 18px;
        cursor: pointer; user-select: none; -webkit-user-select: none;
        -webkit-tap-highlight-color: transparent;
        transition: opacity 0.15s ease, border-color 0.15s ease, background 0.15s ease;
    }
    .shop-item:focus-visible { outline: 2px solid var(--primary, #48bdd3); outline-offset: 2px; }
    .shop-qty { font-size: 2.9rem; font-weight: 900; line-height: 1; color: var(--primary, #48bdd3); width: 100%; }
    .shop-qty.is-unknown { color: var(--muted, #8892a6); font-size: 2rem; }
    /* Nom en bloc simple : PAS de -webkit-box (Safari peut l'écraser à une
       lettre de large dans une tuile flex). La vignette grandit en hauteur
       si le nom est long — lisible avant tout. */
    .shop-name {
        font-size: 1.02rem; font-weight: 800; line-height: 1.2;
        width: 100%; min-width: 0;
        overflow-wrap: anywhere;
    }
    .shop-pack {
        font-size: 0.75rem; font-weight: 800; color: var(--primary, #48bdd3);
        background: rgba(72, 189, 211, 0.14); border-radius: 999px; padding: 0.12rem 0.6rem;
        white-space: nowrap; max-width: 100%; overflow: hidden; text-overflow: ellipsis;
    }
    /* Carré « acheté » : grisé, barré, petit ✓. */
    .shop-item.is-done {
        background: rgba(136, 146, 166, 0.14);
        border-color: rgba(255, 255, 255, 0.04);
        opacity: 0.55;
    }
    .shop-item.is-done .shop-name { text-decoration: line-through; color: var(--muted, #8892a6); }
    .shop-item.is-done .shop-qty { color: var(--muted, #8892a6); }
    .shop-item.is-done .shop-pack { color: var(--muted, #8892a6); background: rgba(136, 146, 166, 0.18); }
    .shop-item.is-done::after {
        content: '✓'; position: absolute; top: 0.4rem; right: 0.6rem;
        font-size: 1.2rem; font-weight: 900; color: var(--muted, #8892a6);
    }

    /* Bulles d'info : stock restant (vert, haut gauche) et conso / semaine
       (rouge, haut droite). Masquées quand la vignette est grisée. */
    .shop-stock, .shop-week {
        position: absolute; top: 0.45rem;
        min-width: 26px; height: 26px; padding: 0 7px;
        display: flex; align-items: center; justify-content: center;
        border-radius: 999px;
        font-size: 0.74rem; font-weight: 800;
    }
    .shop-stock {
        left: 0.45rem;
        background: rgba(34, 197, 94, 0.16);
        border: 1px solid rgba(34, 197, 94, 0.5);
        color: #4ade80;
    }
    .shop-week {
        right: 0.45rem;
        background: rgba(239, 68, 68, 0.12);
        border: 1px solid rgba(239, 68, 68, 0.4);
        color: #f87171;
    }
    .shop-item.is-done .shop-stock,
    .shop-item.is-done .shop-week { display: none; }

    /* Qui a coché (état partagé, rafraîchi en continu). */
    .shop-by {
        font-size: 0.66rem; font-weight: 700; color: var(--primary, #48bdd3);
        max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        min-height: 0;
    }
    .shop-by:empty { display: none; }

    .shop-empty { text-align: center; padding: 3.5rem 1rem; }
    .shop-empty-emoji { font-size: 3rem; margin-bottom: 0.6rem; }
    .shop-empty-title { font-size: 1.25rem; font-weight: 900; margin: 0 0 0.3rem; }
    .shop-empty-sub { color: var(--muted, #8892a6); margin: 0; }

    .shop-footnote { text-align: center; margin-top: 1.75rem; }
    .shop-footnote nav { display: flex; gap: 0.5rem; justify-content: center; flex-wrap: wrap; }

    .shop-legend {
        display: flex; align-items: center; justify-content: center; gap: 1.6rem;
        flex-wrap: wrap; margin-top: 1.5rem;
        font-size: 0.78rem; color: var(--muted, #8892a6);
    }
    .shop-legend .leg { display: inline-flex; align-items: center; gap: 0.45rem; }
    .shop-legend .dot { width: 13px; height: 13px; border-radius: 50%; display: inline-block; }
    .shop-legend .dot-stock { background: #4ade80; }
    .shop-legend .dot-week { background: rgba(239, 68, 68, 0.75); }

    @media print {
        .shop-seg, details, .compta-head .eyebrow { display: none !important; }
        body { background: #fff !important; color: #111 !important; }
        .card, .shop-item { background: #fff !important; border-color: #ddd !important; }
    }
</style>

<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité</p>
        <h1 class="page-title">Liste de courses</h1>
    </div>
</div>

<div class="shop-seg">
    <?php foreach ($covers as $k => $c): ?>
        <?php if ($kiosk): ?>
            <a class="<?= $k === $coverKey ? 'is-active' : '' ?>"
               href="<?= e(url('/kiosque/liste/' . $kioskToken) . '?c=' . $k) ?>"><?= e($c['label']) ?></a>
        <?php else: ?>
            <a class="<?= $k === $coverKey ? 'is-active' : '' ?>"
               href="<?= e(url('/admin/compta/liste') . '?c=' . $k) ?>"><?= e($c['label']) ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
</div>

<?php if ($items !== []): ?>
<div class="shop-total">
    <strong><?= (int) $totalUnits ?> unités</strong>
    <span>≈ <?= e(formatPrice($totalCost)) ?></span>
</div>
<?php endif; ?>

<?php if ($items === []): ?>
<section class="card surface glass shop-empty">
    <div class="shop-empty-emoji">🎉</div>
    <p class="shop-empty-title">Rien à racheter !</p>
    <p class="shop-empty-sub">Le stock couvre la période choisie. essaie « Couvrir pour » plus long.</p>
</section>
<?php else: ?>
<ul class="shop-grid">
    <?php foreach ($items as $r): $uid = 'sp-' . substr(md5((string) $r['name']), 0, 10); ?>
        <li class="shop-item" id="<?= e($uid) ?>"
            data-key="<?= e((string) $r['name']) ?>"
            role="button" tabindex="0" aria-pressed="false"
            title="Clique pour cocher (acheté) — re-clique pour décocher. Partagé avec tout le monde.">
            <span class="shop-stock" title="Stock théorique restant (dernier comptage + achats − ventes − pertes)"><?= (int) $r['stock'] ?></span>
            <span class="shop-week" title="Consommation par semaine (7 j/7)"><?= number_format((float) $r['avg_week'], (float) $r['avg_week'] >= 10 ? 0 : 1, ',', ' ') ?></span>
            <?php if ((int) $r['to_order'] > 0): ?>
            <span class="shop-qty"><?= (int) $r['to_order'] ?></span>
        <?php else: ?>
            <span class="shop-qty is-unknown" title="Stock épuisé — consommation inconnue sur la période">—</span>
        <?php endif; ?>
        <span class="shop-name" title="<?= e((string) $r['name']) ?>"><?= e((string) $r['name']) ?></span>
        <?php if ((int) $r['pack'] > 1): ?>
            <span class="shop-pack">pack de <?= (int) $r['pack'] ?></span>
        <?php endif; ?>
        <span class="shop-by"></span>
    </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<p class="shop-legend">
    <span class="leg"><span class="dot dot-stock"></span> stock restant</span>
    <span class="leg"><span class="dot dot-week"></span> consommé par semaine</span>
</p>

<?php if ($kiosk): ?>
<p class="shop-footnote">
    <nav>
        <a class="btn btn-ghost btn-sm" href="<?= e(($hubUrl ?? '') !== '' ? $hubUrl : url('/kiosque/comptage/' . rawurlencode($kioskToken))) ?>">← Retour au comptage</a>
        <a class="btn btn-outline btn-sm" href="<?= e(url('/kiosque/comptage/caisse/' . rawurlencode($kioskToken))) ?>">💵 Comptage caisse</a>
        <a class="btn btn-outline btn-sm" href="<?= e(url('/kiosque/comptage/inventaire/' . rawurlencode($kioskToken))) ?>">📦 Comptage inventaire</a>
    </nav>
</p>
<?php endif; ?>

<!-- Jeton CSRF pour les POST de coche/décoche (aucun formulaire visible
     sur cette page : le token vit ici, utilisé par le script ci-dessous). -->
<form method="post" style="display:none" aria-hidden="true"><?= csrf_field() ?></form>

<?php if (!$kiosk && ($user['role'] ?? '') === 'SUPERADMIN'): ?>
<details class="card surface glass" style="padding: 0.85rem 1.1rem; margin-top: 1.25rem;">
    <summary style="cursor: pointer; font-weight: 800;">📱 Accès téléphone sans connexion</summary>
    <p class="muted" style="margin: 0.6rem 0 0.4rem;">
        Copie ce lien secret sur ton téléphone : il ouvre cette liste directement,
        <strong>sans jamais demander de connexion</strong> (même jeton que la page Réappro — le régénérer révoque les deux).
    </p>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center;">
        <input type="text" readonly value="<?= e($kioskUrl) ?>" id="liste-kiosk-url" onclick="this.select()"
               style="flex: 1 1 260px; padding: 0.5rem 0.7rem; border: 1px solid var(--border); border-radius: 8px; background: rgba(255,255,255,0.04); color: var(--foreground); font-size: 0.85rem;">
        <button type="button" class="btn btn-outline btn-sm"
                onclick="(function (b) { var i = document.getElementById('liste-kiosk-url'); i.select(); try { document.execCommand('copy'); } catch (e) {} if (navigator.clipboard) { navigator.clipboard.writeText(i.value); } b.textContent = 'Copié ✓'; setTimeout(function () { b.textContent = 'Copier le lien'; }, 1500); })(this)">Copier le lien</button>
    </div>
</details>
<?php endif; ?>

<script>
(function () {
    'use strict';
    var CHECK_URL = <?= json_encode($kiosk ? url('/kiosque/liste/check/' . $kioskToken) : url('/admin/compta/liste/check')) ?>;
    var STATE_URL = <?= json_encode($kiosk ? url('/kiosque/liste/state/' . $kioskToken) : url('/admin/compta/liste/state')) ?>;

    var items = {};   /* clé produit → <li> */
    var pending = {}; /* coches locales en cours d'envoi (non écrasées par le refresh) */

    Array.prototype.forEach.call(document.querySelectorAll('.shop-item'), function (item) {
        items[item.getAttribute('data-key')] = item;
    });

    function whoFields() {
        <?php if ($kiosk): ?>
        if (window.KiosqueWho && window.KiosqueWho.ok()) return window.KiosqueWho.fields();
        return null; /* identité kiosque obligatoire */
        <?php else: ?>
        return { who: <?= json_encode($adminWho) ?>, who_prenom: <?= json_encode((string) ($user['prenom'] ?? '')) ?>, who_nom: <?= json_encode((string) ($user['nom'] ?? '')) ?>, who_alias: <?= json_encode((string) ($user['role'] ?? '')) ?> };
        <?php endif; ?>
    }

    function apply(item, checked, by, at) {
        item.classList.toggle('is-done', checked);
        item.setAttribute('aria-pressed', checked ? 'true' : 'false');
        item.querySelector('.shop-by').textContent = checked ? ('✓ ' + by + ' · ' + at) : '';
    }

    function post(key, checked) {
        var who = whoFields();
        var fd = new FormData();
        fd.append('key', key);
        fd.append('state', checked ? '1' : '0');
        fd.append('who', who.who);
        fd.append('who_prenom', who.who_prenom);
        fd.append('who_nom', who.who_nom);
        fd.append('who_alias', who.who_alias);
        var csrf = document.querySelector('input[name="_csrf"]');
        if (csrf) fd.append('_csrf', csrf.value);
        return fetch(CHECK_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); });
    }

    function toggle(item) {
        var key = item.getAttribute('data-key');
        <?php if ($kiosk): ?>
        if (window.KiosqueWho && !window.KiosqueWho.ok()) {
            window.KiosqueWho.open();
            return;
        }
        <?php endif; ?>
        var checked = !item.classList.contains('is-done');
        var who = whoFields();
        if (!who || who.who === '') { return; } /* pas d'identité : rien */

        pending[key] = true;
        apply(item, checked, who.who, new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }));
        post(key, checked).then(function (j) {
            if (!(j && j.ok)) {
                /* refus (ex. identité manquante) : retour à l'état serveur */
                apply(item, !checked, '', '');
                delete pending[key];
                refresh();
                return;
            }
            apply(item, j.checked, j.by || who.who, j.at);
            delete pending[key];
        }).catch(function () {
            delete pending[key];
            refresh();
        });
    }

    Array.prototype.forEach.call(document.querySelectorAll('.shop-item'), function (item) {
        item.addEventListener('click', function () { toggle(item); });
        item.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                toggle(item);
            }
        });
    });

    /* Synchronisation : toutes les 5 s, l'état partagé écrase l'affichage
       (sauf coches locales en cours d'envoi). Cocher ici se voit partout. */
    function refresh() {
        fetch(STATE_URL, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || !j.ok) return;
                var checks = j.checks || {};
                Object.keys(items).forEach(function (key) {
                    if (pending[key]) return;
                    var c = checks[key];
                    apply(items[key], !!c, c ? c.by : '', c ? c.at : '');
                });
            })
            .catch(function () { /* offline : on retentera */ });
    }
    setInterval(refresh, 5000);
    refresh();
})();
</script>
