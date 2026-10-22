<?php

declare(strict_types=1);

/**
 * PARTIEL PARTAGÉ « Onglets de l'espace Ventes » (lot 4).
 *
 * Trois onglets : Aujourd'hui (/admin/compta), Analyses (/admin/analytics),
 * Bilan annuel (/admin/compta/annuel). L'onglet actif porte
 * aria-current="page" + la classe .is-active.
 *
 * CONTRAT POUR LES PAGES CONSOMMATRICES (/admin/compta, /admin/compta/annuel,
 * vues analytics) :
 *  - $activeTab : 'today' | 'analyses' | 'annual' (défaut 'today') ;
 *  - $tabsVentesCompact (optionnel, défaut false) : true pour le rendu
 *    compact kiosque (classe modulatrice .tabs-ventes--compact).
 *
 * Le style .tabs-ventes vit dans public/css/compta.css (chargé par les
 * pages de l'espace Ventes et par le layout kiosque).
 *
 * @var string $activeTab           Onglet actif ('today'|'analyses'|'annual').
 * @var bool   $tabsVentesCompact   Rendu compact (kiosque) si vrai.
 */

$activeTab = in_array($activeTab ?? '', ['today', 'analyses', 'annual'], true)
    ? (string) $activeTab
    : 'today';
$tabsVentesCompact = (bool) ($tabsVentesCompact ?? false);

$tabsVentes = [
    'today'    => ['label' => "Aujourd'hui", 'href' => url('/admin/compta')],
    'analyses' => ['label' => 'Analyses', 'href' => url('/admin/analytics')],
    'annual'   => ['label' => 'Bilan annuel', 'href' => url('/admin/compta/annuel')],
];
?>
<nav class="tabs-ventes<?= $tabsVentesCompact ? ' tabs-ventes--compact' : '' ?>" aria-label="Espace ventes">
    <?php foreach ($tabsVentes as $key => $tab): ?>
        <a href="<?= e($tab['href']) ?>"<?= $key === $activeTab ? ' class="is-active" aria-current="page"' : '' ?>><?= e($tab['label']) ?></a>
    <?php endforeach; ?>
</nav>
