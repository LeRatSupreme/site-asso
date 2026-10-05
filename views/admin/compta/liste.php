<?php

declare(strict_types=1);

/**
 * Liste de courses : produits à acheter uniquement, quantités arrondies
 * aux packs d'achat. ULTRA SIMPLE — une grille de carrés : coche, chiffre,
 * nom. Utilisable en kiosque (layout kiosk, sans navigation) sur téléphone.
 *
 * @var list<array<string,mixed>> $items
 * @var array<string,array{label:string,days:int}> $covers
 * @var string $coverKey
 * @var int    $totalUnits
 * @var float  $totalCost
 * @var bool   $kiosk
 * @var string $kioskUrl
 * @var string $kioskToken
 */

use App\Models\Setting;

$siteName = Setting::get('site_name', 'AEIC');

/** Emoji par catégorie (recherche insensible à la casse, fallback 🛒). */
function liste_cat_emoji(string $cat): string
{
    $c = mb_strtolower($cat);

    return match (true) {
        str_contains($c, 'boisson')   => '🥤',
        str_contains($c, 'snack')     => '🍫',
        str_contains($c, 'bonbon'),
        str_contains($c, 'confiserie') => '🍬',
        str_contains($c, 'chip'),
        str_contains($c, 'apéritif'),
        str_contains($c, 'aperitif')  => '🍿',
        str_contains($c, 'aliment'),
        str_contains($c, 'food')      => '🥐',
        str_contains($c, 'hygiène'),
        str_contains($c, 'hygiene')   => '🧼',
        str_contains($c, 'fournit'),
        str_contains($c, 'matériel'),
        str_contains($c, 'materiel')  => '🧾',
        default                        => '🛒',
    };
}

// Regroupement par catégorie (catégorie vide → « Divers »).
$groups = [];
foreach ($items as $r) {
    $cat = (string) ($r['category'] !== '' ? $r['category'] : 'Divers');
    $groups[$cat][] = $r;
}
uksort($groups, static function (string $a, string $b): int {
    return strcasecmp($a, $b);
});
?>
<style>
    .shop-seg { display: flex; gap: 0.4rem; flex-wrap: wrap; margin: 0 0 1.2rem; }
    .shop-seg a {
        padding: 0.5rem 1rem; border-radius: 999px;
        border: 1px solid var(--border, rgba(255,255,255,0.15));
        background: rgba(255, 255, 255, 0.04); color: var(--foreground, inherit);
        font-weight: 700; font-size: 0.88rem; text-decoration: none; line-height: 1.3;
        transition: background 0.15s, border-color 0.15s, color 0.15s;
    }
    .shop-seg a:hover { border-color: var(--primary, #48bdd3); color: var(--primary, #48bdd3); }
    .shop-seg a.is-active {
        background: var(--primary, #48bdd3); border-color: var(--primary, #48bdd3);
        color: #06222b;
    }

    .shop-total {
        display: flex; align-items: center; gap: 1.4rem; flex-wrap: wrap;
        padding: 0.75rem 1.2rem; margin-bottom: 1.2rem;
        background: linear-gradient(135deg, rgba(72, 189, 211, 0.28), rgba(72, 189, 211, 0.08));
        border: 1px solid rgba(72, 189, 211, 0.35);
        border-radius: 14px; font-size: 0.95rem;
    }
    .shop-total strong { font-size: 1.1rem; color: var(--primary, #48bdd3); }

    .shop-cat {
        display: flex; align-items: center; gap: 0.55rem;
        margin: 1.4rem 0.15rem 0.7rem; font-size: 0.95rem; font-weight: 800;
        text-transform: uppercase; letter-spacing: 0.06em;
    }
    .shop-cat .shop-cat-emoji { font-size: 1.25rem; }
    .shop-cat .shop-cat-count {
        font-size: 0.7rem; font-weight: 800; color: var(--muted, #8892a6);
        background: rgba(255, 255, 255, 0.06); border-radius: 999px; padding: 0.1rem 0.55rem;
    }

    .shop-grid {
        list-style: none; margin: 0; padding: 0;
        display: grid; grid-template-columns: repeat(auto-fill, minmax(135px, 1fr));
        gap: 0.6rem;
    }
    .shop-item {
        position: relative;
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        gap: 0.25rem; text-align: center;
        aspect-ratio: 1 / 1; padding: 0.6rem 0.5rem;
        background: rgba(255, 255, 255, 0.035);
        border: 1px solid rgba(255, 255, 255, 0.07);
        border-radius: 16px;
        transition: opacity 0.15s ease, border-color 0.15s ease;
    }
    .shop-check {
        appearance: none; -webkit-appearance: none;
        position: absolute; top: 0.4rem; left: 0.4rem;
        width: 22px; height: 22px; margin: 0; cursor: pointer;
        border: 2px solid var(--muted, #8892a6); border-radius: 50%;
        background: transparent;
        transition: background 0.15s, border-color 0.15s;
    }
    .shop-check:checked { background: var(--primary, #48bdd3); border-color: var(--primary, #48bdd3); }
    .shop-check:checked::after {
        content: '✓'; position: absolute; inset: 0;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.8rem; font-weight: 900; color: #06222b;
    }
    .shop-qty { font-size: 2.4rem; font-weight: 900; line-height: 1; color: var(--primary, #48bdd3); }
    .shop-qty.is-unknown { color: var(--muted, #8892a6); font-size: 1.7rem; }
    .shop-name {
        font-size: 0.9rem; font-weight: 800; line-height: 1.15;
        display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
        word-break: break-word; max-width: 100%;
    }
    .shop-pack {
        font-size: 0.64rem; font-weight: 800; color: var(--primary, #48bdd3);
        background: rgba(72, 189, 211, 0.14); border-radius: 999px; padding: 0.08rem 0.5rem;
        white-space: nowrap;
    }
    .shop-item.is-done { opacity: 0.35; border-color: transparent; }
    .shop-item.is-done .shop-name { text-decoration: line-through; }

    .shop-empty { text-align: center; padding: 3.5rem 1rem; }
    .shop-empty-emoji { font-size: 3rem; margin-bottom: 0.6rem; }
    .shop-empty-title { font-size: 1.25rem; font-weight: 900; margin: 0 0 0.3rem; }
    .shop-empty-sub { color: var(--muted, #8892a6); margin: 0; }

    .shop-footnote { text-align: center; margin-top: 1.75rem; }

    @media print {
        .shop-seg, details, .compta-head .eyebrow { display: none !important; }
        body { background: #fff !important; color: #111 !important; }
        .card, .shop-item { background: #fff !important; border-color: #ddd !important; }
    }
</style>

<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité</p>
        <h1 class="page-title">Liste de courses</h1>
    </div>
</div>

<div class="shop-seg">
    <?php foreach ($covers as $k => $c): ?>
        <?php if ($kiosk): ?>
            <a class="<?= $k === $coverKey ? 'is-active' : '' ?>"
               href="<?= e(url('/kiosque/liste/' . $kioskToken) . '?c=' . $k) ?>"><?= e($c['label']) ?></a>
        <?php else: ?>
            <a class="<?= $k === $coverKey ? 'is-active' : '' ?>"
               href="<?= e(url('/admin/compta/liste') . '?c=' . $k) ?>"><?= e($c['label']) ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
</div>

<?php if ($items !== []): ?>
<div class="shop-total">
    <strong><?= (int) $totalUnits ?> unités</strong>
    <span>≈ <?= e(formatPrice($totalCost)) ?></span>
</div>
<?php endif; ?>

<?php if ($items === []): ?>
<section class="card surface glass shop-empty">
    <div class="shop-empty-emoji">🎉</div>
    <p class="shop-empty-title">Rien à racheter !</p>
    <p class="shop-empty-sub">Le stock couvre la période choisie. essaie « Couvrir pour » plus long.</p>
</section>
<?php else: ?>
<?php foreach ($groups as $cat => $groupItems): ?>
<section>
    <h2 class="shop-cat">
        <span class="shop-cat-emoji"><?= liste_cat_emoji($cat) ?></span>
        <?= e($cat) ?>
        <span class="shop-cat-count"><?= count($groupItems) ?></span>
    </h2>
    <ul class="shop-grid">
        <?php foreach ($groupItems as $r): $uid = 'sp-' . substr(md5((string) $r['name']), 0, 10); ?>
        <li class="shop-item" id="<?= e($uid) ?>">
            <input type="checkbox" class="shop-check" id="<?= e($uid) ?>-chk" aria-label="Acheté : <?= e((string) $r['name']) ?>">
            <?php if ((int) $r['to_order'] > 0): ?>
                <span class="shop-qty"><?= (int) $r['to_order'] ?></span>
            <?php else: ?>
                <span class="shop-qty is-unknown" title="Stock épuisé — consommation inconnue sur la période">—</span>
            <?php endif; ?>
            <span class="shop-name" title="<?= e((string) $r['name']) ?>"><?= e((string) $r['name']) ?></span>
            <?php if ((int) $r['pack'] > 1): ?>
                <span class="shop-pack">pack de <?= (int) $r['pack'] ?></span>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endforeach; ?>
<?php endif; ?>

<?php if (!$kiosk): ?>
<details class="card surface glass" style="padding: 0.85rem 1.1rem; margin-top: 1.25rem;">
    <summary style="cursor: pointer; font-weight: 800;">📱 Accès téléphone sans connexion</summary>
    <p class="muted" style="margin: 0.6rem 0 0.4rem;">
        Copie ce lien secret sur ton téléphone : il ouvre cette liste directement,
        <strong>sans jamais demander de connexion</strong> (même jeton que la page Réappro — le régénérer révoque les deux).
    </p>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center;">
        <input type="text" readonly value="<?= e($kioskUrl) ?>" id="liste-kiosk-url" onclick="this.select()"
               style="flex: 1 1 260px; padding: 0.5rem 0.7rem; border: 1px solid var(--border); border-radius: 8px; background: rgba(255,255,255,0.04); color: var(--foreground); font-size: 0.85rem;">
        <button type="button" class="btn btn-outline btn-sm"
                onclick="(function (b) { var i = document.getElementById('liste-kiosk-url'); i.select(); try { document.execCommand('copy'); } catch (e) {} if (navigator.clipboard) { navigator.clipboard.writeText(i.value); } b.textContent = 'Copié ✓'; setTimeout(function () { b.textContent = 'Copier le lien'; }, 1500); })(this)">Copier le lien</button>
    </div>
</details>
<?php endif; ?>

<script>
(function () {
    Array.prototype.forEach.call(document.querySelectorAll('.shop-check'), function (b) {
        b.addEventListener('change', function () {
            var item = b.closest('.shop-item');
            if (item) item.classList.toggle('is-done', b.checked);
        });
    });
})();
</script>
