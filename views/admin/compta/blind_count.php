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
<style>
    .icon-btn { padding: 0.2rem 0.45rem; font-size: 0.95rem; line-height: 1; }
</style>
<div class="compta-head">
    <div>
        <p class="eyebrow">Comptage</p>
        <h1 class="page-title">Comptage inventaire</h1>
        <p class="muted">Compte le stock <strong>physique</strong> et saisis les quantités : les écarts sont détectés à l'enregistrement.</p>
    </div>
</div>

<section class="card surface glass table-wrap">
    <h2 class="card-title">📦 Comptage physique</h2>
    <p class="card-meta">
        Comptage « à l'aveugle » : les quantités théoriques ne sont volontairement pas affichées,
        pour un comptage honnête. Laisse vide les produits non comptés.
        Le bouton 🚫 marque un produit <strong>« plus en vente pour l'instant »</strong> (pause
        temporaire, ex. Redbull Summer hors été) : il sort de la liste mais <strong>rien n'est
        supprimé</strong> — rétablissement en un clic depuis la page Inventaire (groupe Système).
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
            <tr>
                <td><code><?= e($r['key']) ?></code></td>
                <td><input type="number" name="count[<?= e($r['key']) ?>]" min="0" step="1" placeholder="—" inputmode="numeric" style="width:90px" form="blind-count-form"></td>
                <td>
                    <form method="post" action="<?= e(url('/admin/compta/inventaire/comptage/' . rawurlencode($r['key']) . '/discontinue')) ?>"
                          data-confirm="Marquer « <?= e($r['key']) ?> » plus en vente pour l'instant ? Rien n'est supprimé : il sort juste des comptages et restera rétablissable en un clic (page Inventaire)."
                          data-preserve-scroll>
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-outline btn-sm icon-btn" title="Plus en vente pour l'instant (saisonnier…) : masque des comptages, rien n'est supprimé">🚫</button>
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
