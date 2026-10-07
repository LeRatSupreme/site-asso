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

    /* Delta coloré (comparaison avec hier). */
    .kday-delta.is-up { color: #4ade80; font-weight: 700; }
    .kday-delta.is-down { color: #f87171; font-weight: 700; }
    /* Bénéfice de la semaine coloré. */
    .kday-week-profit.is-pos { color: #4ade80; font-weight: 700; }
    .kday-week-profit.is-neg { color: #f87171; font-weight: 700; }

    /* Ventes par heure : barres adaptatives (34px + fluide + 74px →
       tient à 320px par construction). */
    .kday-hours { display: flex; flex-direction: column; gap: 3px; margin-top: 0.6rem; }
    .kday-hour-row { display: flex; align-items: center; gap: 0.5rem; height: 22px; }
    .kday-hour-label { flex: 0 0 34px; font-size: 0.72rem; color: var(--muted, #8892a6); }
    .kday-hour-track { flex: 1 1 auto; min-width: 0; height: 100%; display: flex; align-items: center; }
    .kday-hour-fill { display: block; height: 14px; min-width: 2px; border-radius: 3px; background: var(--primary, #48bdd3); }
    .kday-hour-val {
        flex: 0 0 74px; text-align: right;
        font-size: 0.78rem; font-variant-numeric: tabular-nums;
    }
    .kday-hour-empty { margin: 0.4rem 0 0; font-size: 0.85rem; color: var(--muted, #8892a6); }
</style>

<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité — accès admin</p>
        <h1 class="page-title">Récap du jour</h1>
        <p class="muted"><?= e(ucfirst($dateLabel)) ?> — uniquement la journée en cours, chiffres du dashboard.</p>
    </div>
</div>

<div class="kx-wrap">
    <?php
    // ---- Valeurs dérivées (jour, Europe/Paris) ----
    $fJour = (float) $stats['ca'];
    $fTransactions = (int) $stats['transactions'];
    $fPanier = $fTransactions > 0 ? round($fJour / $fTransactions, 2) : 0.0;
    $fYesterday = (float) ($stats['yesterday_ca'] ?? 0);
    $fDelta = $fYesterday > 0 ? (int) round(($fJour - $fYesterday) / $fYesterday * 100) : null;
    $fWeek = $stats['week'] ?? [];
    $fHours = $stats['hours'] ?? [];
    $fHoursMax = 0.0;
    foreach ($fHours as $row) {
        if ((float) $row['ca'] > $fHoursMax) {
            $fHoursMax = (float) $row['ca'];
        }
    }
    ?>
    <div class="kx-hero">
        <p class="kx-hero-label">Chiffre d'affaires du jour (TTC)</p>
        <div class="kx-hero-value" id="kdayCa"><?= e(formatPrice($fJour)) ?></div>
        <p class="kx-hero-sub"><strong><?= e(formatPrice((float) $stats['ca_products'])) ?></strong> produits · <strong><?= $fTransactions ?></strong> transactions · panier moyen <strong id="kdayBasket"><?= e(formatPrice($fPanier)) ?></strong></p>
    </div>

    <div class="kx-pair">
        <?php $mJour = $fJour > 0 ? round((float) $stats['profit'] / $fJour * 100, 1) : 0.0; ?>
        <div class="kx-card">
            <p class="kx-card-label">Bénéfice</p>
            <div class="kx-card-value <?= (float) $stats['profit'] >= 0 ? 'is-profit' : 'is-loss' ?>" id="kdayProfit">
                <?= e(formatPrice((float) $stats['profit'])) ?>
            </div>
            <p class="kx-card-sub"><span id="kdayMargin"><?= e(number_format($mJour, 1, ',', ' ')) ?> %</span> de marge · perso exclus</p>
            <?php if ($fDelta === null): ?>
            <p class="kx-card-sub" id="kdayYesterday">Hier : <?= e(formatPrice($fYesterday)) ?></p>
            <?php else: ?>
            <p class="kx-card-sub" id="kdayYesterday">Hier : <?= e(formatPrice($fYesterday)) ?>
                <span class="kday-delta <?= $fDelta >= 0 ? 'is-up' : 'is-down' ?>"><?= $fDelta >= 0 ? '↑ +' : '↓ ' ?><?= $fDelta ?> %</span>
            </p>
            <?php endif; ?>
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
            <p class="kx-card-sub" id="kdayLiquideTx"><?= (int) ($stats['liquide_tx'] ?? 0) ?> transaction<?= (int) ($stats['liquide_tx'] ?? 0) > 1 ? 's' : '' ?></p>
        </div>
        <div class="kx-card">
            <p class="kx-card-label">💳 Carte</p>
            <div class="kx-card-value" id="kdayCarte"><?= e(formatPrice((float) $stats['carte'])) ?></div>
            <p class="kx-card-sub" id="kdayCarteTx"><?= (int) ($stats['carte_tx'] ?? 0) ?> transaction<?= (int) ($stats['carte_tx'] ?? 0) > 1 ? 's' : '' ?></p>
        </div>
    </div>

    <?php $fWeekFrom = isset($fWeek['from']) ? \DateTimeImmutable::createFromFormat('Y-m-d', (string) $fWeek['from']) : false; ?>
    <?php $fWeekTo = isset($fWeek['to']) ? \DateTimeImmutable::createFromFormat('Y-m-d', (string) $fWeek['to']) : false; ?>
    <div class="kx-card is-wide" style="margin-bottom: 0.7rem;">
        <p class="kx-card-label">📅 Semaine en cours</p>
        <div class="kx-card-value" id="kdayWeekCa"><?= e(formatPrice((float) ($fWeek['ca'] ?? 0))) ?></div>
        <p class="kx-card-sub">
            <?php if ($fWeekFrom !== false && $fWeekTo !== false): ?>du <?= $fWeekFrom->format('d/m') ?> au <?= $fWeekTo->format('d/m') ?> · <?php endif; ?>bénéfice
            <span class="kday-week-profit <?= (float) ($fWeek['profit'] ?? 0) >= 0 ? 'is-pos' : 'is-neg' ?>" id="kdayWeekProfit"><?= e(formatPrice((float) ($fWeek['profit'] ?? 0))) ?></span>
        </p>
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

    <div class="kx-list-card">
        <p class="kx-card-label">⏰ Ventes par heure</p>
        <div class="kday-hours" id="kdayHours">
            <?php foreach ($fHours as $row): ?>
            <div class="kday-hour-row">
                <span class="kday-hour-label"><?= sprintf('%02dh', (int) $row['h']) ?></span>
                <span class="kday-hour-track"><span class="kday-hour-fill" style="width:<?= $fHoursMax > 0 ? round((float) $row['ca'] / $fHoursMax * 100, 2) : 0 ?>%"></span></span>
                <span class="kday-hour-val"><?= e(formatPrice((float) $row['ca'])) ?></span>
            </div>
            <?php endforeach; ?>
            <?php if ($fHours === []): ?>
            <p class="kday-hour-empty">Aucune vente pour le moment.</p>
            <?php endif; ?>
        </div>
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

        // Hero : produits + transactions + panier moyen (CA / transactions).
        var tx = Number(j.transactions) || 0;
        var panier = tx > 0 ? Math.round(Number(j.ca) / tx * 100) / 100 : 0;
        var sub = document.querySelector('.kx-hero-sub');
        if (sub) {
            sub.innerHTML = '<strong>' + price(j.ca_products) + '</strong> produits · <strong>' + tx + '</strong> transactions'
                + ' · panier moyen <strong id="kdayBasket">' + price(panier) + '</strong>';
        }

        var p = document.getElementById('kdayProfit');
        if (p) {
            p.textContent = price(j.profit);
            p.classList.toggle('is-profit', Number(j.profit) >= 0);
            p.classList.toggle('is-loss', Number(j.profit) < 0);
        }

        var marge = document.getElementById('kdayMargin');
        if (marge) {
            var pct = Number(j.ca) > 0 ? Math.round(Number(j.profit) / Number(j.ca) * 1000) / 10 : 0;
            marge.textContent = String(pct).replace('.', ',') + ' %';
        }

        // Comparaison avec hier : delta % coloré (base nulle → sans delta).
        var yd = document.getElementById('kdayYesterday');
        if (yd) {
            var hier = Number(j.yesterday_ca) || 0;
            if (hier > 0) {
                var d = Math.round((Number(j.ca) - hier) / hier * 100);
                yd.innerHTML = 'Hier : ' + price(hier)
                    + ' <span class="kday-delta ' + (d >= 0 ? 'is-up' : 'is-down') + '">'
                    + (d >= 0 ? '↑ +' : '↓ ') + d + ' %</span>';
            } else {
                yd.textContent = 'Hier : ' + price(hier);
            }
        }

        // Transactions par moyen de paiement.
        function txText(n) { return n + ' transaction' + (n > 1 ? 's' : ''); }
        set('kdayLiquideTx', txText(Number(j.liquide_tx) || 0));
        set('kdayCarteTx', txText(Number(j.carte_tx) || 0));

        // Semaine en cours : CA + bénéfice coloré.
        var w = j.week || {};
        set('kdayWeekCa', price(w.ca));
        var wp = document.getElementById('kdayWeekProfit');
        if (wp) {
            var wpVal = Number(w.profit) || 0;
            wp.textContent = price(wpVal);
            wp.classList.toggle('is-pos', wpVal >= 0);
            wp.classList.toggle('is-neg', wpVal < 0);
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

        // Ventes par heure : barres, pleine = max du jour (heures > 0,
        // triées par heure croissante — déjà garanties côté serveur).
        var hoursWrap = document.getElementById('kdayHours');
        if (hoursWrap) {
            var hours = j.hours || [];
            if (hours.length === 0) {
                hoursWrap.innerHTML = '<p class="kday-hour-empty">Aucune vente pour le moment.</p>';
            } else {
                var hMax = 0;
                hours.forEach(function (row) { if (Number(row.ca) > hMax) hMax = Number(row.ca); });
                hoursWrap.innerHTML = hours.map(function (row) {
                    var ca = Number(row.ca) || 0;
                    var width = hMax > 0 ? Math.round(ca / hMax * 10000) / 100 : 0;
                    var label = String(row.h).padStart(2, '0') + 'h';
                    return '<div class="kday-hour-row">'
                        + '<span class="kday-hour-label">' + label + '</span>'
                        + '<span class="kday-hour-track"><span class="kday-hour-fill" style="width:' + width + '%"></span></span>'
                        + '<span class="kday-hour-val">' + price(ca) + '</span>'
                        + '</div>';
                }).join('');
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
