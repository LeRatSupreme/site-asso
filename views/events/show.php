<?php

declare(strict_types=1);

use App\Core\Auth;

/**
 * Détail d'un événement.
 *
 * @var array<string,mixed> $event
 * @var list<array<string,mixed>> $variants
 * @var int $registrationsCount
 * @var list<array<string,mixed>> $participants
 * @var list<array<string,mixed>> $photos
 * @var bool $isPast
 * @var bool $isRegistered
 * @var bool $isOnWaitlist
 * @var int  $waitlistPosition
 * @var bool $isFull
 * @var int|null $remaining
 * @var string|null $qrToken
 */

$event     = $event ?? [];
$variants  = $variants ?? [];
$participants = $participants ?? [];
$photos    = $photos ?? [];
$isPast    = $isPast ?? false;
$isRegistered = $isRegistered ?? false;
$isOnWaitlist = $isOnWaitlist ?? false;
$waitlistPosition = $waitlistPosition ?? 0;
$isFull    = $isFull ?? false;
$remaining = $remaining ?? null;
$qrToken   = $qrToken ?? null;

$title       = tc((string) ($event['title'] ?? ''));
$excerpt     = tc((string) ($event['excerpt'] ?? ''));
$description = (string) ($event['description'] ?? '');
$location    = tc((string) ($event['location'] ?? ''));
$dateRaw     = (string) ($event['date'] ?? '');
$endDateRaw  = (string) ($event['end_date'] ?? '');
$image       = (string) ($event['image'] ?? '');
$price       = $event['price'] ?? null;
$sumupLink   = sumup_link((string) ($event['sumup_link'] ?? ''));
$maxCapacity = $event['max_capacity'] ?? null;

$imageUrl = '';
if ($image !== '') {
    $imageUrl = is_absolute_url($image) ? $image : asset(ltrim($image, '/'));
}

$priceLabel = ($price === null || (float) $price <= 0)
    ? t('event.free')
    : formatPrice($price);
?>
<header class="page-hero">
    <div class="halo halo-violet" aria-hidden="true"></div>
    <div class="container">
        <p class="back-link">
            <a href="<?= e(url('/events')) ?>"><?= e(t('event.back')) ?></a>
        </p>
        <span class="eyebrow"><?= e(t('event.eyebrow')) ?></span>
        <h1 class="page-title"><?= e($title) ?></h1>
    </div>
</header>

<section class="section">
    <div class="container event-detail">
        <div class="event-detail-main">
            <div class="event-cover surface">
                <?php if ($imageUrl !== ''): ?>
                    <img src="<?= e($imageUrl) ?>" alt="<?= e($title) ?>" loading="lazy">
                <?php else: ?>
                    <div class="event-cover-placeholder aeic-gradient" aria-hidden="true">AE</div>
                <?php endif; ?>
                <?php if ($isPast): ?>
                    <span class="badge badge-muted event-cover-badge"><?= e(t('event.ended')) ?></span>
                <?php endif; ?>
            </div>

            <article class="card surface glass">
                <span class="eyebrow"><?= e(t('event.details')) ?></span>
                <h2 class="card-title"><?= e($title) ?></h2>
                <p class="card-meta">
                    📅 <?= e(formatDateTime($dateRaw)) ?>
                    <?php if ($endDateRaw !== ''): ?> → <?= e(formatDateTime($endDateRaw)) ?><?php endif; ?>
                    <?php if ($location !== ''): ?> · 📍 <?= e($location) ?><?php endif; ?>
                </p>
                <?php if ($excerpt !== ''): ?>
                    <p class="card-excerpt"><?= e($excerpt) ?></p>
                <?php endif; ?>

                <div class="event-ical">
                    <button type="button" class="btn btn-outline btn-sm"
                            id="ical-export"
                            data-title="<?= e($title) ?>"
                            data-date="<?= e($dateRaw) ?>"
                            data-end="<?= e($endDateRaw) ?>"
                            data-location="<?= e($location) ?>"
                            data-description="<?= e(strip_tags($description)) ?>">
                        <?= e(t('event.add_calendar')) ?>
                    </button>
                    <a href="https://www.facebook.com/IUTinfoCalais/" target="_blank" rel="noopener" class="btn btn-outline btn-sm event-fb-btn">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:-2px;"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                        IUT Info Calais
                    </a>
                </div>
            </article>

            <?php if ($description !== ''): ?>
                <div class="prose surface glass"><?= $description ?></div>
            <?php endif; ?>

            <?php if (!empty($photos)): ?>
                <div class="event-gallery">
                    <span class="eyebrow"><?= e(t('event.gallery')) ?></span>
                    <h2 class="section-title"><?= e(t('event.photos')) ?></h2>
                    <div class="grid grid-3 gallery-grid">
                        <?php foreach ($photos as $photo): ?>
                            <figure class="gallery-item surface">
                                <img src="<?= e(is_absolute_url($photo['url']) ? $photo['url'] : asset(ltrim($photo['url'], '/'))) ?>"
                                     alt="<?= e($photo['caption'] ?? '') ?>"
                                     loading="lazy">
                                <?php if (!empty($photo['caption'])): ?>
                                    <figcaption><?= e($photo['caption']) ?></figcaption>
                                <?php endif; ?>
                            </figure>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php
            $showMap = !empty($event['show_map']) && !empty($event['map_lat']) && !empty($event['map_lon']);
            if ($showMap):
                $elat = (float) $event['map_lat'];
                $elon = (float) $event['map_lon'];
                $bbox = sprintf('%f,%f,%f,%f', $elon - 0.005, $elat - 0.0028, $elon + 0.005, $elat + 0.0028);
                $mapEmbed = 'https://www.openstreetmap.org/export/embed.html?bbox=' . rawurlencode($bbox)
                    . '&layer=mapnik&marker=' . rawurlencode((string) $event['map_lat'] . ',' . (string) $event['map_lon']);
                $mapGmaps = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode((string) $event['map_lat'] . ',' . (string) $event['map_lon']);
            ?>
                <div class="event-map card surface glass">
                    <span class="eyebrow"><?= e(t('event.location')) ?></span>
                    <h2 class="card-title"><?= e(t('event.where')) ?></h2>
                    <?php if ($location !== ''): ?><p class="card-meta">📍 <?= e($location) ?></p><?php endif; ?>
                    <div class="map-frame">
                        <iframe title="<?= e(tt('event.map.aria', ['{title}' => $title])) ?>" src="<?= e($mapEmbed) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                    </div>
                    <a class="btn btn-outline btn-sm" href="<?= e($mapGmaps) ?>" target="_blank" rel="noopener"><?= e(t('event.itinerary')) ?></a>
                </div>
            <?php endif; ?>
        </div>

        <aside class="event-detail-sidebar">
            <?php if (!$isPast): ?>
                <div class="card surface glass sidebar-card">
                    <h2 class="card-title"><?= e(t('event.participate')) ?></h2>
                    <p class="sidebar-price">
                        <span class="eyebrow"><?= e(t('event.price')) ?></span>
                        <strong class="stat-value"><?= e($priceLabel) ?></strong>
                    </p>
                    <?php if ($maxCapacity !== null): ?>
                        <?php if (!$isFull && $remaining !== null): ?>
                            <p class="card-meta"><?= tt('event.places_left', ['{n}' => $remaining]) ?> · <?= tt('event.signed_up', ['{a}' => $registrationsCount, '{b}' => $maxCapacity]) ?></p>
                        <?php else: ?>
                            <p class="card-meta"><strong><?= e(t('event.full')) ?></strong> · <?= tt('event.signed_up', ['{a}' => $registrationsCount, '{b}' => $maxCapacity]) ?></p>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if (Auth::check()): ?>
                        <?php if ($isRegistered): ?>
                            <p class="badge badge-success"><?= e(t('event.registered')) ?></p>

                            <?php if ($qrToken !== null && $qrToken !== ''): ?>
                                <?php
                                $checkinUrl = url('/admin/events/' . rawurlencode((string) ($event['slug'] ?? '')) . '/checkin?token=' . $qrToken);
                                $qrImg = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . rawurlencode($checkinUrl);
                                ?>
                                <div class="event-qr">
                                    <img src="<?= e($qrImg) ?>" alt="<?= e(t('event.qr.checkin')) ?>" width="200" height="200" loading="lazy">
                                    <p class="card-meta"><?= e(t('event.qr.help')) ?></p>
                                </div>
                            <?php endif; ?>

                            <form method="post" action="<?= e(url('/events/' . rawurlencode((string) $event['slug']) . '/unregister')) ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-outline btn-block"><?= e(t('event.unregister')) ?></button>
                            </form>
                        <?php elseif ($isOnWaitlist): ?>
                            <p class="badge badge-warning"><?= e(tt('event.waitlist', ['{n}' => $waitlistPosition])) ?></p>
                            <form method="post" action="<?= e(url('/events/' . rawurlencode((string) $event['slug']) . '/unregister')) ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-outline btn-block"><?= e(t('event.leave_queue')) ?></button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="<?= e(url('/events/' . rawurlencode((string) $event['slug']) . '/register')) ?>">
                                <?= csrf_field() ?>

                                <?php if (!empty($variants)): ?>
                                    <?php foreach ($variants as $variant): ?>
                                        <div class="field">
                                            <label for="variant-<?= e((string) $variant['id']) ?>">
                                                <?= e($variant['label'] ?? '') ?>
                                                <?php if (!empty($variant['required'])): ?><span class="badge badge-warning"><?= e(t('event.required')) ?></span><?php endif; ?>
                                            </label>
                                            <select id="variant-<?= e((string) $variant['id']) ?>"
                                                    name="variants[<?= e((string) $variant['id']) ?>]"
                                                    <?= !empty($variant['required']) ? 'required' : '' ?>>
                                                <option value=""><?= e(t('event.choose')) ?></option>
                                                <?php foreach ($variant['choices'] ?? [] as $choice): ?>
                                                    <option value="<?= e((string) $choice['id']) ?>"><?= e($choice['label'] ?? '') ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <?php if ($isFull): ?>
                                    <button type="submit" class="btn btn-warning btn-lg btn-block"><?= e(t('event.join_waitlist')) ?></button>
                                <?php else: ?>
                                    <button type="submit" class="btn btn-primary btn-lg btn-block"><?= e(t('event.register_btn')) ?></button>
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="card-excerpt"><?= e(t('event.login_to_register')) ?></p>
                        <a class="btn btn-primary btn-block" href="<?= e(url('/login?callbackUrl=') . rawurlencode('/events/' . ($event['slug'] ?? ''))) ?>"><?= e(t('event.login_btn')) ?></a>
                        <a class="btn btn-outline btn-block" href="<?= e(url('/register')) ?>"><?= e(t('event.create_account')) ?></a>
                    <?php endif; ?>

                    <?php if ($sumupLink !== null): ?>
                        <a class="btn btn-sumup btn-block" href="<?= e($sumupLink) ?>" rel="noopener noreferrer" target="_blank"><?= e(t('event.pay_online')) ?></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="card surface glass sidebar-card">
                <h2 class="card-title"><?= e(t('event.participants')) ?></h2>
                <p class="sidebar-count">
                    <strong class="stat-value"><?= e((string) $registrationsCount) ?></strong>
                    <span class="stat-label"><?= e(t('event.participants')) ?></span>
                </p>
                <?php if (!empty($participants)): ?>
                    <ul class="participant-list">
                        <?php foreach ($participants as $p): ?>
                            <li><?= e(trim(($p['prenom'] ?? '') . ' ' . ($p['nom'] ?? ''))) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="card-meta"><?= e(t('event.no_participants')) ?></p>
                <?php endif; ?>
            </div>
        </aside>
    </div>
</section>

<script>
    (function () {
        function toIcsDate(value) {
            // value = "YYYY-MM-DD HH:MM:SS" (heure locale serveur) -> UTC iCal.
            var s = String(value || '').replace(/[-:]/g, ' ').replace(' ', 'T');
            var d = new Date(s);
            if (isNaN(d.getTime())) { return ''; }
            function p(n) { return String(n).padStart(2, '0'); }
            return d.getUTCFullYear() + p(d.getUTCMonth() + 1) + p(d.getUTCDate())
                + 'T' + p(d.getUTCHours()) + p(d.getUTCMinutes()) + p(d.getUTCSeconds()) + 'Z';
        }

        function escapeIcs(text) {
            return String(text || '')
                .replace(/\\/g, '\\\\')
                .replace(/;/g, '\\;')
                .replace(/,/g, '\\,')
                .replace(/\r?\n/g, '\\n');
        }

        function downloadIcs(title, date, end, location, description) {
            var dtStart = toIcsDate(date);
            if (!dtStart) { return; }
            var lines = [
                'BEGIN:VCALENDAR',
                'VERSION:2.0',
                'PRODID:-//AEIC//Event//FR',
                'BEGIN:VEVENT',
                'UID:' + Date.now() + '@aeic',
                'DTSTAMP:' + toIcsDate(new Date().toISOString()),
                'DTSTART:' + dtStart
            ];
            if (end) { lines.push('DTEND:' + toIcsDate(end)); }
            lines.push('SUMMARY:' + escapeIcs(title));
            if (location) { lines.push('LOCATION:' + escapeIcs(location)); }
            if (description) { lines.push('DESCRIPTION:' + escapeIcs(description)); }
            lines.push('END:VEVENT', 'END:VCALENDAR');

            var blob = new Blob([lines.join('\r\n') + '\r\n'], { type: 'text/calendar;charset=utf-8' });
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = title.replace(/[^a-z0-9]/gi, '-') + '.ics';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }

        var btn = document.getElementById('ical-export');
        if (btn) {
            btn.addEventListener('click', function () {
                downloadIcs(
                    btn.getAttribute('data-title') || '',
                    btn.getAttribute('data-date') || '',
                    btn.getAttribute('data-end') || '',
                    btn.getAttribute('data-location') || '',
                    btn.getAttribute('data-description') || ''
                );
            });
        }
    })();
</script>
