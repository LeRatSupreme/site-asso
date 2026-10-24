<?php

declare(strict_types=1);

/**
 * Hub d'un KIOSQUE PERSONNALISÉ : tuiles choisies par l'admin, accès par
 * le jeton secret du kiosque — le jeton EST l'authentification (aucune
 * session). Le jeton n'apparaît QUE dans les liens des tuiles (comme sur
 * toutes les pages kiosque) ; rien d'autre ne le reflète.
 *
 * @var string $token
 * @var array{id:string,name:string,token:string,pages:list<string>,created_by:string,created_at:string,updated_at:?string} $kiosque
 * @var list<array{id:string,emoji:string,label:string,desc:string,path:string}> $tiles
 */
?>
<style>
    .khub-wrap { max-width: 44rem; margin: 0 auto; }
    .khub-grid {
        display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.7rem; margin-bottom: 0.7rem;
    }
    .khub-grid a {
        display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.45rem;
        aspect-ratio: 1 / 1; padding: 0.9rem 0.6rem; text-align: center; text-decoration: none;
        background: rgba(255, 255, 255, 0.035);
        border: 1px solid rgba(255, 255, 255, 0.07);
        border-radius: 20px;
        transition: border-color 0.15s ease, background 0.15s ease;
    }
    .khub-grid a:hover { border-color: var(--primary, #48bdd3); background: rgba(72, 189, 211, 0.07); }
    .khub-tile-emoji { font-size: 2.1rem; line-height: 1; }
    .khub-grid .kiosk-tile-title { font-size: 0.98rem; font-weight: 900; padding: 0 0.4rem; }
    .khub-tile-desc {
        font-size: 0.72rem; color: var(--muted, #8892a6); padding: 0 0.4rem;
        overflow: hidden; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical;
    }
    /* Tuile seule sur la 2e rangée : même taille, centrée. */
    .khub-grid a:last-child:nth-child(odd) {
        grid-column: 1 / -1;
        justify-self: center;
        width: calc(50% - 0.35rem);
    }
    .khub-empty {
        margin: 1.2rem 0 0; text-align: center;
        font-size: 0.88rem; color: var(--muted, #8892a6);
    }
</style>

<div class="compta-head">
    <div>
        <p class="eyebrow">Kiosque</p>
        <h1 class="page-title"><?= e($kiosque['name']) ?></h1>
        <p class="muted">Accès direct aux pages de ce kiosque.</p>
    </div>
</div>

<div class="khub-wrap">
    <?php if ($tiles === []): ?>
        <p class="khub-empty">Aucune page sur ce kiosque — demande à un admin d'ajouter des tuiles.</p>
    <?php else: ?>
        <div class="khub-grid">
            <?php foreach ($tiles as $tile): ?>
                <a href="<?= e(url($tile['path'] . rawurlencode($token))) ?>">
                    <span class="khub-tile-emoji" aria-hidden="true"><?= e($tile['emoji']) ?></span>
                    <span class="kiosk-tile-title"><?= e($tile['label']) ?></span>
                    <span class="khub-tile-desc"><?= e($tile['desc']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<p class="card-meta" style="text-align: center; margin-top: 1.75rem;">
    <?= e($kiosque['name']) ?> — accès limité à ces pages
</p>
