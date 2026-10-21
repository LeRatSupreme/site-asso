<?php

declare(strict_types=1);

/**
 * PARTIEL « Idées du moment » (moteur de recommandations commerciales) —
 * inclus par les deux vues analytics (admin et kiosque). Le site PROPOSE,
 * un humain DÉCIDE : les boutons postent une décision, jamais une
 * écriture automatique de prix/stock.
 *
 * @var array<string,mixed> $reco  Payload AdminRecoController::payloadPourAnalytics()
 *                                 (idees, meta, insuffisant, indisponible, raison, fenetre)
 * @var bool                $kiosk Vrai si rendu dans le kiosque admin.
 * @var ?string             $token Jeton kiosque admin (utilisé si $kiosk).
 */

use App\Core\Compta\Reco\Hyper;

$kiosk   = (bool) ($kiosk ?? false);
$token   = (string) ($token ?? '');
$idees   = is_array($reco['idees'] ?? null) ? $reco['idees'] : [];
$meta    = is_array($reco['meta'] ?? null) ? $reco['meta'] : [];
$indispo = (bool) ($reco['indisponible'] ?? ($meta['indisponible'] ?? false));
$raison  = trim((string) ($reco['raison'] ?? ($meta['raison'] ?? '')));
$insuff  = (bool) ($reco['insuffisant'] ?? false);

// URLs de décision : admin (CSRF central) ou kiosque (jeton = authentification).
$actionDecision = $kiosk
    ? url('/kiosque/admin/reco/decision/' . rawurlencode($token))
    : url('/admin/reco/decision');
$lienHistorique = $kiosk
    ? url('/kiosque/admin/reco/' . rawurlencode($token))
    : url('/admin/reco/historique');

// Badge de confiance (couleur maison).
$badgeConfiance = static function (string $niveau): string {
    return match ($niveau) {
        'haute'   => '<span class="reco-badge is-up">confiance élevée</span>',
        'moyenne' => '<span class="reco-badge is-off">confiance moyenne</span>',
        default   => '<span class="reco-badge is-muted">confiance faible</span>',
    };
};

// Fourchette de gain hebdomadaire « X–Y €/sem » (bas–haut du moteur).
$gainTxt = static function (array $gain): string {
    $bas  = (float) ($gain['bas'] ?? 0.0);
    $haut = (float) ($gain['haut'] ?? 0.0);
    if ($bas <= 0.0 && $haut <= 0.0) {
        return '';
    }

    return formatPrice(max(0.0, $bas)) . ' – ' . formatPrice(max($bas, $haut)) . ' €/sem';
};
?>
<div class="card surface glass chart-card chart-span-2 reco-card" id="idees-du-moment">
    <div class="chart-head">
        <h2 class="chart-title">💡 Idées du moment</h2>
        <span class="chart-sub">Le site propose, tu décides — rien n'est appliqué automatiquement</span>
    </div>

    <?php if ($indispo): ?>
        <p class="muted fc-empty">Recommandations indisponibles<?= $raison !== '' ? ' (' . e($raison) . ')' : '' ?>.</p>
    <?php else: ?>
        <?php if ($insuff): ?>
            <p class="fc-banner">Idées limitées : historique insuffisant (moins de <?= (int) Hyper::HIST_MIN_JOURS_OUVERTS ?> jours ouverts sur la fenêtre). Seules quelques règles prudentes sont actives.</p>
        <?php endif; ?>

        <?php if ($idees === []): ?>
            <p class="muted fc-empty">Aucune idée pour le moment — reviens après les prochains imports de ventes.</p>
        <?php else: ?>
            <?php foreach ($idees as $idee): ?>
                <?php
                if (!is_array($idee)) { continue; }
                $idIdee    = (string) ($idee['id'] ?? '');
                if ($idIdee === '') { continue; }
                $gain      = is_array($idee['gain_hebdo'] ?? null) ? $idee['gain_hebdo'] : [];
                $produits  = is_array($idee['produits'] ?? null) ? $idee['produits'] : [];
                $hypoth    = array_values(array_filter(is_array($idee['hypotheses'] ?? null) ? $idee['hypotheses'] : [], 'is_string'));
                $confiance = (string) ($idee['confiance'] ?? 'faible');
                ?>
                <div class="reco-idee">
                    <div class="reco-idee-head">
                        <strong class="reco-titre"><?= e((string) ($idee['titre'] ?? '')) ?></strong>
                        <span class="reco-badge is-rule"><?= e((string) ($idee['regle'] ?? '')) ?></span>
                        <?= $badgeConfiance($confiance) ?>
                        <?php $gainLib = $gainTxt($gain); ?>
                        <?php if ($gainLib !== ''): ?>
                            <span class="reco-badge is-gain">+<?= e($gainLib) ?></span>
                        <?php endif; ?>
                    </div>
                    <p class="reco-pourquoi"><?= e((string) ($idee['pourquoi'] ?? '')) ?></p>
                    <p class="reco-action"><strong>Action :</strong> <?= e((string) ($idee['action'] ?? '')) ?></p>
                    <?php if ($produits !== []): ?>
                        <p class="reco-produits">
                            Produits : <?= e(implode(', ', array_map(
                                static fn (array $p): string => (string) ($p['label'] ?? $p['key'] ?? ''),
                                array_filter($produits, 'is_array')
                            ))) ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($hypoth !== [] || (string) ($idee['comment_mesurer'] ?? '') !== ''): ?>
                        <details class="reco-details">
                            <summary>Hypothèses &amp; mesure</summary>
                            <ul class="reco-hypotheses">
                                <?php foreach ($hypoth as $h): ?>
                                    <li><?= e($h) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <?php $mesure = (string) ($idee['comment_mesurer'] ?? ''); ?>
                            <?php if ($mesure !== ''): ?>
                                <p class="reco-mesure"><?= e($mesure) ?></p>
                            <?php endif; ?>
                        </details>
                    <?php endif; ?>
                    <div class="reco-actions">
                        <form method="post" action="<?= e($actionDecision) ?>" class="reco-form">
                            <?php if (!$kiosk): ?>
                                <?= csrf_field() ?>
                                <input type="hidden" name="retour" value="/admin/analytics">
                            <?php endif; ?>
                            <input type="hidden" name="id" value="<?= e($idIdee) ?>">
                            <button type="submit" name="decision" value="appliquer" class="btn btn-primary btn-sm">Appliquer</button>
                        </form>
                        <form method="post" action="<?= e($actionDecision) ?>" class="reco-form">
                            <?php if (!$kiosk): ?>
                                <?= csrf_field() ?>
                                <input type="hidden" name="retour" value="/admin/analytics">
                            <?php endif; ?>
                            <input type="hidden" name="id" value="<?= e($idIdee) ?>">
                            <button type="submit" name="decision" value="plus_tard" class="btn btn-ghost btn-sm">Plus tard</button>
                        </form>
                        <form method="post" action="<?= e($actionDecision) ?>" class="reco-form reco-form-ignore">
                            <?php if (!$kiosk): ?>
                                <?= csrf_field() ?>
                                <input type="hidden" name="retour" value="/admin/analytics">
                            <?php endif; ?>
                            <input type="hidden" name="id" value="<?= e($idIdee) ?>">
                            <select name="motif" class="af-select" aria-label="Motif d'ignorance">
                                <option value="non_pertinent">Non pertinent</option>
                                <option value="deja_trop">Déjà trop / saturé</option>
                                <option value="autre">Autre</option>
                            </select>
                            <input type="text" name="motif_texte" class="af-input" placeholder="Précision (optionnel)" maxlength="120">
                            <button type="submit" name="decision" value="ignorer" class="btn btn-ghost btn-sm">Ignorer</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <p class="reco-foot"><a href="<?= e($lienHistorique) ?>">Historique des idées</a></p>
    <?php endif; ?>
</div>
<style>
    .reco-card { display: flex; flex-direction: column; gap: .75rem; }
    .reco-idee { border: 1px solid rgba(255,255,255,.07); border-radius: 12px; padding: .85rem 1rem; display: flex; flex-direction: column; gap: .4rem; }
    .reco-idee-head { display: flex; flex-wrap: wrap; align-items: center; gap: .45rem; }
    .reco-titre { font-size: .95rem; }
    .reco-badge { font-size: .7rem; font-weight: 800; padding: .12rem .5rem; border-radius: 999px; border: 1px solid rgba(255,255,255,.14); }
    .reco-badge.is-rule { color: var(--primary, #48bdd3); border-color: rgba(72,189,211,.4); }
    .reco-badge.is-up { color: #22c55e; border-color: rgba(34,197,94,.4); }
    .reco-badge.is-off { color: #f59e0b; border-color: rgba(245,158,11,.4); }
    .reco-badge.is-muted { color: var(--muted, #9fb3c8); }
    .reco-badge.is-gain { color: #22c55e; border-color: rgba(34,197,94,.4); }
    .reco-pourquoi { margin: 0; font-size: .86rem; color: var(--muted, #9fb3c8); }
    .reco-action { margin: 0; font-size: .88rem; }
    .reco-produits { margin: 0; font-size: .8rem; color: var(--muted, #9fb3c8); }
    .reco-details summary { cursor: pointer; font-size: .8rem; color: var(--muted, #9fb3c8); }
    .reco-hypotheses { margin: .4rem 0 0; padding-left: 1.1rem; font-size: .8rem; color: var(--muted, #9fb3c8); }
    .reco-mesure { margin: .35rem 0 0; font-size: .8rem; color: var(--muted, #9fb3c8); }
    .reco-actions { display: flex; flex-wrap: wrap; align-items: center; gap: .45rem; margin-top: .25rem; }
    .reco-form { display: flex; align-items: center; gap: .35rem; margin: 0; }
    .reco-form-ignore { flex-wrap: wrap; }
    .reco-form-ignore .af-select, .reco-form-ignore .af-input { font-size: .78rem; padding: .3rem .5rem; }
    .reco-foot { margin: 0; font-size: .82rem; }
    .reco-foot a { color: var(--primary, #48bdd3); }
</style>
