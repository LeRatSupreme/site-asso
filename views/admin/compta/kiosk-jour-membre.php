<?php

declare(strict_types=1);

/**
 * Kiosque — ventes du jour, version MEMBRES : uniquement le CA et les
 * produits vendus (quantités). Ni bénéfice, ni paiements, ni CA/produit.
 * Lecture seule, auto-actualisée toutes les 60 s (pas de bouton).
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
    .km-wrap { max-width: 46rem; margin: 0 auto; }

    .km-hero {
        text-align: center;
        padding: 2.2rem 1.4rem 2rem;
        background: linear-gradient(160deg, rgba(72, 189, 211, 0.14), rgba(97, 80, 170, 0.08));
        border: 1px solid rgba(72, 189, 211, 0.28);
        border-radius: 24px;
        margin-bottom: 1rem;
    }
    .km-hero-label {
        font-size: 0.85rem; font-weight: 800; text-transform: uppercase;
        letter-spacing: 0.08em; color: var(--muted, #8892a6); margin: 0 0 0.6rem;
    }
    .km-hero-value {
        font-size: clamp(3rem, 10vw, 4.2rem); font-weight: 900; line-height: 1;
        color: var(--primary, #48bdd3); letter-spacing: -0.02em;
    }
    .km-hero-sub { margin: 0.8rem 0 0; font-size: 1rem; color: var(--muted, #8892a6); }
    .km-hero-sub strong { color: var(--foreground, inherit); font-size: 1.15rem; }

    .km-row {
        display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.9rem; margin-bottom: 1rem;
    }
    .km-card {
        padding: 1.2rem 1.1rem; text-align: center;
        background: rgba(255, 255, 255, 0.035);
        border: 1px solid rgba(255, 255, 255, 0.07);
        border-radius: 20px;
    }
    .km-card-label {
        font-size: 0.78rem; font-weight: 800; text-transform: uppercase;
        letter-spacing: 0.07em; color: var(--muted, #8892a6); margin: 0 0 0.45rem;
    }
    .km-card-value { font-size: 2.1rem; font-weight: 900; line-height: 1; }

    .km-top-card {
        padding: 1.3rem 1.3rem 1rem;
        background: rgba(255, 255, 255, 0.035);
        border: 1px solid rgba(255, 255, 255, 0.07);
        border-radius: 20px;
        margin-bottom: 1rem;
    }
    .km-top-title {
        display: flex; align-items: center; gap: 0.5rem;
        font-size: 0.82rem; font-weight: 800; text-transform: uppercase;
        letter-spacing: 0.07em; color: var(--muted, #8892a6); margin: 0 0 0.7rem;
    }
    .km-top { list-style: none; margin: 0; padding: 0; }
    .km-top li {
        display: flex; align-items: center; gap: 0.75rem;
        padding: 0.65rem 0.2rem; border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        font-size: 1rem;
    }
    .km-top li:last-child { border-bottom: none; }
    .km-rank {
        flex-shrink: 0; width: 30px; height: 30px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.8rem; font-weight: 900;
        background: rgba(72, 189, 211, 0.12); color: var(--primary, #48bdd3);
    }
    .km-top li:first-child .km-rank { background: rgba(72, 189, 211, 0.28); }
    .km-top-name {
        flex: 1; min-width: 0; font-weight: 700;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .km-top-qty { font-weight: 900; color: var(--primary, #48bdd3); white-space: nowrap; }
    .km-top-ca {
        font-weight: 800; font-size: 0.85rem; white-space: nowrap;
        color: var(--foreground, inherit); min-width: 64px; text-align: right;
    }

    .kday-meta {
        text-align: center; font-size: 0.78rem; color: var(--muted, #8892a6);
        margin: 0.2rem 0 1rem;
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
</style>

<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité</p>
        <h1 class="page-title">Ventes du jour</h1>
        <p class="muted"><?= e(ucfirst($dateLabel)) ?> — le suivi en direct de la cafétéria.</p>
    </div>
</div>

<div class="km-wrap">
    <div class="km-hero">
        <p class="km-hero-label">Ventes du jour (TTC)</p>
        <div class="km-hero-value" id="kmCa"><?= e(formatPrice((float) $stats['ca'])) ?></div>
        <p class="km-hero-sub"><strong id="kmQty"><?= (int) $stats['qty'] ?></strong> produits vendus aujourd'hui</p>
    </div>

    <div class="km-top-card">
        <p class="km-top-title">🏆 Produits vendus aujourd'hui (<?= count($stats['top']) ?>)</p>
        <ul class="km-top" id="kmTop">
            <?php foreach ($stats['top'] as $i => $t): ?>
            <li>
                <span class="km-rank"><?= $i + 1 ?></span>
                <span class="km-top-name" title="<?= e($t['label']) ?>"><?= e($t['label']) ?></span>
                <span class="km-top-qty">×<?= (int) $t['qty'] ?></span>
                <span class="km-top-ca"><?= e(formatPrice((float) $t['ca'])) ?></span>
            </li>
            <?php endforeach; ?>
            <?php if ($stats['top'] === []): ?>
            <li class="muted">Aucune vente pour le moment.</li>
            <?php endif; ?>
        </ul>
    </div>

    <p class="kday-meta"><span class="kday-live"></span>Mise à jour automatique toutes les 60 s — dernière : <span id="kmAt"><?= e((string) $stats['computed_at']) ?></span></p>

    <div class="kday-actions">
        <a class="btn btn-ghost btn-sm" href="<?= e(url('/kiosque/comptage/' . rawurlencode($token))) ?>">← Retour au comptage</a>
    </div>
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
                j.top.forEach(function (t, i) {
                    var li = document.createElement('li');

                    var rank = document.createElement('span');
                    rank.className = 'km-rank';
                    rank.textContent = String(i + 1);

                    var name = document.createElement('span');
                    name.className = 'km-top-name';
                    name.textContent = t.label;
                    name.title = t.label;

                    var qty = document.createElement('span');
                    qty.className = 'km-top-qty';
                    qty.textContent = '×' + t.qty;

                    var ca = document.createElement('span');
                    ca.className = 'km-top-ca';
                    ca.textContent = price(t.ca);

                    li.appendChild(rank);
                    li.appendChild(name);
                    li.appendChild(qty);
                    li.appendChild(ca);
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

    setInterval(refresh, 60000);
})();
</script>
