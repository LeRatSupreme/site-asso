<?php

declare(strict_types=1);

/**
 * Accueil admin « À faire aujourd'hui » — une page, trois variantes selon
 * le rôle (aucun calcul ici : tout vient des moteurs Dashboard ou des
 * compteurs déjà calculés côté contrôleur).
 *
 *  - « compta »  : verdict + 4 cartes + bloc « À faire » (payload
 *                  Dashboard\Data, le même que /admin/compta) + sections
 *                  détaillées repliées (partiaux partagés) + liens experts.
 *  - « benevol » : page outil minimaliste — trois tuiles-liens, AUCUN
 *                  chiffre, AUCUN montant.
 *  - « bureau »  : accueil non financier — compteurs + liens rapides vers
 *                  les sections du rôle (+ brouillons d'événements à
 *                  publier si le rôle gère les événements).
 *
 * @var array<string,mixed> $user
 * @var 'compta'|'benevol'|'bureau' $variante
 *
 * Variante compta (payload Dashboard\Data — mêmes clés que /admin/compta) :
 * @var array{phrase:string, code:string, objectif_phrase:?string} $verdict
 * @var list<array{cle:string, label:string, valeur:string, valeur_num:float, repere:?string, repere_code:string, aide:string, ligne2:?string}> $cartes
 * @var list<array{cle:string, urgence:int, texte:string, lien:string, urgence_label:string}> $afaire
 * @var array{preset:string, from:?string, to:?string} $period
 * @var list<array<string,mixed>> $byCategory
 * @var list<array<string,mixed>> $top
 * @var array<string,float> $split
 * @var int $txCount
 * @var float $cardFee
 * @var float $cardNet
 * @var string $feeRate
 * @var list<array<string,mixed>> $vat
 * @var float $vatTotal
 * @var float $expenseTtc
 * @var int $expenseCount
 *
 * Variante bureau :
 * @var int $usersCount
 * @var int $eventsCount
 * @var list<array<string,mixed>> $draftEvents
 * @var list<array{label:string, url:string}> $liensRapides
 */
?>
<?php if ($variante === 'benevol'): ?>
    <?php /* Page outil bénévole : aucun chiffre, aucun montant, aucun compteur. */ ?>
    <p class="muted">Votre poste du moment. Choisissez un outil, votre travail s'enregistre directement.</p>
    <div class="grid grid-3 stat-cards">
        <a class="stat-card surface glass" href="<?= e(url('/admin/compta/caisse')) ?>">
            <span class="stat-label">Comptage caisse</span>
            <span class="muted">Compter l'argent liquide de la caisse</span>
        </a>
        <a class="stat-card surface glass" href="<?= e(url('/admin/compta/inventaire/comptage')) ?>">
            <span class="stat-label">Comptage inventaire</span>
            <span class="muted">Compter ce qu'il reste en stock</span>
        </a>
        <a class="stat-card surface glass" href="<?= e(url('/admin/wiki')) ?>">
            <span class="stat-label">Aide &amp; wiki</span>
            <span class="muted">Le guide de l'association</span>
        </a>
    </div>

<?php elseif ($variante === 'compta'): ?>
    <?php /* ── Variante compta : même source que /admin/compta (Dashboard\Data). ── */ ?>
    <p class="muted">L'essentiel du jour, puis le détail replié dessous. Le détail complet reste disponible sur la page Comptabilité.</p>

    <?php $vBadge = [
        'ok'        => 'badge-success',
        'attention' => 'badge-warning',
        'alerte'    => 'badge-danger',
        'neutre'    => 'badge-muted',
    ][$verdict['code']] ?? 'badge-muted'; ?>
    <div class="card surface glass dashboard-verdict dashboard-verdict-<?= e($verdict['code']) ?>">
        <p class="dashboard-verdict-phrase">
            <span class="badge <?= e($vBadge) ?>"><?= [
                'ok'        => 'Ça va',
                'attention' => 'Attention',
                'alerte'    => 'Urgent',
                'neutre'    => 'Info',
            ][$verdict['code']] ?? 'Info' ?></span>
            <?= e($verdict['phrase']) ?>
        </p>
        <?php if (!empty($verdict['objectif_phrase'])): ?>
            <p class="dashboard-verdict-objectif muted"
               title="Objectif calculé au prorata : objectif hebdomadaire × jours de la période ÷ 7 (voir Paramètres)."><?= e($verdict['objectif_phrase']) ?></p>
        <?php endif; ?>
    </div>

    <div class="compta-kpis">
        <?php foreach ($cartes as $c): ?>
            <div class="card surface glass kpi">
                <p class="kpi-label">
                    <span title="<?= e($c['aide']) ?>"><?= e($c['label']) ?> <span class="aide-mark" tabindex="0" title="<?= e($c['aide']) ?>">?</span></span>
                </p>
                <p class="kpi-value <?= (float) $c['valeur_num'] >= 0 ? '' : 'is-negative' ?>"><?= e($c['valeur']) ?></p>
                <?php if ($c['cle'] === 'profit' && !empty($c['ligne2'])): ?>
                    <p class="kpi-sub"><?= e($c['ligne2']) ?></p>
                <?php endif; ?>
                <p class="kpi-repere">
                    <?php if ($c['repere'] !== null && $c['repere'] !== ''): ?>
                        <span class="badge <?= e([
                            'ok'        => 'badge-success',
                            'attention' => 'badge-warning',
                            'alerte'    => 'badge-danger',
                            'neutre'    => 'badge-muted',
                        ][$c['repere_code']] ?? 'badge-muted') ?>"><?= e($c['repere']) ?></span>
                    <?php else: ?>
                        <span class="kpi-repere-none" title="Pas encore de point de comparaison fiable.">—</span>
                    <?php endif; ?>
                </p>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card surface glass">
        <h2 class="card-title" title="Les points qui méritent une action, du plus urgent au moins urgent.">À faire <span class="aide-mark" tabindex="0" title="Les points qui méritent une action, du plus urgent au moins urgent.">?</span></h2>
        <?php if ($afaire !== []): ?>
            <ul class="afaire-list">
                <?php foreach ($afaire as $item): ?>
                    <li class="afaire-item">
                        <span class="badge <?= (int) $item['urgence'] >= 3 ? 'badge-danger' : ((int) $item['urgence'] === 2 ? 'badge-warning' : 'badge-muted') ?>">
                            <?= e($item['urgence_label']) ?>
                        </span>
                        <span class="afaire-texte"><?= e($item['texte']) ?></span>
                        <a href="<?= e($item['lien']) ?>" class="btn btn-outline btn-sm">Ouvrir →</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="muted">Rien à faire : tout est à jour.</p>
        <?php endif; ?>
    </div>

    <?php /* Niveau 2 : le détail du dashboard compta, replié (partiaux partagés). */ ?>
    <?php require AEIC_VIEWS . '/admin/partials/compta_section_paiements.php'; ?>
    <?php require AEIC_VIEWS . '/admin/partials/compta_section_categories.php'; ?>
    <?php require AEIC_VIEWS . '/admin/partials/compta_section_top.php'; ?>
    <?php require AEIC_VIEWS . '/admin/partials/compta_section_depenses.php'; ?>

    <?php /* Niveau 3 : les outils experts. */ ?>
    <div class="admin-actions">
        <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/compta')) ?>">Comptabilité complète</a>
        <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/analytics')) ?>">Analyses</a>
        <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/compta/annuel')) ?>">Bilan annuel</a>
        <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/compta/ventes')) ?>">Journal des ventes</a>
    </div>

<?php else: ?>
    <?php /* ── Variante bureau (hors compta) : accueil non financier. ── */ ?>
    <p class="muted">Bonjour, voici l'état de l'association et vos raccourcis. Vos sections sont dans le menu à gauche.</p>

    <div class="grid grid-2 stat-cards">
        <div class="stat-card surface glass">
            <span class="stat-value"><?= e((string) $usersCount) ?></span>
            <span class="stat-label">Membres actifs</span>
        </div>
        <div class="stat-card surface glass">
            <span class="stat-value"><?= e((string) $eventsCount) ?></span>
            <span class="stat-label">Événements publiés</span>
        </div>
    </div>

    <?php if ($draftEvents !== []): ?>
        <div class="card surface glass">
            <h2 class="card-title" title="Événements enregistrés mais pas encore visibles par les élèves : ouvrez-les pour les publier.">À faire <span class="aide-mark" tabindex="0" title="Événements enregistrés mais pas encore visibles par les élèves : ouvrez-les pour les publier.">?</span></h2>
            <ul class="list-rows">
                <?php foreach ($draftEvents as $ev): ?>
                    <li>
                        <span><?= e((string) $ev['title']) ?></span>
                        <span class="card-meta"><?= e(formatDate($ev['date'] ?? null)) ?></span>
                        <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/events/' . (string) $ev['slug'])) ?>">Publier →</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($liensRapides !== []): ?>
        <div class="card surface glass">
            <h2 class="card-title">Vos raccourcis</h2>
            <div class="admin-actions">
                <?php foreach ($liensRapides as $lien): ?>
                    <a class="btn btn-outline btn-sm" href="<?= e(url($lien['url'])) ?>"><?= e($lien['label']) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>
