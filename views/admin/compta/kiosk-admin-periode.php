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
 * @var array<string,mixed> $stats ca, profit, qty, transactions, liquide,
 *                               carte, top, days, computed_at,
 *                               prev_ca/prev_label (mode mois)
 */
$isSemaine = $mode === 'semaine';
$emoji = $isSemaine ? '📅' : '🗓️';
$retourUrl = url('/kiosque/admin/' . rawurlencode($token));
?>
<style>
    .kp-wrap { max-width: 46rem; margin: 0 auto; }

    .kp-hero {
        display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.8rem; margin-bottom: 1rem;
    }
    .kp-card {
        padding: 1.2rem 1.1rem; text-align: center;
        background: rgba(255, 255, 255, 0.035);
        border: 1px solid rgba(255, 255, 255, 0.07);
        border-radius: 20px;
    }
    .kp-label {
        font-size: 0.78rem; font-weight: 800; text-transform: uppercase;
        letter-spacing: 0.07em; color: var(--muted, #8892a6); margin: 0 0 0.45rem;
    }
    .kp-value { font-size: 2rem; font-weight: 900; line-height: 1; }
    .kp-value.is-ca { color: var(--primary, #48bdd3); }
    .kp-value.is-profit { color: #4ade80; }
    .kp-value.is-loss { color: #f87171; }
    .kp-sub { font-size: 0.8rem; color: var(--muted, #8892a6); margin: 0.4rem 0 0; }
    .kp-sub strong { color: var(--foreground, inherit); }

    .kp-card.is-wide { grid-column: 1 / -1; }
    .kp-days { list-style: none; margin: 0; padding: 0; }
    .kp-days li {
        display: flex; align-items: center; gap: 0.7rem;
        padding: 0.55rem 0.2rem; border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        font-size: 0.95rem;
    }
    .kp-days li:last-child { border-bottom: none; }
    .kp-day-label { flex: 0 0 82px; font-weight: 800; }
    .kp-day-ca { flex: 1; text-align: right; font-weight: 800; }
    .kp-day-profit { flex: 0 0 88px; text-align: right; color: #4ade80; font-size: 0.85rem; }
    .kp-day-profit.is-loss { color: #f87171; }
    .kp-day-qty { flex: 0 0 40px; text-align: right; color: var(--muted, #8892a6); font-size: 0.85rem; }

    .kp-split {
        display: flex; justify-content: center; gap: 1.2rem;
        font-size: 0.9rem; font-weight: 700; margin-top: 0.5rem;
    }

    .kp-top { list-style: none; margin: 0; padding: 0; }
    .kp-top li {
        display: flex; align-items: center; gap: 0.7rem;
        padding: 0.5rem 0.2rem; border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        font-size: 0.95rem;
    }
    .kp-top li:last-child { border-bottom: none; }
    .kp-rank {
        flex-shrink: 0; width: 28px; height: 28px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.78rem; font-weight: 900;
        background: rgba(72, 189, 211, 0.12); color: var(--primary, #48bdd3);
    }
    .kp-top li:first-child .kp-rank { background: rgba(72, 189, 211, 0.28); }
    .kp-top-name { flex: 1; min-width: 0; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .kp-top-qty { color: var(--muted, #8892a6); white-space: nowrap; }
    .kp-top-ca { font-weight: 800; white-space: nowrap; min-width: 64px; text-align: right; }

    .kday-meta {
        text-align: center; font-size: 0.78rem; color: var(--muted, #8892a6);
        margin: 0.2rem 0 1rem;
    }
    .kday-actions {
        max-width: 40rem; margin: 0 auto;
        display: flex; gap: 0.5rem; flex-wrap: wrap; justify-content: center;
    }
    @media (max-width: 480px) {
        .kp-hero { grid-template-columns: 1fr; }
        .kp-day-profit { display: none; }
    }
</style>

<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité — accès admin</p>
        <h1 class="page-title"><?= $isSemaine ? '7 derniers jours' : 'Mois en cours' ?></h1>
        <p class="muted"><?= e($rangeLabel) ?>.</p>
    </div>
</div>

<div class="kp-wrap">
    <div class="kp-hero">
        <div class="kp-card">
            <p class="kp-label">Chiffre d'affaires (TTC)</p>
            <div class="kp-value is-ca"><?= e(formatPrice((float) $stats['ca'])) ?></div>
            <p class="kp-sub"><?= (int) $stats['qty'] ?> produits · <?= (int) $stats['transactions'] ?> transactions</p>
            <?php if (!$isSemaine): ?>
            <p class="kp-sub"><?= e((float) $stats['prev_ca'] > 0 ? 'Mois précédent : ' . formatPrice((float) $stats['prev_ca']) : 'Pas de comparaison disponible') ?></p>
            <?php endif; ?>
        </div>
        <div class="kp-card">
            <p class="kp-label">Bénéfice</p>
            <div class="kp-value <?= (float) $stats['profit'] >= 0 ? 'is-profit' : 'is-loss' ?>">
                <?= e(formatPrice((float) $stats['profit'])) ?>
            </div>
            <p class="kp-sub">perso exclus du bénéfice</p>
        </div>
        <div class="kp-card is-wide">
            <p class="kp-label">Paiements</p>
            <div class="kp-split">
                <span>💵 Liquide : <strong><?= e(formatPrice((float) $stats['liquide'])) ?></strong></span>
                <span>💳 Carte : <strong><?= e(formatPrice((float) $stats['carte'])) ?></strong></span>
            </div>
        </div>
    </div>

    <?php if ($isSemaine): ?>
    <div class="kp-hero">
        <div class="kp-card is-wide">
            <p class="kp-label">Détail jour par jour</p>
            <ul class="kp-days">
                <?php foreach ($stats['days'] as $d): ?>
                <li>
                    <span class="kp-day-label"><?= e($d['label']) ?></span>
                    <span class="kp-day-qty">×<?= (int) $d['qty'] ?></span>
                    <span class="kp-day-ca"><?= e(formatPrice((float) $d['ca'])) ?></span>
                    <span class="kp-day-profit <?= (float) $d['profit'] < 0 ? 'is-loss' : '' ?>"><?= e(formatPrice((float) $d['profit'])) ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <div class="kp-hero">
        <div class="kp-card is-wide">
            <p class="kp-label"><?= $emoji ?> Top produits</p>
            <ul class="kp-top">
                <?php foreach ($stats['top'] as $i => $t): ?>
                <li>
                    <span class="kp-rank"><?= $i + 1 ?></span>
                    <span class="kp-top-name" title="<?= e((string) $t['label']) ?>"><?= e((string) $t['label']) ?></span>
                    <span class="kp-top-qty">×<?= (int) $t['qty'] ?></span>
                    <span class="kp-top-ca"><?= e(formatPrice((float) $t['ca'])) ?></span>
                </li>
                <?php endforeach; ?>
                <?php if ($stats['top'] === []): ?>
                <li class="muted">Aucune vente sur la période.</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>

    <p class="kday-meta">Période : <?= e($rangeLabel) ?> · généré à <?= e((string) $stats['computed_at']) ?> (heure de Paris)</p>

    <div class="kday-actions">
        <a class="btn btn-ghost btn-sm" href="<?= e($retourUrl) ?>">← Kiosque admin</a>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('/kiosque/comptage/jour/' . rawurlencode($token))) ?>">📊 Récap du jour</a>
    </div>
</div>
