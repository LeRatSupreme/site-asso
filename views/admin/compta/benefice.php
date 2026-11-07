<?php

declare(strict_types=1);

/**
 * GABARIT INTÉRIMAIRE — à remplacer par dev-frontend (même contrat).
 *
 * Page unifiée « Bénéfice » : fusion des anciennes pages « Bénéfice par
 * produit » (views/admin/compta/products.php) et « Bénéfice par catégorie »
 * (views/admin/compta/categories.php). Ce gabarit fait uniquement la
 * composition data-only des deux templates existants avec la bascule
 * ?vue=produits|categories — AUCUNE refonte UX/JS/CSS ici.
 *
 * CONTRAT DU CONTRÔLEUR (AdminComptaController::benefice, à conserver tel
 * quel lors du remplacement) :
 *   - string                $vue            Onglet actif ('produits'|'categories')
 *   - list<array>           $rowsProduits   Lignes par produit ; clés calculées :
 *                                           « margin » (marge %, lot 4B)
 *   - list<array>           $rowsCategories Lignes par catégorie ; clés calculées :
 *                                           « margin » (marge %), « share » (part du CA)
 *   - array{qty:int,ca:float,profit:float,margin:float} $totaux
 *                                           Totaux de période (onglet catégories)
 *   - array{preset:string,from:?string,to:?string}     $period
 *   - array<string,string>  $periodOptions  Pastilles de période partagées
 */

/**
 * @var string $vue
 * @var list<array<string,mixed>> $rowsProduits
 * @var list<array<string,mixed>> $rowsCategories
 * @var array{qty:int,ca:float,profit:float,margin:float} $totaux
 * @var array{preset:string,from:?string,to:?string} $period
 * @var array<string,string> $periodOptions
 */

// Liens d'onglet : conservent la période courante (comme la barre période).
$tabQuery = static function (string $vueCible) use ($period): string {
    $params = ['vue' => $vueCible];
    if (($period['preset'] ?? '') !== '') {
        $params['period'] = $period['preset'];
    }
    if ($period['preset'] === 'custom') {
        $params['from'] = (string) ($period['from'] ?? '');
        $params['to'] = (string) ($period['to'] ?? '');
    }

    return '?' . http_build_query($params);
};
?>
<nav class="period-pills" aria-label="Vue bénéfice" style="margin-bottom:1rem">
    <a class="period-pill<?= $vue === 'produits' ? ' is-active' : '' ?>"
       href="<?= e($tabQuery('produits')) ?>">Par produit</a>
    <a class="period-pill<?= $vue === 'categories' ? ' is-active' : '' ?>"
       href="<?= e($tabQuery('categories')) ?>">Par catégorie</a>
</nav>

<?php if ($vue === 'categories'): ?>
    <?php
    // Bloc « Bénéfice par catégorie » (template historique, tel quel).
    $rows = $rowsCategories;
    require AEIC_VIEWS . '/admin/compta/categories.php';
    ?>
<?php else: ?>
    <?php
    // Bloc « Bénéfice par produit » (template historique, tel quel).
    $rows = $rowsProduits;
    require AEIC_VIEWS . '/admin/compta/products.php';
    ?>
<?php endif; ?>
