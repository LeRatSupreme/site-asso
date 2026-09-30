<?php

declare(strict_types=1);

/**
 * Page fusionnée « Opérations » : une seule page compta au quotidien,
 * quatre onglets de niveau 1 — Achats & stock, Dépenses, Pertes,
 * Événements. Les quatre routes historiques (/admin/compta/achats,
 * /depenses, /pertes, /evenements) rendent toutes cette page avec leur
 * section active ; le contenu de l'onglet est la vue historique de la
 * section, incluse telle quelle (période, onglets internes, formulaires
 * et routes POST inchangés).
 *
 * @var string $section 'achats'|'depenses'|'pertes'|'evenements'
 */

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
        'label' => 'Pertes',
        'url'   => '/admin/compta/pertes',
        'desc'  => 'Casse, périmé, vol, offert... Chaque perte est <strong>valorisée au coût</strong> et <strong>déduite du stock théorique</strong> : elle explique un écart d\'inventaire au lieu de le laisser mystérieux.',
    ],
    'evenements' => [
        'label' => 'Événements',
        'url'   => '/admin/compta/evenements',
        'desc'  => 'Crée un événement avec le <strong>nom exact du bouton SumUp</strong> : les ventes importées s\'y rattachent automatiquement, tu saisis les coûts, et le bénéfice se calcule tout seul.',
    ],
];

if (!isset($opSections[$section ?? ''])) {
    $section = 'achats';
}
$opCurrent = $opSections[$section];

// Vue historique de la section (même clé que l'onglet).
$opInclude = [
    'achats'     => 'purchases',
    'depenses'   => 'expenses',
    'pertes'     => 'pertes',
    'evenements' => 'evenements',
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
        <a class="op-tab<?= $key === $section ? ' is-active' : '' ?>" href="<?= e(url($s['url'])) ?>"><?= e($s['label']) ?></a>
    <?php endforeach; ?>
</nav>

<?php require AEIC_VIEWS . '/admin/compta/' . $opInclude . '.php'; ?>
