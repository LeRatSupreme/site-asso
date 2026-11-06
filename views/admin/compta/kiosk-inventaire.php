<?php

declare(strict_types=1);

/**
 * Kiosque — comptage inventaire « à l'aveugle » : les théoriques ne sont
 * volontairement pas affichés, les écarts sont calculés à l'enregistrement.
 * Tri par catégorie, recherche instantanée, gros boutons − / +. Les
 * produits en pause sont listés sans saisie (ignorés côté serveur).
 *
 * @var string $token
 * @var list<array{key:string,cat:string,paused:bool}> $active
 * @var list<array{key:string,cat:string,paused:bool}> $paused
 * @var array<string,int> $packs Taille de pack par clé produit (option — exposée par le contrôleur ; absente/vide : saisie en unités seule, D4).
 */
?>
<style>
    .ksearch { position: relative; margin-bottom: 0.6rem; }
    .ksearch input {
        width: 100%; padding: 0.8rem 0.9rem 0.8rem 2.4rem;
        border: 1px solid var(--border, rgba(255,255,255,0.15));
        border-radius: 12px; background: rgba(255, 255, 255, 0.05);
        color: var(--foreground, inherit); font-size: 1.05rem; font-weight: 600;
    }
    .ksearch::before {
        content: '🔍'; position: absolute; left: 0.7rem; top: 50%;
        transform: translateY(-50%); font-size: 0.95rem; opacity: 0.7;
    }

    .kprog {
        display: flex; align-items: center; justify-content: space-between; gap: 0.8rem;
        font-size: 0.85rem; color: var(--muted, #8892a6); margin: 0 0 0.5rem;
    }
    .kprog strong { color: var(--primary, #48bdd3); }

    .ksec { margin-bottom: 1.7rem; }
    .ksec-title {
        display: flex; align-items: center; gap: 0.5rem;
        margin: 0 0 0.65rem; padding: 0.5rem 0.65rem;
        font-size: 0.95rem; font-weight: 800;
        text-transform: uppercase; letter-spacing: 0.06em;
        color: var(--foreground, inherit);
        background: rgba(255, 255, 255, 0.055);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-left: 4px solid var(--primary, #48bdd3);
        border-radius: 10px;
        position: sticky; top: 0; z-index: 4;
        backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
    }
    .ksec-title .ksec-count {
        font-size: 0.7rem; font-weight: 800; color: var(--muted, #8892a6);
        background: rgba(255, 255, 255, 0.08); border-radius: 999px; padding: 0.1rem 0.55rem;
    }
    .klist {
        list-style: none; margin: 0; padding: 0.2rem;
        display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
        gap: 0.55rem;
    }
    .krow {
        position: relative;
        display: flex; flex-direction: column; gap: 0.45rem;
        padding: 0.6rem 0.55rem;
        border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 12px;
        transition: opacity 0.15s ease;
    }
    .krow.is-counted { background: rgba(72, 189, 211, 0.06); border-color: rgba(72, 189, 211, 0.25); }
    .kname {
        font-size: 0.92rem; font-weight: 800; margin: 0;
        padding-right: 48px;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .kpause {
        position: absolute; top: 6px; right: 6px;
        width: 44px; height: 44px; border-radius: 50%;
        border: 1px solid var(--border, rgba(255,255,255,0.15));
        background: rgba(255, 255, 255, 0.05);
        color: var(--muted, #8892a6); font-size: 0.8rem; line-height: 1;
        cursor: pointer; padding: 0;
        user-select: none; -webkit-tap-highlight-color: transparent;
    }
    .kpause:hover { color: var(--foreground, inherit); border-color: var(--primary, #48bdd3); }
    .kpause:disabled { opacity: 0.4; }
    .kinput {
        width: 100%; text-align: center;
        padding: 0.55rem 0.4rem;
        border: 1px solid var(--border, rgba(255,255,255,0.15));
        border-radius: 10px; background: rgba(255, 255, 255, 0.05);
        color: var(--foreground, inherit);
        font-size: 1.3rem; font-weight: 800;
    }
    /* Produit livré en packs : badge discret + second champ « ×N » (packs
       entiers, ajoutés aux unités isolées — conversion côté serveur). */
    .kpack {
        align-self: flex-start;
        font-size: 0.72rem; font-weight: 800;
        color: var(--primary, #48bdd3);
        background: rgba(72, 189, 211, 0.14);
        border: 1px solid rgba(72, 189, 211, 0.35);
        border-radius: 999px; padding: 0.12rem 0.5rem;
    }
    .kinputs { display: flex; gap: 0.4rem; }
    .kinputs .kinput { flex: 1; min-width: 0; }
    .krow.is-paused { opacity: 0.4; }
    .krow.is-paused .kname { margin-bottom: 0; font-weight: 600; }
    .krow.is-paused .kpaused { font-size: 0.8rem; color: var(--muted, #8892a6); }
    .knone { padding: 2rem 0.5rem; text-align: center; color: var(--muted, #8892a6); }
    .khidden { display: none !important; }

    .kform-actions {
        position: sticky; bottom: 0; z-index: 5;
        padding: 0.8rem 0 0.4rem;
        background: linear-gradient(to top, var(--bg-admin, #0a1626) 65%, transparent);
    }
    .kform-actions .btn { width: 100%; padding: 0.9rem 1rem; font-size: 1.05rem; }

    .shop-footnote { text-align: center; margin-top: 1.75rem; }
    .shop-footnote nav { display: flex; gap: 0.5rem; justify-content: center; flex-wrap: wrap; }
</style>

<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité</p>
        <h1 class="page-title">Comptage inventaire</h1>
        <p class="muted">Comptage « à l'aveugle » : les stocks théoriques ne sont pas affichés. Recherche un produit, ajuste avec − / +, enregistre.</p>
    </div>
</div>

<form method="post" action="<?= e(url('/kiosque/comptage/inventaire/save/' . rawurlencode($token))) ?>">
    <?= csrf_field() ?>

    <div class="ksearch">
        <input type="search" id="kq" placeholder="Rechercher un produit…" autocomplete="off" aria-label="Rechercher un produit">
    </div>

    <p class="kprog"><span id="kProgTxt"></span><span id="kSaveTxt">sauvegarde auto activée</span></p>

    <?php $groups = []; ?>
    <?php foreach ($active as $r) { $groups[$r['cat']][] = $r; } ?>
    <?php uksort($groups, static function (string $a, string $b): int { return strcasecmp($a, $b); }); ?>

    <?php foreach ($groups as $cat => $items): ?>
    <section class="ksec" data-cat="<?= e($cat) ?>">
        <h2 class="ksec-title">
            <?= e($cat) ?>
            <span class="ksec-count"><?= count($items) ?></span>
        </h2>
        <ul class="klist">
            <?php foreach ($items as $r): ?>
            <?php $packSize = (int) (($packs ?? [])[$r['key']] ?? 0); ?>
            <li class="krow" data-name="<?= e(mb_strtolower($r['key'])) ?>" data-key="<?= e($r['key']) ?>" data-cat="<?= e($cat) ?>" title="<?= e($r['key']) ?>">
                <p class="kname"><?= e($r['key']) ?></p>
                <?php if ($packSize > 1): ?>
                    <span class="kpack">pack de <?= $packSize ?></span>
                    <div class="kinputs">
                        <input type="number" class="kinput" name="count[<?= e($r['key']) ?>]"
                               min="0" step="1" inputmode="numeric" placeholder="0"
                               aria-label="Unités isolées comptées de <?= e($r['key']) ?>">
                        <input type="number" class="kinput" name="count_packs[<?= e($r['key']) ?>]"
                               min="0" step="1" inputmode="numeric" placeholder="×<?= $packSize ?>"
                               title="Packs comptés (1 pack = <?= $packSize ?> unités) — les packs s'ajoutent aux unités isolées"
                               aria-label="Packs de <?= $packSize ?> comptés pour <?= e($r['key']) ?>">
                    </div>
                <?php else: ?>
                    <input type="number" class="kinput" name="count[<?= e($r['key']) ?>]"
                           min="0" step="1" inputmode="numeric" placeholder="0"
                           aria-label="Quantité comptée de <?= e($r['key']) ?>">
                <?php endif; ?>
                <button type="button" class="kpause" data-key="<?= e($r['key']) ?>" data-state="pause"
                        title="Mettre en pause (sort du comptage)" aria-label="Mettre <?= e($r['key']) ?> en pause">⏸</button>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endforeach; ?>

    <?php if ($active === []): ?>
    <p class="knone">Aucun produit enregistré pour le moment.</p>
    <?php endif; ?>

    <section class="ksec<?= $paused === [] ? ' khidden' : '' ?>" id="kPausedSec">
        <h2 class="ksec-title">En pause (hors comptage)
            <span class="ksec-count" id="kPausedCount"><?= count($paused) ?></span>
        </h2>
        <ul class="klist" id="kPausedList">
            <?php foreach ($paused as $r): ?>
            <li class="krow is-paused" data-name="<?= e(mb_strtolower($r['key'])) ?>" data-key="<?= e($r['key']) ?>" title="<?= e($r['key']) ?>">
                <p class="kname"><?= e($r['key']) ?></p>
                <span class="kpaused">en pause</span>
                <button type="button" class="kpause" data-key="<?= e($r['key']) ?>" data-state="resume"
                        title="Remettre en comptage" aria-label="Remettre <?= e($r['key']) ?> en comptage">▶</button>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <div class="kform-actions">
        <button type="submit" class="btn btn-primary">Enregistrer le comptage</button>
    </div>
</form>

<p class="shop-footnote">
    <nav>
        <a class="btn btn-ghost btn-sm" href="<?= e(($hubUrl ?? '') !== '' ? $hubUrl : url('/kiosque/comptage/' . rawurlencode($token))) ?>">← Retour au comptage</a>
        <a class="btn btn-outline btn-sm" href="<?= e(url('/kiosque/comptage/caisse/' . rawurlencode($token))) ?>">💵 Passer au comptage caisse</a>
    </nav>
</p>

<?php /* Tailles de pack par clé : alimente le badge et le second champ du
         constructeur JS makeActiveLi (reprise d'un produit en pause). */ ?>
<script type="application/json" id="kPacks"><?= json_encode($packs ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<script>
(function () {
    var rows = document.querySelectorAll('#kList ~ * .krow, .ksec .krow');
    var search = document.getElementById('kq');
    var progTxt = document.getElementById('kProgTxt');
    var saveTxt = document.getElementById('kSaveTxt');

    var form = document.querySelector('form');
    var saveTimer = null;
    var dirty = false;

    /* Tailles de pack par clé produit (rendu serveur) : produit avec pack
       → second champ « ×N » (packs entiers ajoutés aux unités isolées). */
    var PACKS = {};
    (function () {
        var el = document.getElementById('kPacks');
        if (!el) return;
        try { PACKS = JSON.parse(el.textContent || '{}') || {}; } catch (e) { PACKS = {}; }
    })();

    function packSizeOf(key) {
        var n = parseInt(PACKS[key], 10);
        return isNaN(n) ? 0 : n;
    }

    function countFilled() {
        var n = 0;
        Array.prototype.forEach.call(document.querySelectorAll('.krow'), function (row) {
            var inputs = row.querySelectorAll('.kinput');
            for (var i = 0; i < inputs.length; i++) {
                if (inputs[i].value !== '') { n++; return; }
            }
        });
        return n;
    }

    function refresh() {
        var n = countFilled();
        if (progTxt) progTxt.innerHTML = '<strong>' + n + '</strong> produit(s) compté(s)';
        Array.prototype.forEach.call(document.querySelectorAll('.krow'), function (row) {
            var inputs = row.querySelectorAll('.kinput');
            var any = false;
            for (var i = 0; i < inputs.length; i++) {
                if (inputs[i].value !== '') { any = true; break; }
            }
            row.classList.toggle('is-counted', any);
        });
    }

    /* Sauvegarde automatique : 2 s après la dernière saisie, le formulaire
       part en fetch (même POST que le bouton — seules les lignes remplies
       sont enregistrées côté serveur). Tu peux verrouiller/reprendre sans
       rien perdre ; le bouton « Enregistrer » reste disponible.
       Aucune écriture sans identité complète (prénom, nom, rôle). */
    function autoSave() {
        if (!dirty || !form) return;
        if (window.KiosqueWho && !window.KiosqueWho.ok()) {
            window.KiosqueWho.open();
            return; /* dirty reste vrai : la saisie partira après l'identité */
        }
        dirty = false;
        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin'
        }).then(function () {
            if (saveTxt) saveTxt.textContent = '✓ sauvegardé à ' + new Date().toLocaleTimeString('fr-FR');
        }).catch(function () {
            dirty = true; /* réseau indisponible : on retentera à la prochaine saisie */
        });
    }

    function markDirty() {
        dirty = true;
        if (saveTxt) saveTxt.textContent = '…';
        clearTimeout(saveTimer);
        saveTimer = setTimeout(autoSave, 2000);
    }

    // Recherche instantanée sur le nom du produit.
    if (search) {
        search.addEventListener('input', function () {
            var q = search.value.trim().toLowerCase();
            Array.prototype.forEach.call(document.querySelectorAll('.krow'), function (row) {
                var name = row.getAttribute('data-name') || '';
                row.classList.toggle('khidden', q !== '' && name.indexOf(q) === -1);
            });
            Array.prototype.forEach.call(document.querySelectorAll('.ksec'), function (sec) {
                var visible = sec.querySelectorAll('.krow:not(.khidden)').length;
                var hasInputs = sec.querySelector('.kinput') !== null;
                var isPausedSec = sec.id === 'kPausedSec';
                sec.classList.toggle('khidden', hasInputs && visible === 0);
                if (isPausedSec) {
                    var total = sec.querySelectorAll('.krow').length;
                    var visibleRows = sec.querySelectorAll('.krow:not(.khidden)').length;
                    sec.classList.toggle('khidden', total === 0 || visibleRows === 0);
                }
            });
        });
    }

    /* -------- Pause / reprise d'un produit (dynamique, sans recharger) --- */
    var TOGGLE_URL = <?= json_encode(url('/kiosque/comptage/inventaire/pause/' . $token)) ?>;

    function whoFields() {
        if (window.KiosqueWho) return window.KiosqueWho.fields();
        return { who: '', who_prenom: '', who_nom: '', who_alias: '' };
    }

    function pauseBtn(key, state) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'kpause';
        b.setAttribute('data-key', key);
        b.setAttribute('data-state', state);
        b.title = state === 'pause' ? 'Mettre en pause (sort du comptage)' : 'Remettre en comptage';
        b.textContent = state === 'pause' ? '⏸' : '▶';
        return b;
    }

    function makePausedLi(key) {
        var li = document.createElement('li');
        li.className = 'krow is-paused';
        li.setAttribute('data-name', key.toLowerCase());
        li.setAttribute('data-key', key);
        li.title = key;
        var p = document.createElement('p');
        p.className = 'kname';
        p.textContent = key;
        var s = document.createElement('span');
        s.className = 'kpaused';
        s.textContent = 'en pause';
        li.appendChild(p);
        li.appendChild(s);
        li.appendChild(pauseBtn(key, 'resume'));
        return li;
    }

    function makeActiveLi(key, cat) {
        var li = document.createElement('li');
        li.className = 'krow';
        li.setAttribute('data-name', key.toLowerCase());
        li.setAttribute('data-key', key);
        li.setAttribute('data-cat', cat);
        li.title = key;
        var p = document.createElement('p');
        p.className = 'kname';
        p.textContent = key;
        var input = document.createElement('input');
        input.type = 'number';
        input.className = 'kinput';
        input.name = 'count[' + key + ']';
        input.min = '0';
        input.step = '1';
        input.setAttribute('inputmode', 'numeric');
        input.placeholder = '0';
        input.setAttribute('aria-label', 'Quantité comptée de ' + key);
        input.addEventListener('input', function () { refresh(); markDirty(); });
        li.appendChild(p);
        var size = packSizeOf(key);
        if (size > 1) {
            var badge = document.createElement('span');
            badge.className = 'kpack';
            badge.textContent = 'pack de ' + size;
            li.appendChild(badge);
            input.setAttribute('aria-label', 'Unités isolées comptées de ' + key);
            var packInput = document.createElement('input');
            packInput.type = 'number';
            packInput.className = 'kinput';
            packInput.name = 'count_packs[' + key + ']';
            packInput.min = '0';
            packInput.step = '1';
            packInput.setAttribute('inputmode', 'numeric');
            packInput.placeholder = '×' + size;
            packInput.title = 'Packs comptés (1 pack = ' + size + ' unités) — les packs s’ajoutent aux unités isolées';
            packInput.setAttribute('aria-label', 'Packs de ' + size + ' comptés pour ' + key);
            packInput.addEventListener('input', function () { refresh(); markDirty(); });
            var wrap = document.createElement('div');
            wrap.className = 'kinputs';
            wrap.appendChild(input);
            wrap.appendChild(packInput);
            li.appendChild(wrap);
        } else {
            li.appendChild(input);
        }
        li.appendChild(pauseBtn(key, 'pause'));
        return li;
    }

    function updateBadges() {
        Array.prototype.forEach.call(document.querySelectorAll('.ksec'), function (sec) {
            var badge = sec.querySelector('.ksec-count');
            if (!badge || sec.id === 'kPausedSec') return;
            badge.textContent = sec.querySelectorAll('.krow').length;
        });
        var pausedSec = document.getElementById('kPausedSec');
        var pausedCount = document.getElementById('kPausedCount');
        if (pausedSec && pausedCount) {
            var n = pausedSec.querySelectorAll('.krow').length;
            pausedCount.textContent = n;
            pausedSec.classList.toggle('khidden', n === 0);
        }
    }

    function moveRow(li, key, cat, nowPaused) {
        li.remove();
        if (nowPaused) {
            var pausedList = document.getElementById('kPausedList');
            if (pausedList) pausedList.appendChild(makePausedLi(key));
        } else {
            var target = null;
            Array.prototype.forEach.call(document.querySelectorAll('.ksec[data-cat]'), function (sec) {
                if (!target && sec.getAttribute('data-cat') === cat) target = sec;
            });
            if (!target) {
                var firstList = document.querySelector('.ksec .klist');
                if (firstList) target = firstList.closest('.ksec');
            }
            if (target) {
                var list = target.querySelector('.klist');
                if (list) list.appendChild(makeActiveLi(key, cat));
            }
        }
        updateBadges();
        refresh();
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.kpause');
        if (!btn) return;
        e.preventDefault();
        if (window.KiosqueWho && !window.KiosqueWho.ok()) {
            window.KiosqueWho.open();
            return;
        }
        var key = btn.getAttribute('data-key') || '';
        var state = btn.getAttribute('data-state') === 'resume' ? 'resume' : 'pause';
        var li = btn.closest('.krow');
        var cat = li ? (li.getAttribute('data-cat') || 'Divers') : 'Divers';

        var who = whoFields();
        var fd = new FormData();
        fd.append('key', key);
        fd.append('state', state);
        fd.append('who', who.who);
        fd.append('who_prenom', who.who_prenom);
        fd.append('who_nom', who.who_nom);
        fd.append('who_alias', who.who_alias);
        var csrf = document.querySelector('input[name="_csrf"]');
        if (csrf) fd.append('_csrf', csrf.value);

        btn.disabled = true;
        fetch(TOGGLE_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j && j.ok) {
                    moveRow(li, key, cat, state === 'pause');
                } else {
                    btn.disabled = false;
                    if (j && /identite/.test(j.error || '') && window.KiosqueWho) window.KiosqueWho.open();
                }
            })
            .catch(function () { btn.disabled = false; });
    });

    /* Après avoir renseigné l'identité : la saisie en attente part aussitôt. */
    document.addEventListener('kiosque-who-saved', function () {
        if (dirty) autoSave();
    });

    // Saisie clavier : suivi du comptage + auto-save.
    Array.prototype.forEach.call(document.querySelectorAll('.ksec .kinput'), function (input) {
        input.addEventListener('input', function () {
            refresh();
            markDirty();
        });
    });

    // Sécurité : sauvegarde finale si le téléphone se verrouille / onglet caché.
    window.addEventListener('pagehide', function () {
        if (dirty && form && navigator.sendBeacon) {
            navigator.sendBeacon(form.action, new FormData(form));
            dirty = false;
        }
    });

    refresh();
})();
</script>
