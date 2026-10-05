<?php

declare(strict_types=1);

/**
 * Liste de courses : produits à acheter uniquement, quantités arrondies
 * aux packs d'achat. Volontairement minimaliste — utilisable en kiosque
 * (layout kiosk, sans navigation) sur le téléphone.
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
?>
<style>
    .list-chips { display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; margin-bottom: 1.1rem; }
    .list-chips .chip-row { display: flex; gap: 0.4rem; flex-wrap: wrap; }
    .list-total {
        display: flex; align-items: baseline; gap: 1rem; flex-wrap: wrap;
        padding: 0.7rem 1.1rem; margin-bottom: 1.1rem;
        background: rgba(72, 189, 211, 0.07); border: 1px solid rgba(72, 189, 211, 0.25);
        border-radius: 12px; font-size: 0.95rem;
    }
    .list-total strong { font-size: 1.15rem; color: var(--primary, #48bdd3); }
    .shopping-list { list-style: none; margin: 0; padding: 0; }
    .shopping-item {
        display: flex; align-items: center; justify-content: space-between; gap: 0.9rem;
        padding: 0.65rem 0.2rem; border-top: 1px solid rgba(255, 255, 255, 0.06);
    }
    .shopping-item:first-child { border-top: none; }
    .shopping-check { display: flex; align-items: center; gap: 0.6rem; cursor: pointer; min-width: 0; }
    .shopping-check input { width: 1.1rem; height: 1.1rem; accent-color: var(--primary, #48bdd3); flex-shrink: 0; }
    .shopping-name { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
    .shopping-need { font-size: 0.75rem; }
    .shopping-item:has(input:checked) { opacity: 0.45; }
    .shopping-item:has(input:checked) .shopping-name { text-decoration: line-through; }
    .shopping-qty { font-size: 1.25rem; font-weight: 900; color: var(--primary, #48bdd3); white-space: nowrap; }
    .list-empty { text-align: center; padding: 2.5rem 1rem; font-size: 1.05rem; }
    @media print {
        .reappro-bar, details, .compta-head .eyebrow { display: none !important; }
        body { background: #fff !important; color: #111 !important; }
        .card { background: #fff !important; border-color: #ddd !important; }
    }
</style>

<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité</p>
        <h1 class="page-title">Liste de courses</h1>
        <p class="muted">Produits à racheter, quantités arrondies aux packs d'achat.</p>
    </div>
</div>

<div class="reappro-bar list-chips">
    <span class="field-label">Couvrir pour</span>
    <div class="chip-row">
        <?php foreach ($covers as $k => $c): ?>
            <?php if ($kiosk): ?>
                <a class="chip<?= $k === $coverKey ? ' is-active' : '' ?>"
                   href="<?= e(url('/kiosque/liste/' . $kioskToken) . '?c=' . $k) ?>"><?= e($c['label']) ?></a>
            <?php else: ?>
                <a class="chip<?= $k === $coverKey ? ' is-active' : '' ?>"
                   href="<?= e(url('/admin/compta/liste') . '?c=' . $k) ?>"><?= e($c['label']) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($items !== []): ?>
<div class="list-total">
    <span>Total à acheter : <strong><?= (int) $totalUnits ?> unités</strong></span>
    <span>≈ <strong><?= e(formatPrice($totalCost)) ?></strong></span>
    <?php if ($missingCost > 0): ?>
        <span class="muted"><?= $missingCost ?> produit<?= $missingCost > 1 ? 's' : '' ?> sans coût saisi (hors total)</span>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($items === []): ?>
<section class="card surface glass">
    <p class="list-empty">Rien à racheter pour l'instant — le stock couvre la période choisie.</p>
</section>
<?php else: ?>
<?php $groups = []; ?>
<?php foreach ($items as $r) { $groups[(string) ($r['category'] !== '' ? $r['category'] : 'Divers')][] = $r; } ?>
<?php ksort($groups, SORT_NATURAL | SORT_FLAG_CASE); ?>
<?php foreach ($groups as $cat => $groupItems): ?>
<section class="card surface glass">
    <h2 class="card-title"><?= e($cat) ?> <span class="muted" style="font-size: 0.8rem; font-weight: 400;">(<?= count($groupItems) ?>)</span></h2>
    <ul class="shopping-list">
        <?php foreach ($groupItems as $r): ?>
        <li class="shopping-item">
            <label class="shopping-check">
                <input type="checkbox">
                <span class="shopping-name">
                    <strong><?= e((string) $r['name']) ?></strong>
                    <?php if ((int) $r['pack'] > 1): ?>
                        <span class="badge badge-info">pack de <?= (int) $r['pack'] ?></span>
                    <?php endif; ?>
                    <?php if ((int) $r['to_order_raw'] !== (int) $r['to_order']): ?>
                        <span class="muted shopping-need" title="Besoin avant arrondi au pack">besoin <?= (int) $r['to_order_raw'] ?></span>
                    <?php endif; ?>
                </span>
            </label>
            <span class="shopping-qty" title="À acheter (arrondi au pack)"><strong><?= (int) $r['to_order'] ?></strong></span>
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
