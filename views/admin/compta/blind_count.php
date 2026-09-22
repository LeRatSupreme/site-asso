<?php

declare(strict_types=1);

/**
 * Comptage inventaire — saisie « à l'aveugle » (tout le bureau, hors élèves).
 *
 * Les stocks théoriques ne sont volontairement pas affichés ; l'écart est
 * calculé à l'enregistrement et révélé via le message flash. Historique,
 * écarts et stocks : page Inventaire (groupe Système).
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
    <h2 class="card-title">📦 Comptage physique</h2>
    <p class="card-meta">
        Comptage « à l'aveugle » : les quantités théoriques ne sont volontairement pas affichées,
        pour un comptage honnête. Laisse vide les produits non comptés.
    </p>
    <?php if ($rows === []): ?>
        <p class="muted">Aucun produit enregistré pour le moment.</p>
    <?php else: ?>
    <form method="post" action="<?= e(url('/admin/compta/inventaire/comptage/save')) ?>" data-preserve-scroll>
        <?= csrf_field() ?>
        <table class="table">
            <thead><tr><th>Produit</th><th>Quantité comptée</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><code><?= e($r['key']) ?></code></td>
                    <td><input type="number" name="count[<?= e($r['key']) ?>]" min="0" step="1" placeholder="—" inputmode="numeric" style="width:90px"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Enregistrer le comptage</button>
            <button type="button" class="btn btn-ghost" onclick="if (confirm('Effacer les quantités saisies ?')) this.form.reset();">Annuler</button>
        </div>
    </form>
    <?php endif; ?>
</section>
