<?php

declare(strict_types=1);

/**
 * Kiosque — ventes du jour, version MEMBRES : uniquement le CA et les
 * produits vendus (quantités). Ni bénéfice, ni paiements, ni CA/produit.
 * Lecture seule, auto-actualisée toutes les 60 s.
 *
 * @var string $token
 * @var array<string,mixed> $stats ca, qty, top (label, qty), computed_at, date
 */
$jours = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
$mois = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
$dt = new DateTimeImmutable('today', new DateTimeZone('Europe/Paris'));
$dateLabel = $jours[(int) $dt->format('N') - 1] . ' ' . $dt->format('j') . ' ' . $mois[(int) $dt->format('n') - 1] . ' ' . $dt->format('Y');
?>
<style>
    .kday-grid {
        display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.8rem; max-width: 40rem; margin: 0 auto 1rem;
    }
    .kday-card {
        padding: 1.1rem 1rem; text-align: center;
        background: rgba(255, 255, 255, 0.035);
        border: 1px solid rgba(255, 255, 255, 0.07);
        border-radius: 18px;
    }
    .kday-card.is-wide { grid-column: 1 / -1; }
    .kday-label {
        font-size: 0.78rem; font-weight: 800; text-transform: uppercase;
        letter-spacing: 0.06em; color: var(--muted, #8892a6); margin: 0 0 0.35rem;
    }
    .kday-value { font-size: 2rem; font-weight: 900; line-height: 1.05; }
    .kday-value.is-ca { color: var(--primary, #48bdd3); }
    .kday-top { list-style: none; margin: 0; padding: 0; text-align: left; }
    .kday-top li {
        display: flex; justify-content: space-between; gap: 0.6rem;
        padding: 0.4rem 0.15rem; border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        font-size: 0.9rem;
    }
    .kday-top li:last-child { border-bottom: none; }
    .kday-top .kday-top-qty { font-weight: 900; color: var(--primary, #48bdd3); white-space: nowrap; }
    .kday-meta {
        text-align: center; font-size: 0.78rem; color: var(--muted, #8892a6);
        margin: 0.4rem 0 1rem;
    }
    .kday-live {
        display: inline-block; width: 9px; height: 9px; border-radius: 50%;
        background: #4ade80; margin-right: 0.35rem;
        animation: kday-pulse 2s ease-in-out infinite;
    }
    @keyframes kday-pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.35; } }
    .kday-actions {
        max-width: 40rem; margin: 0 auto;
        display: flex; gap: 0.5rem; flex-wrap: wrap; justify-content: center;
    }
    @media (max-width: 480px) {
        .kday-grid { grid-template-columns: 1fr; }
        .kday-value { font-size: 2.4rem; }
    }
</style>

<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité</p>
        <h1 class="page-title">Ventes du jour</h1>
        <p class="muted"><?= e(ucfirst($dateLabel)) ?> — le suivi en direct de la cafétéria.</p>
    </div>
</div>

<div class="kday-grid">
    <div class="kday-card is-wide">
        <p class="kday-label">Ventes du jour (TTC)</p>
        <div class="kday-value is-ca" id="kmCa"><?= e(formatPrice((float) $stats['ca'])) ?></div>
    </div>
    <div class="kday-card is-wide">
        <p class="kday-label">Produits vendus</p>
        <div class="kday-value" id="kmQty"><?= (int) $stats['qty'] ?></div>
    </div>
    <div class="kday-card is-wide">
        <p class="kday-label">Top produits du jour</p>
        <ul class="kday-top" id="kmTop">
            <?php foreach ($stats['top'] as $t): ?>
            <li>
                <span><?= e($t['label']) ?></span>
                <span class="kday-top-qty">×<?= (int) $t['qty'] ?></span>
            </li>
            <?php endforeach; ?>
            <?php if ($stats['top'] === []): ?>
            <li class="muted">Aucune vente pour le moment.</li>
            <?php endif; ?>
        </ul>
    </div>
</div>

<p class="kday-meta"><span class="kday-live"></span>Actualisation auto toutes les 60 s — dernière : <span id="kmAt"><?= e((string) $stats['computed_at']) ?></span></p>

<div class="kday-actions">
    <button type="button" class="btn btn-outline btn-sm" id="kmRefresh">↻ Actualiser</button>
    <a class="btn btn-ghost btn-sm" href="<?= e(url('/kiosque/comptage/' . rawurlencode($token))) ?>">← Retour au comptage</a>
</div>

<script>
(function () {
    'use strict';
    var DATA_URL = <?= json_encode(url('/kiosque/comptage/jour-membre/data/' . $token)) ?>;

    function price(n) { return Number(n).toFixed(2).replace('.', ',') + ' €'; }
    function set(id, text) { var el = document.getElementById(id); if (el) el.textContent = text; }

    function render(j) {
        if (!j || !j.ok) return;
        set('kmCa', price(j.ca));
        set('kmQty', String(j.qty));
        set('kmAt', j.computed_at);

        var top = document.getElementById('kmTop');
        if (top) {
            top.innerHTML = '';
            if (!j.top || j.top.length === 0) {
                var li = document.createElement('li');
                li.className = 'muted';
                li.textContent = 'Aucune vente pour le moment.';
                top.appendChild(li);
            } else {
                j.top.forEach(function (t) {
                    var li = document.createElement('li');
                    var left = document.createElement('span');
                    left.textContent = t.label;
                    var right = document.createElement('span');
                    right.className = 'kday-top-qty';
                    right.textContent = '×' + t.qty;
                    li.appendChild(left);
                    li.appendChild(right);
                    top.appendChild(li);
                });
            }
        }
    }

    function refresh() {
        fetch(DATA_URL, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(render)
            .catch(function () { /* offline : on retentera */ });
    }

    document.getElementById('kmRefresh').addEventListener('click', refresh);
    setInterval(refresh, 60000);
})();
</script>
