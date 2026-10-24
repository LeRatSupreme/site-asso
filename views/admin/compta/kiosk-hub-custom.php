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
        gap: 0.65rem; margin-bottom: 0.7rem;
    }
    @media (max-width: 560px) {
        .khub-grid { grid-template-columns: 1fr; }
    }
    .khub-card {
        display: flex; align-items: center; gap: 0.8rem;
        padding: 0.85rem 0.95rem; border-radius: 16px;
        background: rgba(255, 255, 255, 0.035);
        border: 1px solid rgba(255, 255, 255, 0.08);
        text-decoration: none;
        transition: border-color 0.15s ease, background 0.15s ease;
    }
    .khub-card:hover { border-color: var(--primary, #48bdd3); background: rgba(72, 189, 211, 0.07); }
    .khub-emoji {
        flex-shrink: 0; width: 2.5rem; height: 2.5rem;
        display: flex; align-items: center; justify-content: center;
        border-radius: 12px; background: rgba(72, 189, 211, 0.12);
        font-size: 1.25rem; line-height: 1;
    }
    .khub-text { display: flex; flex-direction: column; gap: 0.15rem; min-width: 0; }
    .khub-label { font-size: 0.98rem; font-weight: 900; line-height: 1.2; }
    .khub-desc {
        font-size: 0.78rem; color: var(--muted, #8892a6); line-height: 1.35;
        overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
    }
    .khub-chevron {
        margin-left: auto; flex-shrink: 0; line-height: 1;
        font-size: 1.3rem; color: var(--muted, #8892a6);
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
                <a class="khub-card" href="<?= e(url($tile['path'] . rawurlencode($token))) ?>">
                    <span class="khub-emoji" aria-hidden="true"><?= e($tile['emoji']) ?></span>
                    <span class="khub-text">
                        <span class="khub-label"><?= e($tile['label']) ?></span>
                        <span class="khub-desc"><?= e($tile['desc']) ?></span>
                    </span>
                    <span class="khub-chevron" aria-hidden="true">›</span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<p class="card-meta" style="text-align: center; margin-top: 1.75rem;">
    <?= e($kiosque['name']) ?> — accès limité à ces pages
</p>
