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
    <h2 class="card-title">Comptage physique</h2>
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
        </div>    </form>
</section>

<details class="card surface glass" style="padding: 0.85rem 1.1rem; margin-top: 1.25rem;">
    <summary style="cursor: pointer; font-weight: 800;">📱 Accès téléphone sans connexion</summary>
    <p class="muted" style="margin: 0.6rem 0 0.4rem;">
        Copie ces liens secrets sur ton téléphone : ils ouvrent le comptage directement,
        <strong>sans jamais demander de connexion</strong> (même jeton que Réappro/Liste — le régénérer sur la page Réappro révoque tout).
    </p>
    <p style="margin: 0.4rem 0 0.2rem;"><strong>Les deux comptages</strong> (hub) :</p>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; margin-bottom: 0.6rem;">
        <input type="text" readonly value="<?= e($kioskHub) ?>" id="kiosk-hub" onclick="this.select()"
               style="flex: 1 1 260px; padding: 0.5rem 0.7rem; border: 1px solid var(--border); border-radius: 8px; background: rgba(255,255,255,0.04); color: var(--foreground); font-size: 0.85rem;">
        <button type="button" class="btn btn-outline btn-sm"
                onclick="(function (b) { var i = document.getElementById('kiosk-hub'); i.select(); try { document.execCommand('copy'); } catch (e) {} if (navigator.clipboard) { navigator.clipboard.writeText(i.value); } b.textContent = 'Copié ✓'; setTimeout(function () { b.textContent = 'Copier'; }, 1500); })(this)">Copier</button>
    </div>
    <p style="margin: 0.4rem 0 0.2rem;"><strong>Comptage caisse</strong> directement :</p>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center;">
        <input type="text" readonly value="<?= e($kioskCaisse) ?>" id="kiosk-caisse" onclick="this.select()"
               style="flex: 1 1 260px; padding: 0.5rem 0.7rem; border: 1px solid var(--border); border-radius: 8px; background: rgba(255,255,255,0.04); color: var(--foreground); font-size: 0.85rem;">
        <button type="button" class="btn btn-outline btn-sm"
                onclick="(function (b) { var i = document.getElementById('kiosk-caisse'); i.select(); try { document.execCommand('copy'); } catch (e) {} if (navigator.clipboard) { navigator.clipboard.writeText(i.value); } b.textContent = 'Copié ✓'; setTimeout(function () { b.textContent = 'Copier'; }, 1500); })(this)">Copier</button>
    </div>
</details>
