<?php

declare(strict_types=1);

/**
 * Kiosque ADMIN — récap de période (7 derniers jours ou mois en cours) :
 * CA, bénéfice, paiements, détail jour par jour (semaine), comparaison
 * mois précédent (mois), top produits.
 *
 * @var string $token
 * @var string $mode 'semaine'|'mois'
 * @var string $rangeLabel
 * @var array<string,mixed> $stats
 */
$isSemaine = $mode === 'semaine';
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
    .kx-card-label {
        font-size: 0.75rem; font-weight: 800; text-transform: uppercase;
        letter-spacing: 0.07em; color: var(--muted, #8892a6); margin: 0 0 0.4rem;
    }
    .kx-card-value { font-size: 1.55rem; font-weight: 900; line-height: 1.1; }
    .kx-card-value.is-profit { color: #4ade80; }
    .kx-card-value.is-loss { color: #f87171; }
    .kx-card-sub { font-size: 0.72rem; color: var(--muted, #8892a6); margin: 0.35rem 0 0; }
    .kx-card-sub strong { color: var(--foreground, inherit); }

    .kx-split { display: flex; justify-content: center; gap: 1rem; font-size: 0.92rem; font-weight: 700; }
    .kx-split strong { color: var(--foreground, inherit); }

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

    .kx-days { list-style: none; margin: 0; padding: 0; }
    .kx-days li {
        display: flex; align-items: center; gap: 0.7rem;
        padding: 0.6rem 0.15rem; border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        font-size: 0.95rem;
    }
    .kx-days li:last-child { border-bottom: none; }
    .kx-day-label { flex: 1; font-weight: 800; }
    .kx-day-qty { color: var(--muted, #8892a6); }
    .kx-day-ca { font-weight: 800; min-width: 68px; text-align: right; }
    .kx-day-profit { min-width: 80px; text-align: right; color: #4ade80; font-size: 0.88rem; }
    .kx-day-profit.is-loss { color: #f87171; }

    .kday-meta {
        text-align: center; font-size: 0.78rem; color: var(--muted, #8892a6);
        margin: 0.2rem 0 1rem;
    }
    .kday-actions {
        max-width: 40rem; margin: 0 auto;
        display: flex; gap: 0.5rem; flex-wrap: wrap; justify-content: center;
    }
</style>

<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité — accès admin</p>
        <h1 class="page-title"><?= $isSemaine ? '7 derniers jours' : 'Mois en cours' ?></h1>
        <p class="muted"><?= e($rangeLabel) ?>.</p>
    </div>
</div>

<div class="kx-wrap">
    <div class="kx-hero">
        <p class="kx-hero-label">Chiffre d'affaires (TTC) — <?= $isSemaine ? '7 derniers jours' : 'mois en cours' ?></p>
        <div class="kx-hero-value"><?= e(formatPrice((float) $stats['ca'])) ?></div>
        <p class="kx-hero-sub"><strong><?= (int) $stats['qty'] ?></strong> produits · <strong><?= (int) $stats['transactions'] ?></strong> transactions</p>
        <?php if (!$isSemaine): ?>
        <p class="kx-hero-sub"><?= e((float) $stats['prev_ca'] > 0 ? 'Mois précédent : ' . formatPrice((float) $stats['prev_ca']) : 'Premier mois avec des ventes') ?></p>
        <?php endif; ?>
    </div>

    <div class="kx-pair">
        <?php $mPct = (float) $stats['ca'] > 0 ? round((float) $stats['profit'] / (float) $stats['ca'] * 100, 1) : 0.0; ?>
        <div class="kx-card">
            <p class="kx-card-label">Bénéfice</p>
            <div class="kx-card-value <?= (float) $stats['profit'] >= 0 ? 'is-profit' : 'is-loss' ?>">
                <?= e(formatPrice((float) $stats['profit'])) ?>
            </div>
            <p class="kx-card-sub">Marge : <?= e(number_format($mPct, 1, ',', ' ')) ?> % · perso exclus</p>
        </div>
        <div class="kx-card">
            <p class="kx-card-label">Produits vendus</p>
            <div class="kx-card-value"><?= (int) $stats['qty'] ?></div>
        </div>
    </div>

    <div class="kx-pair">
        <div class="kx-card">
            <p class="kx-card-label">💵 Liquide</p>
            <div class="kx-card-value"><?= e(formatPrice((float) $stats['liquide'])) ?></div>
        </div>
        <div class="kx-card">
            <p class="kx-card-label">💳 Carte</p>
            <div class="kx-card-value"><?= e(formatPrice((float) $stats['carte'])) ?></div>
        </div>
    </div>

    <?php if ($isSemaine): ?>
    <div class="kx-list-card">
        <p class="kx-card-label">📅 Détail jour par jour</p>
        <ul class="kx-days">
            <?php foreach ($stats['days'] as $d): ?>
            <li>
                <span class="kx-day-label"><?= e($d['label']) ?></span>
                <span class="kx-day-qty">×<?= (int) $d['qty'] ?></span>
                <span class="kx-day-ca"><?= e(formatPrice((float) $d['ca'])) ?></span>
                <span class="kx-day-profit <?= (float) $d['profit'] < 0 ? 'is-loss' : '' ?>"><?= e(formatPrice((float) $d['profit'])) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="kx-list-card">
        <p class="kx-card-label">🏆 Top produits</p>
        <ul class="kx-list">
            <?php foreach ($stats['top'] as $i => $t): ?>
            <li>
                <span class="kx-rank"><?= $i + 1 ?></span>
                <span class="kx-list-name" title="<?= e((string) $t['label']) ?>"><?= e((string) $t['label']) ?></span>
                <span class="kx-list-qty">×<?= (int) $t['qty'] ?></span>
                <span class="kx-list-ca"><?= e(formatPrice((float) $t['ca'])) ?></span>
            </li>
            <?php endforeach; ?>
            <?php if ($stats['top'] === []): ?>
            <li class="muted">Aucune vente sur la période.</li>
            <?php endif; ?>
        </ul>
    </div>

    <p class="kday-meta">Période : <?= e($rangeLabel) ?> · généré à <?= e((string) $stats['computed_at']) ?> (heure de Paris)</p>

    <div class="kday-actions">
        <a class="btn btn-ghost btn-sm" href="<?= e(url('/kiosque/admin/' . rawurlencode($token))) ?>">← Kiosque admin</a>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('/kiosque/comptage/jour/' . rawurlencode($token))) ?>">📊 Récap du jour</a>
    </div>
</div>
