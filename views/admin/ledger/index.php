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
 *     LES DEUX branches câblent le scan automatique du ticket (analyse
 *     serveur + préremplissage + détail produits + création d'achats) :
 *     endpoints miroirs /admin/compta/depenses/scan (admin) et
 *     /kiosque/admin/ledger/scan/{token} (kiosque).
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
 * @var list<string> $purchaseProductKeys Clés produits connues (datalist
 *      des achats créés depuis le scan — injectée par le contrôleur,
 *      admin ET kiosque ; `?? []` par robustesse).
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
    .lg-scan-row { display: flex; flex-wrap: wrap; gap: 0.4rem; align-items: center; margin: 0.3rem 0; }
    /* Ligne d'en-têtes du pavé « Détail des produits » : mêmes flex que
       les .lg-scan-row (mêmes largeurs) — libellés discrets au-dessus des
       champs (Produit · Qté · Montant · TVA · PU), lisible sur téléphone. */
    .lg-scan-head {
        display: flex; flex-wrap: wrap; gap: 0.4rem; align-items: flex-end;
        margin: 0.45rem 0 0.1rem;
    }
    .lg-scan-head span {
        font-size: 0.68rem; color: var(--muted, #8892a6);
        text-transform: uppercase; letter-spacing: 0.05em;
    }
    .lg-scan-head .lg-scan-key { flex: 3 1 150px; }
    .lg-scan-head .lg-scan-qty { flex: 0 0 74px; }
    .lg-scan-head .lg-scan-total { flex: 1 1 90px; }
    .lg-scan-head .lg-scan-vat { min-width: 3rem; text-align: right; }
    .lg-scan-row input {
        padding: 0.4rem 0.55rem; border: 1px solid var(--border, rgba(255,255,255,0.15));
        border-radius: 8px; background: rgba(255, 255, 255, 0.05); color: var(--foreground, inherit);
        font-size: 0.88rem; min-width: 0;
    }
    .lg-scan-row .lg-scan-key { flex: 3 1 150px; }
    .lg-scan-row .lg-scan-qty { flex: 0 0 74px; }
    .lg-scan-row .lg-scan-total { flex: 1 1 90px; }
    .lg-scan-row .lg-scan-vat, .lg-scan-row .lg-scan-unit {
        font-size: 0.74rem; color: var(--muted, #8892a6); white-space: nowrap;
    }
    .lg-scan-row .lg-scan-vat { min-width: 3rem; text-align: right; }
    /* Le display:inline-flex ci-dessous écraserait l'attribut hidden sinon. */
    .lg-scan-row .lg-scan-vat[hidden], .lg-scan-row .lg-scan-unit[hidden] { display: none; }
    .lg-scan-row .lg-scan-nostock {
        display: inline-flex; align-items: center; gap: 0.25rem;
        font-size: 0.74rem; color: var(--muted, #8892a6);
        cursor: pointer; white-space: nowrap;
    }
    .lg-scan-row .lg-scan-nostock input { width: 0.95rem; height: 0.95rem; padding: 0; }
    /* Achats à créer depuis le scan (stock + coût de revient) : section
       sous le détail des produits — case de confirmation + résumé live. */
    .lg-scan-purchase {
        margin: 0.7rem 0 0.2rem; padding: 0.8rem 0.9rem;
        border: 1px dashed rgba(255, 255, 255, 0.2); border-radius: 10px;
        background: rgba(255, 255, 255, 0.03);
    }
    .lg-scan-purchase[hidden] { display: none; }
    .lg-purchase-toggle {
        display: flex; align-items: center; gap: 0.5rem;
        font-size: 0.88rem; cursor: pointer;
    }
    .lg-purchase-toggle input { width: 1.05rem; height: 1.05rem; }
    .lg-purchase-toggle input:disabled { cursor: not-allowed; }
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
             Référence, Photo.
             data-scan-url / data-purchase-products : mêmes attributs que la
             branche admin — le JS du bas câble le scan automatique du
             ticket dans LES DEUX branches (endpoints miroirs, auth par
             jeton dans l'URL côté kiosque). -->
        <form method="post" action="<?= e(url('/kiosque/admin/ledger/depense/' . rawurlencode($token))) ?>" enctype="multipart/form-data" data-scan-url="<?= e(url('/kiosque/admin/ledger/scan/' . rawurlencode($token))) ?>" data-purchase-products="<?= e(json_encode($purchaseProductKeys ?? [], JSON_UNESCAPED_UNICODE)) ?>">
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
                <!-- Scan automatique : occupation (texte simple, pas de
                     spinner) puis message discret en cas d'échec réseau —
                     la photo reste utilisable dans tous les cas. -->
                <p class="muted" id="lg-scan-status" hidden style="margin:0.45rem 0 0; font-size:0.82rem;"></p>
            </div>

            <!-- ── Scan automatique du ticket : pavés remplis par le JS
                 en bas de page (mêmes ids que la branche admin — une seule
                 branche est rendue). « Informations extraites » récapitule
                 l'analyse (préremplissage non destructif déjà fait à
                 réception) ; « Détail des produits » est éditable et part
                 dans le champ `notes` à l'enregistrement. -->
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
                <!-- En-têtes des colonnes (lisible sur téléphone) : mêmes
                     largeurs flex que les .lg-scan-row ci-dessous. -->
                <div class="lg-scan-head" aria-hidden="true">
                    <span class="lg-scan-key">Produit</span>
                    <span class="lg-scan-qty">Qté</span>
                    <span class="lg-scan-total">Montant (€)</span>
                    <span class="lg-scan-vat">TVA</span>
                    <span class="lg-scan-unit">PU</span>
                </div>
                <div id="lg-scan-rows"></div>
                <div class="form-actions">
                    <button type="button" id="lg-scan-row-add" class="btn btn-ghost btn-sm">+ Ligne</button>
                </div>
                <p class="muted" id="lg-scan-total" style="margin:0.3rem 0 0; font-size:0.8rem;"></p>
            </div>

            <!-- ── Achats à créer depuis les lignes du scan (stock + coût
                 de revient) : JAMAIS sans la case cochée ni sans
                 confirmation explicite — cf. JS en bas. Case désactivée
                 tant qu'aucun ticket n'a été scanné (ou aucune ligne
                 exploitable) ; le datalist des produits connus est
                 rempli par le JS depuis data-purchase-products (liste
                 injectée par le contrôleur). data-save-bulk-url pointe
                 vers l'endpoint kiosque (auth par jeton). -->
            <div id="lg-purchase-box" class="lg-scan-purchase" data-save-bulk-url="<?= e(url('/kiosque/admin/ledger/purchases/' . rawurlencode($token))) ?>">
                <p class="lg-scan-title">🛒 Achats à créer (stock + coût de revient)</p>
                <label class="lg-purchase-toggle">
                    <input type="checkbox" id="lg-create-purchases">
                    <span>Créer aussi ces achats <span class="muted">(stock + coût de revient — confirmation demandée)</span></span>
                </label>
                <p class="muted" id="lg-purchase-warn" hidden style="margin:0.35rem 0 0; font-size:0.8rem;"></p>
                <p class="muted" id="lg-purchase-summary" hidden style="margin:0.35rem 0 0; font-size:0.82rem;"></p>
                <p id="lg-purchase-status" hidden style="margin:0.35rem 0 0; font-size:0.82rem;"></p>
            </div>
            <datalist id="lg-purchase-products"></datalist>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Enregistrer la dépense</button>
            </div>
        </form>
        <?php else: ?>
        <!-- data-scan-url : endpoint d'analyse du ticket (même contrat que
             /kiosque/admin/ledger/scan/{token}) — le JS du bas câble le
             scan automatique à l'image choisie dans #lg-receipt, en admin
             comme en kiosque (branches miroirs). -->
        <form method="post" action="<?= e(url('/admin/compta/depenses/save')) ?>" enctype="multipart/form-data" data-scan-url="<?= e(url('/admin/compta/depenses/scan')) ?>" data-purchase-products="<?= e(json_encode($purchaseProductKeys ?? [], JSON_UNESCAPED_UNICODE)) ?>">
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
                <!-- En-têtes des colonnes (lisible sur téléphone) : mêmes
                     largeurs flex que les .lg-scan-row ci-dessous. -->
                <div class="lg-scan-head" aria-hidden="true">
                    <span class="lg-scan-key">Produit</span>
                    <span class="lg-scan-qty">Qté</span>
                    <span class="lg-scan-total">Montant (€)</span>
                    <span class="lg-scan-vat">TVA</span>
                    <span class="lg-scan-unit">PU</span>
                </div>
                <div id="lg-scan-rows"></div>
                <div class="form-actions">
                    <button type="button" id="lg-scan-row-add" class="btn btn-ghost btn-sm">+ Ligne</button>
                </div>
                <p class="muted" id="lg-scan-total" style="margin:0.3rem 0 0; font-size:0.8rem;"></p>
            </div>

            <!-- ── Achats à créer depuis les lignes du scan (stock + coût
                 de revient) : JAMAIS sans la case cochée ni sans
                 confirmation explicite — cf. JS en bas. Case désactivée
                 tant qu'aucun ticket n'a été scanné (ou aucune ligne
                 exploitable) ; le datalist des produits connus est
                 rempli par le JS depuis data-purchase-products (liste
                 injectée par le contrôleur). -->
            <div id="lg-purchase-box" class="lg-scan-purchase" data-save-bulk-url="<?= e(url('/admin/compta/achats/save-bulk')) ?>">
                <p class="lg-scan-title">🛒 Achats à créer (stock + coût de revient)</p>
                <label class="lg-purchase-toggle">
                    <input type="checkbox" id="lg-create-purchases">
                    <span>Créer aussi ces achats <span class="muted">(stock + coût de revient — confirmation demandée)</span></span>
                </label>
                <p class="muted" id="lg-purchase-warn" hidden style="margin:0.35rem 0 0; font-size:0.8rem;"></p>
                <p class="muted" id="lg-purchase-summary" hidden style="margin:0.35rem 0 0; font-size:0.82rem;"></p>
                <p id="lg-purchase-status" hidden style="margin:0.35rem 0 0; font-size:0.82rem;"></p>
            </div>
            <datalist id="lg-purchase-products"></datalist>

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
<!-- Helpers purs du scan de ticket (scanUpload, invoiceToRows,
     applyInvoiceToExpense, serializeExpenseNotes, buildPurchaseBulkPayload)
     : partagés avec la saisie d'achats, testés automatiquement. Chargé
     AVANT le script inline, en admin ET en kiosque (le scan du ticket est
     câblé dans les deux branches ; en kiosque, sans CSRF : le jeton de
     l'URL EST l'authentification). -->
<script src="<?= e(rootAssetVersioned('/assets/js/compta-saisie.js')) ?>"></script>

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

// ── Scan automatique du ticket (ADMIN ET KIOSQUE) ──
// Le formulaire porte data-scan-url dans les deux branches (endpoint
// miroir en kiosque, auth par jeton dans l'URL). À l'image ou au PDF
// choisi dans #lg-receipt (change) : analyse serveur immédiate sans
// bouton, préremplissage NON destructif des champs vides
// (applyInvoiceToExpense, pur et testé), puis pavé « Informations
// extraites » (tout le reste, dont ce qui n'a pas pu être appliqué) et
// pavé « Détail des produits » éditable -> notes. PDF : le serveur
// rastérise et OCRise comme une photo. Autre fichier non image :
// justificatif seulement, pas d'analyse.
// CSRF : présent en admin (champ _csrf du formulaire), ABSENT en kiosque
// (le jeton de l'URL EST l'authentification) — null transmis aux helpers,
// ni champ ni en-tête X-CSRF-Token ne partent alors dans les fetch.
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
    // Section « Achats à créer » : case de confirmation, avertissement,
    // résumé live, message d'erreur, endpoint du POST save-bulk.
    var purchaseBox = document.getElementById('lg-purchase-box');
    var createCb = document.getElementById('lg-create-purchases');
    var purchaseWarn = document.getElementById('lg-purchase-warn');
    var purchaseSummary = document.getElementById('lg-purchase-summary');
    var purchaseStatus = document.getElementById('lg-purchase-status');
    var saveBulkUrl = purchaseBox ? (purchaseBox.getAttribute('data-save-bulk-url') || '') : '';
    if (!spentAtEl || !labelEl || !amountEl || !invoiceEl || !vatEl || !infoBox || !infoList) return;

    var basisInputs = form.querySelectorAll('input[name="amount_basis"]');
    // Champ _csrf : présent en admin seulement — null en kiosque (les
    // helpers ComptaSaisie tolèrent null : ni champ ni en-tête envoyés).
    var csrfInput = form.querySelector('input[name="_csrf"]');
    var csrfToken = csrfInput ? csrfInput.value : null;
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
        // Autocomplétion sur les produits connus (datalist rempli au
        // chargement depuis data-purchase-products) — mêmes noms que
        // les ventes pour alimenter le bon stock.
        key.setAttribute('list', 'lg-purchase-products');
        key.setAttribute('autocomplete', 'off');
        key.value = (r && r.key) || '';
        key.addEventListener('input', updateLinesTotal);

        var qty = document.createElement('input');
        qty.type = 'number';
        qty.className = 'lg-scan-qty';
        qty.min = '1';
        qty.step = '1';
        qty.value = r && r.qty ? String(r.qty) : '1';
        qty.setAttribute('aria-label', 'Quantité');
        qty.addEventListener('input', updateLinesTotal);

        var total = document.createElement('input');
        total.type = 'text';
        total.className = 'lg-scan-total';
        total.inputMode = 'decimal';
        total.placeholder = 'Montant (€)';
        total.setAttribute('aria-label', 'Montant');
        total.value = (r && r.total) || '';
        total.addEventListener('input', updateLinesTotal);

        // Taux de TVA DE LA LIGNE (lecture seule, ex « 5,5 % ») — tel
        // que détecté dans la facture ; le brut reste en dataset pour le
        // payload des achats (vat_rate[]).
        var rawVat = r && r.vat_rate !== null && r.vat_rate !== undefined ? String(r.vat_rate) : '';
        var vat = document.createElement('span');
        vat.className = 'lg-scan-vat';
        if (rawVat !== '') {
            vat.textContent = rawVat.replace('.', ',') + ' %';
        } else {
            vat.hidden = true;
        }

        // Coût unitaire dérivé (montant ÷ quantité), rempli par
        // updateLinesTotal — aide au contrôle avant création.
        var unit = document.createElement('span');
        unit.className = 'lg-scan-unit';
        unit.hidden = true;

        // Case « hors stock » de la ligne : cochée, l'achat restera
        // comptabilisé mais n'entrera pas en stock. SANS attribut name :
        // cette case ne doit jamais partir avec la dépense.
        var nsLabel = document.createElement('label');
        nsLabel.className = 'lg-scan-nostock';
        nsLabel.title = 'Cochée : l\u2019achat sera comptabilisé mais n\u2019entrera pas en stock.';
        var ns = document.createElement('input');
        ns.type = 'checkbox';
        ns.className = 'lg-scan-nostock-input';
        ns.addEventListener('change', updateLinesTotal);
        nsLabel.appendChild(ns);
        nsLabel.appendChild(document.createTextNode('hors stock'));

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
        div.appendChild(vat);
        div.appendChild(unit);
        div.appendChild(nsLabel);
        div.appendChild(del);
        div.dataset.vatRate = rawVat;
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

    // Lignes du pavé -> [{key, qty, total, vat_rate, no_stock}] pour les
    // ACHATS : toutes les lignes non totalement vides (y compris
    // invalides — buildPurchaseBulkPayload explique chaque rejet),
    // taux de TVA brut de la facture et case « hors stock » inclus.
    function readPurchaseRows() {
        var out = [];
        Array.prototype.forEach.call(rowsBox.querySelectorAll('.lg-scan-row'), function (div) {
            var key = div.querySelector('.lg-scan-key').value.trim();
            var total = div.querySelector('.lg-scan-total').value.trim();
            if (key === '' && total === '') return; // jamais remplie
            var ns = div.querySelector('.lg-scan-nostock-input');
            out.push({
                key: key,
                qty: parseInt(div.querySelector('.lg-scan-qty').value, 10),
                total: total,
                vat_rate: div.dataset.vatRate ? div.dataset.vatRate : null,
                no_stock: !!(ns && ns.checked)
            });
        });
        return out;
    }

    // Total de contrôle : Σ montants + écart vs montant saisi, coût
    // unitaire dérivé par ligne (montant ÷ quantité), puis état de la
    // section « Achats à créer » (case + résumé).
    function updateLinesTotal() {
        var sum = 0;
        var count = 0;
        Array.prototype.forEach.call(rowsBox.querySelectorAll('.lg-scan-row'), function (div) {
            var key = div.querySelector('.lg-scan-key').value.trim();
            var qty = parseInt(div.querySelector('.lg-scan-qty').value, 10);
            var n = H.parseAmount(div.querySelector('.lg-scan-total').value);
            var unit = div.querySelector('.lg-scan-unit');
            if (key !== '' && isFinite(qty) && qty >= 1 && isFinite(n) && n > 0) {
                sum += n;
                count++;
                if (unit) {
                    unit.textContent = '≈ ' + fr2(n / qty) + ' €/u';
                    unit.hidden = false;
                }
            } else if (unit) {
                unit.textContent = '';
                unit.hidden = true;
            }
        });
        if (totalEl) {
            if (count === 0) {
                totalEl.textContent = '';
            } else {
                var text = 'Total lignes : ' + fr2(sum) + ' € (' + count + ' ligne' + (count > 1 ? 's' : '') + ')';
                var amount = H.parseAmount(amountEl.value);
                if (isFinite(amount) && amount > 0) {
                    var diff = sum - amount;
                    text += ' · montant saisi : ' + fr2(amount) + ' € · écart : ' + (diff >= 0 ? '+' : '\u2212') + fr2(Math.abs(diff)) + ' €';
                }
                totalEl.textContent = text;
            }
        }
        updatePurchaseSummary();
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
        // PDF accepté au scan : le serveur rastérise les pages puis OCRise
        // (fusion ensembliste). Autre fichier non image : justificatif seul.
        var isPdf = !!file && (file.type === 'application/pdf' || /\.pdf$/i.test(String(file.name || '')));
        if (!file || (String(file.type || '').indexOf('image/') !== 0 && !isPdf)) {
            hideScan();
            status('');
            return;
        }
        status('Analyse du ticket…');
        H.scanUpload(file, csrfToken, scanUrl).then(function (json) {
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

    /* ── Achats à créer depuis le scan (stock + coût de revient) ──
       La grille éditée ci-dessus est la source : « Créer aussi ces
       achats » cochée + confirmation explicite = POST save-bulk
       (contrat as_json=1) AVANT la dépense ; en cas d'échec, le submit
       est abandonné (dépense non enregistrée, erreur affichée). */
    var purchaseSubmitBtn = form.querySelector('button[type="submit"]');
    var purchaseBusy = false;

    // Datalist des produits connus : rempli depuis data-purchase-products
    // (JSON injecté par le contrôleur ; [] tant que la variable de vue
    // n'existe pas encore) — robuste à l'absence de la variable.
    (function fillPurchaseDatalist() {
        var dl = document.getElementById('lg-purchase-products');
        if (!dl) return;
        var keys = [];
        try { keys = JSON.parse(form.getAttribute('data-purchase-products') || '[]'); } catch (e) { keys = []; }
        if (!Array.isArray(keys)) keys = [];
        Array.prototype.forEach.call(keys, function (k) {
            if (typeof k !== 'string' || k.trim() === '') return;
            var opt = document.createElement('option');
            opt.value = k;
            dl.appendChild(opt);
        });
    })();

    // Case active seulement avec un scan affiché et ≥ 1 ligne
    // exploitable ; résumé live (N lignes, Σ montants).
    function updatePurchaseSummary() {
        if (!createCb || !purchaseWarn) return;
        var scanned = !!(linesBox && !linesBox.hidden && saveBulkUrl !== '');
        var valid = 0;
        var sum = 0;
        Array.prototype.forEach.call(readPurchaseRows(), function (r) {
            var n = H.parseAmount(r.total);
            if (r.key !== '' && isFinite(r.qty) && r.qty >= 1 && isFinite(n) && n > 0) {
                valid++;
                sum += n;
            }
        });
        var can = scanned && valid > 0;
        createCb.disabled = !can;
        if (!can && createCb.checked) createCb.checked = false; // jamais d'achat sans lignes contrôlables
        if (!scanned) {
            purchaseWarn.textContent = 'Aucun ticket scanné : photographie d\u2019abord la facture pour remplir les lignes.';
            purchaseWarn.hidden = false;
        } else if (valid === 0) {
            purchaseWarn.textContent = 'Aucune ligne exploitable : complète produit, quantité et montant sur au moins une ligne.';
            purchaseWarn.hidden = false;
        } else {
            purchaseWarn.textContent = '';
            purchaseWarn.hidden = true;
        }
        if (purchaseSummary) {
            if (valid > 0) {
                purchaseSummary.textContent = valid + ' achat' + (valid > 1 ? 's' : '') + ' prêt' + (valid > 1 ? 's' : '')
                    + ' — Σ ' + fr2(sum) + ' € · coût unitaire dérivé = montant ÷ quantité (affiché sur chaque ligne).';
                purchaseSummary.hidden = false;
            } else {
                purchaseSummary.textContent = '';
                purchaseSummary.hidden = true;
            }
        }
    }

    function purchaseError(msg) {
        if (!purchaseStatus) { window.alert(msg); return; }
        purchaseStatus.textContent = msg;
        purchaseStatus.style.color = '#f87171';
        purchaseStatus.hidden = false;
    }

    function purchaseClearError() {
        if (!purchaseStatus) return;
        purchaseStatus.textContent = '';
        purchaseStatus.style.color = '';
        purchaseStatus.hidden = true;
    }

    // Soumission : sans la case cochée, la dépense part normalement
    // (le listener ci-dessus n'a qu'à injecter `notes`). Cochée : les
    // achats partent D'ABORD — validation, confirmation, POST save-bulk
    // — et la dépense n'est envoyée qu'après {ok:true}.
    form.addEventListener('submit', function (ev) {
        if (!createCb || !createCb.checked || saveBulkUrl === '') return;
        ev.preventDefault();
        if (purchaseBusy) return;

        // (a) Validation client : dépense complète + lignes exploitables.
        var problems = [];
        if (spentAtEl.value.trim() === '') problems.push('date de la dépense manquante');
        if (labelEl.value.trim() === '') problems.push('nom de la dépense manquant');
        var depAmount = H.parseAmount(amountEl.value);
        if (!isFinite(depAmount) || depAmount <= 0) problems.push('montant de la dépense invalide');

        // En-tête des achats : la dépense fait foi (date, libellé comme
        // fournisseur, référence, base HT/TTC, taux global du select).
        var bulk = H.buildPurchaseBulkPayload({
            purchased_at: spentAtEl.value.trim(),
            supplier: labelEl.value,
            invoice_number: invoiceEl.value,
            amount_basis: basis(),
            vat_rate: vatEl.value === '' ? null : vatEl.value,
            update_cost: true
        }, readPurchaseRows());
        Array.prototype.forEach.call(bulk.rejected, function (r) {
            problems.push('ligne ' + (r.index + 1) + (r.key !== '' ? ' (« ' + r.key + ' »)' : '') + ' : ' + r.reason);
        });
        if (bulk.product_key.length === 0) problems.push('aucune ligne d\u2019achat valide');
        if (problems.length > 0) {
            purchaseError('Achats non créés — corrige : ' + problems.join(' · ') + '. La dépense n\u2019a pas été enregistrée.');
            return;
        }

        // (b) Confirmation OBLIGATOIRE : récapitulatif avec les lignes.
        var sum = 0;
        Array.prototype.forEach.call(bulk.total_amount, function (a) { sum += parseFloat(a); });
        var MAX_CONFIRM = 12;
        var lines = [];
        Array.prototype.forEach.call(bulk.product_key, function (k, i) {
            if (lines.length >= MAX_CONFIRM) return;
            lines.push('- ' + k + ' × ' + bulk.quantity[i] + ' = ' + bulk.total_amount[i].replace('.', ',') + ' €'
                + (bulk.no_stock_indexes.indexOf(i) !== -1 ? ' (hors stock)' : ''));
        });
        if (bulk.product_key.length > MAX_CONFIRM) {
            lines.push('- … (' + (bulk.product_key.length - MAX_CONFIRM) + ' autre(s) ligne(s))');
        }
        if (!window.confirm('Créer ' + bulk.product_key.length + ' achat(s) pour ' + fr2(sum)
            + ' € + enregistrer la dépense ? Vérifiez les lignes :\n' + lines.join('\n'))) {
            return; // refusée : RIEN n'est envoyé (ni achats, ni dépense)
        }

        // (d) Anti double-clic pendant le POST.
        purchaseBusy = true;
        var oldBtnText = purchaseSubmitBtn ? purchaseSubmitBtn.textContent : '';
        if (purchaseSubmitBtn) {
            purchaseSubmitBtn.disabled = true;
            purchaseSubmitBtn.textContent = 'Création des achats…';
        }

        // (c) POST save-bulk (FormData ; champ _csrf + en-tête X-CSRF-Token
        // en admin SEULEMENT — en kiosque, ni l'un ni l'autre : le jeton de
        // l'URL EST l'authentification). En cas d'échec : erreur affichée,
        // submit ABANDONNÉ — la dépense n'est pas enregistrée, l'utilisateur
        // corrige et renvoie.
        var fd = new FormData();
        fd.append('as_json', '1');
        if (csrfToken !== null) fd.append('_csrf', csrfToken);
        fd.append('purchased_at', bulk.purchased_at);
        fd.append('supplier', bulk.supplier);
        fd.append('invoice_number', bulk.invoice_number);
        fd.append('amount_basis', bulk.amount_basis);
        if (bulk.update_cost !== undefined) fd.append('update_cost', bulk.update_cost);
        // Taux de TVA sur le fil : PHP ne peut pas recevoir « vat_rate »
        // (scalaire) ET « vat_rate[] » (tableau) dans le même POST — le
        // tableau écrase silencieusement le scalaire. Donc :
        //  - au moins une ligne porte son propre taux -> mode PAR LIGNE :
        //    vat_rate[] pour TOUTES les lignes, les trous reprennent le
        //    taux global (en mode tableau le backend force l'en-tête à
        //    '' : sans ce report, une ligne sans taux perdrait sa TVA) ;
        //  - sinon (taux global seul) -> vat_rate scalaire, les lignes
        //    héritent côté serveur (comportement historique de la page
        //    Achats).
        var hasLineVat = bulk.vat_rate_lines.some(function (v) { return v !== ''; });
        if (hasLineVat) {
            Array.prototype.forEach.call(bulk.vat_rate_lines, function (v) {
                fd.append('vat_rate[]', v === '' ? (bulk.vat_rate || '') : v);
            });
        } else if (Object.prototype.hasOwnProperty.call(bulk, 'vat_rate')) {
            fd.append('vat_rate', bulk.vat_rate);
        }
        Array.prototype.forEach.call(bulk.product_key, function (k) { fd.append('product_key[]', k); });
        Array.prototype.forEach.call(bulk.quantity, function (q) { fd.append('quantity[]', String(q)); });
        Array.prototype.forEach.call(bulk.total_amount, function (a) { fd.append('total_amount[]', a); });
        Array.prototype.forEach.call(bulk.no_stock_indexes, function (i) { fd.append('no_stock[' + i + ']', '1'); });

        function release() {
            purchaseBusy = false;
            if (purchaseSubmitBtn) {
                purchaseSubmitBtn.disabled = false;
                purchaseSubmitBtn.textContent = oldBtnText;
            }
        }

        fetch(saveBulkUrl, {
            method: 'POST',
            headers: csrfToken !== null ? { 'X-CSRF-Token': String(csrfToken) } : {},
            credentials: 'same-origin',
            body: fd
        }).then(function (res) {
            return res.json().catch(function () { return null; }).then(function (json) {
                if (res.ok && json && json.ok === true) return json;
                throw new Error(json && json.error
                    ? String(json.error)
                    : 'Erreur ' + res.status + ' : la création des achats a échoué.');
            });
        }).then(function () {
            release();
            purchaseClearError();
            form.submit(); // achats créés : la dépense part à son tour (redirection + flash)
        }, function (err) {
            release();
            purchaseError('Achats non créés — dépense NON enregistrée : '
                + (err && err.message ? err.message : 'erreur réseau.')
                + ' Vérifie la page Achats avant de renvoyer pour ne pas créer deux fois.');
        });
    });

    // État initial de la section (case désactivée + avertissement tant
    // qu'aucun scan n'a produit de lignes exploitables).
    updatePurchaseSummary();
})();
</script>
