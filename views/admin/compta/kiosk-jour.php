<?php

declare(strict_types=1);

/**
 * Kiosque ADMIN — récap du jour : CA, bénéfice, paiements, top produits
 * de la journée en cours (heure de Paris). Mêmes calculs que le dashboard
 * analytics (perso inclus dans le CA, exclus du bénéfice). Lecture seule,
 * auto-actualisée toutes les 60 s.
 *
 * @var string $token
 * @var array<string,mixed> $stats
 */
$jours = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
$mois = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
$dt = new DateTimeImmutable('today', new DateTimeZone('Europe/Paris'));
$dateLabel = $jours[(int) $dt->format('N') - 1] . ' ' . $dt->format('j') . ' ' . $mois[(int) $dt->format('n') - 1] . ' ' . $dt->format('Y');
?>
<style>
    .kx-wrap { max-width: 44rem; margin: 0 auto; }
    .kx-hero {
        text-align: center;
        padding: 1.7rem 1rem 1.5rem; margin-bottom: 0.7rem;
        background: linear-gradient(160deg, rgba(72, 189, 211, 0.16), rgba(97, 80, 170, 0.08));
        border: 1px solid rgba(72, 189, 211, 0.3);
        border-radius: 22px;
    }
    .kx-hero-label {
        font-size: 0.8rem; font-weight: 800; text-transform: uppercase;
        letter-spacing: 0.08em; color: var(--muted, #8892a6); margin: 0 0 0.5rem;
    }
    .kx-hero-value {
        font-size: clamp(2.3rem, 9vw, 3rem); font-weight: 900; line-height: 1;
        color: var(--primary, #48bdd3); letter-spacing: -0.02em;
    }
    .kx-hero-sub { margin: 0.75rem 0 0; font-size: 0.88rem; color: var(--muted, #8892a6); }
    .kx-hero-sub strong { color: var(--foreground, inherit); }

    .kx-pair {
        display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.7rem; margin-bottom: 0.7rem;
    }
    .kx-card {
        padding: 1.1rem 0.6rem; text-align: center;
        background: rgba(255, 255, 255, 0.035);
        border: 1px solid rgba(255, 255, 255, 0.07);
        border-radius: 18px;
    }
    .kx-card.is-wide { padding: 1.15rem 0.9rem; }
    .kx-card-label {
        font-size: 0.75rem; font-weight: 800; text-transform: uppercase;
        letter-spacing: 0.07em; color: var(--muted, #8892a6); margin: 0 0 0.4rem;
    }
    .kx-card-value { font-size: 1.55rem; font-weight: 900; line-height: 1.1; }
    .kx-card-value.is-profit { color: #4ade80; }
    .kx-card-value.is-loss { color: #f87171; }
    .kx-card-sub { font-size: 0.72rem; color: var(--muted, #8892a6); margin: 0.35rem 0 0; }

    .kx-list-card {
        padding: 1.15rem 1rem 0.9rem; margin-bottom: 0.7rem;
        background: rgba(255, 255, 255, 0.035);
        border: 1px solid rgba(255, 255, 255, 0.07);
        border-radius: 18px;
    }
    .kx-list { list-style: none; margin: 0; padding: 0; }
    .kx-list li {
        display: flex; align-items: center; gap: 0.7rem;
        padding: 0.55rem 0.15rem; border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        font-size: 0.95rem;
    }
    .kx-list li:last-child { border-bottom: none; }
    .kx-rank {
        flex-shrink: 0; width: 28px; height: 28px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.76rem; font-weight: 900;
        background: rgba(72, 189, 211, 0.12); color: var(--primary, #48bdd3);
    }
    .kx-list li:first-child .kx-rank { background: rgba(72, 189, 211, 0.28); }
    .kx-list-name { flex: 1; min-width: 0; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .kx-list-qty { color: var(--muted, #8892a6); white-space: nowrap; }
    .kx-list-ca { font-weight: 800; white-space: nowrap; min-width: 60px; text-align: right; }

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
        <p class="eyebrow">Comptabilité — accès admin</p>
        <h1 class="page-title">Récap du jour</h1>
        <p class="muted"><?= e(ucfirst($dateLabel)) ?> — uniquement la journée en cours, chiffres du dashboard.</p>
    </div>
</div>

<div class="kx-wrap">
    <div class="kx-hero">
        <p class="kx-hero-label">Chiffre d'affaires du jour (TTC)</p>
        <div class="kx-hero-value" id="kdayCa"><?= e(formatPrice((float) $stats['ca'])) ?></div>
        <p class="kx-hero-sub"><strong><?= e(formatPrice((float) $stats['ca_products'])) ?></strong> produits · <strong><?= (int) $stats['transactions'] ?></strong> transactions</p>
    </div>

    <div class="kx-pair">
        <div class="kx-card">
            <p class="kx-card-label">Bénéfice</p>
            <div class="kx-card-value <?= (float) $stats['profit'] >= 0 ? 'is-profit' : 'is-loss' ?>" id="kdayProfit">
                <?= e(formatPrice((float) $stats['profit'])) ?>
            </div>
            <p class="kx-card-sub">perso exclus</p>
        </div>
        <div class="kx-card">
            <p class="kx-card-label">Produits vendus</p>
            <div class="kx-card-value" id="kdayQty"><?= (int) $stats['qty'] ?></div>
        </div>
    </div>

    <div class="kx-pair">
        <div class="kx-card">
            <p class="kx-card-label">💵 Liquide</p>
            <div class="kx-card-value" id="kdayLiquide"><?= e(formatPrice((float) $stats['liquide'])) ?></div>
        </div>
        <div class="kx-card">
            <p class="kx-card-label">💳 Carte</p>
            <div class="kx-card-value" id="kdayCarte"><?= e(formatPrice((float) $stats['carte'])) ?></div>
        </div>
    </div>

    <div class="kx-list-card">
        <p class="kx-card-label">🏆 Top produits du jour</p>
        <ul class="kx-list" id="kdayTop">
            <?php foreach ($stats['top'] as $i => $t): ?>
            <li>
                <span class="kx-rank"><?= $i + 1 ?></span>
                <span class="kx-list-name" title="<?= e((string) $t['label']) ?>"><?= e((string) $t['label']) ?></span>
                <span class="kx-list-qty">×<?= (int) $t['qty'] ?></span>
                <span class="kx-list-ca"><?= e(formatPrice((float) $t['ca'])) ?></span>
            </li>
            <?php endforeach; ?>
            <?php if ($stats['top'] === []): ?>
            <li class="muted">Aucune vente pour le moment.</li>
            <?php endif; ?>
        </ul>
    </div>

    <p class="kday-meta"><span class="kday-live"></span>Actualisation auto toutes les 60 s — dernière : <span id="kdayAt"><?= e((string) $stats['computed_at']) ?></span></p>

    <div class="kday-actions">
        <a class="btn btn-ghost btn-sm" href="<?= e(url('/kiosque/admin/' . rawurlencode($token))) ?>">← Kiosque admin</a>
    </div>
</div>

<script>
(function () {
    'use strict';
    var DATA_URL = <?= json_encode(url('/kiosque/comptage/jour/data/' . $token)) ?>;

    function price(n) { return Number(n).toFixed(2).replace('.', ',') + ' €'; }
    function set(id, text) { var el = document.getElementById(id); if (el) el.textContent = text; }

    function render(j) {
        if (!j || !j.ok) return;
        set('kdayCa', price(j.ca));
        set('kdayQty', String(j.qty));
        set('kdayLiquide', price(j.liquide));
        set('kdayCarte', price(j.carte));
        set('kdayAt', j.computed_at);

        var sub = document.querySelector('.kx-hero-sub');
        if (sub) {
            sub.innerHTML = '<strong>' + price(j.ca_products) + '</strong> produits · <strong>' + j.transactions + '</strong> transactions';
        }

        var p = document.getElementById('kdayProfit');
        if (p) {
            p.textContent = price(j.profit);
            p.classList.toggle('is-profit', Number(j.profit) >= 0);
            p.classList.toggle('is-loss', Number(j.profit) < 0);
        }

        var top = document.getElementById('kdayTop');
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
                    rank.className = 'kx-rank';
                    rank.textContent = String(i + 1);

                    var name = document.createElement('span');
                    name.className = 'kx-list-name';
                    name.textContent = t.label;
                    name.title = t.label;

                    var qty = document.createElement('span');
                    qty.className = 'kx-list-qty';
                    qty.textContent = '×' + t.qty;

                    var ca = document.createElement('span');
                    ca.className = 'kx-list-ca';
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
