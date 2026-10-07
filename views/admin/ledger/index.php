<?php

declare(strict_types=1);

/**
 * Livre comptable — Date | Objet | Débit | Crédit, avec lignes « ticket »
 * (achats groupés par jour + fournisseur : une écriture au total du jour,
 * un reçu par ligne ticket), les ventes en une ligne de clôture en fin de
 * livre et l'équilibrage final (bénéfice/déficit).
 * Adapté téléphone : le tableau tient en largeur (police compacte).
 *
 * @var array<string,mixed> $user
 * @var array{rows:list<array<string,mixed>>, total_debit:float, total_credit:float, balance:float} $entries
 * @var string $from
 * @var string $to
 * @var string $preset
 * @var bool   $kiosk
 * @var string $token
 */
$presets = [
    '1d'  => '1 jour',
    '7d'  => '7 derniers jours',
    '30d' => '30 derniers jours',
    '3m'  => '3 derniers mois',
    '6m'  => '6 derniers mois',
    '12m' => '12 derniers mois',
    'ytd' => 'Année civile',
    'all' => 'Tout',
];
$balancePos = (float) $entries['balance'] >= 0;
$fmtDate = static fn (string $d): string => (new DateTimeImmutable($d))->format('d/m/Y');
?>
<style>
    .lg-form {
        display: flex; flex-wrap: wrap; gap: 0.6rem; align-items: flex-end;
        padding: 0.9rem 1rem; margin-bottom: 1.2rem;
        background: rgba(255, 255, 255, 0.035);
        border: 1px solid rgba(255, 255, 255, 0.07);
        border-radius: 14px;
    }
    .lg-seg { display: flex; flex-wrap: wrap; gap: 0.35rem; flex: 1 1 100%; }
    .lg-seg label {
        padding: 0.35rem 0.7rem; border-radius: 999px; cursor: pointer;
        border: 1px solid var(--border, rgba(255,255,255,0.12));
        font-size: 0.78rem; font-weight: 800;
        background: rgba(255, 255, 255, 0.04);
    }
    .lg-seg input { display: none; }
    .lg-seg input:checked + span { color: #062033; }
    .lg-seg label:has(input:checked) {
        background: var(--primary, #48bdd3); border-color: var(--primary, #48bdd3);
        color: #062033;
    }
    .lg-dates { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; font-size: 0.82rem; }
    .lg-dates input[type="date"] {
        padding: 0.4rem 0.55rem; border: 1px solid var(--border, rgba(255,255,255,0.15));
        border-radius: 8px; background: rgba(255, 255, 255, 0.05); color: var(--foreground, inherit);
        font-size: 0.85rem;
    }
    .lg-table-wrap { overflow-x: auto; }
    .ledger-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; min-width: 460px; }
    .ledger-table th, .ledger-table td {
        padding: 0.5rem 0.6rem; text-align: left;
        border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    }
    .ledger-table th { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted, #8892a6); }
    .ledger-table .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .ledger-table td.num.debit { color: #f87171; font-weight: 800; }
    .ledger-table td.num.credit { color: #4ade80; font-weight: 800; }
    .ledger-ticket td {
        padding-top: 0 !important;
        font-size: 0.74rem; color: var(--muted, #8892a6);
        font-style: italic;
    }
    .ledger-ticket .tk { padding-left: 1.6rem; }
    .ledger-table tfoot td { border-top: 2px solid rgba(255, 255, 255, 0.18); font-weight: 900; }
    .ledger-balance td { background: <?= $balancePos ? 'rgba(74, 222, 128, 0.08)' : 'rgba(248, 113, 113, 0.08)' ?>; font-weight: 900; font-size: 1rem; }
    .ledger-balance .is-pos { color: #4ade80; }
    .ledger-balance .is-neg { color: #f87171; }
    @media (max-width: 520px) {
        .ledger-table { font-size: 0.8rem; min-width: 380px; }
        .ledger-table th, .ledger-table td { padding: 0.42rem 0.4rem; }
    }
</style>

<div class="compta-head">
    <div>
        <p class="eyebrow">Système</p>
        <h1 class="page-title">Livre comptable</h1>
        <p class="muted">Prêt à recopier dans le livret papier : chaque ligne est datée, les tickets sont détaillés, l'équilibrage est calculé.</p>
    </div>
</div>

<?php if (!$kiosk): ?>
<div class="card surface glass" style="padding: 0.95rem 1.1rem; margin-bottom: 1.2rem;">
<?php else: ?>
<div class="lg-form">
<?php endif; ?>
    <form method="get" style="display: contents;">
        <div class="lg-seg">
            <?php foreach ($presets as $key => $label): ?>
            <label>
                <input type="radio" name="period" value="<?= e($key) ?>" <?= $preset === $key ? 'checked' : '' ?>>
                <span><?= e($label) ?></span>
            </label>
            <?php endforeach; ?>
            <label>
                <input type="radio" name="period" value="custom" <?= $preset === 'custom' ? 'checked' : '' ?>>
                <span>Personnalisé</span>
            </label>
        </div>
        <div class="lg-dates">
            <span>Du</span>
            <input type="date" name="du" value="<?= e($from ?? '') ?>">
            <span>Au</span>
            <input type="date" name="au" value="<?= e($to ?? '') ?>">
            <button type="submit" class="btn btn-primary btn-sm">Appliquer</button>
        </div>
    </form>
</div>

<div class="card surface glass lg-table-wrap">
    <table class="ledger-table">
        <thead>
            <tr><th>Date</th><th>Objet</th><th class="num">Débit</th><th class="num">Crédit</th></tr>
        </thead>
        <tbody>
            <?php foreach ($entries['rows'] as $r): ?>
            <tr>
                <td><?= e($fmtDate((string) $r['date'])) ?></td>
                <td><?= e((string) $r['label']) ?></td>
                <td class="num debit"><?= (float) $r['debit'] > 0 ? '− ' . e(formatPrice((float) $r['debit'])) : '' ?></td>
                <td class="num credit"><?= (float) $r['credit'] > 0 ? '+ ' . e(formatPrice((float) $r['credit'])) : '' ?></td>
            </tr>
            <?php if (($r['ticket'] ?? '') !== ''): ?>
            <tr class="ledger-ticket">
                <td></td>
                <td colspan="3"><span class="tk">└ Ticket <?= nl2br(e((string) $r['ticket'])) ?></span></td>
            </tr>
            <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($entries['rows'] === []): ?>
            <tr><td colspan="4" class="muted">Aucune écriture sur la période.</td></tr>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2">TOTAUX</td>
                <td class="num"><?= e(formatPrice((float) $entries['total_debit'])) ?></td>
                <td class="num"><?= e(formatPrice((float) $entries['total_credit'])) ?></td>
            </tr>
            <tr class="ledger-balance">
                <td colspan="2"><?= $balancePos ? 'ÉQUILIBRAGE — BÉNÉFICE' : 'ÉQUILIBRAGE — DÉFICIT' ?></td>
                <td colspan="2" class="num <?= $balancePos ? 'is-pos' : 'is-neg' ?>">
                    <?= ($balancePos ? '+ ' : '− ') . e(formatPrice(abs((float) $entries['balance']))) ?>
                </td>
            </tr>
        </tfoot>
    </table>
</div>

<?php if ($kiosk): ?>
<p style="text-align:center; font-size:0.78rem; color: var(--muted, #8892a6); margin: 0.9rem 0 1rem;">
    <a class="btn btn-ghost btn-sm" href="<?= e(url('/kiosque/admin/' . rawurlencode($token))) ?>">← Kiosque admin</a>
</p>
<?php endif; ?>

<script>
// Même comportement que sur Analytics : toucher une date bascule
// immédiatement sur « Personnalisé », et dès que les deux bornes sont
// remplies la recherche s'applique toute seule (plus de bouton à cliquer).
// Les préréglages (1 jour, 7 jours…) s'appliquent aussi au clic.
(function () {
    var form = document.querySelector('.lg-form form, .card.surface.glass form');
    if (!form) return;
    var du = form.querySelector('input[name="du"]');
    var au = form.querySelector('input[name="au"]');
    var custom = form.querySelector('input[name="period"][value="custom"]');
    if (!du || !au) return;
    function onChange() {
        if (custom) custom.checked = true;
        if (du.value && au.value) form.submit();
    }
    du.addEventListener('change', onChange);
    au.addEventListener('change', onChange);
    // Préréglages : application immédiate au clic.
    Array.prototype.forEach.call(form.querySelectorAll('input[name="period"]'), function (radio) {
        radio.addEventListener('change', function () {
            form.submit();
        });
    });
})();
</script>
