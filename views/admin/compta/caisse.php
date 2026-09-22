<?php

declare(strict_types=1);

/**
 * Comptage de caisse — Comptabilité (tout le bureau, hors élèves).
 *
 * Saisie seule : aucun historique ici. Comptage « à l'aveugle » — le
 * théorique n'est pas affiché avant la saisie ; l'écart est révélé après
 * enregistrement (message flash). Historique et écarts : Système → Caisses
 * (réservé au Fondateur).
 */
?>

<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité</p>
        <h1 class="page-title">Comptage de caisse</h1>
        <p class="muted">Comptez le liquide présent en caisse et saisissez le montant : l'écart éventuel s'affiche juste après l'enregistrement.</p>
    </div>
</div>

<section class="card surface glass">
    <h2 class="card-title">🧾 Comptage physique</h2>
    <p class="card-meta">
        Comptage « à l'aveugle » : le montant théorique n'est volontairement pas affiché,
        pour un comptage honnête.
    </p>
    <form method="post" action="<?= e(url('/admin/compta/caisse/comptage')) ?>" style="max-width:480px">
        <?= csrf_field() ?>
        <div class="field">
            <label for="counted">Montant compté (€)</label>
            <input type="text" id="counted" name="counted" inputmode="decimal" placeholder="ex: 152,30" required>
        </div>
        <div class="field">
            <label for="count-label">Note <span class="muted">(optionnel — ex : comptage après soirée)</span></label>
            <input type="text" id="count-label" name="label" placeholder="ex: comptage après soirée">
        </div>
        <?php datetime_selects_field('date', 'count-date'); ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Vérifier la caisse</button>
            <button type="button" class="btn btn-ghost" onclick="if (confirm('Effacer la saisie ?')) this.form.reset();">Annuler</button>
        </div>
    </form>
</section>
