<?php

declare(strict_types=1);

/**
 * Liste de courses : produits à acheter uniquement, quantités arrondies
 * aux packs d'achat. Interface volontairement VISUELLE et minimaliste —
 * utilisable en kiosque (layout kiosk, sans navigation) sur le téléphone.
 *
 * @var list<array<string,mixed>> $items
 * @var array<string,array{label:string,days:int}> $covers
 * @var string $coverKey
 * @var int    $totalUnits
 * @var float  $totalCost
 * @var int    $missingCost
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
    /* ── Segmented « Couvrir pour » ─────────────────────────────── */
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

    /* ── Bandeau total ──────────────────────────────────────────── */
    .shop-total {
        display: flex; align-items: center; gap: 2rem; flex-wrap: wrap;
        padding: 1.1rem 1.4rem; margin-bottom: 1.2rem;
        background: linear-gradient(135deg, rgba(72, 189, 211, 0.28), rgba(72, 189, 211, 0.08));
        border: 1px solid rgba(72, 189, 211, 0.35);
        border-radius: 18px;
    }
    .shop-total-num { display: block; font-size: 1.9rem; font-weight: 900; line-height: 1.05; }
    .shop-total-lbl {
        display: block; font-size: 0.66rem; text-transform: uppercase;
        letter-spacing: 0.09em; font-weight: 800; opacity: 0.75; margin-top: 0.15rem;
    }

    /* ── Progression ────────────────────────────────────────────── */
    .shop-progress { height: 7px; border-radius: 999px; background: rgba(255, 255, 255, 0.09); overflow: hidden; margin-bottom: 0.35rem; }
    .shop-progress-bar { height: 100%; width: 0%; border-radius: 999px; background: var(--primary, #48bdd3); transition: width 0.2s ease; }
    .shop-progress-txt { font-size: 0.75rem; color: var(--muted, #8892a6); margin: 0 0 1.2rem; }

    /* ── Catégories ─────────────────────────────────────────────── */
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

    /* ── Tuiles produit ─────────────────────────────────────────── */
    .shop-item {
        display: flex; align-items: center; gap: 0.95rem;
        padding: 0.85rem 1rem; margin-bottom: 0.6rem;
        background: rgba(255, 255, 255, 0.035);
        border: 1px solid rgba(255, 255, 255, 0.07);
        border-radius: 16px;
        transition: opacity 0.15s ease, border-color 0.15s ease;
    }
    .shop-check {
        appearance: none; -webkit-appearance: none;
        width: 27px; height: 27px; flex-shrink: 0; margin: 0; cursor: pointer;
        border: 2px solid var(--muted, #8892a6); border-radius: 50%;
        background: transparent; position: relative;
        transition: background 0.15s, border-color 0.15s;
    }
    .shop-check:checked { background: var(--primary, #48bdd3); border-color: var(--primary, #48bdd3); }
    .shop-check:checked::after {
        content: '✓'; position: absolute; inset: 0;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.95rem; font-weight: 900; color: #06222b;
    }
    .shop-label { display: flex; flex-direction: column; gap: 0.3rem; cursor: pointer; min-width: 0; }
    .shop-name { font-size: 1.06rem; font-weight: 800; line-height: 1.2; word-break: break-word; }
    .shop-meta { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
    .shop-pack {
        font-size: 0.7rem; font-weight: 800; color: var(--primary, #48bdd3);
        background: rgba(72, 189, 211, 0.14); border-radius: 999px; padding: 0.12rem 0.6rem;
    }
    .shop-need { font-size: 0.74rem; color: var(--muted, #8892a6); }
    .shop-qty {
        margin-left: auto; font-size: 2.05rem; font-weight: 900; line-height: 1;
        color: var(--primary, #48bdd3); white-space: nowrap; text-align: right;
    }
    .shop-qty small { font-size: 0.72rem; font-weight: 800; color: var(--muted, #8892a6); display: block; text-align: right; }
    .shop-item.is-done { opacity: 0.42; border-color: transparent; }
    .shop-item.is-done .shop-name { text-decoration: line-through; }

    .shop-empty { text-align: center; padding: 3.5rem 1rem; }
    .shop-empty-emoji { font-size: 3rem; margin-bottom: 0.6rem; }
    .shop-empty-title { font-size: 1.25rem; font-weight: 900; margin: 0 0 0.3rem; }
    .shop-empty-sub { color: var(--muted, #8892a6); margin: 0; }

    .shop-footnote { text-align: center; margin-top: 1.75rem; }

    @media print {
        .shop-seg, .shop-progress, .shop-progress-txt, details, .compta-head .eyebrow { display: none !important; }
        body { background: #fff !important; color: #111 !important; }
        .card, .shop-item { background: #fff !important; border-color: #ddd !important; }
    }
</style>

<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité</p>
        <h1 class="page-title">Liste de courses</h1>
        <p class="muted">Produits à racheter, quantités arrondies aux packs d'achat.</p>
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
    <div>
        <span class="shop-total-num"><?= (int) $totalUnits ?></span>
        <span class="shop-total-lbl">unités à acheter</span>
    </div>
    <div>
        <span class="shop-total-num">≈ <?= e(formatPrice($totalCost)) ?></span>
        <span class="shop-total-lbl">panier estimé<?php if ($missingCost > 0): ?> (+<?= $missingCost ?> sans coût)<?php endif ?></span>
    </div>
    <div style="margin-left: auto; min-width: 160px; flex: 1 1 160px;">
        <div class="shop-progress"><div class="shop-progress-bar" id="spBar"></div></div>
        <p class="shop-progress-txt" id="spTxt"></p>
    </div>
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
    <ul style="list-style: none; margin: 0; padding: 0;">
        <?php foreach ($groupItems as $r): $uid = 'sp-' . substr(md5((string) $r['name']), 0, 10); ?>
        <li class="shop-item" id="<?= e($uid) ?>">
            <input type="checkbox" class="shop-check" id="<?= e($uid) ?>-chk">
            <label class="shop-label" for="<?= e($uid) ?>-chk">
                <span class="shop-name"><?= e((string) $r['name']) ?></span>
                <span class="shop-meta">
                    <?php if ((int) $r['pack'] > 1): ?>
                        <span class="shop-pack">pack de <?= (int) $r['pack'] ?></span>
                    <?php endif; ?>
                    <?php if ((int) $r['to_order_raw'] !== (int) $r['to_order']): ?>
                        <span class="shop-need" title="Besoin avant arrondi au pack">besoin <?= (int) $r['to_order_raw'] ?></span>
                    <?php endif; ?>
                </span>
            </label>
            <span class="shop-qty" title="<?= (int) $r['to_order'] > 0 ? 'À acheter (arrondi au pack)' : 'Stock épuisé — consommation inconnue sur la période' ?>">
                <?php if ((int) $r['to_order'] > 0): ?>
                    <?= (int) $r['to_order'] ?><small>à acheter</small>
                <?php else: ?>
                    —<small>stock épuisé</small>
                <?php endif; ?>
            </span>
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
    var boxes = document.querySelectorAll('.shop-check');
    var bar = document.getElementById('spBar');
    var txt = document.getElementById('spTxt');

    function refresh() {
        var done = document.querySelectorAll('.shop-check:checked').length;
        var total = boxes.length;
        if (bar) bar.style.width = total > 0 ? Math.round(done / total * 100) + '%' : '0%';
        if (txt) txt.textContent = done + ' / ' + total + ' articles cochés';
        Array.prototype.forEach.call(boxes, function (b) {
            var item = b.closest('.shop-item');
            if (item) item.classList.toggle('is-done', b.checked);
        });
    }

    Array.prototype.forEach.call(boxes, function (b) {
        b.addEventListener('change', refresh);
    });
    refresh();
})();
</script>
