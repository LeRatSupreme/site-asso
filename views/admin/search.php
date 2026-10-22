<?php

declare(strict_types=1);

/**
 * Recherche globale (/admin/search?q=) — trois groupes de résultats,
 * filtrés selon les droits du rôle (les groupes interdits ne sont ni
 * interrogés ni affichés, côté contrôleur). Requêtes LIKE paramétrées,
 * échappement systématique.
 *
 * @var array<string,mixed> $user
 * @var string $q
 * @var list<array{label:string, detail:string, lien:?string}> $produits
 * @var list<array{description:string, transaction_ref:string, sold_at:string, price_ttc:float|int|string, lien:string}> $ventes
 * @var list<array{nom:string, email:string, role:string, lien:string}> $membres
 * @var bool $voirVentes
 * @var bool $voirMembres
 * @var bool $peutCompta
 */
$vide = $q !== '' && $produits === [] && (!$voirVentes || $ventes === []) && (!$voirMembres || $membres === []);
?>
<form method="get" class="costs-toolbar" action="<?= e(url('/admin/search')) ?>" aria-label="Recherche globale">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Produit, vente ou membre…" aria-label="Recherche" required>
    <button type="submit" class="btn btn-primary btn-sm">Rechercher</button>
</form>

<?php if ($q === ''): ?>
    <p class="muted">Saisissez au moins une lettre : le moteur cherche dans les produits, les ventes<?= $voirMembres ? ' et les membres' : '' ?>.</p>
<?php elseif ($vide): ?>
    <p class="muted">Aucun résultat pour « <?= e($q) ?> ».</p>
<?php endif; ?>

<?php if ($q !== '' && $produits !== []): ?>
    <div class="card surface glass">
        <h2 class="card-title">Produits</h2>
        <ul class="list-rows">
            <?php foreach ($produits as $p): ?>
                <li>
                    <span><?= e($p['label']) ?><?= $p['detail'] !== '' ? ' <span class="muted">' . e($p['detail']) . '</span>' : '' ?></span>
                    <?php if ($p['lien'] !== null): ?>
                        <a class="btn btn-outline btn-sm" href="<?= e($p['lien']) ?>">Voir →</a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($q !== '' && $voirVentes): ?>
    <div class="card surface glass">
        <h2 class="card-title">Ventes</h2>
        <?php if ($ventes !== []): ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Date</th><th>Description</th><th>Référence</th><th>Montant</th></tr></thead>
                    <tbody>
                        <?php foreach ($ventes as $v): ?>
                            <tr>
                                <td><?= e(formatDateTime($v['sold_at'])) ?></td>
                                <td><a href="<?= e($v['lien']) ?>"><?= e($v['description']) ?></a></td>
                                <td class="muted"><?= e($v['transaction_ref']) ?></td>
                                <td><?= e(formatPrice($v['price_ttc'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="muted">Aucune vente pour « <?= e($q) ?> ».</p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($q !== '' && $voirMembres): ?>
    <div class="card surface glass">
        <h2 class="card-title">Membres</h2>
        <?php if ($membres !== []): ?>
            <ul class="list-rows">
                <?php foreach ($membres as $m): ?>
                    <li>
                        <span><?= e($m['nom']) ?></span>
                        <span class="card-meta"><?= e($m['email']) ?></span>
                        <a class="btn btn-outline btn-sm" href="<?= e($m['lien']) ?>">Gérer →</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="muted">Aucun membre pour « <?= e($q) ?> ».</p>
        <?php endif; ?>
    </div>
<?php endif; ?>
