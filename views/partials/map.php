<?php

declare(strict_types=1);

/**
 * Section « Où nous trouver » avec carte interactive OpenStreetMap.
 *
 * Coordonnées/address ajustables via les settings (map_lat, map_lon, address).
 * Par défaut : IUT de Calais — département Informatique, 19 Rue Louis David.
 */

use App\Models\Setting;

$lat = Setting::get('map_lat', '50.9463');
$lon = Setting::get('map_lon', '1.8456');
$address = Setting::get('address', t('map.address'));

$lat = trim((string) $lat);
$lon = trim((string) $lon);
if ($lat === '' || $lon === '') {
    $lat = '50.9463';
    $lon = '1.8456';
}

// Petite zone autour du point pour l'embed OSM.
$dLat = 0.0028;
$dLon = 0.0050;
$bbox = sprintf('%f,%f,%f,%f', (float) $lon - $dLon, (float) $lat - $dLat, (float) $lon + $dLon, (float) $lat + $dLat);
$embedSrc = 'https://www.openstreetmap.org/export/embed.html?bbox=' . rawurlencode($bbox)
    . '&layer=mapnik&marker=' . rawurlencode($lat . ',' . $lon);
$osmLink = 'https://www.openstreetmap.org/?mlat=' . rawurlencode($lat) . '&mlon=' . rawurlencode($lon) . '#map=17/' . rawurlencode($lat) . '/' . rawurlencode($lon);
$gmapsLink = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($lat . ',' . $lon);
?>
<section class="section section-alt" id="ou-nous-trouver">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow"><?= e(t('map.eyebrow')) ?></span>
            <h2 class="section-title"><?= e(t('map.title')) ?></h2>
        </div>

        <div class="map-block">
            <div class="map-card card surface glass">
                <h3 class="card-title"><?= e(t('map.card_title')) ?></h3>
                <p class="map-address"><?= e($address) ?></p>
                <div class="map-actions">
                    <a class="btn btn-primary btn-sm" href="<?= e($gmapsLink) ?>" target="_blank" rel="noopener"><?= e(t('map.directions')) ?></a>
                    <a class="btn btn-outline btn-sm" href="<?= e($osmLink) ?>" target="_blank" rel="noopener"><?= e(t('map.osm')) ?></a>
                </div>
            </div>

            <div class="map-frame" id="mapFrame">
                <button type="button" class="map-expand" id="mapExpand" aria-label="<?= e(t('map.expand')) ?>" title="<?= e(t('map.expand')) ?>" data-expand="<?= e(t('map.expand')) ?>" data-shrink="<?= e(t('map.shrink')) ?>"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg></button>
                <iframe
                    title="<?= e(t('map.iframe.title')) ?>"
                    src="<?= e($embedSrc) ?>"
                    loading="lazy"
                    referrerpolicy="no-referrer-when-downgrade"></iframe>
            </div>
        </div>
    </div>
</section>

<script>
(function () {
    var frame = document.getElementById('mapFrame');
    var btn = document.getElementById('mapExpand');
    if (!frame || !btn) return;

    var ICON_EXPAND = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg>';
    var ICON_SHRINK = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14h6v6M20 10h-6V4M14 10l7-7M3 21l7-7"/></svg>';

    function setOpen(open) {
        frame.classList.toggle('is-fullscreen', open);
        btn.innerHTML = open ? ICON_SHRINK : ICON_EXPAND;
        var label = open ? btn.getAttribute('data-shrink') : btn.getAttribute('data-expand');
        btn.setAttribute('aria-label', label);
        btn.title = label;
    }

    btn.addEventListener('click', function () {
        setOpen(!frame.classList.contains('is-fullscreen'));
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && frame.classList.contains('is-fullscreen')) {
            setOpen(false);
        }
    });
})();
</script>
