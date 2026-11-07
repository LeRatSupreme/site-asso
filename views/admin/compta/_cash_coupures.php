<?php

declare(strict_types=1);

use App\Core\Compta\CashBreakdown;

/**
 * Pavé « coupures » PARTAGÉ (prise « chez soi » ET dépôt banque, kiosque
 * ET admin) : UNE LIGNE = UNE coupure, les lignes empilées en flex
 * column avec gaps explicites — jamais de grille à colonnes collées ni
 * de position absolute : aucun chevauchement possible, même à 320 px
 * (correctif mobile du 22/10/2026 : steppers + et − supprimés, ils
 * chevauchaient les champs voisins sur téléphone).
 *
 * Chaque ligne : « [libellé 20 €] [champ nombre ×] [total ligne €] » —
 * chaque élément dans SA propre zone (tailles bornées, min-width), un
 * SEUL champ par ligne. Saisie DIRECTE dans le champ nombre
 * (inputmode numeric, police ≥ 16 px — leçon iOS : en dessous, Safari
 * zoome au focus). Catalogue VERROUILLÉ de CashBreakdown::UNITS
 * (unique source, jamais de liste dupliquée en vue), grosses coupures
 * d'abord comme dans CashBreakdown::format().
 *
 * Total général en direct, calculé en CENTIMES ENTIERS côté JS
 * (Σ unit×qty) et détail formaté « 3×20€ · 5×2€ · 12×50c » (même
 * convention que le serveur). Un évènement « cashcoupures » (bubbles)
 * porte {cents, filled, ok} à chaque recalcul pour les récaps des vues.
 *
 * DEUX modes (data-cc-mode) :
 *  - 'amount' (prise) : le détail EST le montant (aucun champ montant) —
 *    le bouton d'envoi ($ccSubmitBtn) reste désactivé tant que le total
 *    vaut 0 ;
 *  - 'check' (dépôt) : le détail est OPTIONNEL (un dépôt sans détail
 *    reste légal) ; s'il est saisi, il doit retomber EXACTEMENT sur le
 *    montant du champ $ccAmountInput — même contrôle bloquant que le
 *    serveur (CashBreakdown::matchesAmount), un écran avant l'erreur.
 *
 * Champs postés : TOUJOURS qty_<cents> (contrat CashBreakdown::fromPost),
 * identiques dans tous les formulaires — rien à adapter côté serveur.
 *
 * Variables attendues (préfixées cc pour ne jamais polluer la vue hôte) :
 *  @var string      $ccId           Racine des ids HTML (unique par page).
 *  @var string      $ccMode         'amount' (défaut) | 'check'.
 *  @var string|null $ccAmountInput  Id du champ montant à contrôler ('check').
 *  @var string|null $ccSubmitBtn    Id du bouton d'envoi à verrouiller.
 *  @var bool        $ccBig          Vrai = total XXL (exigence kiosque).
 */
$ccId = $ccId ?? 'cc';
$ccMode = ($ccMode ?? 'amount') === 'check' ? 'check' : 'amount';
$ccAmountInput = $ccAmountInput ?? null;
$ccSubmitBtn = $ccSubmitBtn ?? null;
$ccBig = !empty($ccBig);
$ccUnits = array_reverse(CashBreakdown::UNITS);
// Libellé d'une coupure — même convention que CashBreakdown::unitLabel()
// (méthode d'INSTANCE, non appelable en statique depuis une vue) : le
// catalogue, lui, vient bien de CashBreakdown::UNITS, unique source.
$ccLabel = static fn (int $cents): string => $cents >= 100 ? ((string) (int) ($cents / 100)) . '€' : $cents . 'c';
?>
<style>
    .cc-wrap { margin: 0.2rem 0 1rem; }
    /* Une coupure = UNE ligne, empilées verticalement (flex column, gap
       explicite) : jamais de grille à colonnes collées, jamais
       d'absolute — aucun chevauchement possible sur téléphone. */
    .cc-list { display: flex; flex-direction: column; gap: 0.45rem; }
    .cc-line {
        display: flex; flex-wrap: wrap; align-items: center;
        gap: 0.35rem 0.6rem;
        padding: 0.5rem 0.6rem;
        border: 1px solid var(--border, rgba(255,255,255,0.12));
        border-radius: 12px; background: rgba(255, 255, 255, 0.03);
    }
    .cc-line.is-filled { border-color: var(--primary, #48bdd3); background: rgba(72, 189, 211, 0.09); }
    /* Zone 1 — libellé de la coupure : largeur minimale fixe, jamais
       compressible sous son contenu. */
    .cc-line-label {
        flex: 0 0 auto; min-width: 2.75rem;
        font-size: 1.05rem; font-weight: 900; line-height: 1;
        letter-spacing: 0.02em; font-variant-numeric: tabular-nums;
    }
    /* Zone 2 — le SEUL champ de la ligne : nombre × (le « × » reste
       solidaire du champ dans la même zone). */
    .cc-line-field {
        display: flex; align-items: center; gap: 0.4rem;
        flex: 0 0 auto; margin-left: auto;
    }
    .cc-qty {
        width: 5.25rem; min-width: 4.5rem; max-width: 100%; min-height: 48px;
        padding: 0.2rem 0.45rem; text-align: center;
        border: 1px solid var(--border, rgba(255,255,255,0.15));
        border-radius: 10px; background: rgba(255, 255, 255, 0.04);
        color: var(--foreground, inherit);
        font-size: 1.1rem; /* ≥ 16 px : pas de zoom iOS au focus */
        font-size: max(16px, 1.1rem);
        font-weight: 800;
        font-variant-numeric: tabular-nums;
    }
    .cc-qty:focus { border-color: var(--primary, #48bdd3); outline: none; }
    .cc-qty::-webkit-outer-spin-button,
    .cc-qty::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    .cc-qty { -moz-appearance: textfield; appearance: textfield; }
    .cc-times { flex: 0 0 auto; color: var(--muted, #8892a6); font-weight: 700; line-height: 1; }
    /* Zone 3 — total de la ligne (unit × qty), calé à droite, compressible
       mais jamais sous 0 : il passe à la ligne plutôt que de chevaucher. */
    .cc-line-total {
        flex: 1 1 3.5rem; min-width: 0; text-align: right;
        font-size: 1rem; font-weight: 800; font-variant-numeric: tabular-nums;
        color: var(--muted, #8892a6);
    }
    .cc-line.is-filled .cc-line-total { color: var(--foreground, inherit); }
    .cc-total { margin: 0.8rem 0 0; font-size: 0.95rem; font-weight: 700; }
    .cc-total strong { font-variant-numeric: tabular-nums; }
    .cc-detail { margin: 0.3rem 0 0; font-size: 0.85rem; color: var(--muted, #8892a6); }
    .cc-warn { margin: 0.45rem 0 0; font-size: 0.9rem; font-weight: 700; color: #f87171; }
    .cc-warn.is-ok { color: #4ade80; }
    /* Exigence kiosque : le total est LE chiffre de la page. */
    .cc-wrap.cc-big .cc-total { font-size: 1.05rem; }
    .cc-wrap.cc-big .cc-total strong {
        font-size: 2rem; color: var(--primary, #48bdd3); letter-spacing: -0.02em;
    }
</style>

<div class="cc-wrap<?= $ccBig ? ' cc-big' : '' ?>" id="<?= e($ccId) ?>-cc"
     data-cc data-cc-mode="<?= $ccMode === 'check' ? 'check' : 'amount' ?>"
     <?= $ccAmountInput !== null ? 'data-cc-amount="' . e($ccAmountInput) . '"' : '' ?>
     <?= $ccSubmitBtn !== null ? 'data-cc-submit="' . e($ccSubmitBtn) . '"' : '' ?>>
    <div class="cc-list" role="group" aria-label="Détail par coupures">
        <?php foreach ($ccUnits as $ccUnit): ?>
        <?php $ccLab = $ccLabel((int) $ccUnit); ?>
        <div class="cc-line" data-unit="<?= (int) $ccUnit ?>" data-label="<?= e($ccLab) ?>">
            <span class="cc-line-label"><?= e($ccLab) ?></span>
            <span class="cc-line-field">
                <input type="number" class="cc-qty" name="qty_<?= (int) $ccUnit ?>" min="0" step="1" max="9999"
                       inputmode="numeric" placeholder="0" autocomplete="off"
                       aria-label="Nombre de coupures de <?= e($ccLab) ?>">
                <span class="cc-times" aria-hidden="true">×</span>
            </span>
            <span class="cc-line-total">0,00&nbsp;€</span>
        </div>
        <?php endforeach; ?>
    </div>
    <p class="cc-total">Total <strong id="<?= e($ccId) ?>-cc-total" aria-live="polite">0,00&nbsp;€</strong></p>
    <p class="cc-detail" id="<?= e($ccId) ?>-cc-detail" hidden></p>
    <p class="cc-warn" id="<?= e($ccId) ?>-cc-warn" role="alert" hidden></p>
</div>

<script>
/* Grille de coupures : totaux de ligne + total général en direct
   (centimes entiers côté JS, jamais de float intermédiaire) +
   verrouillage du bouton d'envoi selon le mode. Plus de boutons − / + :
   la saisie se fait directement dans le champ nombre. Initialisation
   idempotente (data-cc-ready) : le pavé peut être rendu plusieurs fois
   par page sans double branchement. */
(function () {
    'use strict';

    function parseCents(v) {
        var s = String(v == null ? '' : v).replace(/\s/g, '').replace(',', '.');
        if (s === '') return null;
        var n = parseFloat(s);
        if (!isFinite(n)) return null;
        return Math.round(n * 100);
    }

    function fmt(cents) {
        var neg = cents < 0 ? '−' : '';
        cents = Math.abs(cents);
        var e = Math.floor(cents / 100);
        var c = cents % 100;
        var es = String(e).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
        return neg + es + ',' + (c < 10 ? '0' : '') + c + ' €';
    }

    function qtyValue(input) {
        var n = parseInt(String(input.value == null ? '' : input.value).trim(), 10);
        if (!isFinite(n) || n < 0) return 0;
        if (n > 9999) { input.value = '9999'; return 9999; }
        return n;
    }

    function init(grid) {
        if (grid.getAttribute('data-cc-ready') === '1') return;
        grid.setAttribute('data-cc-ready', '1');

        var mode = grid.getAttribute('data-cc-mode') || 'amount';
        var amountInput = null;
        var submitBtn = null;
        var amountId = grid.getAttribute('data-cc-amount');
        var submitId = grid.getAttribute('data-cc-submit');
        if (amountId) amountInput = document.getElementById(amountId);
        if (submitId) submitBtn = document.getElementById(submitId);

        var cells = grid.querySelectorAll('.cc-line');
        var totalEl = grid.querySelector('.cc-total strong');
        var detailEl = grid.querySelector('.cc-detail');
        var warnEl = grid.querySelector('.cc-warn');

        function recalc() {
            var cents = 0;
            var filled = 0;
            var parts = [];
            /* Les lignes sont en ordre décroissant dans le DOM : le
               détail reconstruit suit l'ordre du format() serveur. */
            Array.prototype.forEach.call(cells, function (cell) {
                var input = cell.querySelector('.cc-qty');
                var unit = parseInt(cell.getAttribute('data-unit'), 10);
                var q = qtyValue(input);
                cell.classList.toggle('is-filled', q > 0);
                var lineTotal = cell.querySelector('.cc-line-total');
                if (lineTotal) lineTotal.textContent = fmt(unit * q);
                if (q > 0) {
                    filled++;
                    cents += unit * q;
                    parts.push(q + '×' + cell.getAttribute('data-label'));
                }
            });
            if (totalEl) totalEl.textContent = fmt(cents);
            if (detailEl) {
                detailEl.textContent = parts.length > 0 ? parts.join(' · ') : '';
                detailEl.hidden = parts.length === 0;
            }

            /* Verrouillage du bouton d'envoi, selon le mode. */
            var ok = true;
            var warn = '';
            if (mode === 'amount') {
                ok = cents > 0;
                if (!ok) warn = 'Saisis au moins une coupure — le montant est déduit du détail.';
            } else if (cents > 0) {
                var target = amountInput ? parseCents(amountInput.value) : null;
                if (target === null) {
                    ok = false;
                    warn = 'Saisis aussi le montant : ' + fmt(cents) + ' sont détaillés.';
                } else if (target !== cents) {
                    ok = false;
                    var diff = cents - target;
                    warn = 'Le détail (' + fmt(cents) + ') ne correspond pas au montant saisi (' + fmt(target) + ') — écart ' + (diff > 0 ? '+' : '−') + fmt(Math.abs(diff)) + '.';
                } else {
                    warn = 'Détail des coupures = montant saisi.';
                }
            }
            if (warnEl) {
                warnEl.textContent = warn;
                warnEl.classList.toggle('is-ok', mode === 'check' && ok && cents > 0);
                warnEl.hidden = warn === '';
            }
            if (submitBtn) submitBtn.disabled = !ok;

            grid.dispatchEvent(new CustomEvent('cashcoupures', {
                bubbles: true,
                detail: { cents: cents, filled: filled, ok: ok }
            }));
        }

        Array.prototype.forEach.call(cells, function (cell) {
            var input = cell.querySelector('.cc-qty');
            input.addEventListener('input', recalc);
            input.addEventListener('blur', function () {
                if (qtyValue(input) === 0) input.value = '';
            });
        });
        if (amountInput) amountInput.addEventListener('input', recalc);

        recalc();
    }

    function initAll() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-cc]'), init);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
</script>
