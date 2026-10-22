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
 *    R2/R8/R9/R11) — bouton principal « Ouvrir la fiche du produit » ou
 *    « Ajouter à la liste de courses » (lien résolu côté contrôleur),
 *    options discrètes « Plus tard » / « Pas pour moi » ;
 *  - « Idées pour vendre plus » : consignes commerciales (bloc 'idee',
 *    ≤ 3) — REGROUPÉES PAR NATURE : une seule carte par règle (libellé
 *    humain, jamais le code R#), liste des produits en <details>, gain
 *    estimé en fourchette, confiance EN MOTS, hypothèses et mesure en
 *    <details>, boutons « Je le fais » / « Plus tard » / « Pas pour moi ».
 *
 * Les idées écartées par les caps du moteur (payload 'autres_idees')
 * sont listées derrière le dépliant « Voir les autres idées ».
 * AUCUN calcul métier ici : tout chiffre vient du payload moteur.
 *
 * @var array<string,mixed> $reco  Payload AdminRecoController::payloadPourAnalytics()
 *                                 (idees, corrections, autres_idees, meta, insuffisant,
 *                                 indisponible, raison, fenetre)
 * @var bool                $kiosk Vrai si rendu dans le kiosque admin.
 * @var ?string             $token Jeton kiosque admin (utilisé si $kiosk).
 */

use App\Core\Compta\Reco\Hyper;
use App\Core\Compta\Reco\RecoLibelles;

$kiosk  = (bool) ($kiosk ?? false);
$token  = (string) ($token ?? '');
$toutes = is_array($reco['idees'] ?? null) ? $reco['idees'] : [];
if (is_array($reco['corrections'] ?? null)) {
    // Payload itération 3 : les blocs arrivent déjà séparés.
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
$autres  = array_values(array_filter(
    is_array($reco['autres_idees'] ?? null) ? $reco['autres_idees'] : [],
    'is_array'
));

// Regroupement PAR NATURE : les idées d'une même règle forment UNE carte
// (au plus 3 cartes, le cap moteur MAX_IDEES garantissant déjà ≤ 3 idées).
$groupes = [];
foreach ($idees as $idee) {
    if (!is_array($idee) || (string) ($idee['id'] ?? '') === '') {
        continue;
    }
    $groupes[(string) ($idee['regle'] ?? '')][] = $idee;
}
$groupes = array_slice($groupes, 0, Hyper::MAX_IDEES);

// URLs de décision : admin (CSRF central) ou kiosque (jeton = authentification).
$actionDecision = $kiosk
    ? url('/kiosque/admin/reco/decision/' . rawurlencode($token))
    : url('/admin/reco/decision');
$lienHistorique = $kiosk
    ? url('/kiosque/admin/reco/' . rawurlencode($token))
    : url('/admin/reco/historique');

// Badge de confiance EN MOTS (faible / moyenne / élevée — jamais un %).
$badgeConfiance = static function (string $niveau, string $prefix = 'reco-badge'): string {
    return match ($niveau) {
        'haute'   => '<span class="' . $prefix . ' is-up">confiance élevée</span>',
        'moyenne' => '<span class="' . $prefix . ' is-off">confiance moyenne</span>',
        default   => '<span class="' . $prefix . ' is-muted">confiance faible</span>',
    };
};

// Gain estimé EN FOURCHETTE (« entre 8 € et 20 € par semaine »), format fr
// via formatPrice ; vide si le payload ne porte aucun gain (rien n'est
// calculé en vue).
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

// Bouton principal d'une correction : {href, libelle} résolu côté
// contrôleur (les payloads stockés d'avant l'itération 3 portaient une
// chaîne : libellé par défaut). Aucun lien → aucune ligne.
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
<div class="card surface glass chart-card chart-span-2 reco-card" id="idees-du-moment">
    <div class="chart-head">
        <h2 class="chart-title">Idées du moment</h2>
        <span class="chart-sub">Le site propose, vous décidez — rien n'est appliqué automatiquement</span>
    </div>

    <?php if ($indispo): ?>
        <p class="muted fc-empty">Recommandations indisponibles<?= $raison !== '' ? ' (' . e($raison) . ')' : '' ?>.</p>
    <?php elseif ($idees === [] && $corrections === []): ?>
        <?php if ($insuff): ?>
            <p class="fc-banner">Idées limitées : historique insuffisant (moins de <?= (int) Hyper::HIST_MIN_JOURS_OUVERTS ?> jours ouverts sur la fenêtre). Seules quelques règles prudentes sont actives.</p>
        <?php endif; ?>
        <p class="muted fc-empty">Rien d'important cette semaine.</p>
    <?php else: ?>
        <?php if ($insuff): ?>
            <p class="fc-banner">Idées limitées : historique insuffisant (moins de <?= (int) Hyper::HIST_MIN_JOURS_OUVERTS ?> jours ouverts sur la fenêtre). Seules quelques règles prudentes sont actives.</p>
        <?php endif; ?>

        <!-- ==================================================== -->
        <!--  Bloc « À corriger » : données / stock (opérables)   -->
        <!-- ==================================================== -->
        <div class="reco-bloc">
            <h3 class="reco-bloc-titre">À corriger</h3>
            <?php if ($corrections === []): ?>
                <p class="muted fc-empty">Rien à corriger pour l'instant.</p>
            <?php else: ?>
                <?php foreach ($corrections as $c): ?>
                    <?php
                    if (!is_array($c)) { continue; }
                    $idC = (string) ($c['id'] ?? '');
                    if ($idC === '') { continue; }
                    $lienC = $lienPrincipal($c);
                    ?>
                    <div class="reco-idee reco-correction">
                        <div class="reco-idee-head">
                            <strong class="reco-titre"><?= e((string) ($c['titre'] ?? '')) ?></strong>
                            <span class="reco-badge is-rule"><?= e(RecoLibelles::regle((string) ($c['regle'] ?? ''))) ?></span>
                        </div>
                        <p class="reco-pourquoi"><?= e((string) ($c['pourquoi'] ?? '')) ?></p>
                        <div class="reco-actions">
                            <?php if ($lienC !== []): ?>
                                <a class="btn btn-primary btn-sm" href="<?= e($lienC['href']) ?>" target="_blank" rel="noopener"><?= e($lienC['libelle']) ?></a>
                            <?php endif; ?>
                            <form method="post" action="<?= e($actionDecision) ?>" class="reco-form">
                                <?= $champsDecision($idC) ?>
                                <button type="submit" name="decision" value="plus_tard" class="btn btn-ghost btn-sm">Plus tard</button>
                            </form>
                            <form method="post" action="<?= e($actionDecision) ?>" class="reco-form">
                                <?= $champsDecision($idC) ?>
                                <input type="hidden" name="motif" value="non_pertinent">
                                <button type="submit" name="decision" value="ignorer" class="btn btn-ghost btn-sm">Pas pour moi</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- ==================================================== -->
        <!--  Bloc « Idées pour vendre plus » : consignes ≤ 3     -->
        <!--  regroupées par nature (1 carte = 1 nature d'idée)   -->
        <!-- ==================================================== -->
        <div class="reco-bloc">
            <h3 class="reco-bloc-titre">Idées pour vendre plus</h3>
            <?php if ($idees === []): ?>
                <p class="muted fc-empty">Rien d'important cette semaine.</p>
            <?php else: ?>
                <?php foreach ($groupes as $regle => $ideesDuGroupe): ?>
                    <?php
                    // Produits de TOUTE la règle (liste dépliable partagée).
                    $produitsGroupe = [];
                    foreach ($ideesDuGroupe as $ideeG) {
                        foreach ((array) ($ideeG['produits'] ?? []) as $pg) {
                            if (is_array($pg) && (string) ($pg['label'] ?? $pg['key'] ?? '') !== '') {
                                $produitsGroupe[(string) ($pg['key'] ?? $pg['label'])] = (string) ($pg['label'] ?? $pg['key']);
                            }
                        }
                    }
                    ?>
                    <div class="reco-idee">
                        <div class="reco-idee-head">
                            <span class="reco-badge is-rule"><?= e(RecoLibelles::regle($regle)) ?></span>
                        </div>
                        <?php foreach ($ideesDuGroupe as $idee): ?>
                            <?php
                            $idIdee    = (string) ($idee['id'] ?? '');
                            if ($idIdee === '') { continue; }
                            $gain      = is_array($idee['gain_hebdo'] ?? null) ? $idee['gain_hebdo'] : [];
                            $hypoth    = array_values(array_filter(is_array($idee['hypotheses'] ?? null) ? $idee['hypotheses'] : [], 'is_string'));
                            $confiance = (string) ($idee['confiance'] ?? 'faible');
                            $gainLib   = $gainTxt($gain);
                            $periode   = $periodeTxt(
                                is_string($idee['debut'] ?? null) ? $idee['debut'] : null,
                                is_string($idee['fin'] ?? null) ? $idee['fin'] : null
                            );
                            ?>
                            <div class="reco-idee-item">
                                <p class="reco-chiffres">
                                    <?= $badgeConfiance($confiance) ?>
                                    <?php if ($gainLib !== ''): ?>
                                        <span class="reco-badge is-gain">Gain estimé : <?= e($gainLib) ?></span>
                                    <?php endif; ?>
                                </p>
                                <?php if ($periode !== ''): ?>
                                    <p class="reco-periode"><?= e($periode) ?></p>
                                <?php endif; ?>
                                <p class="reco-pourquoi"><?= e((string) ($idee['pourquoi'] ?? '')) ?></p>
                                <p class="reco-action"><?= e((string) ($idee['action'] ?? '')) ?></p>
                                <?php if ($hypoth !== [] || (string) ($idee['comment_mesurer'] ?? '') !== ''): ?>
                                    <details class="reco-details">
                                        <summary>Hypothèses et mesure</summary>
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
                                        <button type="submit" name="decision" value="appliquer" class="btn btn-primary btn-sm">Je le fais</button>
                                    </form>
                                    <form method="post" action="<?= e($actionDecision) ?>" class="reco-form">
                                        <?= $champsDecision($idIdee) ?>
                                        <button type="submit" name="decision" value="plus_tard" class="btn btn-ghost btn-sm">Plus tard</button>
                                    </form>
                                    <form method="post" action="<?= e($actionDecision) ?>" class="reco-form reco-form-ignore">
                                        <?= $champsDecision($idIdee) ?>
                                        <select name="motif" class="af-select" aria-label="Motif">
                                            <option value="non_pertinent">Non pertinent</option>
                                            <option value="deja_trop">Déjà trop / saturé</option>
                                            <option value="autre">Autre</option>
                                        </select>
                                        <input type="text" name="motif_texte" class="af-input" placeholder="Précision (optionnel)" maxlength="120">
                                        <button type="submit" name="decision" value="ignorer" class="btn btn-ghost btn-sm">Pas pour moi</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($produitsGroupe !== []): ?>
                            <details class="reco-details reco-produits-details">
                                <summary>Produits concernés (<?= count($produitsGroupe) ?>)</summary>
                                <ul class="reco-produits-liste">
                                    <?php foreach ($produitsGroupe as $labelProduit): ?>
                                        <li><?= e($labelProduit) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if ($autres !== []): ?>
                    <details class="reco-details reco-autres">
                        <summary>Voir les autres idées (<?= count($autres) ?>)</summary>
                        <ul class="reco-autres-liste">
                            <?php foreach ($autres as $a): ?>
                                <li>
                                    <strong><?= e(RecoLibelles::regle((string) ($a['regle'] ?? ''))) ?></strong>
                                    — <?= e((string) ($a['titre'] ?? '')) ?>
                                    <?php $pourquoiAutre = (string) ($a['pourquoi'] ?? ''); ?>
                                    <?php if ($pourquoiAutre !== ''): ?>
                                        <span class="muted">(<?= e($pourquoiAutre) ?>)</span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <p class="reco-foot"><a href="<?= e($lienHistorique) ?>">Historique des idées</a></p>
    <?php endif; ?>
</div>
