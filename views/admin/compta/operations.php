<?php

declare(strict_types=1);

/**
 * Page fusionnée « Opérations » : une seule page compta au quotidien,
 * quatre onglets de niveau 1 — Achats & stock, Dépenses, Pertes /
 * Inventaire, Événements. Le bloc « Pertes / Inventaire » porte deux
 * sous-onglets de niveau 2 (journal des pertes · relevé tablette
 * SumUp) : deux pages distinctes, rangées sous le même onglet. Les
 * routes historiques (/admin/compta/achats, /depenses, /pertes,
 * /evenements, /inventaire-tablette) rendent toutes cette page avec
 * leur section active ; le contenu de l'onglet est la vue historique
 * de la section, incluse telle quelle (période, onglets internes,
 * formulaires et routes POST inchangés).
 *
 * @var string $section 'achats'|'depenses'|'pertes'|'evenements'|'inventaire'
 */

// Sections affichables : description d'en-tête + vue incluse (même
// clé que l'onglet / sous-onglet).
$opSections = [
    'achats' => [
        'label' => 'Achats & stock',
        'url'   => '/admin/compta/achats',
        'desc'  => 'Note ici <strong>ce que tu commandes vraiment</strong>. Ces achats alimentent le stock théorique de l\'inventaire : dernier comptage + achats − ventes.',
    ],
    'depenses' => [
        'label' => 'Dépenses',
        'url'   => '/admin/compta/depenses',
        'desc'  => 'Les charges de l\'asso (matériel, événements, frais...). Le <strong>résultat net</strong> = bénéfice cafétéria − ces dépenses.',
    ],
    'pertes' => [
        'label' => 'Pertes / Inventaire',
        'url'   => '/admin/compta/pertes',
        'desc'  => 'Casse, périmé, vol, offert... Chaque perte est <strong>valorisée au coût</strong> et <strong>déduite du stock théorique</strong> : elle explique un écart d\'inventaire au lieu de le laisser mystérieux.',
    ],
    'evenements' => [
        'label' => 'Événements',
        'url'   => '/admin/compta/evenements',
        'desc'  => 'Crée un événement avec le <strong>nom exact du bouton SumUp</strong> : les ventes importées s\'y rattachent automatiquement, tu saisis les coûts, et le bénéfice se calcule tout seul.',
    ],
    'inventaire' => [
        'label' => 'Inventaire',
        'url'   => '/admin/compta/inventaire-tablette',
        'desc'  => 'Stock tablette SumUp : écarts, corrigés et pertes',
    ],
];

if (!isset($opSections[$section ?? ''])) {
    $section = 'achats';
}
$opCurrent = $opSections[$section];

// Onglet de niveau 1 actif : mapping partagé OperationsTabs (classe
// pure testée unitairement) — « pertes » et « inventaire » allument le
// MÊME onglet « Pertes / Inventaire », dont les sous-onglets de
// niveau 2 (nav ci-dessous) distinguent les deux pages.
$opTabActive = \App\Core\Compta\OperationsTabs::tabFor($section);

// Vue historique de la section (même clé que l'onglet / sous-onglet).
$opInclude = [
    'achats'     => 'purchases',
    'depenses'   => 'expenses',
    'pertes'     => 'pertes',
    'evenements' => 'evenements',
    'inventaire' => 'inventaire_tablette',
][$section];
?>
<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité</p>
        <h1 class="page-title">Opérations</h1>
        <p class="muted"><?= $opCurrent['desc'] // texte interne maîtrisé (avec <strong>) ?></p>
    </div>
</div>

<nav class="op-tabs" aria-label="Sections Opérations">
    <?php foreach ($opSections as $key => $s): ?>
        <?php if (\App\Core\Compta\OperationsTabs::isSubSection($key)) {
            continue; // sous-section : pas d'onglet de niveau 1 propre
        } ?>
        <a class="op-tab<?= $key === $opTabActive ? ' is-active' : '' ?>" href="<?= e(url($s['url'])) ?>"><?= e($s['label']) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($opTabActive === 'pertes'): ?>
<nav class="op-tabs op-subtabs" aria-label="Pertes / Inventaire">
    <a class="op-tab<?= $section === 'pertes' ? ' is-active' : '' ?>" href="<?= e(url('/admin/compta/pertes')) ?>">Pertes</a>
    <a class="op-tab<?= $section === 'inventaire' ? ' is-active' : '' ?>" href="<?= e(url('/admin/compta/inventaire-tablette')) ?>">Inventaire</a>
</nav>
<?php endif; ?>

<?php require AEIC_VIEWS . '/admin/compta/' . $opInclude . '.php'; ?>

<!-- Boîte à outils tableaux (lot 4B) : filtre rapide des journaux
     (une seule inclusion pour toutes les sections Opérations). -->
<script src="<?= e(rootAssetVersioned('/assets/js/table-tools.js')) ?>" defer></script>
