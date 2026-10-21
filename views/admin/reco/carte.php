<?php

declare(strict_types=1);

/**
 * PARTIEL « Idées du moment » (moteur de recommandations commerciales) —
 * inclus par les deux vues analytics (admin et kiosque). Le site PROPOSE,
 * un humain DÉCIDE : les boutons postent une décision, jamais une
 * écriture automatique de prix/stock.
 *
 * DEUX BLOCS distingués :
 *  - « À corriger » : corrections données/stock (bloc 'correction',
 *    R2/R8/R9/R11) — PAS de bouton Appliquer (rien à mesurer) ;
 *  - « Idées pour vendre plus » : consignes commerciales (bloc 'idee',
 *    ≤ 3) avec l'ACTION mise en avant, le gain estimé et l'effet mesuré
 *    au clic « Appliquer ».
 *
 * @var array<string,mixed> $reco  Payload AdminRecoController::payloadPourAnalytics()
 *                                 (idees, corrections, meta, insuffisant, indisponible, raison, fenetre)
 * @var bool                $kiosk Vrai si rendu dans le kiosque admin.
 * @var ?string             $token Jeton kiosque admin (utilisé si $kiosk).
 */

use App\Core\Compta\Reco\Hyper;

$kiosk  = (bool) ($kiosk ?? false);
$token  = (string) ($token ?? '');
$toutes = is_array($reco['idees'] ?? null) ? $reco['idees'] : [];
if (is_array($reco['corrections'] ?? null)) {
    // Payload itération 2 : les blocs arrivent déjà séparés.
    $idees       = array_values(array_filter($toutes, static fn ($i): bool => is_array($i) && ($i['bloc'] ?? 'idee') !== 'correction'));
    $corrections = array_values(array_filter((array) $reco['corrections'], 'is_array'));
} else {
    // Compatibilité payload d'avant l'itération 2 : tout était dans 'idees'.
    $idees       = array_values(array_filter($toutes, static fn ($i): bool => is_array($i) && ($i['bloc'] ?? 'idee') !== 'correction'));
    $corrections = array_values(array_filter($toutes, static fn ($i): bool => is_array($i) && ($i['bloc'] ?? 'idee') === 'correction'));
}
$meta    = is_array($reco['meta'] ?? null) ? $reco['meta'] : [];
$indispo = (bool) ($reco['indisponible'] ?? ($meta['indisponible'] ?? false));
$raison  = trim((string) ($reco['raison'] ?? ($meta['raison'] ?? '')));
$insuff  = (bool) ($reco['insuffisant'] ?? false);

// Message d'honnêteté du moteur (0 ou 1 idée fiable), sinon un mot
// prudent générique quand il n'y a vraiment rien à proposer.
$message = isset($meta['message']) && is_string($meta['message']) && $meta['message'] !== ''
    ? $meta['message']
    : null;

// URLs de décision : admin (CSRF central) ou kiosque (jeton = authentification).
$actionDecision = $kiosk
    ? url('/kiosque/admin/reco/decision/' . rawurlencode($token))
    : url('/admin/reco/decision');
$lienHistorique = $kiosk
    ? url('/kiosque/admin/reco/' . rawurlencode($token))
    : url('/admin/reco/historique');

// Badge de confiance (couleur maison).
$badgeConfiance = static function (string $niveau, string $prefix = 'reco-badge'): string {
    return match ($niveau) {
        'haute'   => '<span class="' . $prefix . ' is-up">confiance élevée</span>',
        'moyenne' => '<span class="' . $prefix . ' is-off">confiance moyenne</span>',
        default   => '<span class="' . $prefix . ' is-muted">confiance faible</span>',
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

// Fenêtre de validité d'une consigne datée (« Du X au Y »).
$periodeTxt = static function (?string $debut, ?string $fin): string {
    $d = is_string($debut) && $debut !== '' ? formatDate($debut, 'd/m/Y') : '';
    $f = is_string($fin) && $fin !== '' ? formatDate($fin, 'd/m/Y') : '';
    if ($d !== '' && $f !== '') {
        return 'Du ' . $d . ' au ' . $f;
    }
    if ($d !== '') {
        return 'À partir du ' . $d;
    }
    if ($f !== '') {
        return 'Jusqu\'au ' . $f;
    }

    return '';
};

// Champs cachés d'un formulaire de décision (CSRF admin, jeton inutile
// en kiosque : le jeton du chemin EST l'authentification).
$champsDecision = static function (string $id) use ($kiosk): string {
    $champs = !$kiosk ? csrf_field() . '<input type="hidden" name="retour" value="/admin/analytics">' : '';

    return $champs . '<input type="hidden" name="id" value="' . e($id) . '">';
};

// Lien « Ouvrir la fiche ↗ » résolu côté contrôleur (admin : fiches
// compta ; kiosque : seulement la liste publique).
$lienFiche = static function (array $item): string {
    $lien = $item['lien'] ?? null;
    if (!is_string($lien) || $lien === '') {
        return '';
    }

    return '<a class="btn btn-outline btn-sm" href="' . e($lien) . '" target="_blank" rel="noopener">Ouvrir la fiche ↗</a>';
};
?>
<div class="card surface glass chart-card chart-span-2 reco-card" id="idees-du-moment">
    <div class="chart-head">
        <h2 class="chart-title">💡 Idées du moment</h2>
        <span class="chart-sub">Le site propose, tu décides — rien n'est appliqué automatiquement</span>
    </div>

    <?php if ($indispo): ?>
        <p class="muted fc-empty">Recommandations indisponibles<?= $raison !== '' ? ' (' . e($raison) . ')' : '' ?>.</p>
    <?php elseif ($idees === [] && $corrections === []): ?>
        <?php if ($insuff): ?>
            <p class="fc-banner">Idées limitées : historique insuffisant (moins de <?= (int) Hyper::HIST_MIN_JOURS_OUVERTS ?> jours ouverts sur la fenêtre). Seules quelques règles prudentes sont actives.</p>
        <?php endif; ?>
        <p class="muted fc-empty"><?= e($message ?? 'Peu d\'idées fiables pour l\'instant.') ?></p>
    <?php else: ?>
        <?php if ($insuff): ?>
            <p class="fc-banner">Idées limitées : historique insuffisant (moins de <?= (int) Hyper::HIST_MIN_JOURS_OUVERTS ?> jours ouverts sur la fenêtre). Seules quelques règles prudentes sont actives.</p>
        <?php endif; ?>

        <!-- ==================================================== -->
        <!--  Bloc « À corriger » : données / stock (opérables)   -->
        <!-- ==================================================== -->
        <div class="reco-bloc">
            <h3 class="reco-bloc-titre">🔧 À corriger</h3>
            <?php if ($corrections === []): ?>
                <p class="muted fc-empty">Rien à corriger pour l'instant.</p>
            <?php else: ?>
                <?php foreach ($corrections as $c): ?>
                    <?php
                    if (!is_array($c)) { continue; }
                    $idC = (string) ($c['id'] ?? '');
                    if ($idC === '') { continue; }
                    ?>
                    <div class="reco-idee reco-correction">
                        <div class="reco-idee-head">
                            <strong class="reco-titre"><?= e((string) ($c['titre'] ?? '')) ?></strong>
                            <span class="reco-badge is-rule"><?= e((string) ($c['regle'] ?? '')) ?></span>
                        </div>
                        <?php if (!$kiosk): ?>
                            <p class="reco-pourquoi"><?= e((string) ($c['pourquoi'] ?? '')) ?></p>
                        <?php endif; ?>
                        <div class="reco-actions">
                            <?= $lienFiche($c) ?>
                            <form method="post" action="<?= e($actionDecision) ?>" class="reco-form">
                                <?= $champsDecision($idC) ?>
                                <button type="submit" name="decision" value="plus_tard" class="btn btn-ghost btn-sm">Plus tard</button>
                            </form>
                            <form method="post" action="<?= e($actionDecision) ?>" class="reco-form">
                                <?= $champsDecision($idC) ?>
                                <input type="hidden" name="motif" value="non_pertinent">
                                <button type="submit" name="decision" value="ignorer" class="btn btn-ghost btn-sm">Ignorer</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- ==================================================== -->
        <!--  Bloc « Idées pour vendre plus » : consignes ≤ 3     -->
        <!-- ==================================================== -->
        <div class="reco-bloc">
            <h3 class="reco-bloc-titre">🚀 Idées pour vendre plus</h3>
            <?php if ($message !== null): ?>
                <p class="muted reco-message"><?= e($message) ?></p>
            <?php endif; ?>
            <?php if ($idees === []): ?>
                <p class="muted fc-empty">Peu d'idées fiables pour l'instant.</p>
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
                    $gainLib   = $gainTxt($gain);
                    $periode   = $periodeTxt(
                        is_string($idee['debut'] ?? null) ? $idee['debut'] : null,
                        is_string($idee['fin'] ?? null) ? $idee['fin'] : null
                    );
                    ?>
                    <div class="reco-idee">
                        <div class="reco-idee-head">
                            <span class="reco-badge is-rule"><?= e((string) ($idee['regle'] ?? '')) ?></span>
                            <?= $badgeConfiance($confiance) ?>
                            <?php if ($gainLib !== ''): ?>
                                <span class="reco-badge is-gain">+<?= e($gainLib) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($periode !== ''): ?>
                            <p class="reco-periode"><?= e($periode) ?></p>
                        <?php endif; ?>
                        <?php if (!$kiosk): ?>
                            <p class="reco-pourquoi"><?= e((string) ($idee['pourquoi'] ?? '')) ?></p>
                        <?php endif; ?>
                        <p class="reco-action"><?= e((string) ($idee['action'] ?? '')) ?></p>
                        <?php if (!$kiosk && $produits !== []): ?>
                            <p class="reco-produits">
                                Produits : <?= e(implode(', ', array_map(
                                    static fn (array $p): string => (string) ($p['label'] ?? $p['key'] ?? ''),
                                    array_filter($produits, 'is_array')
                                ))) ?>
                            </p>
                        <?php endif; ?>
                        <?php if (!$kiosk && ($hypoth !== [] || (string) ($idee['comment_mesurer'] ?? '') !== '')): ?>
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
                                <?= $champsDecision($idIdee) ?>
                                <button type="submit" name="decision" value="appliquer" class="btn btn-primary btn-sm">Appliquer</button>
                            </form>
                            <form method="post" action="<?= e($actionDecision) ?>" class="reco-form">
                                <?= $champsDecision($idIdee) ?>
                                <button type="submit" name="decision" value="plus_tard" class="btn btn-ghost btn-sm">Plus tard</button>
                            </form>
                            <form method="post" action="<?= e($actionDecision) ?>" class="reco-form reco-form-ignore">
                                <?= $champsDecision($idIdee) ?>
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
        </div>

        <p class="reco-foot"><a href="<?= e($lienHistorique) ?>">Historique des idées</a></p>
    <?php endif; ?>
</div>
<style>
    .reco-card { display: flex; flex-direction: column; gap: .75rem; }
    .reco-bloc { display: flex; flex-direction: column; gap: .5rem; }
    .reco-bloc-titre { margin: 0; font-size: .95rem; }
    .reco-message { margin: 0; font-size: .84rem; }
    .reco-idee { border: 1px solid rgba(255,255,255,.07); border-radius: 12px; padding: .85rem 1rem; display: flex; flex-direction: column; gap: .4rem; }
    .reco-correction { border-style: dashed; }
    .reco-idee-head { display: flex; flex-wrap: wrap; align-items: center; gap: .45rem; }
    .reco-titre { font-size: .95rem; }
    .reco-badge { font-size: .7rem; font-weight: 800; padding: .12rem .5rem; border-radius: 999px; border: 1px solid rgba(255,255,255,.14); }
    .reco-badge.is-rule { color: var(--primary, #48bdd3); border-color: rgba(72,189,211,.4); }
    .reco-badge.is-up { color: #22c55e; border-color: rgba(34,197,94,.4); }
    .reco-badge.is-off { color: #f59e0b; border-color: rgba(245,158,11,.4); }
    .reco-badge.is-muted { color: var(--muted, #9fb3c8); }
    .reco-badge.is-gain { color: #22c55e; border-color: rgba(34,197,94,.4); }
    .reco-periode { margin: 0; font-size: .8rem; color: var(--primary, #48bdd3); font-weight: 700; }
    .reco-pourquoi { margin: 0; font-size: .86rem; color: var(--muted, #9fb3c8); }
    .reco-action { margin: 0; font-size: .95rem; font-weight: 800; }
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
