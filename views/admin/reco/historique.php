<?php

declare(strict_types=1);

/**
 * Historique des idées (recommandations commerciales), refondu en
 * TROIS sections + annexes conservées :
 *  - « ✅ Ce qui a marché » (EN TÊTE) : idées concluantes à effet
 *    mesuré strictement positif — ce que le conseil a réellement
 *    rapporté, libellé humain via EffetMesure::libelle ;
 *  - « En cours » : idées appliquées en cours de mesure (~4 semaines)
 *    et corrections appliquées (sans mesure d'effet, par nature) ;
 *  - « Mesurées » : effet en euros AVEC fourchette (EffetMesure) ou
 *    verdict « non concluant » ;
 *  - « Ignorées » : motif d'ignorance et bouton « Rétablir » (remise à
 *    l'état proposable — l'idée repart dans « Idées en attente »).
 * Suivent « Idées en attente » (décidables) et « Seuils du moteur ».
 *
 * JAMAIS de code de règle (R1…) : libellés humains via RecoLibelles.
 * Aucun calcul métier en vue : les effets viennent du contrôleur/cœur.
 *
 * @var string $title
 * @var list<array<string,mixed>> $lignes    Idées appliquées (Recommendation::historique()).
 * @var list<array<string,mixed>> $reussies  Mesures concluantes à effet > 0.
 * @var list<array<string,mixed>> $proposees Idées en attente (Recommendation::proposees()).
 * @var list<array<string,mixed>> $ignorees  Idées ignorées (Recommendation::ignorees()).
 * @var array<string,string>      $seuils    Valeurs courantes des seuils « reco_* ».
 */

use App\Core\Compta\Reco\EffetMesure;
use App\Core\Compta\Reco\RecoLibelles;

// « Ce qui a marché » : mesures concluantes à effet positif fournies
// par le contrôleur (jamais recalculées ici) ; normalisation défensive.
$reussies = is_array($reussies ?? null) ? $reussies : [];

$libellesSeuils = [
    'reco_marge_min'              => 'Marge minimale (fraction, ex. 0.15 = 15 %)',
    'reco_stock_dormant_semaines' => 'Seuil stock dormant (semaines)',
    'reco_stock_dormant_min'      => 'Stock minimal pour stock dormant (unités)',
    'reco_echantillon_min_unites' => 'Échantillon minimal par règle (unités / 30 j)',
    'reco_duo_remise_max'         => 'Remise max d\'un duo (fraction)',
    'reco_immobilise_seuil_eur'   => 'Seuil stock immobilisé gamme (€)',
    'reco_max_idees'              => 'Nombre max d\'idées commerciales',
    'reco_max_corrections'        => 'Nombre max de corrections affichées',
];

// Découpage des lignes APPLIQUÉES : en cours de mesure (pas encore
// contrôlées) vs mesurées (verdict posé), et corrections (sans mesure).
$lignesEnCours = [];
$lignesMesurees = [];
$lignesCorrection = [];
foreach ($lignes as $l) {
    $dec = json_decode((string) ($l['payload_json'] ?? '{}'), true);
    $bloc = is_array($dec) ? (string) ($dec['bloc'] ?? 'idee') : 'idee';
    $conclusion = (string) ($l['effet_conclusion'] ?? '');
    if ($bloc === 'correction') {
        $lignesCorrection[] = $l;
    } elseif ($conclusion === 'concluant' || $conclusion === 'non_concluant') {
        $lignesMesurees[] = $l;
    } else {
        $lignesEnCours[] = $l;
    }
}

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
            <p class="muted">Le site propose, vous décidez : historique des idées appliquées et de leur effet mesuré. Aucun prix ni stock n'est modifié automatiquement.</p>
        </div>
    </div>

    <!-- ============================================================ -->
    <!--  0. CE QUI A MARCHÉ : idées concluantes à effet positif       -->
    <!--      (la valorisation du conseil : ce qu'il a rapporté)       -->
    <!-- ============================================================ -->
    <div class="card surface glass chart-card">
        <div class="chart-head">
            <h2 class="chart-title">✅ Ce qui a marché</h2>
            <span class="chart-sub">idées appliquées dont l'effet mesuré est concluant et positif</span>
        </div>
        <?php if ($reussies === []): ?>
            <p class="muted fc-empty">Aucune idée concluante pour l'instant.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>Idée appliquée</th><th>Nature</th><th>Appliquée le</th><th>Effet mesuré</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reussies as $l): ?>
                            <?php
                            // Effet déjà décodé/normalisé par le contrôleur
                            // (prêt pour EffetMesure::libelle — libellé
                            // humain, jamais de code R#).
                            $effetReussi = is_array($l['effet'] ?? null) ? $l['effet'] : null;
                            ?>
                            <tr>
                                <td><?= e((string) ($l['titre'] ?? '')) ?></td>
                                <td><span class="reco-badge is-rule"><?= e(RecoLibelles::regle((string) ($l['regle'] ?? ''))) ?></span></td>
                                <td><?= e((string) ($l['applied_at'] ?? '') !== '' ? formatDate(substr((string) $l['applied_at'], 0, 10), 'd/m/Y') : '—') ?></td>
                                <td><span class="reco-badge is-up"><?= e($effetReussi !== null ? EffetMesure::libelle($effetReussi) : '') ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- ============================================================ -->
    <!--  1. EN COURS : idées appliquées en cours de mesure +          -->
    <!--      corrections appliquées (sans mesure d'effet)             -->
    <!-- ============================================================ -->
    <div class="card surface glass chart-card">
        <div class="chart-head">
            <h2 class="chart-title">En cours</h2>
            <span class="chart-sub">mesure de l'effet en cours, résultat dans environ 4 semaines</span>
        </div>
        <?php if ($lignesEnCours === [] && $lignesCorrection === []): ?>
            <p class="muted fc-empty">Rien en cours pour l'instant. Les idées appliquées depuis la carte apparaîtront ici.</p>
        <?php else: ?>
            <?php if ($lignesEnCours !== []): ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr><th>Idée</th><th>Nature</th><th>Appliquée le</th><th>Statut</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lignesEnCours as $l): ?>
                                <tr>
                                    <td><?= e((string) ($l['titre'] ?? '')) ?></td>
                                    <td><span class="reco-badge is-rule"><?= e(RecoLibelles::regle((string) ($l['regle'] ?? ''))) ?></span></td>
                                    <td><?= e((string) ($l['applied_at'] ?? '') !== '' ? formatDate(substr((string) $l['applied_at'], 0, 10), 'd/m/Y') : '—') ?></td>
                                    <td><?= $badgeConclusion($l) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <?php if ($lignesCorrection !== []): ?>
                <h3 class="reco-sous-titre">Corrections appliquées</h3>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr><th>Correction</th><th>Nature</th><th>Appliquée le</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lignesCorrection as $l): ?>
                                <tr>
                                    <td><?= e((string) ($l['titre'] ?? '')) ?></td>
                                    <td><span class="reco-badge is-rule"><?= e(RecoLibelles::regle((string) ($l['regle'] ?? ''))) ?></span></td>
                                    <td><?= e((string) ($l['applied_at'] ?? '') !== '' ? formatDate(substr((string) $l['applied_at'], 0, 10), 'd/m/Y') : '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- ============================================================ -->
    <!--  2. MESURÉES : effet en € (fourchette) ou non concluant       -->
    <!-- ============================================================ -->
    <div class="card surface glass chart-card">
        <div class="chart-head">
            <h2 class="chart-title">Mesurées</h2>
            <span class="chart-sub">effet observé avec sa fourchette, ou verdict non concluant</span>
        </div>
        <?php if ($lignesMesurees === []): ?>
            <p class="muted fc-empty">Rien de mesuré pour l'instant — les mesures prennent environ 4 semaines.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>Idée</th><th>Nature</th><th>Appliquée le</th><th>Effet mesuré</th><th>Verdict</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lignesMesurees as $l): ?>
                            <?php
                            // $l['effet'] : tableau déjà décodé/normalisé par
                            // le contrôleur (prêt pour EffetMesure::libelle).
                            $effet = is_array($l['effet'] ?? null) ? $l['effet'] : null;
                            $libelle = $effet !== null ? EffetMesure::libelle($effet) : 'non concluant';
                            ?>
                            <tr>
                                <td><?= e((string) ($l['titre'] ?? '')) ?></td>
                                <td><span class="reco-badge is-rule"><?= e(RecoLibelles::regle((string) ($l['regle'] ?? ''))) ?></span></td>
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
    <!--  3. IGNORÉES : motif + bouton « Rétablir »                    -->
    <!-- ============================================================ -->
    <div class="card surface glass chart-card">
        <div class="chart-head">
            <h2 class="chart-title">Ignorées</h2>
            <span class="chart-sub">idées écartées — rétablissez celle que vous voulez revoir</span>
        </div>
        <?php if ($ignorees === []): ?>
            <p class="muted fc-empty">Aucune idée ignorée pour l'instant.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>Idée</th><th>Nature</th><th>Ignorée le</th><th>Motif</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ignorees as $l): ?>
                            <tr>
                                <td><?= e((string) ($l['titre'] ?? '')) ?></td>
                                <td><span class="reco-badge is-rule"><?= e(RecoLibelles::regle((string) ($l['regle'] ?? ''))) ?></span></td>
                                <td><?= e((string) ($l['decided_at'] ?? '') !== '' ? formatDate(substr((string) $l['decided_at'], 0, 10), 'd/m/Y') : '—') ?></td>
                                <td><?= e(trim((string) ($l['motif'] ?? '')) !== '' ? (string) $l['motif'] : '—') ?></td>
                                <td>
                                    <form method="post" action="<?= e(url('/admin/reco/retablir')) ?>" class="reco-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= e((string) ($l['id'] ?? '')) ?>">
                                        <button type="submit" class="btn btn-outline btn-sm">Rétablir</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
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
                        <tr><th>Idée</th><th>Nature</th><th>Proposée le</th><th>Décision</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($proposees as $p): ?>
                            <tr>
                                <td><?= e((string) ($p['titre'] ?? '')) ?></td>
                                <td><span class="reco-badge is-rule"><?= e(RecoLibelles::regle((string) ($p['regle'] ?? ''))) ?></span></td>
                                <td><?= e((string) ($p['created_at'] ?? '') !== '' ? formatDate(substr((string) $p['created_at'], 0, 10), 'd/m/Y') : '—') ?></td>
                                <td>
                                    <div class="reco-actions">
                                        <form method="post" action="<?= e(url('/admin/reco/decision')) ?>" class="reco-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) ($p['id'] ?? '')) ?>">
                                            <input type="hidden" name="retour" value="/admin/reco/historique">
                                            <button type="submit" name="decision" value="appliquer" class="btn btn-primary btn-sm">Je le fais</button>
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
                                            <button type="submit" name="decision" value="ignorer" class="btn btn-ghost btn-sm">Pas pour moi</button>
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
    <!--  Seuils du moteur (reco_*) — conservé, inchangé               -->
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
