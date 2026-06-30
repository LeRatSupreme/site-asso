<?php

declare(strict_types=1);

/**
 * Page de check-in : scanner / saisir un token QR.
 *
 * @var array<string,mixed> $event
 * @var int $count
 * @var int $present
 */

$event   = $event ?? [];
$count   = $count ?? 0;
$present = $present ?? 0;
$slug    = (string) ($event['slug'] ?? '');
?>
<header class="admin-section-head">
    <p><a href="<?= e(url('/admin/events/' . rawurlencode($slug) . '/registrations')) ?>">← Inscriptions</a></p>
    <h2 class="card-title">Check-in — <?= e($event['title'] ?? '') ?></h2>
    <p class="card-meta"><?= e((string) $present) ?> présent(s) / <?= e((string) $count) ?> inscrit(s)</p>
</header>

<div class="card surface glass checkin-panel">
    <label for="checkin-token" class="eyebrow">Scanner / saisir un token</label>
    <input type="text"
           id="checkin-token"
           autocomplete="off"
           autofocus
           placeholder="Token du QR code…"
           class="checkin-input">
    <p class="card-meta" id="checkin-hint">Scannez le QR de chaque participant. Le champ se vide après chaque scan.</p>

    <ul class="checkin-log" id="checkin-log" aria-live="polite"></ul>
</div>

<style>
    .checkin-panel { max-width: 560px; }
    .checkin-input {
        width: 100%;
        font-size: 1.2rem;
        padding: 0.8rem 1rem;
        border-radius: 0.6rem;
        background: rgba(255,255,255,0.06);
        border: 2px solid rgba(72,189,211,0.4);
        color: var(--foreground);
        margin: 0.5rem 0;
    }
    .checkin-input:focus { outline: none; border-color: var(--primary); }
    .checkin-log { list-style: none; padding: 0; margin: 1rem 0 0; max-height: 320px; overflow-y: auto; }
    .checkin-log li {
        padding: 0.7rem 1rem;
        border-radius: 0.5rem;
        margin-bottom: 0.4rem;
        font-weight: 700;
        animation: fadeIn 0.15s ease;
    }
    .checkin-log li.ok      { background: rgba(34,197,94,0.18); color: #4ade80; }
    .checkin-log li.warning { background: rgba(217,119,6,0.18); color: #fbbf24; }
    .checkin-log li.error   { background: rgba(239,68,68,0.18); color: #f87171; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: none; } }
</style>

<script>
    (function () {
        var input = document.getElementById('checkin-token');
        var log = document.getElementById('checkin-log');
        var endpoint = "<?= e(url('/admin/events/' . rawurlencode($slug) . '/checkin')) ?>";
        var csrf = "<?= e(csrf_token()) ?>";

        function addLine(message, kind) {
            var li = document.createElement('li');
            li.className = kind;
            li.textContent = message;
            input.focus();
            log.prepend(li);
        }

        input.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') { return; }
            e.preventDefault();
            var token = input.value.trim();
            if (token === '') { return; }

            fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': csrf
                },
                credentials: 'same-origin',
                body: JSON.stringify({ token: token })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.ok && data.already) {
                    addLine('⚠️ ' + data.message, 'warning');
                } else if (data.ok) {
                    addLine('✅ ' + data.message, 'ok');
                } else {
                    addLine('❌ ' + data.message, 'error');
                }
            })
            .catch(function () { addLine('❌ Erreur réseau.', 'error'); });

            input.value = '';
            input.focus();
        });
    })();
</script>
