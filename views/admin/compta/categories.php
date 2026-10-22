<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $rows
 *      Chaque ligne porte depuis le lot 4B les clés calculées par le
 *      contrôleur : « margin » (marge en %) et « share » (part du CA).
 * @var array{qty:int,ca:float,profit:float,margin:float} $totaux
 *      Totaux de la période calculés par le contrôleur (lot 4B : aucun
 *      calcul métier dans la vue).
 * @var array{preset:string,from:?string,to:?string} $period
 * @var array<string,string> $periodOptions
 */
?>
<div class="compta-head">
    <div class="compta-head-row">
        <div>
            <p class="eyebrow">Comptabilité</p>
            <h1 class="page-title">Bénéfice par catégorie</h1>
            <p class="muted">Vue d'ensemble par grand regroupement (Boisson, Nourriture, Spécial…).</p>
        </div>
        <?php require AEIC_VIEWS . '/admin/compta/_period_bar.php'; ?>
    </div>
</div>

<?php // Totaux calculés par le contrôleur (lot 4B). ?>

<!-- Total global : 4 cartes séparées -->
<div class="grid grid-4 stat-cards">
    <div class="stat-card surface glass">
        <span class="stat-value"><?= (int) $totaux['qty'] ?></span>
        <span class="stat-label">Quantité vendue</span>
    </div>
    <div class="stat-card surface glass">
        <span class="stat-value"><?= e(formatPrice($totaux['ca'])) ?></span>
        <span class="stat-label">Chiffre d'affaires</span>
    </div>
    <div class="stat-card surface glass">
        <span class="stat-value is-positive"><?= e(formatPrice($totaux['profit'])) ?></span>
        <span class="stat-label">Bénéfice</span>
    </div>
    <div class="stat-card surface glass">
        <span class="stat-value"><?= e(number_format($totaux['margin'], 1, ',', ' ')) ?> %</span>
        <span class="stat-label">Marge globale</span>
    </div>
</div>

<?php if ($rows === []): ?>
    <div class="empty-state card surface glass">
        <p class="muted">Aucune vente sur cette période.</p>
    </div>
<?php else: ?>
    <!-- Filtre rapide des cartes (lot 4B, boîte à outils tableaux :
         cibles non tableau, affichage/masquage simple). -->
    <div class="table-tools">
        <input type="search" class="tt-filter" data-table-filter=".cat-card"
               aria-label="Filtrer les catégories" placeholder="Filtrer les catégories…">
        <span class="tt-count muted" data-table-count=".cat-card" data-count-label="items" aria-live="polite"></span>
    </div>
    <div class="cat-grid">
        <?php foreach ($rows as $r): ?>
            <article class="cat-card card surface glass <?= (float) $r['profit'] >= 0 ? 'is-positive' : 'is-negative' ?>">
                <header class="cat-card-head">
                    <h2 class="cat-card-title"><?= e((string) $r['category']) ?></h2>
                    <span class="cat-card-qty muted"><?= (int) $r['qty'] ?> vendus</span>
                </header>

                <div class="cat-card-metrics">
                    <div class="cat-metric">
                        <span class="cat-metric-label">Chiffre d'affaires</span>
                        <strong class="cat-metric-value"><?= e(formatPrice((float) $r['ca'])) ?></strong>
                    </div>
                    <div class="cat-metric">
                        <span class="cat-metric-label">Bénéfice</span>
                        <strong class="cat-metric-value <?= (float) $r['profit'] >= 0 ? 'is-positive' : 'is-negative' ?>"><?= e(formatPrice((float) $r['profit'])) ?></strong>
                    </div>
                    <div class="cat-metric">
                        <span class="cat-metric-label">Marge</span>
                        <span class="cat-metric-badge <?= (float) $r['margin'] >= 50 ? 'badge-success' : ((float) $r['margin'] >= 20 ? 'badge-info' : 'badge-warning') ?>"><?= e(number_format((float) $r['margin'], 1, ',', ' ')) ?> %</span>
                    </div>
                </div>

                <div class="cat-card-bar" title="Part du chiffre d'affaires">
                    <div class="cat-card-bar-fill" style="width: <?= max(2, (int) round((float) $r['share'])) ?>%"></div>
                </div>
                <p class="cat-card-share muted"><?= e(number_format((float) $r['share'], 1, ',', ' ')) ?> % du CA total</p>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Boîte à outils tableaux (lot 4B) : filtre des cartes. -->
<script src="<?= e(rootAssetVersioned('/assets/js/table-tools.js')) ?>" defer></script>

