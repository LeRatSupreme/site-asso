<?php

declare(strict_types=1);

/**
 * Livre comptable — deux menus (admin ET kiosque) :
 *  1. « Saisir une dépense » : saisie express (30 s) — nom, référence du
 *     ticket, montant TTC/HT, TVA et photo du ticket ; la trace apparaît
 *     aussitôt dans les dernières dépenses et dans le livre. En admin,
 *     POST /admin/compta/depenses/save (CSRF + return_to) ; en kiosque,
 *     POST /kiosque/admin/ledger/depense/{token} (jeton = auth, pas de
 *     CSRF, identité obligatoire injectée par le layout kiosk).
 *  2. « Livre comptable » : Date | Objet | Débit | Crédit, avec lignes
 *     « ticket » (achats groupés par jour + fournisseur, référencés par
 *     leur n° de facture) et les ventes en une ligne de clôture en fin de
 *     livre. L'« équilibrage » est un SOLDE DE TRÉSORERIE (encaissements
 *     − décaissements de la période), PAS le bénéfice : les achats
 *     incluent du stock pas encore vendu, les ventes du stock acheté
 *     avant. Le bénéfice net (coût des produits vendus déduit) est
 *     affiché sur sa propre ligne.
 * Adapté téléphone : le tableau tient en largeur (police compacte).
 *
 * @var array<string,mixed> $user
 * @var array{rows:list<array<string,mixed>>, total_debit:float, total_credit:float, balance:float, sales_profit:float} $entries
 * @var list<array<string,mixed>> $recentExpenses
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
// Bénéfice net de la période (coût des produits vendus déduit) — distinct
// du solde de trésorerie ci-dessus.
$salesProfit = round((float) ($entries['sales_profit'] ?? 0.0), 2);
$salesProfitPos = $salesProfit >= 0;
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
    /* Ligne « bénéfice net » : même code couleur, un cran moins forte que
       le solde de trésorerie pour bien les distinguer. */
    .ledger-balance.lg-profit td { background: none; font-weight: 800; font-size: 0.78rem; }
    /* Saisie express */
    .lg-quick .field-row { display: flex; flex-wrap: wrap; gap: 0.9rem; }
    .lg-quick .field { flex: 1 1 180px; display: grid; gap: 0.35rem; }
    .lg-quick .chip-row { display: flex; gap: 0.35rem; }
    .lg-quick .chip {
        padding: 0.35rem 0.7rem; border-radius: 999px; cursor: pointer;
        border: 1px solid var(--border, rgba(255,255,255,0.12));
        font-size: 0.8rem; font-weight: 800;
        background: rgba(255, 255, 255, 0.04);
    }
    .lg-quick .chip input { display: none; }
    .lg-quick .chip:has(input:checked) {
        background: var(--primary, #48bdd3); border-color: var(--primary, #48bdd3);
        color: #062033;
    }
    .lg-quick input[type="text"], .lg-quick input[type="date"], .lg-quick select {
        padding: 0.5rem 0.6rem; border: 1px solid var(--border, rgba(255,255,255,0.15));
        border-radius: 8px; background: rgba(255, 255, 255, 0.05); color: var(--foreground, inherit);
        font-size: 0.95rem; width: 100%;
    }
    .lg-quick input[type="file"] { font-size: 0.85rem; }
    .lg-recent-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
    .lg-recent-table th, .lg-recent-table td {
        padding: 0.45rem 0.5rem; text-align: left;
        border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    }
    .lg-recent-table th { font-size: 0.72rem; text-transform: uppercase; color: var(--muted, #8892a6); }
    .lg-recent-table .num { text-align: right; white-space: nowrap; }
    .ledger-thumb { height: 44px; border-radius: 6px; display: block; border: 1px solid rgba(255,255,255,0.12); }
    @media (max-width: 520px) {
        .ledger-table { font-size: 0.8rem; min-width: 380px; }
        .ledger-table th, .ledger-table td { padding: 0.42rem 0.4rem; }
    }
</style>

<div class="compta-head">
    <div>
        <p class="eyebrow">Système</p>
        <h1 class="page-title">Livre comptable</h1>
        <p class="muted"><?= $kiosk
            ? 'Prêt à recopier dans le livret papier : chaque ligne est datée, les tickets sont détaillés, l\'équilibrage est calculé.'
            : 'Saisis une dépense en 30 secondes avec la photo du ticket, ou consulte le livre prêt à recopier dans le livret papier.' ?></p>
    </div>
</div>

<nav class="compta-tabs" data-ledger-tabs aria-label="Menus du livre comptable">
    <button type="button" class="compta-tab is-active" data-tab="saisie">Saisir une dépense</button>
    <button type="button" class="compta-tab" data-tab="livre">Livre comptable</button>
</nav>

<!-- ==================== Menu 1 : saisie express ==================== -->
<div class="compta-tabpane is-active" data-pane="saisie">
    <section class="card surface glass lg-quick">
        <h2 class="card-title">Dépense en 30 secondes</h2>
        <p class="muted">Nom, référence du ticket, montant, TVA, photo — la trace est aussitôt dans le livre, prête à être traitée.</p>
        <?php if ($kiosk): ?>
        <!-- Kiosque : POST vers le contrôleur kiosque — pas de CSRF (le jeton
             admin EST l'authentification), identité (prénom, nom, rôle)
             ajoutée automatiquement à chaque formulaire par le layout kiosk.
             Ordre des champs pensé téléphone : Date, Nom, Prix, TVA,
             Référence, Photo. -->
        <form method="post" action="<?= e(url('/kiosque/admin/ledger/depense/' . rawurlencode($token))) ?>" enctype="multipart/form-data">
            <input type="hidden" name="category" value="DIVERS">

            <div class="field-row">
                <div class="field">
                    <label for="lg-spent-at">Date</label>
                    <input type="date" id="lg-spent-at" name="spent_at" value="<?= e(date('Y-m-d')) ?>" required>
                </div>
            </div>

            <div class="field-row">
                <div class="field" style="flex: 3 1 260px;">
                    <label for="lg-label">Nom</label>
                    <input type="text" id="lg-label" name="label" placeholder="ex: Verrou x4 — Leroy Merlin" required>
                </div>
            </div>

            <div class="field-row">
                <div class="field">
                    <label for="lg-amount">Prix (€)</label>
                    <input type="text" id="lg-amount" name="amount" inputmode="decimal" placeholder="ex: 36,60" required>
                </div>
                <div class="field">
                    <label>Le montant saisi est…</label>
                    <div class="chip-row" role="radiogroup" aria-label="Base du montant">
                        <label class="chip"><input type="radio" name="amount_basis" value="ttc" checked><span>TTC</span></label>
                        <label class="chip"><input type="radio" name="amount_basis" value="ht"><span>HT</span></label>
                    </div>
                </div>
                <div class="field">
                    <label for="lg-vat">TVA</label>
                    <select id="lg-vat" name="vat_rate">
                        <option value="">Aucune (0 %)</option>
                        <option value="20">20 %</option>
                        <option value="10">10 %</option>
                        <option value="5.5">5,5 %</option>
                        <option value="2.1">2,1 %</option>
                    </select>
                </div>
            </div>

            <div class="field-row">
                <div class="field">
                    <label for="lg-invoice">Référence du ticket</label>
                    <input type="text" id="lg-invoice" name="invoice_number" placeholder="ex: 159-10007284" autocomplete="off">
                </div>
            </div>

            <div class="field">
                <label for="lg-receipt">Photo du ticket <span class="muted">(optionnelle — sauvegardée avec la dépense)</span></label>
                <input type="file" id="lg-receipt" name="receipt" accept="image/*" capture="environment">
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Enregistrer la dépense</button>
            </div>
        </form>
        <?php else: ?>
        <form method="post" action="<?= e(url('/admin/compta/depenses/save')) ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="return_to" value="/admin/ledger">
            <input type="hidden" name="category" value="DIVERS">

            <div class="field-row">
                <div class="field">
                    <label for="lg-spent-at">Date</label>
                    <input type="date" id="lg-spent-at" name="spent_at" value="<?= e(date('Y-m-d')) ?>" required>
                </div>
                <div class="field">
                    <label for="lg-invoice">Référence du ticket</label>
                    <input type="text" id="lg-invoice" name="invoice_number" placeholder="ex: 159-10007284" autocomplete="off">
                </div>
            </div>

            <div class="field-row">
                <div class="field" style="flex: 3 1 260px;">
                    <label for="lg-label">Nom</label>
                    <input type="text" id="lg-label" name="label" placeholder="ex: Verrou x4 — Leroy Merlin" required>
                </div>
            </div>

            <div class="field-row">
                <div class="field">
                    <label for="lg-amount">Prix (€)</label>
                    <input type="text" id="lg-amount" name="amount" inputmode="decimal" placeholder="ex: 36,60" required>
                </div>
                <div class="field">
                    <label>Le montant saisi est…</label>
                    <div class="chip-row" role="radiogroup" aria-label="Base du montant">
                        <label class="chip"><input type="radio" name="amount_basis" value="ttc" checked><span>TTC</span></label>
                        <label class="chip"><input type="radio" name="amount_basis" value="ht"><span>HT</span></label>
                    </div>
                </div>
                <div class="field">
                    <label for="lg-vat">TVA</label>
                    <select id="lg-vat" name="vat_rate">
                        <option value="">Aucune (0 %)</option>
                        <option value="20">20 %</option>
                        <option value="10">10 %</option>
                        <option value="5.5">5,5 %</option>
                        <option value="2.1">2,1 %</option>
                    </select>
                </div>
            </div>

            <div class="field">
                <label for="lg-receipt">Photo du ticket <span class="muted">(optionnel — sauvegardée avec la référence)</span></label>
                <input type="file" id="lg-receipt" name="receipt" accept="image/*" capture="environment">
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Enregistrer la dépense</button>
            </div>
        </form>
        <?php endif; ?>
    </section>

    <section class="card surface glass">
        <h2 class="card-title">Dernières dépenses saisies</h2>
        <?php if ($recentExpenses === []): ?>
            <p class="muted">Aucune dépense enregistrée pour le moment.</p>
        <?php else: ?>
        <div class="lg-table-wrap">
            <table class="lg-recent-table">
                <thead>
                    <tr><th>Date</th><th>Nom</th><th>Référence</th><th class="num">Montant</th><th>Photo</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($recentExpenses as $x): ?>
                    <tr>
                        <td><?= e($fmtDate((string) $x['spent_at'])) ?></td>
                        <td><?= e((string) $x['label']) ?></td>
                        <td><?= e(trim((string) ($x['invoice_number'] ?? '')) !== '' ? (string) $x['invoice_number'] : '—') ?></td>
                        <td class="num"><?= e(formatPrice((float) $x['amount_ttc'])) ?></td>
                        <td>
                            <?php if (!empty($x['receipt_path'])): ?>
                                <a href="<?= e(asset((string) $x['receipt_path'])) ?>" target="_blank" rel="noopener" title="Voir la photo du ticket">
                                    <img class="ledger-thumb" src="<?= e(asset((string) $x['receipt_path'])) ?>" alt="Ticket <?= e((string) $x['label']) ?>">
                                </a>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (!$kiosk): ?>
        <p class="muted" style="font-size:0.78rem; margin-top:0.6rem;">
            Le détail complet (avec catégorie, HT/TVA et suppression) vit dans
            <a href="<?= e(url('/admin/compta/depenses')) ?>">Opérations · Dépenses</a>.
        </p>
        <?php endif; ?>
        <?php endif; ?>
    </section>
</div>

<!-- ==================== Menu 2 : livre comptable ==================== -->
<!-- Défaut côté serveur : onglet « Saisir une dépense » actif à
     l'ouverture (admin ET kiosque) ; #livre dans l'URL ouvre le livre. -->
<div class="compta-tabpane" data-pane="livre" hidden>
    <div class="card surface glass" style="padding: 0.95rem 1.1rem; margin-bottom: 1.2rem;">
        <form id="lg-period-form" method="get" style="display: contents;">
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
                    <td colspan="3"><span class="tk"><?= ($r['kind'] ?? '') === 'sales' ? '└' : '└ Ticket' ?> <?= nl2br(e((string) $r['ticket'])) ?></span></td>
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
                    <td colspan="2">ÉQUILIBRAGE — SOLDE</td>
                    <td colspan="2" class="num <?= $balancePos ? 'is-pos' : 'is-neg' ?>">
                        <?= ($balancePos ? '+ ' : '− ') . e(formatPrice(abs((float) $entries['balance']))) ?>
                    </td>
                </tr>
                <tr class="ledger-balance lg-profit">
                    <td colspan="2">BÉNÉFICE NET — coût des produits vendus déduit</td>
                    <td colspan="2" class="num <?= $salesProfitPos ? 'is-pos' : 'is-neg' ?>">
                        <?= ($salesProfitPos ? '+ ' : '− ') . e(formatPrice(abs($salesProfit))) ?>
                    </td>
                </tr>
            </tfoot>
        </table>
        <p class="muted" style="font-size: 0.78rem; margin-top: 0.7rem;">
            Le solde compare les encaissements (ventes) aux décaissements (achats + dépenses) de la période :
            c'est une trace de trésorerie, pas le bénéfice — les achats incluent du stock pas encore vendu,
            et les ventes du stock acheté avant. Le bénéfice net, lui, déduit le coût réel des produits vendus.
        </p>
    </div>
</div>

<?php if ($kiosk): ?>
<p style="text-align:center; font-size:0.78rem; color: var(--muted, #8892a6); margin: 0.9rem 0 1rem;">
    <a class="btn btn-ghost btn-sm" href="<?= e(url('/kiosque/admin/' . rawurlencode($token))) ?>">← Kiosque admin</a>
</p>
<?php endif; ?>

<script>
// Onglets Saisie / Livre (mémorisés dans le hash de l'URL : #livre ouvre
// directement le livre, #saisie la saisie). Défaut serveur : saisie active,
// dans les deux modes (admin et kiosque).
(function () {
    var bar = document.querySelector('[data-ledger-tabs]');
    if (!bar) return;
    var tabs = bar.querySelectorAll('.compta-tab');
    var panes = document.querySelectorAll('[data-pane]');

    function activate(name, push) {
        var found = false;
        Array.prototype.forEach.call(tabs, function (t) {
            var on = t.getAttribute('data-tab') === name;
            t.classList.toggle('is-active', on);
            if (on) found = true;
        });
        if (!found) name = tabs[0].getAttribute('data-tab');
        Array.prototype.forEach.call(panes, function (p) {
            var on = p.getAttribute('data-pane') === name;
            p.classList.toggle('is-active', on);
            p.hidden = !on;
        });
        if (push && window.history && window.history.replaceState) {
            window.history.replaceState(null, '', '#' + name);
        }
    }

    Array.prototype.forEach.call(tabs, function (t) {
        t.addEventListener('click', function () { activate(t.getAttribute('data-tab'), true); });
    });

    var initial = (window.location.hash || '').replace('#', '');
    if (initial) activate(initial, false);
})();

// Même comportement que sur Analytics : toucher une date bascule
// immédiatement sur « Personnalisé », et dès que les deux bornes sont
// remplies la recherche s'applique toute seule (plus de bouton à cliquer).
// Les préréglages (1 jour, 7 jours…) s'appliquent aussi au clic.
(function () {
    var form = document.getElementById('lg-period-form');
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
