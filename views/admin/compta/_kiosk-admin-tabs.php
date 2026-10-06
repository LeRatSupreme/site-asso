<?php

declare(strict_types=1);

/**
 * Barre d'onglets partagée du KIOSQUE ADMIN : navigation instantanée
 * entre les vues (accueil, jour, 7 jours, mois) + accès aux pages admin
 * complètes (connexion admin mémorisée sur l'appareil).
 *
 * @var string $token   jeton admin kiosque
 * @var string $active  'hub'|'jour'|'semaine'|'mois'
 */
$tabs = [
    'hub'     => ['🏠', 'Accueil', url('/kiosque/admin/' . rawurlencode($token))],
    'jour'    => ['📊', 'Jour', url('/kiosque/comptage/jour/' . rawurlencode($token))],
    'semaine' => ['📅', '7 jours', url('/kiosque/admin/semaine/' . rawurlencode($token))],
    'mois'    => ['🗓️', 'Mois', url('/kiosque/admin/mois/' . rawurlencode($token))],
];
?>
<style>
    .katab {
        position: sticky; top: 0; z-index: 20;
        display: flex; gap: 0.4rem; align-items: center;
        padding: 0.55rem 0.2rem; margin: -0.4rem -0.2rem 0.9rem;
        overflow-x: auto; -webkit-overflow-scrolling: touch;
        background: rgba(10, 22, 38, 0.94);
        backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
        scrollbar-width: none;
    }
    .katab::-webkit-scrollbar { display: none; }
    .katab a {
        flex-shrink: 0;
        display: inline-flex; align-items: center; gap: 0.3rem;
        padding: 0.42rem 0.75rem; border-radius: 999px;
        border: 1px solid rgba(255, 255, 255, 0.09);
        background: rgba(255, 255, 255, 0.04);
        color: var(--muted, #8892a6);
        font-size: 0.83rem; font-weight: 800; text-decoration: none;
        white-space: nowrap;
    }
    .katab a:hover { color: var(--foreground, inherit); border-color: var(--primary, #48bdd3); }
    .katab a.is-active {
        background: var(--primary, #48bdd3);
        border-color: var(--primary, #48bdd3);
        color: #062033;
    }
    .katab a.is-ext { opacity: 0.85; }
    .katab .katab-sep { flex-shrink: 0; width: 1px; height: 22px; background: rgba(255, 255, 255, 0.12); margin: 0 0.2rem; }
</style>

<nav class="katab" aria-label="Kiosque admin">
    <?php foreach ($tabs as $key => [$emoji, $label, $urlTab]): ?>
    <a href="<?= e($urlTab) ?>" class="<?= $active === $key ? 'is-active' : '' ?>"><?= $emoji ?> <?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
