<?php

declare(strict_types=1);

/**
 * Kiosque ADMIN — « Idées du moment » : version téléphone compacte de la
 * carte de recommandations + historique court des idées appliquées.
 * Accès par lien secret (jeton admin = l'authentification, sans
 * connexion) ; les POST sont sans CSRF (Router::dispatch), le jeton du
 * chemin EST l'authentification.
 *
 * @var list<array<string,mixed>> $idees       Idées du jour (toArray() du cœur).
 * @var array<string,mixed>       $meta        Méta du moteur (indisponible, raison…).
 * @var bool                      $insuffisant Historique insuffisant (< 14 jours ouverts).
 * @var bool                      $indisponible Vrai si le moteur Reco est en échec.
 * @var string                    $raison      Raison de l'indisponibilité.
 * @var bool                      $kiosk       Toujours vrai (rendu kiosque).
 * @var string                    $token       Jeton kiosque admin.
 * @var list<array{titre:string,regle:string,applied_at:string,conclusion:string,libelle:string,note:string}> $hist
 */

use App\Core\Compta\Reco\Hyper;

$kiosk          = true;
$idees          = is_array($idees ?? null) ? $idees : [];
$meta           = is_array($meta ?? null) ? $meta : [];
$indispo        = (bool) ($indisponible ?? ($meta['indisponible'] ?? false));
$raison         = trim((string) ($raison ?? ($meta['raison'] ?? '')));
$insuff         = (bool) ($insuffisant ?? false);
$actionDecision = url('/kiosque/admin/reco/decision/' . rawurlencode($token));
$hist           = is_array($hist ?? null) ? $hist : [];

$badgeConfiance = static function (string $niveau): string {
    return match ($niveau) {
        'haute'   => '<span class="krb is-up">confiance élevée</span>',
        'moyenne' => '<span class="krb is-off">confiance moyenne</span>',
        default   => '<span class="krb is-muted">confiance faible</span>',
    };
};

$formBoutons = static function (array $idee) use ($actionDecision, $kiosk): string {
    $id = (string) ($idee['id'] ?? '');
    if ($id === '') {
        return '';
    }
    $champs = !$kiosk ? csrf_field() : '';
    $champs .= '<input type="hidden" name="id" value="' . e($id) . '">';

    return $champs;
};
?>
<div class="kr-page">

    <header class="ka-head">
        <h1 class="ka-title">💡 Idées du moment</h1>
        <p class="ka-period">Le site propose, tu décides — rien n'est appliqué automatiquement</p>
    </header>

    <?php if ($indispo): ?>
        <section class="card surface glass ka-card">
            <p class="ka-fc-note">Recommandations indisponibles<?= $raison !== '' ? ' (' . e($raison) . ')' : '' ?>.</p>
        </section>
    <?php else: ?>
        <?php if ($insuff): ?>
            <section class="card surface glass ka-card">
                <span class="ka-kpi-delta is-off">Idées limitées : historique insuffisant (&lt; <?= (int) Hyper::HIST_MIN_JOURS_OUVERTS ?> jours ouverts)</span>
            </section>
        <?php endif; ?>

        <?php if ($idees === []): ?>
            <section class="card surface glass ka-card">
                <span class="ka-kpi-delta is-muted">Aucune idée pour le moment — reviens après les prochains imports.</span>
            </section>
        <?php else: ?>
            <?php foreach ($idees as $idee): ?>
                <?php
                if (!is_array($idee)) { continue; }
                $idIdee = (string) ($idee['id'] ?? '');
                if ($idIdee === '') { continue; }
                $gain      = is_array($idee['gain_hebdo'] ?? null) ? $idee['gain_hebdo'] : [];
                $gainBas   = (float) ($gain['bas'] ?? 0.0);
                $gainHaut  = (float) ($gain['haut'] ?? 0.0);
                $confiance = (string) ($idee['confiance'] ?? 'faible');
                ?>
                <section class="card surface glass ka-card kr-idee">
                    <div class="kr-head">
                        <strong><?= e((string) ($idee['titre'] ?? '')) ?></strong>
                        <span class="krb is-rule"><?= e((string) ($idee['regle'] ?? '')) ?></span>
                        <?= $badgeConfiance($confiance) ?>
                    </div>
                    <?php if ($gainBas > 0.0 || $gainHaut > 0.0): ?>
                        <span class="ka-kpi-delta is-up">+<?= e(formatPrice(max(0.0, $gainBas)) . ' – ' . formatPrice(max($gainBas, $gainHaut)) . ' €/sem') ?></span>
                    <?php endif; ?>
                    <p class="kr-pourquoi"><?= e((string) ($idee['pourquoi'] ?? '')) ?></p>
                    <p class="kr-action"><strong>Action :</strong> <?= e((string) ($idee['action'] ?? '')) ?></p>
                    <div class="kr-actions">
                        <form method="post" action="<?= e($actionDecision) ?>" class="kr-form">
                            <?= $formBoutons($idee) ?>
                            <button type="submit" name="decision" value="appliquer" class="btn btn-primary btn-sm">Appliquer</button>
                        </form>
                        <form method="post" action="<?= e($actionDecision) ?>" class="kr-form">
                            <?= $formBoutons($idee) ?>
                            <button type="submit" name="decision" value="plus_tard" class="btn btn-ghost btn-sm">Plus tard</button>
                        </form>
                        <form method="post" action="<?= e($actionDecision) ?>" class="kr-form">
                            <?= $formBoutons($idee) ?>
                            <input type="hidden" name="motif" value="non_pertinent">
                            <button type="submit" name="decision" value="ignorer" class="btn btn-ghost btn-sm">Ignorer</button>
                        </form>
                    </div>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
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
                    <span class="krb is-rule"><?= e($h['regle']) ?></span>
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
        <a class="btn btn-ghost btn-sm" href="<?= e(url('/kiosque/admin/analytics/' . rawurlencode($token))) ?>">📈 Analytique</a>
    </div>
</div>

<style>
    .kr-page { display: flex; flex-direction: column; gap: 0.7rem; }
    .kr-idee { display: flex; flex-direction: column; gap: 0.4rem; }
    .kr-head { display: flex; flex-wrap: wrap; align-items: center; gap: 0.4rem; }
    .krb { font-size: 0.68rem; font-weight: 800; padding: 0.1rem 0.45rem; border-radius: 999px; border: 1px solid rgba(255,255,255,.14); white-space: nowrap; }
    .krb.is-rule { color: var(--primary, #48bdd3); border-color: rgba(72,189,211,.4); }
    .krb.is-up { color: #22c55e; border-color: rgba(34,197,94,.4); }
    .krb.is-off { color: #f59e0b; border-color: rgba(245,158,11,.4); }
    .krb.is-muted { color: var(--muted, #9fb3c8); }
    .kr-pourquoi { margin: 0; font-size: 0.82rem; color: var(--muted, #9fb3c8); }
    .kr-action { margin: 0; font-size: 0.86rem; }
    .kr-actions { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-top: 0.2rem; }
    .kr-form { display: flex; margin: 0; }
    .kr-hist-head { margin-top: 0.6rem; }
</style>
