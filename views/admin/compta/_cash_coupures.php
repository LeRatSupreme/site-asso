<?php

declare(strict_types=1);

use App\Core\Compta\CashBreakdown;

/**
 * Pavé « coupures » PARTAGÉ (prise « chez soi » ET dépôt banque, kiosque
 * ET admin) : grille des 12 coupures légales (catalogue VERROUILLÉ de
 * CashBreakdown::UNITS — unique source, jamais de liste dupliquée en
 * vue), grosses coupures d'abord comme dans CashBreakdown::format().
 *
 * Chaque cellule : libellé (20€, 50c…) + stepper − / saisie / + (zones
 * tactiles ≥ 44 px, police ≥ 16 px — leçon iOS : en dessous, Safari
 * zoome au focus). Total en direct, calculé en CENTIMES ENTIERS côté JS
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
    .cc-grid {
        display: grid; grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.5rem;
    }
    .cc-cell {
        display: grid; gap: 0.35rem; justify-items: stretch;
        padding: 0.5rem 0.4rem 0.55rem;
        border: 1px solid var(--border, rgba(255,255,255,0.12));
        border-radius: 12px; background: rgba(255, 255, 255, 0.03);
    }
    .cc-cell.is-filled { border-color: var(--primary, #48bdd3); background: rgba(72, 189, 211, 0.09); }
    .cc-label {
        text-align: center; font-size: 0.95rem; font-weight: 900;
        letter-spacing: 0.02em; line-height: 1;
    }
    .cc-step { display: flex; gap: 0.3rem; align-items: stretch; }
    .cc-btn {
        flex: 1 1 0; min-width: 38px; min-height: 44px;
        border: 1px solid var(--border, rgba(255,255,255,0.18));
        border-radius: 10px; background: rgba(255, 255, 255, 0.05);
        color: var(--foreground, inherit);
        font-size: 1.3rem; font-weight: 900; line-height: 1;
        cursor: pointer; -webkit-tap-highlight-color: transparent;
    }
    .cc-btn:hover { border-color: var(--primary, #48bdd3); }
    .cc-qty {
        flex: 1.25 1 0; width: 100%; min-width: 0; min-height: 44px;
        padding: 0.15rem 0.2rem; text-align: center;
        border: 1px solid var(--border, rgba(255,255,255,0.15));
        border-radius: 10px; background: rgba(255, 255, 255, 0.04);
        color: var(--foreground, inherit);
        font-size: 1.05rem; font-weight: 800; /* ≥ 16 px : pas de zoom iOS */
        font-variant-numeric: tabular-nums;
    }
    .cc-qty::-webkit-outer-spin-button,
    .cc-qty::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    .cc-qty { -moz-appearance: textfield; appearance: textfield; }
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
    <div class="cc-grid" role="group" aria-label="Détail par coupures">
        <?php foreach ($ccUnits as $ccUnit): ?>
        <?php $ccLab = $ccLabel((int) $ccUnit); ?>
        <div class="cc-cell" data-unit="<?= (int) $ccUnit ?>" data-label="<?= e($ccLab) ?>">
            <span class="cc-label" aria-hidden="true"><?= e($ccLab) ?></span>
            <div class="cc-step">
                <button type="button" class="cc-btn cc-minus" aria-label="Retirer une coupure de <?= e($ccLab) ?>">−</button>
                <input type="number" class="cc-qty" name="qty_<?= (int) $ccUnit ?>" min="0" step="1" max="9999"
                       inputmode="numeric" placeholder="0" autocomplete="off"
                       aria-label="Nombre de coupures de <?= e($ccLab) ?>">
                <button type="button" class="cc-btn cc-plus" aria-label="Ajouter une coupure de <?= e($ccLab) ?>">+</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <p class="cc-total">Total <strong id="<?= e($ccId) ?>-cc-total" aria-live="polite">0,00&nbsp;€</strong></p>
    <p class="cc-detail" id="<?= e($ccId) ?>-cc-detail" hidden></p>
    <p class="cc-warn" id="<?= e($ccId) ?>-cc-warn" role="alert" hidden></p>
</div>

<script>
/* Grille de coupures : total en direct (centimes entiers côté JS, jamais
   de float intermédiaire) + verrouillage du bouton d'envoi selon le mode.
   Initialisation idempotente (data-cc-ready) : le pavé peut être rendu
   plusieurs fois par page sans double branchement. */
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

        var cells = grid.querySelectorAll('.cc-cell');
        var totalEl = grid.querySelector('.cc-total strong');
        var detailEl = grid.querySelector('.cc-detail');
        var warnEl = grid.querySelector('.cc-warn');

        function recalc() {
            var cents = 0;
            var filled = 0;
            var parts = [];
            /* Les cellules sont en ordre décroissant dans le DOM : le
               détail reconstruit suit l'ordre du format() serveur. */
            Array.prototype.forEach.call(cells, function (cell) {
                var input = cell.querySelector('.cc-qty');
                var q = qtyValue(input);
                cell.classList.toggle('is-filled', q > 0);
                if (q > 0) {
                    filled++;
                    cents += parseInt(cell.getAttribute('data-unit'), 10) * q;
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
            var minus = cell.querySelector('.cc-minus');
            var plus = cell.querySelector('.cc-plus');
            function setQty(v) {
                input.value = String(Math.max(0, Math.min(9999, v)));
                recalc();
            }
            minus.addEventListener('click', function () { setQty(qtyValue(input) - 1); });
            plus.addEventListener('click', function () { setQty(qtyValue(input) + 1); });
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
