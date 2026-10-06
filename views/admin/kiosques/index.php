<?php

declare(strict_types=1);

/**
 * Page « Kiosques » (Système) : tous les liens kiosque centralisés.
 *
 * @var array<string,mixed> $user
 * @var list<array{emoji:string,label:string,desc:string,url:string,main?:bool}> $pages
 */
?>
<style>
    .kq-card {
        display: flex; flex-direction: column; gap: 0.45rem;
        padding: 1rem 1.1rem; margin-bottom: 0.9rem;
        background: rgba(255, 255, 255, 0.035);
        border: 1px solid rgba(255, 255, 255, 0.07);
        border-radius: 14px;
    }
    .kq-card.is-main { border-color: rgba(72, 189, 211, 0.45); background: rgba(72, 189, 211, 0.06); }
    .kq-head { display: flex; align-items: baseline; gap: 0.5rem; flex-wrap: wrap; }
    .kq-emoji { font-size: 1.25rem; }
    .kq-label { font-size: 1.02rem; font-weight: 900; }
    .kq-badge {
        font-size: 0.68rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em;
        color: var(--primary, #48bdd3); background: rgba(72, 189, 211, 0.14);
        border-radius: 999px; padding: 0.1rem 0.55rem;
    }
    .kq-desc { margin: 0; font-size: 0.85rem; color: var(--muted, #8892a6); }
    .kq-row { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin-top: 0.25rem; }
    .kq-row input {
        flex: 1 1 320px; padding: 0.5rem 0.7rem;
        border: 1px solid var(--border); border-radius: 8px;
        background: rgba(255, 255, 255, 0.04); color: var(--foreground);
        font-size: 0.83rem; font-family: monospace;
    }
    .kq-note { font-size: 0.85rem; color: var(--muted, #8892a6); margin: 0 0 1rem; max-width: 720px; }
    .kq-note strong { color: var(--foreground); }
</style>

<div class="compta-head">
    <div>
        <p class="eyebrow">Système</p>
        <h1 class="page-title">Kiosques</h1>
        <p class="muted">Tous les liens d'accès téléphone (sans connexion) au même endroit.</p>
    </div>
</div>

<div class="card surface glass" style="padding: 0.95rem 1.1rem; margin-bottom: 1.2rem;">
    <p class="kq-note">
        Le <strong>lien kiosque</strong> donne un accès sans connexion aux pages ci-dessous.
        L'authentification <strong>est le lien lui-même</strong> : ne le partage qu'aux membres du bureau.
        Chaque action (comptage, pause, case cochée) exige l'identité du membre (prénom, nom, rôle)
        et est tracée en son nom. La régénération du lien <strong>révoque tous les liens d'un coup</strong>.
    </p>
    <form method="post" action="<?= e(url('/admin/kiosques/regenerate')) ?>"
          data-confirm="Régénérer le lien kiosque ? TOUS les liens déjà partagés cesseront de fonctionner — il faudra redistribuer le nouveau."
          data-confirm-button="Régénérer">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-outline btn-sm">🔄 Régénérer le lien (révoquer tout)</button>
    </form>
</div>

<?php foreach ($pages as $p): ?>
<div class="kq-card<?= !empty($p['main']) ? ' is-main' : '' ?>">
    <div class="kq-head">
        <span class="kq-emoji"><?= $p['emoji'] ?></span>
        <span class="kq-label"><?= e($p['label']) ?></span>
        <?php if (!empty($p['main'])): ?><span class="kq-badge">à partager</span><?php endif; ?>
    </div>
    <p class="kq-desc"><?= e($p['desc']) ?></p>
    <div class="kq-row">
        <input type="text" readonly value="<?= e($p['url']) ?>" onclick="this.select()"
               aria-label="Lien <?= e($p['label']) ?>">
        <button type="button" class="btn btn-outline btn-sm kq-copy" data-url="<?= e($p['url']) ?>">Copier</button>
        <a class="btn btn-ghost btn-sm" href="<?= e($p['url']) ?>" target="_blank">Ouvrir ↗</a>
    </div>
</div>
<?php endforeach; ?>

<script>
(function () {
    document.addEventListener('click', function (e) {
        var b = e.target.closest('.kq-copy');
        if (!b) return;
        var url = b.getAttribute('data-url');
        var done = function () {
            var old = b.textContent;
            b.textContent = 'Copié ✓';
            setTimeout(function () { b.textContent = old; }, 1500);
        };
        if (navigator.clipboard) {
            navigator.clipboard.writeText(url).then(done, done);
        } else {
            var i = b.parentElement.querySelector('input');
            i.select();
            try { document.execCommand('copy'); } catch (err) {}
            done();
        }
    });
})();
</script>
