<?php

declare(strict_types=1);

/**
 * Page « Kiosques » (Système) : tous les liens kiosque centralisés, en
 * deux catégories — Membres (à partager) et Admins (données financières).
 *
 * @var array<string,mixed> $user
 * @var list<array{emoji:string,label:string,desc:string,url:string,main?:bool}> $memberPages
 * @var list<array{emoji:string,label:string,desc:string,url:string,kiosk?:bool}> $adminPages
 */
?>
<style>
    .kq-section { margin-bottom: 1.6rem; }
    .kq-section-title {
        display: flex; align-items: center; gap: 0.5rem;
        margin: 0 0 0.65rem; font-size: 1rem; font-weight: 900;
    }
    .kq-section-sub { margin: -0.3rem 0 0.8rem; font-size: 0.82rem; color: var(--muted, #8892a6); }
    .kq-card {
        display: flex; flex-direction: column; gap: 0.45rem;
        padding: 1rem 1.1rem; margin-bottom: 0.9rem;
        background: rgba(255, 255, 255, 0.035);
        border: 1px solid rgba(255, 255, 255, 0.07);
        border-radius: 14px;
    }
    .kq-card.is-main { border-color: rgba(72, 189, 211, 0.45); background: rgba(72, 189, 211, 0.06); }
    .kq-card.is-admin { border-left: 4px solid rgba(97, 80, 170, 0.65); }
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
        <p class="muted">Tous les liens d'accès téléphone, sans connexion, en deux catégories.</p>
    </div>
</div>

<div class="card surface glass" style="padding: 0.95rem 1.1rem; margin-bottom: 1.4rem;">
    <p class="kq-note">
        L'authentification du kiosque <strong>est le lien lui-même</strong> : ne partage les liens
        « Membres » qu'aux membres du bureau. Chaque action (comptage, pause, case cochée) exige
        l'identité du membre (prénom, nom, rôle) et est tracée en son nom. Les liens « Admins »
        donnent accès aux <strong>données financières</strong> — récap complet, analytics, bilan.
        La régénération du lien <strong>révoque tous les liens d'un coup</strong>.
    </p>
    <form method="post" action="<?= e(url('/admin/kiosques/regenerate')) ?>"
          data-confirm="Régénérer le lien MEMBRES ? Tous les liens membres déjà partagés (hub, comptages, liste, ventes du jour) cesseront de fonctionner."
          data-confirm-button="Régénérer membres">
        <?= csrf_field() ?>
        <input type="hidden" name="scope" value="membres">
        <button type="submit" class="btn btn-outline btn-sm">👥 Régénérer le lien membres</button>
    </form>
    <form method="post" action="<?= e(url('/admin/kiosques/regenerate')) ?>" style="margin-top: 0.5rem;"
          data-confirm="Régénérer le lien ADMIN ? Tous les liens financiers déjà copiés (hub admin, récaps) cesseront de fonctionner."
          data-confirm-button="Régénérer admins">
        <?= csrf_field() ?>
        <input type="hidden" name="scope" value="admins">
        <button type="submit" class="btn btn-outline btn-sm">🔐 Régénérer le lien admins</button>
    </form>
</div>

<section class="kq-section">
    <h2 class="kq-section-title">👥 Membres — à partager</h2>
    <p class="kq-section-sub">Outils de saisie et suivi léger : aucun bénéfice ni donnée financière sensible.</p>

    <?php foreach ($memberPages as $p): ?>
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
</section>

<section class="kq-section">
    <h2 class="kq-section-title">🔐 Admins — pour ton téléphone</h2>
    <p class="kq-section-sub">Données financières complètes. Les pages /admin demandent la connexion admin (mémorisée sur ton téléphone) ; les liens kiosque marchent sans connexion.</p>

    <?php foreach ($adminPages as $p): ?>
    <div class="kq-card is-admin<?= !empty($p['main']) ? ' is-main' : '' ?>">
        <div class="kq-head">
            <span class="kq-emoji"><?= $p['emoji'] ?></span>
            <span class="kq-label"><?= e($p['label']) ?></span>
            <?php if (!empty($p['main'])): ?><span class="kq-badge">à garder sous la main</span><?php endif; ?>
            <?php if (empty($p['kiosk'])): ?><span class="kq-badge" style="color:#a78bfa;background:rgba(97,80,170,0.16)">connexion requise</span><?php endif; ?>
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
</section>

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
