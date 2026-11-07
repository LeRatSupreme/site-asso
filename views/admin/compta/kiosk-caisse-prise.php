<?php

declare(strict_types=1);

/**
 * Kiosque ADMIN — prise « chez soi » : version téléphone de la carte
 * « Prise chez soi » de la page Caisses (Système → Caisses). Mêmes
 * écritures serveur (caissePriseSave) : mouvement PRISE signé (négatif
 * = pris chez soi, positif = ramené) + lignes de coupures — le montant
 * n'est JAMAIS saisi à la main, il est déduit du détail (Σ unit×qty).
 *
 * Exigence kiosque : ULTRA SIMPLE — steppers ≥ 44 px, police ≥ 16 px
 * (leçon iOS : en dessous, Safari zoome au focus), total XXL en direct,
 * récap AVANT confirmation (panneau DANS la page — jamais alert() ni
 * confirm()). L'identité (pastille profil du layout kiosk) est exigée à
 * l'enregistrement : le layout bloque toute soumission sans identité
 * complète et injecte who_prenom / who_nom / who_alias.
 *
 * @var string $token
 */
?>
<style>
    .kp-form { max-width: 480px; }
    .kp-form .field { margin-bottom: 1rem; }
    .kp-form .field > label,
    .kp-who-title { display: block; margin-bottom: 0.4rem; font-weight: 800; }
    .kp-form input[type="text"] {
        width: 100%; padding: 0.8rem 0.9rem;
        border: 1px solid var(--border, rgba(255,255,255,0.15));
        border-radius: 12px; background: rgba(255, 255, 255, 0.04);
        color: var(--foreground, inherit); font-size: 1.15rem; font-weight: 700;
    }
    /* Qui prend l'argent : miroir de la pastille profil du layout
       (unique source de vérité — jamais un second champ à remplir). */
    .kp-who {
        display: flex; align-items: center; justify-content: space-between; gap: 0.6rem;
        padding: 0.7rem 0.9rem; border-radius: 12px;
        border: 1px dashed var(--border, rgba(255,255,255,0.25));
        background: rgba(255, 255, 255, 0.03);
    }
    .kp-who-name { font-size: 1rem; font-weight: 800; }
    .kp-who-name.is-empty { color: var(--muted, #8892a6); font-weight: 600; }
    /* Bascule de sens : deux grosses options, l'une clairement active. */
    .kp-sens-field {
        border: none; padding: 0; margin: 0 0 1rem;
        display: grid; gap: 0.4rem;
    }
    .kp-sens-field legend { font-weight: 800; margin-bottom: 0.4rem; padding: 0; }
    .kp-sens { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.5rem; }
    .kp-sens-opt { display: block; cursor: pointer; }
    .kp-sens-opt input { position: absolute; opacity: 0; pointer-events: none; }
    .kp-sens-opt span {
        display: grid; gap: 0.2rem; justify-items: center;
        min-height: 44px; padding: 0.7rem 0.5rem;
        border: 2px solid var(--border, rgba(255,255,255,0.15));
        border-radius: 14px; background: rgba(255, 255, 255, 0.04);
        text-align: center; font-weight: 800; font-size: 1rem; line-height: 1.25;
    }
    .kp-sens-opt span small { font-size: 0.78rem; font-weight: 600; color: var(--muted, #8892a6); }
    .kp-sens-opt:has(input:checked) span {
        border-color: var(--primary, #48bdd3); background: rgba(72, 189, 211, 0.12);
    }
    .kp-sens-opt:has(input:checked) span small { color: var(--primary, #48bdd3); }
    .kp-sens-opt input:focus-visible + span { outline: 2px solid var(--primary, #48bdd3); outline-offset: 2px; }
    /* Récap en direct (le chiffre qui compte avant de confirmer). */
    .kp-recap {
        margin: 0.4rem 0 1rem; padding: 0.9rem 1rem; border-radius: 14px;
        border: 1px solid var(--border, rgba(255,255,255,0.12));
        background: rgba(255, 255, 255, 0.03);
    }
    .kp-recap[hidden] { display: none; }
    .kp-recap-main { margin: 0; font-size: 1.1rem; font-weight: 900; }
    .kp-recap-detail, .kp-recap-who { margin: 0.3rem 0 0; font-size: 0.85rem; color: var(--muted, #8892a6); }
    .kp-recap-empty { margin: 0; font-size: 0.9rem; color: var(--muted, #8892a6); }
    /* Confirmation DANS la page (jamais de confirm()). */
    .kp-confirm {
        position: fixed; inset: 0; z-index: 8700;
        display: flex; align-items: center; justify-content: center; padding: 1rem;
        background: rgba(4, 10, 20, 0.72);
    }
    .kp-confirm[hidden] { display: none; }
    .kp-confirm-card {
        width: 100%; max-width: 380px; padding: 1.2rem;
        background: #0e2036; border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 16px; box-shadow: 0 18px 50px rgba(0, 0, 0, 0.45);
    }
    .kp-confirm-card h2 { margin: 0 0 0.7rem; font-size: 1.15rem; font-weight: 900; }
    .kp-confirm-list { margin: 0 0 1rem; padding: 0; list-style: none; display: grid; gap: 0.45rem; }
    .kp-confirm-list li { font-size: 0.95rem; line-height: 1.4; }
    .kp-confirm-actions { display: flex; justify-content: flex-end; gap: 0.5rem; }
    .kp-confirm-actions .btn { min-height: 44px; }
    .kp-actions { margin: 0.2rem 0 0.6rem; }
    .kp-actions .btn { width: 100%; padding: 0.9rem 1rem; font-size: 1.05rem; }
</style>

<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité — accès admin</p>
        <h1 class="page-title">Prise chez soi</h1>
        <p class="muted">L'argent quitte le tiroir pour dormir chez un membre — ou revient. Compté par coupures, retrouvé dans le livre.</p>
    </div>
</div>

<section class="card surface glass">
    <h2 class="card-title">Prise ou retour d'argent</h2>
    <p class="card-meta">Le montant est déduit des coupures : compte ce qui part (ou revient), le total s'affiche tout seul.</p>
    <form method="post" action="<?= e(url('/kiosque/admin/caisse/prise/' . rawurlencode($token))) ?>" class="kp-form" id="kp-form">
        <div class="field">
            <p class="kp-who-title">Qui prend l'argent&nbsp;? <span class="muted">(obligatoire)</span></p>
            <div class="kp-who">
                <span class="kp-who-name is-empty" id="kp-who-name">— identité à renseigner —</span>
                <button type="button" class="btn btn-outline btn-sm" id="kp-who-btn">Modifier</button>
            </div>
        </div>

        <fieldset class="kp-sens-field">
            <legend>Sens</legend>
            <div class="kp-sens" role="radiogroup" aria-label="Sens de l'opération">
                <label class="kp-sens-opt">
                    <input type="radio" name="sens" value="prise" checked>
                    <span>🏠 Pris chez soi<small>caisse → chez moi</small></span>
                </label>
                <label class="kp-sens-opt">
                    <input type="radio" name="sens" value="retour">
                    <span>↩️ Ramené<small>chez moi → caisse</small></span>
                </label>
            </div>
        </fieldset>

        <div class="field">
            <p class="kp-who-title">Coupures <span class="muted">(obligatoires)</span></p>
            <?php
            $ccId = 'kp';
            $ccMode = 'amount';
            $ccAmountInput = null;
            $ccSubmitBtn = 'kp-review';
            $ccBig = true;
            require AEIC_VIEWS . '/admin/compta/_cash_coupures.php';
            ?>
        </div>

        <?php datetime_selects_field('date', 'kp-date'); ?>

        <div class="field">
            <label for="kp-note">Note <span class="muted">(optionnel)</span></label>
            <input type="text" id="kp-note" name="label" placeholder="ex : prise fin de soirée">
        </div>

        <div class="kp-recap" id="kp-recap" hidden>
            <p class="kp-recap-main" id="kp-recap-main"></p>
            <p class="kp-recap-detail" id="kp-recap-detail" hidden></p>
            <p class="kp-recap-who" id="kp-recap-who" hidden></p>
        </div>
        <p class="kp-recap-empty" id="kp-recap-empty">Compte les coupures — le récap s'affichera ici.</p>

        <div class="kp-actions">
            <button type="button" class="btn btn-primary" id="kp-review" disabled>Vérifier avant d'enregistrer</button>
        </div>

        <div class="kp-confirm" id="kp-confirm" hidden>
            <div class="kp-confirm-card" role="dialog" aria-modal="true" aria-labelledby="kp-confirm-title">
                <h2 id="kp-confirm-title">On récapitule</h2>
                <ul class="kp-confirm-list" id="kp-confirm-list"></ul>
                <div class="kp-confirm-actions">
                    <button type="button" class="btn btn-ghost" id="kp-back">Modifier</button>
                    <button type="submit" class="btn btn-primary" id="kp-ok">Confirmer</button>
                </div>
            </div>
        </div>
    </form>
</section>

<p class="shop-footnote" style="display: flex; gap: 0.5rem; justify-content: center; flex-wrap: wrap;">
    <a class="btn btn-ghost btn-sm" href="<?= e(url('/kiosque/admin/' . rawurlencode($token))) ?>">← Retour au kiosque admin</a>
    <a class="btn btn-outline btn-sm" href="<?= e(url('/kiosque/admin/caisse/depot/' . rawurlencode($token))) ?>">🏦 Dépôt banque</a>
</p>

<script>
/* Prise « chez soi » (kiosque) : miroir de l'identité (pastille profil
   du layout — window.KiosqueWho), récap en direct piloté par l'évènement
   « cashcoupures » du pavé partagé, puis confirmation DANS LA PAGE avant
   l'envoi réel (jamais alert()/confirm()). */
(function () {
    'use strict';
    var form = document.getElementById('kp-form');
    var grid = document.getElementById('kp-cc');
    var detailEl = document.getElementById('kp-cc-detail');
    var recap = document.getElementById('kp-recap');
    var recapEmpty = document.getElementById('kp-recap-empty');
    var recapMain = document.getElementById('kp-recap-main');
    var recapDetail = document.getElementById('kp-recap-detail');
    var recapWho = document.getElementById('kp-recap-who');
    var reviewBtn = document.getElementById('kp-review');
    var confirmBox = document.getElementById('kp-confirm');
    var confirmList = document.getElementById('kp-confirm-list');
    var backBtn = document.getElementById('kp-back');
    var okBtn = document.getElementById('kp-ok');
    var whoName = document.getElementById('kp-who-name');
    var whoBtn = document.getElementById('kp-who-btn');
    if (!form || !grid || !reviewBtn || !confirmBox) return;

    var last = { cents: 0 };

    function fmt(cents) {
        cents = Math.abs(cents);
        var e = Math.floor(cents / 100);
        var c = cents % 100;
        return String(e).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ',' + (c < 10 ? '0' : '') + c + ' €';
    }

    function sens() {
        var r = form.querySelector('input[name="sens"]:checked');
        return r ? r.value : 'prise';
    }

    function sensLabel(s) {
        return s === 'retour' ? '↩️ Ramené de chez soi' : '🏠 Pris chez soi';
    }

    function who() {
        return window.KiosqueWho && window.KiosqueWho.ok()
            ? window.KiosqueWho.fields().who
            : '';
    }

    function refreshWho() {
        var w = who();
        if (!whoName) return;
        whoName.textContent = w !== '' ? w : '— identité à renseigner —';
        whoName.classList.toggle('is-empty', w === '');
    }

    function refreshRecap() {
        var has = last.cents > 0;
        var s = sens();
        recap.hidden = !has;
        recapEmpty.hidden = has;
        if (!has) return;
        recapMain.textContent = (s === 'retour' ? 'Tu ramènes ' : 'Tu prends ') + fmt(last.cents);
        var detail = detailEl && !detailEl.hidden ? detailEl.textContent : '';
        recapDetail.textContent = detail !== '' ? 'Coupures : ' + detail : '';
        recapDetail.hidden = detail === '';
        var w = who();
        recapWho.textContent = w !== '' ? 'Par ' + w : '';
        recapWho.hidden = w === '';
    }

    grid.addEventListener('cashcoupures', function (e) {
        last.cents = e.detail.cents;
        refreshRecap();
    });

    Array.prototype.forEach.call(form.querySelectorAll('input[name="sens"]'), function (r) {
        r.addEventListener('change', refreshRecap);
    });

    if (whoBtn) {
        whoBtn.addEventListener('click', function () {
            if (window.KiosqueWho) window.KiosqueWho.open();
        });
    }
    document.addEventListener('kiosque-who-saved', function () {
        refreshWho();
        refreshRecap();
    });

    reviewBtn.addEventListener('click', function () {
        if (reviewBtn.disabled) return;
        var s = sens();
        var w = who();
        confirmList.textContent = '';
        [
            ['Sens', sensLabel(s)],
            ['Montant', (s === 'retour' ? 'Ramené : ' : 'Pris : ') + fmt(last.cents)],
            ['Coupures', detailEl && !detailEl.hidden ? detailEl.textContent : '—'],
            ['Qui', w !== '' ? w : '—']
        ].forEach(function (pair) {
            var li = document.createElement('li');
            var b = document.createElement('strong');
            b.textContent = pair[0] + ' : ';
            li.appendChild(b);
            li.appendChild(document.createTextNode(pair[1]));
            confirmList.appendChild(li);
        });
        confirmBox.hidden = false;
        if (okBtn) okBtn.focus();
    });

    function closeConfirm() {
        confirmBox.hidden = true;
        reviewBtn.focus();
    }
    if (backBtn) backBtn.addEventListener('click', closeConfirm);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !confirmBox.hidden) closeConfirm();
    });

    refreshWho();
    refreshRecap();
})();
</script>
