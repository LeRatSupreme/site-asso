<?php

declare(strict_types=1);

/**
 * Vue d'ensemble de la zone jeux (admin).
 *
 * @var string $title
 * @var array<string,int> $stats
 */
?>
<div class="admin-actions">
    <a class="btn btn-outline btn-sm" href="<?= e(url('/jeux')) ?>" target="_blank" rel="noopener">👁️ Voir le site</a>
</div>

<div class="grid grid-3" style="margin-bottom:1.5rem;">
    <div class="card surface glass stat-card-admin">
        <span class="stat-icon">🔤</span>
        <span class="stat-value"><?= number_format($stats['wordsFr'] + $stats['wordsEn'], 0, ',', ' ') ?></span>
        <span class="stat-label">Mots Wordle (total)</span>
        <span class="stat-sub">🇫🇷 <?= number_format($stats['wordsFr']) ?> · 🇬🇧 <?= number_format($stats['wordsEn']) ?></span>
    </div>
    <div class="card surface glass stat-card-admin">
        <span class="stat-icon">📊</span>
        <span class="stat-value"><?= $stats['wordsFacile'] ?> / <?= $stats['wordsMoyen'] ?> / <?= $stats['wordsDifficile'] ?></span>
        <span class="stat-label">Facile / Moyen / Difficile</span>
    </div>
    <div class="card surface glass stat-card-admin">
        <span class="stat-icon">🧩</span>
        <span class="stat-value"><?= $stats['enigmas'] ?></span>
        <span class="stat-label">Énigmes actives</span>
    </div>
</div>

<div class="card surface glass stat-card-admin" style="margin-bottom:1.5rem;">
    <span class="stat-icon">👥</span>
    <span class="stat-value"><?= $stats['players'] ?></span>
    <span class="stat-label">Joueurs ayant au moins une partie</span>
</div>

<h2 class="form-section-title">Gérer</h2>
<div class="grid grid-2">
    <a class="card surface glass card-hover" href="<?= e(url('/admin/jeux/scores')) ?>" style="text-decoration:none;color:inherit;">
        <h3>👥 Joueurs & Pseudos</h3>
        <p>Voir tous les joueurs, modifier les pseudos, réinitialiser les scores.</p>
        <span class="btn btn-primary btn-sm">Ouvrir →</span>
    </a>
    <a class="card surface glass card-hover" href="<?= e(url('/admin/jeux/wordle')) ?>" style="text-decoration:none;color:inherit;">
        <h3>🔤 Mots Wordle</h3>
        <p>Rechercher, ajouter, modifier ou supprimer des mots (5 à 7 lettres).</p>
        <span class="btn btn-primary btn-sm">Ouvrir →</span>
    </a>
    <a class="card surface glass card-hover" href="<?= e(url('/admin/jeux/enigmes')) ?>" style="text-decoration:none;color:inherit;">
        <h3>🧩 Énigmes</h3>
        <p>Créer et modifier les devinettes quotidiennes (FR + EN + réponse).</p>
        <span class="btn btn-primary btn-sm">Ouvrir →</span>
    </a>
</div>

<style>
.stat-card-admin { display:flex; flex-direction:column; gap:0.3rem; }
.stat-card-admin .stat-icon { font-size:1.8rem; }
.stat-card-admin .stat-value { font-size:1.8rem; font-weight:900; color:var(--primary); }
.stat-card-admin .stat-label { color:var(--muted); font-size:0.85rem; }
.stat-card-admin .stat-sub { color:var(--muted); font-size:0.78rem; }
.stat-card-admin h3 { margin:0 0 0.3rem; }
.stat-card-admin p { margin:0 0 0.8rem; color:var(--muted); font-size:0.9rem; }
</style>
