<?php

declare(strict_types=1);

/**
 * Kiosque — comptage inventaire « à l'aveugle » : les théoriques ne sont
 * volontairement pas affichés, les écarts sont calculés à l'enregistrement.
 * Interface facilitée : recherche instantanée, gros boutons − / +,
 * compteur de produits comptés. Les produits en pause sont listés sans
 * saisie (ignorés côté serveur).
 *
 * @var string $token
 * @var list<array{key:string,paused:bool}> $rows
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

    .klist { list-style: none; margin: 0; padding: 0; }
    .krow {
        padding: 0.7rem 0.5rem; border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        transition: opacity 0.15s ease;
    }
    .krow.is-counted { background: rgba(72, 189, 211, 0.06); border-radius: 12px; border-bottom-color: transparent; }
    .kname { font-size: 1.02rem; font-weight: 800; word-break: break-word; margin: 0 0 0.5rem; }
    .kstep { display: flex; align-items: stretch; gap: 0.45rem; }
    .kbtn {
        width: 56px; height: 50px; flex-shrink: 0;
        font-size: 1.5rem; font-weight: 900; line-height: 1;
        border: 1px solid var(--border, rgba(255,255,255,0.15));
        border-radius: 12px; background: rgba(255, 255, 255, 0.06);
        color: var(--foreground, inherit); cursor: pointer;
        user-select: none; -webkit-user-select: none; -webkit-tap-highlight-color: transparent;
        transition: background 0.12s ease, border-color 0.12s ease;
    }
    .kbtn:active { background: rgba(72, 189, 211, 0.2); border-color: var(--primary, #48bdd3); }
    .kinput {
        flex: 1; min-width: 0; text-align: center;
        padding: 0.5rem 0.5rem;
        border: 1px solid var(--border, rgba(255,255,255,0.15));
        border-radius: 12px; background: rgba(255, 255, 255, 0.05);
        color: var(--foreground, inherit);
        font-size: 1.35rem; font-weight: 800;
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

    <p class="kprog"><span id="kProgTxt"></span><span>laisse vide = non compté</span></p>

    <ul class="klist" id="kList">
        <?php $activeRows = array_filter($rows, static fn(array $r): bool => empty($r['paused'])); ?>
        <?php $pausedRows = array_filter($rows, static fn(array $r): bool => !empty($r['paused'])); ?>
        <?php foreach ($activeRows as $r): ?>
        <li class="krow" data-name="<?= e(mb_strtolower($r['key'])) ?>">
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
        <?php if ($activeRows === []): ?>
        <li class="knone">Aucun produit enregistré pour le moment.</li>
        <?php endif; ?>
        <?php foreach ($pausedRows as $r): ?>
        <li class="krow is-paused" data-name="<?= e(mb_strtolower($r['key'])) ?>">
            <p class="kname"><?= e($r['key']) ?></p>
            <span class="kpaused">en pause (hors comptage)</span>
        </li>
        <?php endforeach; ?>
    </ul>

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
    var rows = document.querySelectorAll('#kList .krow');
    var search = document.getElementById('kq');
    var progTxt = document.getElementById('kProgTxt');

    function countFilled() {
        var n = 0;
        Array.prototype.forEach.call(document.querySelectorAll('#kList .kinput'), function (i) {
            if (i.value !== '') n++;
        });
        return n;
    }

    function refresh() {
        var n = countFilled();
        if (progTxt) progTxt.innerHTML = '<strong>' + n + '</strong> produit(s) compté(s)';
        Array.prototype.forEach.call(rows, function (row) {
            var input = row.querySelector('.kinput');
            if (input) row.classList.toggle('is-counted', input.value !== '');
        });
    }

    // Boutons − / + : ajustent la quantité sans clavier.
    Array.prototype.forEach.call(document.querySelectorAll('.kbtn'), function (btn) {
        btn.addEventListener('click', function () {
            var input = btn.closest('.kstep').querySelector('.kinput');
            var step = parseInt(btn.getAttribute('data-step'), 10) || 0;
            var v = parseInt(input.value, 10);
            if (isNaN(v)) { v = 0; }
            v = Math.max(0, v + step);
            input.value = v === 0 && input.value === '' && step < 0 ? '' : v;
            refresh();
        });
    });

    // Recherche instantanée sur le nom du produit.
    if (search) {
        search.addEventListener('input', function () {
            var q = search.value.trim().toLowerCase();
            Array.prototype.forEach.call(rows, function (row) {
                var name = row.getAttribute('data-name') || '';
                row.classList.toggle('khidden', q !== '' && name.indexOf(q) === -1);
            });
        });
    }

    // Saisie clavier : suivi du comptage.
    Array.prototype.forEach.call(document.querySelectorAll('#kList .kinput'), function (input) {
        input.addEventListener('input', refresh);
    });

    refresh();
})();
</script>
