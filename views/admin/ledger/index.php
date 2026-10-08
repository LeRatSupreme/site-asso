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
    /* Capture photo du ticket : boutons caméra/fichier + aperçu compact. */
    .lg-shot-actions { display: flex; flex-wrap: wrap; gap: 0.45rem; }
    .lg-shot-preview {
        display: flex; flex-wrap: wrap; align-items: center; gap: 0.6rem;
        margin-top: 0.5rem;
    }
    /* Le display:flex ci-dessus écraserait l'attribut hidden sinon. */
    .lg-shot-preview[hidden] { display: none; }
    .lg-shot-preview img {
        max-height: 120px; border-radius: 8px; display: block;
        border: 1px solid rgba(255, 255, 255, 0.12);
    }
    .lg-shot-chip {
        padding: 0.35rem 0.7rem; border-radius: 999px;
        border: 1px solid rgba(255, 255, 255, 0.12);
        background: rgba(255, 255, 255, 0.04);
        font-size: 0.8rem; max-width: 100%;
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .lg-recent-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
    .lg-recent-table th, .lg-recent-table td {
        padding: 0.45rem 0.5rem; text-align: left;
        border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    }
    .lg-recent-table th { font-size: 0.72rem; text-transform: uppercase; color: var(--muted, #8892a6); }
    .lg-recent-table .num { text-align: right; white-space: nowrap; }
    .ledger-thumb { height: 44px; border-radius: 6px; display: block; border: 1px solid rgba(255,255,255,0.12); }
    /* Scan automatique du ticket (admin) : pavés « Informations extraites »
       et « Détail des produits » — style sobre, réutilise .muted, .btn,
       .form-actions et le style des inputs de .lg-quick. */
    .lg-scan-info, .lg-scan-lines {
        margin: 0.9rem 0 0.2rem; padding: 0.8rem 0.9rem;
        border: 1px dashed rgba(255, 255, 255, 0.2); border-radius: 10px;
        background: rgba(255, 255, 255, 0.03);
    }
    .lg-scan-info[hidden], .lg-scan-lines[hidden] { display: none; }
    .lg-scan-title { font-weight: 800; font-size: 0.9rem; margin: 0 0 0.45rem; }
    .lg-scan-info-list { margin: 0; }
    .lg-scan-info-list p { margin: 0.18rem 0; font-size: 0.86rem; }
    .lg-scan-skip { color: var(--muted, #8892a6); font-size: 0.78rem; }
    .lg-scan-warnings { margin: 0.45rem 0 0; padding-left: 1.1rem; }
    .lg-scan-row { display: flex; gap: 0.4rem; align-items: center; margin: 0.3rem 0; }
    .lg-scan-row input {
        padding: 0.4rem 0.55rem; border: 1px solid var(--border, rgba(255,255,255,0.15));
        border-radius: 8px; background: rgba(255, 255, 255, 0.05); color: var(--foreground, inherit);
        font-size: 0.88rem; min-width: 0;
    }
    .lg-scan-row .lg-scan-key { flex: 3 1 150px; }
    .lg-scan-row .lg-scan-qty { flex: 0 0 74px; }
    .lg-scan-row .lg-scan-total { flex: 1 1 90px; }
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
                <!-- Capture photo : deux boutons pilotent le même input file
                     invisible (caméra vs galerie/PDF), aperçu vignette ou
                     chip PDF retirable — cf. JS en bas de page. -->
                <div class="lg-shot-actions">
                    <button type="button" id="lg-shot-btn" class="btn btn-primary btn-sm">📷 Prendre une photo</button>
                    <button type="button" id="lg-file-btn" class="btn btn-ghost btn-sm">🖼️ Choisir un fichier</button>
                </div>
                <input type="file" id="lg-receipt" name="receipt" accept="image/*,.pdf" style="display:none;">
                <div id="lg-shot-preview" hidden>
                    <img id="lg-shot-img" alt="Aperçu du ticket" hidden>
                    <span id="lg-shot-chip" class="lg-shot-chip" hidden></span>
                    <button type="button" id="lg-shot-clear" class="btn btn-ghost btn-sm">✕ Retirer</button>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Enregistrer la dépense</button>
            </div>
        </form>
        <?php else: ?>
        <!-- data-scan-url : endpoint d'analyse du ticket (même contrat que
             /admin/compta/achats/scan) — le JS du bas câble le scan
             automatique à l'image choisie dans #lg-receipt. Absent de la
             version kiosque : pas de scan là-bas (endpoint différent). -->
        <form method="post" action="<?= e(url('/admin/compta/depenses/save')) ?>" enctype="multipart/form-data" data-scan-url="<?= e(url('/admin/compta/depenses/scan')) ?>">
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
                <!-- Capture photo : deux boutons pilotent le même input file
                     invisible (caméra vs galerie/PDF), aperçu vignette ou
                     chip PDF retirable — cf. JS en bas de page. -->
                <div class="lg-shot-actions">
                    <button type="button" id="lg-shot-btn" class="btn btn-primary btn-sm">📷 Prendre une photo</button>
                    <button type="button" id="lg-file-btn" class="btn btn-ghost btn-sm">🖼️ Choisir un fichier</button>
                </div>
                <input type="file" id="lg-receipt" name="receipt" accept="image/*,.pdf" style="display:none;">
                <div id="lg-shot-preview" hidden>
                    <img id="lg-shot-img" alt="Aperçu du ticket" hidden>
                    <span id="lg-shot-chip" class="lg-shot-chip" hidden></span>
                    <button type="button" id="lg-shot-clear" class="btn btn-ghost btn-sm">✕ Retirer</button>
                </div>
                <!-- Scan automatique : occupation (texte simple, pas de
                     spinner) puis message discret en cas d'échec réseau —
                     la photo reste utilisable dans tous les cas. -->
                <p class="muted" id="lg-scan-status" hidden style="margin:0.45rem 0 0; font-size:0.82rem;"></p>
            </div>

            <!-- ── Scan automatique du ticket : pavés remplis par le JS
                 en bas de page. « Informations extraites » récapitule
                 l'analyse (préremplissage non destructif déjà fait à
                 réception) ; « Détail des produits » est éditable et
                 part dans le champ `notes` à l'enregistrement. -->
            <div id="lg-scan-info" class="lg-scan-info" hidden>
                <p class="lg-scan-title">Informations extraites</p>
                <div class="lg-scan-info-list" id="lg-scan-info-list"></div>
                <ul class="lg-scan-warnings field-help" id="lg-scan-info-warnings" hidden></ul>
                <p class="muted" id="lg-scan-vatmix" hidden style="margin:0.45rem 0 0; font-size:0.8rem;"></p>
                <div class="form-actions">
                    <button type="button" id="lg-scan-apply" class="btn btn-primary btn-sm">Utiliser ces infos</button>
                    <button type="button" id="lg-scan-ignore" class="btn btn-ghost btn-sm">Ignorer</button>
                </div>
            </div>
            <div id="lg-scan-lines" class="lg-scan-lines" hidden>
                <p class="lg-scan-title">Détail des produits</p>
                <div id="lg-scan-rows"></div>
                <div class="form-actions">
                    <button type="button" id="lg-scan-row-add" class="btn btn-ghost btn-sm">+ Ligne</button>
                </div>
                <p class="muted" id="lg-scan-total" style="margin:0.3rem 0 0; font-size:0.8rem;"></p>
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
<?php else: ?>
<!-- Helpers purs du scan de ticket (scanUpload, invoiceToRows,
     applyInvoiceToExpense, serializeExpenseNotes) : partagés avec la
     saisie d'achats, testés automatiquement. Chargé AVANT le script
     inline, seulement en admin (pas de scan côté kiosque). -->
<script src="<?= e(rootAssetVersioned('/assets/js/compta-saisie.js')) ?>"></script>
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

// Capture photo du ticket (saisie express, admin ET kiosque) : deux boutons
// pilotent le même input file invisible. Caméra = attribut capture posé à la
// volée (iOS Safari l'ignore s'il est en dur au chargement) ; fichier =
// capture retirée et PDF accepté. Aperçu : vignette (object URL révoqué à
// chaque remplacement) ou chip « nom.pdf », avec bouton Retirer. Le
// formulaire part ensuite normalement (multipart déjà en place).
(function () {
    var input = document.getElementById('lg-receipt');
    var shotBtn = document.getElementById('lg-shot-btn');
    var fileBtn = document.getElementById('lg-file-btn');
    var preview = document.getElementById('lg-shot-preview');
    var img = document.getElementById('lg-shot-img');
    var chip = document.getElementById('lg-shot-chip');
    var clearBtn = document.getElementById('lg-shot-clear');
    if (!input || !shotBtn || !fileBtn || !preview || !img || !chip || !clearBtn) return;

    var objectUrl = null;

    function revoke() {
        if (objectUrl) {
            URL.revokeObjectURL(objectUrl);
            objectUrl = null;
        }
    }

    function showPreview(file) {
        revoke();
        if (file && file.type.indexOf('image/') === 0) {
            objectUrl = URL.createObjectURL(file);
            img.src = objectUrl;
            img.hidden = false;
            chip.hidden = true;
        } else if (file) {
            img.removeAttribute('src');
            img.hidden = true;
            chip.textContent = '📄 ' + file.name;
            chip.hidden = false;
        }
        preview.hidden = false;
    }

    function reset() {
        revoke();
        input.value = '';
        img.removeAttribute('src');
        chip.textContent = '';
        preview.hidden = true;
    }

    shotBtn.addEventListener('click', function () {
        input.setAttribute('capture', 'environment');
        input.setAttribute('accept', 'image/*');
        input.click();
    });

    fileBtn.addEventListener('click', function () {
        input.removeAttribute('capture');
        input.setAttribute('accept', 'image/*,.pdf');
        input.click();
    });

    input.addEventListener('change', function () {
        if (input.files && input.files.length > 0) showPreview(input.files[0]);
        else reset();
    });

    clearBtn.addEventListener('click', reset);
})();

// ── Scan automatique du ticket (ADMIN uniquement) ──
// Le formulaire kiosque n'a pas de data-scan-url : ce bloc sort
// immédiatement là-bas. À l'image choisie dans #lg-receipt (change) :
// analyse serveur immédiate sans bouton, préremplissage NON destructif
// des champs vides (applyInvoiceToExpense, pur et testé), puis pavé
// « Informations extraites » (tout le reste, dont ce qui n'a pas pu
// être appliqué) et pavé « Détail des produits » éditable -> notes.
// PDF ou fichier non image : justificatif seulement, pas d'analyse.
(function () {
    var form = document.querySelector('form[data-scan-url]');
    var input = document.getElementById('lg-receipt');
    if (!form || !input || !window.ComptaSaisie) return;
    var H = window.ComptaSaisie;

    var spentAtEl = document.getElementById('lg-spent-at');
    var labelEl = document.getElementById('lg-label');
    var amountEl = document.getElementById('lg-amount');
    var invoiceEl = document.getElementById('lg-invoice');
    var vatEl = document.getElementById('lg-vat');
    var statusEl = document.getElementById('lg-scan-status');
    var infoBox = document.getElementById('lg-scan-info');
    var infoList = document.getElementById('lg-scan-info-list');
    var infoWarn = document.getElementById('lg-scan-info-warnings');
    var vatMixEl = document.getElementById('lg-scan-vatmix');
    var applyBtn = document.getElementById('lg-scan-apply');
    var ignoreBtn = document.getElementById('lg-scan-ignore');
    var linesBox = document.getElementById('lg-scan-lines');
    var rowsBox = document.getElementById('lg-scan-rows');
    var rowAddBtn = document.getElementById('lg-scan-row-add');
    var totalEl = document.getElementById('lg-scan-total');
    if (!spentAtEl || !labelEl || !amountEl || !invoiceEl || !vatEl || !infoBox || !infoList) return;

    var basisInputs = form.querySelectorAll('input[name="amount_basis"]');
    var csrfInput = form.querySelector('input[name="_csrf"]');
    var scanUrl = form.getAttribute('data-scan-url');
    var lastInvoice = null;

    function basis() {
        for (var i = 0; i < basisInputs.length; i++) {
            if (basisInputs[i].checked) return basisInputs[i].value;
        }
        return 'ttc';
    }

    function setBasis(v) {
        Array.prototype.forEach.call(basisInputs, function (r) { r.checked = r.value === v; });
    }

    function fr2(n) { return n.toFixed(2).replace('.', ','); }

    function status(msg) {
        if (!statusEl) return;
        statusEl.textContent = msg || '';
        statusEl.hidden = !msg;
    }

    function fire(el, type) {
        el.dispatchEvent(new Event(type, { bubbles: true }));
    }

    function setField(el, value) {
        if (!el || el.value === value) return;
        el.value = value;
        fire(el, 'input');
        fire(el, 'change');
    }

    // État courant du formulaire, format attendu par
    // H.applyInvoiceToExpense (chaînes, '' = vide).
    function currentState() {
        var hiddenVat = form.querySelector('input[name="vat_amount"]');
        return {
            spent_at: spentAtEl.value,
            label: labelEl.value,
            amount: amountEl.value,
            basis: basis(),
            vat_rate: vatEl.value,
            vat_amount: hiddenVat ? hiddenVat.value : '',
            invoice_number: invoiceEl.value
        };
    }

    // Applique l'état fusionné au DOM : les champs « skipped » ont déjà
    // leur valeur courante (rien à faire), les autres sont remplis avec
    // dispatch input/change (cohérence avec les autres scripts).
    function applyResult(res) {
        setField(spentAtEl, res.spent_at);
        setField(labelEl, res.label);
        setField(amountEl, res.amount);
        if (res.basis !== basis()) {
            setBasis(res.basis);
            Array.prototype.forEach.call(basisInputs, function (r) { fire(r, 'change'); });
        }
        if (res.vat_rate !== null && res.vat_rate !== '' && parseFloat(vatEl.value) !== parseFloat(res.vat_rate)) {
            Array.prototype.forEach.call(vatEl.options, function (opt) {
                if (parseFloat(opt.value) === parseFloat(res.vat_rate)) vatEl.value = opt.value;
            });
            fire(vatEl, 'change');
        }
        // TVA multi-taux : input hidden `vat_amount` (lu par save()) ;
        // retiré si le dernier scan n'en produit pas (input propre au
        // scan : le formulaire n'en a pas en dur).
        var hidden = form.querySelector('input[name="vat_amount"]');
        if (res.vat_amount !== '') {
            if (!hidden) {
                hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'vat_amount';
                form.appendChild(hidden);
            }
            setField(hidden, res.vat_amount);
        } else if (hidden) {
            hidden.remove();
        }
    }

    function addInfoLine(label, value) {
        var p = document.createElement('p');
        var b = document.createElement('strong');
        b.textContent = label + ' : ';
        p.appendChild(b);
        p.appendChild(document.createTextNode(value));
        infoList.appendChild(p);
        return p;
    }

    function fmtDate(d) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(d)) return d;
        return d.slice(8, 10) + '/' + d.slice(5, 7) + '/' + d.slice(0, 4);
    }

    function fmtMoney(v) {
        var n = typeof v === 'number' ? v : parseFloat(String(v == null ? '' : v).replace(',', '.'));
        return isFinite(n) ? fr2(n) + ' €' : null;
    }

    // Pavé « Informations extraites » : TOUT ce que le ticket contient,
    // y compris ce qui n'a pas pu être appliqué (champs déjà remplis).
    function renderInfo(invoice, res) {
        infoList.textContent = '';
        var inv = invoice || {};

        var supplier = String(inv.supplier == null ? '' : inv.supplier).trim();
        addInfoLine('Fournisseur', supplier !== '' ? supplier : 'non détecté');

        var date = String(inv.purchased_at == null ? '' : inv.purchased_at).trim();
        addInfoLine('Date', date !== '' ? fmtDate(date) : 'non détectée');

        var ttc = fmtMoney(inv.total_ttc);
        var ht = fmtMoney(inv.total_ht);
        addInfoLine('Montant TTC', ttc !== null ? ttc + (ht !== null ? ' (HT ' + ht + ')' : '') : 'non détecté');

        var rates = Array.isArray(inv.vat_rates) ? inv.vat_rates : [];
        var vatTxt;
        if (inv.vat_rate !== null && inv.vat_rate !== undefined && inv.vat_rate !== '') {
            vatTxt = String(inv.vat_rate).replace('.', ',') + ' %';
            var vatSum = 0;
            var hasVat = false;
            for (var i = 0; i < rates.length; i++) {
                var v = rates[i] && typeof rates[i].vat === 'number' ? rates[i].vat : parseFloat(String(rates[i] && rates[i].vat).replace(',', '.'));
                if (isFinite(v)) { vatSum += v; hasVat = true; }
            }
            if (hasVat) vatTxt += ' — TVA ' + fr2(vatSum) + ' €';
        } else if (rates.length >= 2) {
            vatTxt = 'multi-taux (détail ci-dessous)';
        } else {
            vatTxt = 'non détectée';
        }
        addInfoLine('TVA', vatTxt);

        var num = String(inv.invoice_number == null ? '' : inv.invoice_number).trim();
        addInfoLine('Référence ticket', num !== '' ? num : 'non détectée');

        // Champs laissés tels quel (déjà remplis par l'utilisateur) :
        // la valeur extraite reste affichée ici, rien n'a été écrasé.
        Array.prototype.forEach.call(res.skipped, function (s) {
            var p = document.createElement('p');
            p.className = 'lg-scan-skip';
            p.textContent = s.field + ' : ' + s.reason;
            infoList.appendChild(p);
        });

        infoWarn.textContent = '';
        var warnings = Array.isArray(inv.warnings) ? inv.warnings : [];
        if (warnings.length > 0) {
            Array.prototype.forEach.call(warnings, function (w) {
                var li = document.createElement('li');
                li.textContent = String(w == null ? '' : w);
                infoWarn.appendChild(li);
            });
            infoWarn.hidden = false;
        } else {
            infoWarn.hidden = true;
        }

        // TVA mixte : note + montant total injecté dans `vat_amount`.
        if (rates.length >= 2 && res.vat_amount !== '') {
            var labels = [];
            Array.prototype.forEach.call(rates, function (r) {
                if (r && r.rate !== null && r.rate !== undefined) labels.push(String(r.rate).replace('.', ',') + ' %');
            });
            vatMixEl.textContent = 'TVA mixte (' + labels.join(' + ') + ') : montant TVA total enregistré (' + res.vat_amount + ' €).';
            vatMixEl.hidden = false;
        } else {
            vatMixEl.textContent = '';
            vatMixEl.hidden = true;
        }

        infoBox.hidden = false;
    }

    /* ── Pavé « Détail des produits » : liste éditable -> notes ── */

    function addLineRow(r) {
        var div = document.createElement('div');
        div.className = 'lg-scan-row';

        var key = document.createElement('input');
        key.type = 'text';
        key.className = 'lg-scan-key';
        key.placeholder = 'Produit';
        key.setAttribute('aria-label', 'Produit');
        key.value = (r && r.key) || '';

        var qty = document.createElement('input');
        qty.type = 'number';
        qty.className = 'lg-scan-qty';
        qty.min = '1';
        qty.step = '1';
        qty.value = r && r.qty ? String(r.qty) : '1';
        qty.setAttribute('aria-label', 'Quantité');

        var total = document.createElement('input');
        total.type = 'text';
        total.className = 'lg-scan-total';
        total.inputMode = 'decimal';
        total.placeholder = 'Montant (€)';
        total.setAttribute('aria-label', 'Montant');
        total.value = (r && r.total) || '';
        total.addEventListener('input', updateLinesTotal);

        var del = document.createElement('button');
        del.type = 'button';
        del.className = 'btn btn-ghost btn-sm';
        del.textContent = '×';
        del.setAttribute('aria-label', 'Retirer la ligne');
        del.addEventListener('click', function () {
            div.remove();
            updateLinesTotal();
        });

        div.appendChild(key);
        div.appendChild(qty);
        div.appendChild(total);
        div.appendChild(del);
        rowsBox.appendChild(div);
    }

    // Lignes du pavé -> [{key, qty, total}] pour serializeExpenseNotes.
    function readLineRows() {
        var out = [];
        Array.prototype.forEach.call(rowsBox.querySelectorAll('.lg-scan-row'), function (div) {
            var key = div.querySelector('.lg-scan-key').value.trim();
            if (key === '') return;
            out.push({
                key: key,
                qty: parseInt(div.querySelector('.lg-scan-qty').value, 10) || 1,
                total: div.querySelector('.lg-scan-total').value
            });
        });
        return out;
    }

    // Total de contrôle : Σ montants + écart vs montant saisi.
    function updateLinesTotal() {
        if (!totalEl) return;
        var sum = 0;
        var count = 0;
        Array.prototype.forEach.call(readLineRows(), function (r) {
            var n = H.parseAmount(r.total);
            if (isFinite(n) && n > 0) { sum += n; count++; }
        });
        if (count === 0) { totalEl.textContent = ''; return; }
        var text = 'Total lignes : ' + fr2(sum) + ' € (' + count + ' ligne' + (count > 1 ? 's' : '') + ')';
        var amount = H.parseAmount(amountEl.value);
        if (isFinite(amount) && amount > 0) {
            var diff = sum - amount;
            text += ' · montant saisi : ' + fr2(amount) + ' € · écart : ' + (diff >= 0 ? '+' : '\u2212') + fr2(Math.abs(diff)) + ' €';
        }
        totalEl.textContent = text;
    }

    function renderRows(invoice) {
        var rows = H.invoiceToRows(invoice);
        rowsBox.textContent = '';
        if (rows.length === 0) { linesBox.hidden = true; return; }
        Array.prototype.forEach.call(rows, function (r) { addLineRow(r); });
        linesBox.hidden = false;
        updateLinesTotal();
    }

    function hideScan() {
        lastInvoice = null;
        infoBox.hidden = true;
        if (linesBox) linesBox.hidden = true;
    }

    // Réception d'une analyse : préremplissage non destructif immédiat
    // (champs vides seulement), puis affichage des pavés.
    function handleInvoice(invoice) {
        lastInvoice = invoice;
        var res = H.applyInvoiceToExpense(currentState(), invoice);
        applyResult(res);
        renderInfo(invoice, res);
        if (linesBox) renderRows(invoice);
    }

    input.addEventListener('change', function () {
        var file = input.files && input.files[0];
        if (!file || file.type.indexOf('image/') !== 0) {
            // PDF ou sélection vidée : justificatif seulement.
            hideScan();
            status('');
            return;
        }
        status('Analyse du ticket…');
        H.scanUpload(file, csrfInput ? csrfInput.value : '', scanUrl).then(function (json) {
            status('');
            handleInvoice(json && json.invoice ? json.invoice : null);
        }, function (err) {
            status('Analyse automatique indisponible' + (err && err.message ? ' : ' + err.message : '') + ' — la photo reste utilisable, complète les champs à la main.');
        });
    });

    // « Utiliser ces infos » : écrase volontairement TOUS les champs
    // avec les valeurs du ticket (fusion depuis un état vide = aucune
    // valeur « déjà renseignée » à protéger).
    if (applyBtn) {
        applyBtn.addEventListener('click', function () {
            if (!lastInvoice) return;
            applyResult(H.applyInvoiceToExpense({
                spent_at: '', label: '', amount: '', basis: basis(),
                vat_rate: '', vat_amount: '', invoice_number: ''
            }, lastInvoice));
            updateLinesTotal();
            status('Champs remplis avec les informations extraites — vérifie avant d\u2019enregistrer.');
        });
    }

    // « Ignorer » : referme seulement le pavé — le préremplissage des
    // champs vides, déjà appliqué, reste en place.
    if (ignoreBtn) {
        ignoreBtn.addEventListener('click', function () {
            infoBox.hidden = true;
        });
    }

    if (rowAddBtn) {
        rowAddBtn.addEventListener('click', function () {
            addLineRow(null);
            updateLinesTotal();
        });
    }

    // Le formulaire ne propose pas de champ `notes` en dur : sérialisé
    // dans un input hidden au moment du submit. Pavé vidé -> pas de
    // notes injectées (input retiré).
    form.addEventListener('submit', function () {
        if (!linesBox || linesBox.hidden) return;
        var data = H.serializeExpenseNotes(readLineRows());
        var existing = form.querySelector('input[name="notes"]');
        if (data === '') {
            if (existing) existing.remove();
            return;
        }
        if (!existing) {
            existing = document.createElement('input');
            existing.type = 'hidden';
            existing.name = 'notes';
            form.appendChild(existing);
        }
        existing.value = data;
    });
})();
</script>
