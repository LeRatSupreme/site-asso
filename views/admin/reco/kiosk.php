<?php

declare(strict_types=1);

/**
 * Kiosque ADMIN — « Idées du moment » : version téléphone compacte de la
 * carte de recommandations, MÊMES COMPOSANTS ET MÊMES RÈGLES que la vue
 * admin : DEUX BLOCS (« À corriger » / « Idées pour vendre plus »),
 * idées REGROUPÉES PAR NATURE (1 carte = 1 nature, produits en
 * <details>), confiance EN MOTS, gain en fourchette, dépliant « Voir
 * les autres idées », état vide sobre. Accès par lien secret (jeton
 * admin = l'authentification, sans connexion) ; les POST sont sans CSRF
 * (Router::dispatch), le jeton du chemin EST l'authentification.
 * JAMAIS de code de règle (R1…) : libellés humains via RecoLibelles.
 *
 * @var list<array<string,mixed>> $idees        Idées du jour (bloc 'idee', toArray() du cœur).
 * @var list<array<string,mixed>> $corrections  Corrections du jour (bloc 'correction').
 * @var list<array<string,mixed>> $autres_idees Idées écartées par les caps (extension additive).
 * @var array<string,mixed>       $meta        Méta du moteur (indisponible, raison…).
 * @var bool                      $insuffisant Historique insuffisant (< 14 jours ouverts).
 * @var bool                      $indisponible Vrai si le moteur Reco est en échec.
 * @var string                    $raison      Raison de l'indisponibilité.
 * @var bool                      $kiosk       Toujours vrai (rendu kiosque).
 * @var string                    $token       Jeton kiosque admin.
 * @var list<array{titre:string,regle_lib:string,applied_at:string,conclusion:string,libelle:string,note:string,effet:float}> $hist
 */

use App\Core\Compta\Reco\Hyper;
use App\Core\Compta\Reco\RecoLibelles;

$kiosk          = true;
$idees          = is_array($idees ?? null) ? array_values(array_filter($idees, 'is_array')) : [];
$corrections    = is_array($corrections ?? null) ? array_values(array_filter($corrections, 'is_array')) : [];
$autres         = array_values(array_filter(is_array($autres_idees ?? null) ? $autres_idees : [], 'is_array'));
$meta           = is_array($meta ?? null) ? $meta : [];
$indispo        = (bool) ($indisponible ?? ($meta['indisponible'] ?? false));
$raison         = trim((string) ($raison ?? ($meta['raison'] ?? '')));
$insuff         = (bool) ($insuffisant ?? false);
$actionDecision = url('/kiosque/admin/reco/decision/' . rawurlencode($token));
$hist           = is_array($hist ?? null) ? $hist : [];

// « Ce qui a marché » : top 3 des idées concluantes à effet mesuré
// strictement positif (MÊME critère que la section « ✅ Ce qui a
// marché » de la page historique admin — jamais de concluant négatif).
$reussiesKiosk = [];
foreach ($hist as $h) {
    if ((string) ($h['conclusion'] ?? '') === 'concluant' && (float) ($h['effet'] ?? 0.0) > 0.0) {
        $reussiesKiosk[] = $h;
    }
}
$reussiesKiosk = array_slice($reussiesKiosk, 0, 3);

// Regroupement PAR NATURE (même règle que la vue admin, ≤ 3 cartes).
$groupes = [];
foreach ($idees as $idee) {
    if ((string) ($idee['id'] ?? '') === '') {
        continue;
    }
    $groupes[(string) ($idee['regle'] ?? '')][] = $idee;
}
$groupes = array_slice($groupes, 0, Hyper::MAX_IDEES);

$badgeConfiance = static function (string $niveau): string {
    return match ($niveau) {
        'haute'   => '<span class="krb is-up">confiance élevée</span>',
        'moyenne' => '<span class="krb is-off">confiance moyenne</span>',
        default   => '<span class="krb is-muted">confiance faible</span>',
    };
};

// Gain estimé en fourchette (format fr via formatPrice).
$gainTxt = static function (array $gain): string {
    $bas  = (float) ($gain['bas'] ?? 0.0);
    $haut = (float) ($gain['haut'] ?? 0.0);
    if ($bas <= 0.0 && $haut <= 0.0) {
        return '';
    }
    $bas  = max(0.0, $bas);
    $haut = max($bas, $haut);
    if ($haut - $bas < 0.005) {
        return 'environ ' . formatPrice($haut, 0) . ' par semaine';
    }

    return 'entre ' . formatPrice($bas, 0) . ' et ' . formatPrice($haut, 0) . ' par semaine';
};

$champs = static function (array $idee): string {
    $id = (string) ($idee['id'] ?? '');

    return '<input type="hidden" name="id" value="' . e($id) . '">';
};

// Bouton principal d'une correction ({href, libelle} côté contrôleur ;
// payloads d'avant l'itération 3 : chaîne → libellé par défaut).
$lienPrincipal = static function (array $item): array {
    $l = $item['lien'] ?? null;
    if (is_array($l)) {
        $href = (string) ($l['href'] ?? '');

        return $href !== '' ? ['href' => $href, 'libelle' => (string) ($l['libelle'] ?? 'Ouvrir la fiche du produit')] : [];
    }
    if (is_string($l) && $l !== '') {
        return ['href' => $l, 'libelle' => 'Ouvrir la fiche du produit'];
    }

    return [];
};
?>
<div class="kr-page">

    <header class="ka-head">
        <h1 class="ka-title">Idées du moment</h1>
        <p class="ka-period">Le site propose, vous décidez — rien n'est appliqué automatiquement</p>
    </header>

    <?php if ($indispo): ?>
        <section class="card surface glass ka-card">
            <p class="ka-fc-note">Recommandations indisponibles<?= $raison !== '' ? ' (' . e($raison) . ')' : '' ?>.</p>
        </section>
    <?php elseif ($idees === [] && $corrections === []): ?>
        <?php if ($insuff): ?>
            <section class="card surface glass ka-card">
                <span class="ka-kpi-delta is-off">Idées limitées : historique insuffisant (&lt; <?= (int) Hyper::HIST_MIN_JOURS_OUVERTS ?> jours ouverts)</span>
            </section>
        <?php endif; ?>
        <section class="card surface glass ka-card">
            <span class="ka-kpi-delta is-muted">Rien d'important cette semaine.</span>
        </section>
    <?php else: ?>
        <?php if ($insuff): ?>
            <section class="card surface glass ka-card">
                <span class="ka-kpi-delta is-off">Idées limitées : historique insuffisant (&lt; <?= (int) Hyper::HIST_MIN_JOURS_OUVERTS ?> jours ouverts)</span>
            </section>
        <?php endif; ?>

        <!-- ======================================================== -->
        <!--  Bloc « À corriger » : données / stock (pas d'appliquer) -->
        <!-- ======================================================== -->
        <header class="ka-head">
            <h2 class="ka-chart-title">À corriger</h2>
        </header>
        <?php if ($corrections === []): ?>
            <section class="card surface glass ka-card">
                <span class="ka-kpi-delta is-muted">Rien à corriger pour l'instant.</span>
            </section>
        <?php else: ?>
            <?php foreach ($corrections as $c): ?>
                <?php
                $idC = (string) ($c['id'] ?? '');
                if ($idC === '') { continue; }
                $lienC = $lienPrincipal($c);
                ?>
                <section class="card surface glass ka-card kr-idee">
                    <div class="kr-head">
                        <strong><?= e((string) ($c['titre'] ?? '')) ?></strong>
                        <span class="krb is-rule"><?= e(RecoLibelles::regle((string) ($c['regle'] ?? ''))) ?></span>
                    </div>
                    <p class="kr-pourquoi"><?= e((string) ($c['pourquoi'] ?? '')) ?></p>
                    <div class="kr-actions">
                        <?php if ($lienC !== []): ?>
                            <a class="btn btn-primary btn-sm" href="<?= e($lienC['href']) ?>" target="_blank" rel="noopener"><?= e($lienC['libelle']) ?></a>
                        <?php endif; ?>
                        <form method="post" action="<?= e($actionDecision) ?>" class="kr-form">
                            <?= $champs($c) ?>
                            <button type="submit" name="decision" value="plus_tard" class="btn btn-ghost btn-sm">Plus tard</button>
                        </form>
                        <form method="post" action="<?= e($actionDecision) ?>" class="kr-form">
                            <?= $champs($c) ?>
                            <input type="hidden" name="motif" value="non_pertinent">
                            <button type="submit" name="decision" value="ignorer" class="btn btn-ghost btn-sm">Pas pour moi</button>
                        </form>
                    </div>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- ======================================================== -->
        <!--  Bloc « Idées pour vendre plus » : 1 carte = 1 nature    -->
        <!-- ======================================================== -->
        <header class="ka-head">
            <h2 class="ka-chart-title">Idées pour vendre plus</h2>
        </header>
        <?php if ($idees === []): ?>
            <section class="card surface glass ka-card">
                <span class="ka-kpi-delta is-muted">Rien d'important cette semaine.</span>
            </section>
        <?php else: ?>
            <?php foreach ($groupes as $regle => $ideesDuGroupe): ?>
                <?php
                $produitsGroupe = [];
                foreach ($ideesDuGroupe as $ideeG) {
                    foreach ((array) ($ideeG['produits'] ?? []) as $pg) {
                        if (is_array($pg) && (string) ($pg['label'] ?? $pg['key'] ?? '') !== '') {
                            $produitsGroupe[(string) ($pg['key'] ?? $pg['label'])] = (string) ($pg['label'] ?? $pg['key']);
                        }
                    }
                }
                ?>
                <section class="card surface glass ka-card kr-idee">
                    <div class="kr-head">
                        <span class="krb is-rule"><?= e(RecoLibelles::regle($regle)) ?></span>
                    </div>
                    <?php foreach ($ideesDuGroupe as $idee): ?>
                        <?php
                        $idIdee = (string) ($idee['id'] ?? '');
                        if ($idIdee === '') { continue; }
                        $gain      = is_array($idee['gain_hebdo'] ?? null) ? $idee['gain_hebdo'] : [];
                        $gainLib   = $gainTxt($gain);
                        $confiance = (string) ($idee['confiance'] ?? 'faible');
                        $debut     = is_string($idee['debut'] ?? null) ? $idee['debut'] : '';
                        $fin       = is_string($idee['fin'] ?? null) ? $idee['fin'] : '';
                        $hypoth    = array_values(array_filter(is_array($idee['hypotheses'] ?? null) ? $idee['hypotheses'] : [], 'is_string'));
                        $mesure    = (string) ($idee['comment_mesurer'] ?? '');
                        ?>
                        <div class="kr-idee-item">
                            <p class="kr-chiffres">
                                <?= $badgeConfiance($confiance) ?>
                                <?php if ($gainLib !== ''): ?>
                                    <span class="krb is-gain">Gain estimé : <?= e($gainLib) ?></span>
                                <?php endif; ?>
                            </p>
                            <?php if ($debut !== '' && $fin !== ''): ?>
                                <span class="ka-kpi-delta is-muted">Du <?= e(formatDate($debut, 'd/m/Y')) ?> au <?= e(formatDate($fin, 'd/m/Y')) ?></span>
                            <?php endif; ?>
                            <p class="kr-pourquoi"><?= e((string) ($idee['pourquoi'] ?? '')) ?></p>
                            <p class="kr-action"><?= e((string) ($idee['action'] ?? '')) ?></p>
                            <?php if ($hypoth !== [] || $mesure !== ''): ?>
                                <details class="kr-details">
                                    <summary>Hypothèses et mesure</summary>
                                    <ul class="kr-hypotheses">
                                        <?php foreach ($hypoth as $h): ?>
                                            <li><?= e($h) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <?php if ($mesure !== ''): ?>
                                        <p class="kr-mesure"><?= e($mesure) ?></p>
                                    <?php endif; ?>
                                </details>
                            <?php endif; ?>
                            <div class="kr-actions">
                                <form method="post" action="<?= e($actionDecision) ?>" class="kr-form">
                                    <?= $champs($idee) ?>
                                    <button type="submit" name="decision" value="appliquer" class="btn btn-primary btn-sm">Je le fais</button>
                                </form>
                                <form method="post" action="<?= e($actionDecision) ?>" class="kr-form">
                                    <?= $champs($idee) ?>
                                    <button type="submit" name="decision" value="plus_tard" class="btn btn-ghost btn-sm">Plus tard</button>
                                </form>
                                <form method="post" action="<?= e($actionDecision) ?>" class="kr-form">
                                    <?= $champs($idee) ?>
                                    <input type="hidden" name="motif" value="non_pertinent">
                                    <button type="submit" name="decision" value="ignorer" class="btn btn-ghost btn-sm">Pas pour moi</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($produitsGroupe !== []): ?>
                        <details class="kr-details">
                            <summary>Produits concernés (<?= count($produitsGroupe) ?>)</summary>
                            <ul class="kr-hypotheses">
                                <?php foreach ($produitsGroupe as $labelProduit): ?>
                                    <li><?= e($labelProduit) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
            <?php if ($autres !== []): ?>
                <details class="kr-details ka-card">
                    <summary>Voir les autres idées (<?= count($autres) ?>)</summary>
                    <ul class="kr-hypotheses">
                        <?php foreach ($autres as $a): ?>
                            <li>
                                <strong><?= e(RecoLibelles::regle((string) ($a['regle'] ?? ''))) ?></strong>
                                — <?= e((string) ($a['titre'] ?? '')) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>

    <!-- ============================================================ -->
    <!--  Ce qui a marché : top 3 des idées concluantes               -->
    <!-- ============================================================ -->
    <header class="ka-head kr-hist-head">
        <h2 class="ka-chart-title">Ce qui a marché</h2>
    </header>
    <?php if ($reussiesKiosk === []): ?>
        <section class="card surface glass ka-card">
            <span class="ka-kpi-delta is-muted">Aucune idée concluante pour l'instant.</span>
        </section>
    <?php else: ?>
        <?php foreach ($reussiesKiosk as $h): ?>
            <section class="card surface glass ka-card">
                <div class="kr-head">
                    <strong><?= e((string) ($h['titre'] ?? '')) ?></strong>
                    <span class="krb is-rule"><?= e((string) ($h['regle_lib'] ?? '')) ?></span>
                    <span class="krb is-up">ça a marché</span>
                </div>
                <span class="ka-kpi-delta is-up">
                    appliquée le <?= e((string) ($h['applied_at'] ?? '') !== '' ? formatDate(substr((string) $h['applied_at'], 0, 10), 'd/m/Y') : '—') ?>
                    <?php if ((string) ($h['libelle'] ?? '') !== ''): ?> · <?= e((string) $h['libelle']) ?><?php endif; ?>
                </span>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- ============================================================ -->
    <!--  Historique court des idées appliquées                        -->
    <!-- ============================================================ -->
    <header class="ka-head kr-hist-head">
        <h2 class="ka-chart-title">Idées appliquées</h2>
    </header>
    <?php if ($hist === []): ?>
        <section class="card surface glass ka-card">
            <span class="ka-kpi-delta is-muted">Aucune idée appliquée pour l'instant.</span>
        </section>
    <?php else: ?>
        <?php foreach ($hist as $h): ?>
            <section class="card surface glass ka-card">
                <div class="kr-head">
                    <strong><?= e($h['titre']) ?></strong>
                    <span class="krb is-rule"><?= e($h['regle_lib']) ?></span>
                    <?php if ($h['conclusion'] === 'concluant'): ?>
                        <span class="krb is-up">concluant</span>
                    <?php elseif ($h['conclusion'] === 'non_concluant'): ?>
                        <span class="<?= str_contains($h['note'], 'chevauchement') ? 'krb is-off' : 'krb is-muted' ?>">
                            <?= str_contains($h['note'], 'chevauchement') ? 'chevauchement' : 'non concluant' ?>
                        </span>
                    <?php endif; ?>
                </div>
                <span class="ka-kpi-delta is-muted">
                    appliquée le <?= e($h['applied_at'] !== '' ? formatDate(substr($h['applied_at'], 0, 10), 'd/m/Y') : '—') ?>
                    <?php if ($h['libelle'] !== ''): ?> · <?= e($h['libelle']) ?><?php endif; ?>
                </span>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Nav de bas de page kiosque. -->
    <div class="ka-actions">
        <a class="btn btn-ghost btn-sm" href="<?= e(url('/kiosque/admin/' . rawurlencode($token))) ?>">← Kiosque admin</a>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('/kiosque/admin/analytics/' . rawurlencode($token))) ?>">Analytique</a>
    </div>
</div>
