<?php

declare(strict_types=1);

/**
 * Barre « 📅 Période » partagée : pastilles cliquables + dates personnalisées.
 *
 * Attend en scope : $period (array{preset:string, from:?string, to:?string})
 * et $periodOptions (array<string,string>). Les autres paramètres GET de la
 * page (filtres catégorie/produit/paiement…) sont conservés par les pastilles
 * comme par le formulaire de dates personnalisées.
 */

/** @var array{preset:string,from:?string,to:?string} $period */
/** @var array<string,string> $periodOptions */

// Paramètres GET courants à conserver (hors période elle-même).
$keep = $_GET;
unset($keep['period'], $keep['from'], $keep['to']);
?>
<div class="period-bar" id="period-filters">
    <span class="period-bar-label">📅 Période</span>

    <nav class="period-pills" aria-label="Sélection de période">
        <?php foreach ($periodOptions as $k => $label): ?>
            <?php if ($k === 'custom') { continue; } ?>
            <?php $q = http_build_query(array_merge($keep, ['period' => $k])); ?>
            <a class="period-pill<?= $k === $period['preset'] ? ' is-active' : '' ?>" href="?<?= e($q) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
        <?php
            // En mode personnalisé, la pastille affiche la plage active pour
            // que la sélection reste lisible d'un coup d'œil.
            $customLabel = 'Personnalisé';
            if ($period['preset'] === 'custom' && !empty($period['from']) && !empty($period['to'])) {
                $customLabel = sprintf(
                    'Personnalisé : %s → %s',
                    formatDate($period['from'], 'd/m/Y'),
                    formatDate($period['to'], 'd/m/Y')
                );
            }
        ?>
        <button type="button" id="period-custom-toggle" class="period-pill<?= $period['preset'] === 'custom' ? ' is-active' : '' ?>"><?= e($customLabel) ?></button>
    </nav>

    <form method="get" class="period-dates" id="period-custom" <?= $period['preset'] === 'custom' ? '' : 'hidden' ?>>
        <?php foreach ($keep as $name => $value): ?>
            <?php if (is_string($name) && $name !== '' && is_scalar($value)): ?>
                <input type="hidden" name="<?= e($name) ?>" value="<?= e((string) $value) ?>">
            <?php endif; ?>
        <?php endforeach; ?>
        <input type="hidden" name="period" value="custom">
        <label>Du <input type="date" name="from" value="<?= e($period['from'] ?? '') ?>"></label>
        <label>Au <input type="date" name="to" value="<?= e($period['to'] ?? '') ?>"></label>
        <button type="submit" class="btn btn-primary btn-sm">Appliquer</button>
    </form>
</div>

<script>
(function () {
    var toggle = document.getElementById('period-custom-toggle');
    var custom = document.getElementById('period-custom');
    if (!toggle || !custom) return;

    toggle.addEventListener('click', function () {
        custom.hidden = false;
        var pills = document.querySelectorAll('#period-filters .period-pill');
        Array.prototype.forEach.call(pills, function (p) { p.classList.remove('is-active'); });
        toggle.classList.add('is-active');
        var from = custom.querySelector('input[name="from"]');
        if (from) from.focus();
    });

    // Saisie manuelle d'une date → bascule immédiate sur « Personnalisé »,
    // avec application automatique dès que les deux bornes sont remplies.
    var dateInputs = [
        custom.querySelector('input[name="from"]'),
        custom.querySelector('input[name="to"]')
    ];
    Array.prototype.forEach.call(dateInputs, function (input) {
        if (!input) return;
        input.addEventListener('change', function () {
            var pills = document.querySelectorAll('#period-filters .period-pill');
            Array.prototype.forEach.call(pills, function (p) { p.classList.remove('is-active'); });
            toggle.classList.add('is-active');
            custom.hidden = false;
            var from = dateInputs[0], to = dateInputs[1];
            if (from && to && from.value && to.value) {
                custom.submit();
            }
        });
    });

    custom.addEventListener('submit', function () {
        toggle.classList.add('is-active');
    });
})();
</script>
