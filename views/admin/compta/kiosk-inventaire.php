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

    .ksec { margin-bottom: 1.3rem; }
    .ksec-title {
        display: flex; align-items: center; gap: 0.5rem;
        margin: 0 0.15rem 0.5rem; font-size: 0.95rem; font-weight: 800;
        text-transform: uppercase; letter-spacing: 0.06em;
    }
    .ksec-title .ksec-count {
        font-size: 0.7rem; font-weight: 800; color: var(--muted, #8892a6);
        background: rgba(255, 255, 255, 0.06); border-radius: 999px; padding: 0.1rem 0.55rem;
    }
    .klist {
        list-style: none; margin: 0; padding: 0.2rem;
        display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
        gap: 0.55rem;
    }
    .krow {
        display: flex; flex-direction: column; gap: 0.45rem;
        padding: 0.6rem 0.55rem;
        border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 12px;
        transition: opacity 0.15s ease;
    }
    .krow.is-counted { background: rgba(72, 189, 211, 0.06); border-color: rgba(72, 189, 211, 0.25); }
    .kname {
        font-size: 0.92rem; font-weight: 800; margin: 0;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .kstep { display: flex; align-items: stretch; gap: 0.35rem; }
    .kbtn {
        width: 42px; height: 44px; flex-shrink: 0;
        font-size: 1.25rem; font-weight: 900; line-height: 1;
        border: 1px solid var(--border, rgba(255,255,255,0.15));
        border-radius: 10px; background: rgba(255, 255, 255, 0.06);
        color: var(--foreground, inherit); cursor: pointer;
        user-select: none; -webkit-user-select: none; -webkit-tap-highlight-color: transparent;
        transition: background 0.12s ease, border-color 0.12s ease;
    }
    .kbtn:active { background: rgba(72, 189, 211, 0.2); border-color: var(--primary, #48bdd3); }
    .kinput {
        flex: 1; min-width: 0; text-align: center;
        padding: 0.4rem 0.3rem;
        border: 1px solid var(--border, rgba(255,255,255,0.15));
        border-radius: 10px; background: rgba(255, 255, 255, 0.05);
        color: var(--foreground, inherit);
        font-size: 1.15rem; font-weight: 800;
    }
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
    <section class="ksec">
        <h2 class="ksec-title">
            <?= e($cat) ?>
            <span class="ksec-count"><?= count($items) ?></span>
        </h2>
        <ul class="klist">
            <?php foreach ($items as $r): ?>
            <li class="krow" data-name="<?= e(mb_strtolower($r['key'])) ?>" title="<?= e($r['key']) ?>">
                <p class="kname"><?= e($r['key']) ?></p>
                <div class="kstep">
                    <button type="button" class="kbtn" data-step="-1" aria-label="Retirer 1 à <?= e($r['key']) ?>">−</button>
                    <input type="number" class="kinput" name="count[<?= e($r['key']) ?>]"
                           min="0" step="1" inputmode="numeric" placeholder="0"
                           aria-label="Quantité comptée de <?= e($r['key']) ?>">
                    <button type="button" class="kbtn" data-step="1" aria-label="Ajouter 1 à <?= e($r['key']) ?>">+</button>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endforeach; ?>

    <?php if ($active === []): ?>
    <p class="knone">Aucun produit enregistré pour le moment.</p>
    <?php endif; ?>

    <?php if ($paused !== []): ?>
    <section class="ksec">
        <h2 class="ksec-title">En pause (hors comptage)</h2>
        <ul class="klist">
            <?php foreach ($paused as $r): ?>
            <li class="krow is-paused" data-name="<?= e(mb_strtolower($r['key'])) ?>">
                <p class="kname"><?= e($r['key']) ?></p>
                <span class="kpaused">en pause</span>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <div class="kform-actions">
        <button type="submit" class="btn btn-primary">Enregistrer le comptage</button>
    </div>
</form>

<p class="shop-footnote">
    <nav>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('/kiosque/comptage/' . rawurlencode($token))) ?>">← Retour au comptage</a>
        <a class="btn btn-outline btn-sm" href="<?= e(url('/kiosque/comptage/caisse/' . rawurlencode($token))) ?>">💵 Passer au comptage caisse</a>
    </nav>
</p>

<script>
(function () {
    var rows = document.querySelectorAll('#kList ~ * .krow, .ksec .krow');
    var search = document.getElementById('kq');
    var progTxt = document.getElementById('kProgTxt');
    var saveTxt = document.getElementById('kSaveTxt');

    var form = document.querySelector('form');
    var saveTimer = null;
    var dirty = false;

    function countFilled() {
        var n = 0;
        Array.prototype.forEach.call(document.querySelectorAll('.kinput'), function (i) {
            if (i.value !== '') n++;
        });
        return n;
    }

    function refresh() {
        var n = countFilled();
        if (progTxt) progTxt.innerHTML = '<strong>' + n + '</strong> produit(s) compté(s)';
        Array.prototype.forEach.call(document.querySelectorAll('.krow'), function (row) {
            var input = row.querySelector('.kinput');
            if (input) row.classList.toggle('is-counted', input.value !== '');
        });
    }

    /* Sauvegarde automatique : 2 s après la dernière saisie, le formulaire
       part en fetch (même POST que le bouton — seules les lignes remplies
       sont enregistrées côté serveur). Tu peux verrouiller/reprendre sans
       rien perdre ; le bouton « Enregistrer » reste disponible. */
    function autoSave() {
        if (!dirty || !form) return;
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

    // Boutons − / + : ajustent la quantité sans clavier.
    Array.prototype.forEach.call(document.querySelectorAll('.kbtn'), function (btn) {
        btn.addEventListener('click', function () {
            var input = btn.closest('.kstep').querySelector('.kinput');
            var step = parseInt(btn.getAttribute('data-step'), 10) || 0;
            var v = parseInt(input.value, 10);
            if (isNaN(v)) { v = 0; }
            v = Math.max(0, v + step);
            input.value = (v === 0 && input.value === '' && step < 0) ? '' : v;
            refresh();
            markDirty();
        });
    });

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
                sec.classList.toggle('khidden', hasInputs && visible === 0);
            });
        });
    }

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
