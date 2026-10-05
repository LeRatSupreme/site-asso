<?php

declare(strict_types=1);

/**
 * Comptage inventaire — saisie « à l'aveugle » (tout le bureau, hors élèves).
 *
 * Les stocks théoriques ne sont volontairement pas affichés ; l'écart est
 * calculé à l'enregistrement et révélé via le message flash. Historique,
 * écarts et stocks : page Inventaire (groupe Système).
 *
 * Pattern « form= » : le formulaire de comptage est un formulaire porteur
 * (id + csrf) placé avant la table ; les inputs s'y rattachent via
 * l'attribut form=, ce qui permet un mini-formulaire indépendant par ligne
 * (bouton « plus en vente ») sans imbriquer de formulaires.
 *
 * @var list<array{key:string}> $rows
 */
?>
<div class="compta-head">
    <div>
        <p class="eyebrow">Comptage</p>
        <h1 class="page-title">Comptage inventaire</h1>
        <p class="muted">Compte le stock <strong>physique</strong> et saisis les quantités : les écarts sont détectés à l'enregistrement.</p>
    </div>
</div>

<section class="card surface glass table-wrap">
    <h2 class="card-title">Comptage physique</h2>
    <p class="card-meta">
        Comptage « à l'aveugle » : les quantités théoriques ne sont volontairement pas affichées,
        pour un comptage honnête. Laisse vide les produits non comptés.
        Le bouton met un produit <strong>« en pause »</strong> (« plus en vente pour l'instant »,
        ex. Redbull Summer hors été) : rien n'est supprimé, le produit sort de cette liste
        — rétablissement en un clic depuis la page Inventaire (groupe Système).
    </p>
    <?php if ($rows === []): ?>
        <p class="muted">Aucun produit enregistré pour le moment.</p>
    <?php else: ?>
    <form id="blind-count-form" method="post" action="<?= e(url('/admin/compta/inventaire/comptage/save')) ?>" data-preserve-scroll>
        <?= csrf_field() ?>
    </form>
    <table class="table">
        <thead><tr><th>Produit</th><th>Quantité comptée</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <?php if (!empty($r['paused'])): continue; endif; ?>
            <tr>
                <td><code><?= e($r['key']) ?></code></td>
                <td><input type="number" name="count[<?= e($r['key']) ?>]" min="0" step="1" placeholder="—" inputmode="numeric" style="width:90px" form="blind-count-form"></td>
                <td>
                    <form method="post" action="<?= e(url('/admin/compta/inventaire/comptage/' . rawurlencode($r['key']) . '/discontinue')) ?>"
                          data-confirm="Marquer « <?= e($r['key']) ?> » plus en vente pour l'instant ? Rien n'est supprimé : il passe en pause, sort de cette liste et restera rétablissable en un clic (page Inventaire)."
                          data-confirm-button="Plus en vente"
                          data-preserve-scroll>
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-outline btn-sm" title="Plus en vente pour l'instant (saisonnier…) : met en pause, rien n'est supprimé">Mettre en pause</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary" form="blind-count-form">Enregistrer le comptage</button>
        <button type="button" class="btn btn-ghost" form="blind-count-form" onclick="if (confirm('Effacer les quantités saisies ?')) this.form.reset();">Annuler</button>
    </div>
    <?php endif; ?>
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
    <p style="margin: 0.4rem 0 0.2rem;"><strong>Comptage inventaire</strong> directement :</p>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center;">
        <input type="text" readonly value="<?= e($kioskInventaire) ?>" id="kiosk-inventaire" onclick="this.select()"
               style="flex: 1 1 260px; padding: 0.5rem 0.7rem; border: 1px solid var(--border); border-radius: 8px; background: rgba(255,255,255,0.04); color: var(--foreground); font-size: 0.85rem;">
        <button type="button" class="btn btn-outline btn-sm"
                onclick="(function (b) { var i = document.getElementById('kiosk-inventaire'); i.select(); try { document.execCommand('copy'); } catch (e) {} if (navigator.clipboard) { navigator.clipboard.writeText(i.value); } b.textContent = 'Copié ✓'; setTimeout(function () { b.textContent = 'Copier'; }, 1500); })(this)">Copier</button>
    </div>
</details>
