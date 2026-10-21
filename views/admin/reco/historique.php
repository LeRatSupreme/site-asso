<?php

declare(strict_types=1);

/**
 * Historique des idées appliquées (recommandations commerciales) :
 * effet mesuré par le cœur (EffetMesure), « Ce qui a marché », idées en
 * attente de décision et seuils modifiables du moteur.
 *
 * @var string $title
 * @var list<array<string,mixed>> $lignes    Idées appliquées (Recommendation::historique()).
 * @var list<array<string,mixed>> $reussies  Mesures concluantes à effet > 0.
 * @var list<array<string,mixed>> $proposees Idées en attente (Recommendation::proposees()).
 * @var array<string,string>      $seuils    Valeurs courantes des 7 seuils « reco_* ».
 */

use App\Core\Compta\Reco\EffetMesure;

$libellesSeuils = [
    'reco_marge_min'              => 'Marge minimale (fraction, ex. 0.15 = 15 %)',
    'reco_stock_dormant_semaines' => 'Seuil stock dormant (semaines)',
    'reco_stock_dormant_min'      => 'Stock minimal pour stock dormant (unités)',
    'reco_echantillon_min_unites' => 'Échantillon minimal par règle (unités / 30 j)',
    'reco_duo_remise_max'         => 'Remise max d\'un duo (fraction)',
    'reco_immobilise_seuil_eur'   => 'Seuil stock immobilisé gamme (€)',
    'reco_max_actives'            => 'Nombre max d\'idées actives',

];

$badgeConclusion = static function (array $l): string {
    $conclusion = (string) ($l['effet_conclusion'] ?? '');
    $note = is_array($l['effet'] ?? null) ? (string) ($l['effet']['note'] ?? '') : '';
    if ($conclusion === 'concluant') {
        return '<span class="reco-badge is-up">concluant</span>';
    }
    if ($conclusion === 'non_concluant') {
        return str_contains($note, 'chevauchement')
            ? '<span class="reco-badge is-off">chevauchement</span>'
            : '<span class="reco-badge is-muted">non concluant</span>';
    }

    return '<span class="reco-badge is-muted">mesure en cours</span>';
};
?>
<div class="reco-hist">

    <div class="compta-head">
        <div>
            <p class="eyebrow">Analyse</p>
            <h1 class="page-title">Idées appliquées</h1>
            <p class="muted">Le site propose, tu décides : historique des idées appliquées et de leur effet mesuré. Aucun prix ni stock n'est modifié automatiquement.</p>
        </div>
    </div>

    <!-- ============================================================ -->
    <!--  Idées appliquées : effet mesuré                               -->
    <!-- ============================================================ -->
    <div class="card surface glass chart-card">
        <div class="chart-head">
            <h2 class="chart-title">Idées appliquées</h2>
            <span class="chart-sub">effet mesuré ~4 semaines après l'application</span>
        </div>
        <?php if ($lignes === []): ?>
            <p class="muted fc-empty">Aucune idée appliquée pour l'instant. Les idées du moment sont sur le dashboard <a href="<?= e(url('/admin/analytics')) ?>">Analytics</a>.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>Idée</th><th>Règle</th><th>Appliquée le</th><th>Effet mesuré</th><th>Statut</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lignes as $l): ?>
                            <?php
                            // $l['effet'] : tableau déjà décodé/normalisé par
                            // le contrôleur (prêt pour EffetMesure::libelle).
                            $effet = is_array($l['effet'] ?? null) ? $l['effet'] : null;
                            $libelle = $effet !== null ? EffetMesure::libelle($effet) : 'Mesure pas encore déclenchée (délai de 14 jours ouverts).';
                            ?>
                            <tr>
                                <td><?= e((string) ($l['titre'] ?? '')) ?></td>
                                <td><span class="reco-badge is-rule"><?= e((string) ($l['regle'] ?? '')) ?></span></td>
                                <td><?= e((string) ($l['applied_at'] ?? '') !== '' ? formatDate(substr((string) $l['applied_at'], 0, 10), 'd/m/Y') : '—') ?></td>
                                <td><?= e($libelle) ?></td>
                                <td><?= $badgeConclusion($l) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- ============================================================ -->
    <!--  Ce qui a marché (effet mesuré > 0, concluant)                 -->
    <!-- ============================================================ -->
    <div class="card surface glass chart-card">
        <div class="chart-head">
            <h2 class="chart-title">Ce qui a marché</h2>
            <span class="chart-sub">effet mesuré strictement positif</span>
        </div>
        <?php if ($reussies === []): ?>
            <p class="muted fc-empty">Rien de concluant pour l'instant — les mesures prennent environ 4 semaines.</p>
        <?php else: ?>
            <ul class="reco-reussites">
                <?php foreach ($reussies as $r): ?>
                    <li>
                        <strong><?= e((string) ($r['titre'] ?? '')) ?></strong>
                        <span class="reco-badge is-rule"><?= e((string) ($r['regle'] ?? '')) ?></span>
                        — <?= e(EffetMesure::libelle(is_array($r['effet'] ?? null) ? $r['effet'] : [])) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <!-- ============================================================ -->
    <!--  Idées en attente de décision                                  -->
    <!-- ============================================================ -->
    <div class="card surface glass chart-card">
        <div class="chart-head">
            <h2 class="chart-title">Idées en attente</h2>
            <span class="chart-sub">proposées, pas encore décidées</span>
        </div>
        <?php if ($proposees === []): ?>
            <p class="muted fc-empty">Aucune idée en attente — les nouvelles apparaissent sur le dashboard <a href="<?= e(url('/admin/analytics')) ?>">Analytics</a>.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>Idée</th><th>Règle</th><th>Proposée le</th><th>Décision</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($proposees as $p): ?>
                            <tr>
                                <td><?= e((string) ($p['titre'] ?? '')) ?></td>
                                <td><span class="reco-badge is-rule"><?= e((string) ($p['regle'] ?? '')) ?></span></td>
                                <td><?= e((string) ($p['created_at'] ?? '') !== '' ? formatDate(substr((string) $p['created_at'], 0, 10), 'd/m/Y') : '—') ?></td>
                                <td>
                                    <div class="reco-actions">
                                        <form method="post" action="<?= e(url('/admin/reco/decision')) ?>" class="reco-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) ($p['id'] ?? '')) ?>">
                                            <input type="hidden" name="retour" value="/admin/reco/historique">
                                            <button type="submit" name="decision" value="appliquer" class="btn btn-primary btn-sm">Appliquer</button>
                                        </form>
                                        <form method="post" action="<?= e(url('/admin/reco/decision')) ?>" class="reco-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) ($p['id'] ?? '')) ?>">
                                            <input type="hidden" name="retour" value="/admin/reco/historique">
                                            <button type="submit" name="decision" value="plus_tard" class="btn btn-ghost btn-sm">Plus tard</button>
                                        </form>
                                        <form method="post" action="<?= e(url('/admin/reco/decision')) ?>" class="reco-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) ($p['id'] ?? '')) ?>">
                                            <input type="hidden" name="retour" value="/admin/reco/historique">
                                            <input type="hidden" name="motif" value="non_pertinent">
                                            <button type="submit" name="decision" value="ignorer" class="btn btn-ghost btn-sm">Ignorer</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- ============================================================ -->
    <!--  Seuils du moteur (reco_*)                                     -->
    <!-- ============================================================ -->
    <div class="card surface glass chart-card">
        <div class="chart-head">
            <h2 class="chart-title">Seuils du moteur</h2>
            <span class="chart-sub">s'appliquent à la prochaine génération d'idées</span>
        </div>
        <form method="post" action="<?= e(url('/admin/reco/parametres')) ?>" class="reco-seuils">
            <?= csrf_field() ?>
            <?php foreach ($libellesSeuils as $cle => $libelle): ?>
                <label class="reco-seuil">
                    <span><?= e($libelle) ?></span>
                    <input type="number" name="<?= e($cle) ?>" step="any" min="0"
                           value="<?= e((string) ($seuils[$cle] ?? '')) ?>" class="af-input">
                </label>
            <?php endforeach; ?>
            <div class="reco-actions">
                <button type="submit" class="btn btn-primary btn-sm">Enregistrer les seuils</button>
            </div>
        </form>
    </div>
</div>

<style>
    .reco-hist { display: flex; flex-direction: column; gap: 1.25rem; }
    .reco-badge { display: inline-block; font-size: .7rem; font-weight: 800; padding: .12rem .5rem; border-radius: 999px; border: 1px solid rgba(255,255,255,.14); }
    .reco-badge.is-rule { color: var(--primary, #48bdd3); border-color: rgba(72,189,211,.4); }
    .reco-badge.is-up { color: #22c55e; border-color: rgba(34,197,94,.4); }
    .reco-badge.is-off { color: #f59e0b; border-color: rgba(245,158,11,.4); }
    .reco-badge.is-muted { color: var(--muted, #9fb3c8); }
    .reco-reussites { margin: 0; padding-left: 1.1rem; display: flex; flex-direction: column; gap: .35rem; font-size: .88rem; }
    .reco-actions { display: flex; flex-wrap: wrap; align-items: center; gap: .45rem; }
    .reco-form { display: flex; align-items: center; gap: .35rem; margin: 0; }
    .reco-seuils { display: flex; flex-direction: column; gap: .6rem; max-width: 480px; }
    .reco-seuil { display: flex; flex-direction: column; gap: .2rem; }
    .reco-seuil span { font-size: .78rem; color: var(--muted, #9fb3c8); }
    .reco-seuil .af-input { width: 100%; }
</style>
