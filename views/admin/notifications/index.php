<?php

declare(strict_types=1);

/**
 * Page « Notifications SMS » — messages programmés Free Mobile.
 *
 * @var list<array<string,mixed>> $schedules   Lignes sms_reports + time_hm + preview
 * @var list<array{label:string, user:string, pass:string}> $recipients
 * @var array<string,string> $vars             Variables réelles du jour
 * @var array<string, list<array{var:string, desc:string}>> $varGroups
 */

use App\Core\SmsReport;

$dayLabels = [
    1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi',
    4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche',
];
?>
<div class="compta-head">
    <div class="compta-head-row">
        <div>
            <p class="eyebrow">Système</p>
            <h1 class="page-title">Notifications SMS</h1>
            <p class="muted">Crée autant de messages programmés que tu veux : CA, bénéfice, top produits, catégories… envoyés par SMS via l'API Free Mobile.</p>
        </div>
    </div>
</div>

<!-- ===================== Messages programmés ===================== -->
<section class="card surface glass">
    <div class="compta-head-row">
        <h2 class="card-title">📨 Messages programmés (<?= count($schedules) ?>)</h2>
        <form method="post" action="<?= e(url('/admin/notifications/create')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary">➕ Nouveau message</button>
        </form>
    </div>

    <?php if ($schedules === []): ?>
        <p class="muted">Aucun message pour l'instant — clique sur « ➕ Nouveau message » pour commencer.</p>
    <?php endif; ?>

    <?php foreach ($schedules as $s):
        $id = (string) $s['id'];
        $enabled = (int) ($s['is_enabled'] ?? 0) === 1;
        $selectedDays = array_map('intval', explode(',', (string) ($s['days'] ?? '1,2,3,4,5')));
        $timeHm = (string) ($s['time_hm'] ?? '20:00');
        $label = (string) ($s['label'] ?? '');
        $lastDay = (string) ($s['last_sent_day'] ?? '');
    ?>
    <div class="sms-card<?= $enabled ? ' is-on' : '' ?>" data-card>
        <!-- Actions (hors du formulaire principal : forms non imbriqués) -->
        <div class="sms-card-actions">
            <?php if ($lastDay !== ''): ?>
                <span class="muted" style="font-size:0.78rem;">Dernier envoi : <?= e(date('d/m/Y', strtotime($lastDay))) ?></span>
            <?php endif; ?>
            <form method="post" action="<?= e(url('/admin/notifications/' . $id . '/test')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-outline btn-sm" title="Envoyer maintenant un test avec les données du jour">📤 Test</button>
            </form>
            <form method="post" action="<?= e(url('/admin/notifications/' . $id . '/delete')) ?>"
                  data-confirm="Supprimer ce message programmé ?">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-danger btn-sm">🗑</button>
            </form>
        </div>

        <form method="post" action="<?= e(url('/admin/notifications/' . $id . '/save')) ?>">
            <?= csrf_field() ?>

            <div class="sms-card-head">
                <input type="text" name="label" value="<?= e($label) ?>" maxlength="80"
                       class="sms-card-title-input" placeholder="Nom du message">
                <label class="toggle-switch">
                    <input type="checkbox" name="is_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                    <span class="toggle-label <?= $enabled ? 'is-on' : '' ?>"><?= $enabled ? '✅ Activé' : '⛔ Désactivé' ?></span>
                </label>
            </div>

            <div class="sms-planif-row">
                <div class="field" style="flex:1;min-width:260px;">
                    <label>📅 Jours d'envoi
                        <span class="sms-quick-days">
                            <button type="button" class="btn btn-ghost btn-sm" data-days-preset="1,2,3,4,5">Lun-Ven</button>
                            <button type="button" class="btn btn-ghost btn-sm" data-days-preset="6,7">Week-end</button>
                            <button type="button" class="btn btn-ghost btn-sm" data-days-preset="1,2,3,4,5,6,7">Tous</button>
                            <button type="button" class="btn btn-ghost btn-sm" data-days-preset="">Aucun</button>
                        </span>
                    </label>
                    <div class="sms-days">
                        <?php foreach ($dayLabels as $n => $dayLabel): ?>
                            <label class="sms-day-chip<?= in_array($n, $selectedDays, true) ? ' is-on' : '' ?>">
                                <input type="checkbox" name="days[]" value="<?= $n ?>"
                                       <?= in_array($n, $selectedDays, true) ? 'checked' : '' ?>>
                                <span><?= e($dayLabel) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="field sms-planif-time">
                    <label>⏰ Heure</label>
                    <input type="time" name="send_time" value="<?= e($timeHm) ?>" required>
                </div>
            </div>

            <div class="field">
                <label>✉️ Modèle du message <span class="muted" style="font-weight:400;">— clique sur une variable dans le panneau ci-dessus pour l'insérer</span></label>
                <textarea name="template" rows="9" maxlength="999" class="sms-template-area js-tpl"><?= e(trim((string) ($s['template'] ?? '')) !== '' ? (string) $s['template'] : SmsReport::DEFAULT_TEMPLATE) ?></textarea>
                <div class="sms-editor-foot">
                    <button type="button" class="btn btn-ghost btn-sm js-reset">↺ Modèle par défaut</button>
                    <span class="sms-count js-count">0 / 999</span>
                </div>
            </div>

            <div class="field">
                <label>👀 Aperçu live (données réelles du jour)</label>
                <pre class="sms-preview surface glass js-preview"><?= e((string) ($s['preview'] ?? '')) ?></pre>
            </div>

            <div class="settings-save-bar">
                <button type="submit" class="btn btn-primary">💾 Enregistrer ce message</button>
            </div>
        </form>
    </div>
    <hr>
    <?php endforeach; ?>
</section>

<!-- ===================== Variables ===================== -->
<section class="card surface glass">
    <h2 class="card-title">🧩 Variables disponibles</h2>
    <p class="muted" style="font-size:0.82rem;">
        Clique sur une variable pour l'insérer dans le modèle du message que tu es en train de modifier.
        L'aperçu de chaque message se met à jour en temps réel avec les données réelles du jour.
    </p>
    <?php foreach ($varGroups as $groupName => $groupVars): ?>
        <div class="sms-var-group">
            <p class="sms-var-group-title"><?= e($groupName) ?></p>
            <div class="sms-var-list">
                <?php foreach ($groupVars as $v): ?>
                    <button type="button" class="sms-var-chip" data-var="<?= e($v['var']) ?>"
                            title="<?= e($v['desc']) ?>">
                        <code>{<?= e($v['var']) ?>}</code>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</section>

<!-- ===================== Destinataires ===================== -->
<section class="card surface glass">
    <h2 class="card-title">📱 Destinataires (lignes Free Mobile)</h2>
    <p class="muted" style="font-size:0.85rem;">
        Pour chaque ligne : active l'option « Notifications par SMS » dans l'espace abonné Free Mobile,
        puis note l'identifiant (8 chiffres) et la clé générée. Tous les messages partent vers toutes les lignes.
    </p>

    <?php if ($recipients === []): ?>
        <p class="muted">Aucun destinataire pour l'instant.</p>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr><th>Libellé</th><th>Identifiant</th><th>Clé</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($recipients as $i => $r): ?>
                    <tr>
                        <td><?= e($r['label'] !== '' ? $r['label'] : '—') ?></td>
                        <td><code><?= e($r['user']) ?></code></td>
                        <td><code><?= e(str_repeat('•', max(4, strlen($r['pass'])))) ?></code></td>
                        <td style="text-align:right;">
                            <form method="post" action="<?= e(url('/admin/notifications/recipient/' . $i . '/delete')) ?>"
                                  style="display:inline;" data-confirm="Supprimer ce destinataire ?">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-danger btn-sm">🗑 Supprimer</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <hr>
    <form method="post" action="<?= e(url('/admin/notifications/recipient')) ?>">
        <?= csrf_field() ?>
        <div style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:flex-end;">
            <div class="field" style="flex:1;min-width:140px;">
                <label for="rcpt_label">Libellé (optionnel)</label>
                <input type="text" id="rcpt_label" name="label" maxlength="40" placeholder="Trésorier">
            </div>
            <div class="field" style="flex:1;min-width:140px;">
                <label for="rcpt_user">Identifiant Free (8 chiffres)</label>
                <input type="text" id="rcpt_user" name="user" inputmode="numeric" maxlength="8" placeholder="12345678" required>
            </div>
            <div class="field" style="flex:1.4;min-width:180px;">
                <label for="rcpt_pass">Clé d'identification</label>
                <input type="text" id="rcpt_pass" name="pass" autocomplete="off" placeholder="clé de l'espace abonné" required>
            </div>
            <button type="submit" class="btn btn-primary">➕ Ajouter (+ SMS de test)</button>
        </div>
    </form>
</section>

<script>
window.AEIC_SMS_VARS = <?= json_encode($vars, JSON_UNESCAPED_UNICODE) ?>;
window.AEIC_SMS_DEFAULT_TEMPLATE = <?= json_encode(SmsReport::DEFAULT_TEMPLATE, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script>
(function () {
    var vars = window.AEIC_SMS_VARS || {};
    var defaultTemplate = window.AEIC_SMS_DEFAULT_TEMPLATE || '';
    var MAX = 999;

    function renderCard(card) {
        var tpl = card.querySelector('.js-tpl');
        var preview = card.querySelector('.js-preview');
        var counter = card.querySelector('.js-count');
        if (!tpl || !preview) return;

        var msg = tpl.value;
        Object.keys(vars).forEach(function (k) {
            msg = msg.split(k).join(vars[k]);
        });
        preview.textContent = msg.trim();

        if (counter) {
            var len = Array.from(tpl.value).length;
            counter.textContent = len + ' / ' + MAX;
            counter.classList.toggle('is-warn', len > 800 && len <= MAX);
            counter.classList.toggle('is-over', len > MAX);
        }
    }

    // Chaque carte : aperçu live + compteur + reset + puces jours.
    document.querySelectorAll('[data-card]').forEach(function (card) {
        var tpl = card.querySelector('.js-tpl');
        if (tpl) {
            tpl.addEventListener('input', function () { renderCard(card); });
            tpl.addEventListener('focus', function () { window.__smsLastTpl = tpl; });
        }
        var reset = card.querySelector('.js-reset');
        if (reset) {
            reset.addEventListener('click', function () {
                tpl.value = defaultTemplate;
                renderCard(card);
            });
        }
        card.querySelectorAll('[data-days-preset]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var preset = (btn.getAttribute('data-days-preset') || '').split(',').filter(Boolean);
                card.querySelectorAll('.sms-day-chip input[type="checkbox"]').forEach(function (cb) {
                    cb.checked = preset.indexOf(cb.value) !== -1;
                    cb.closest('.sms-day-chip').classList.toggle('is-on', cb.checked);
                });
            });
        });
        card.querySelectorAll('.sms-day-chip input[type="checkbox"]').forEach(function (cb) {
            cb.addEventListener('change', function () {
                cb.closest('.sms-day-chip').classList.toggle('is-on', cb.checked);
            });
        });
        renderCard(card);
    });

    // Variables : insertion dans le dernier modèle modifié.
    document.querySelectorAll('.sms-var-chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            var tpl = window.__smsLastTpl || document.querySelector('.js-tpl');
            if (!tpl) return;
            var tag = '{' + chip.getAttribute('data-var') + '}';
            var start = tpl.selectionStart || 0;
            var end = tpl.selectionEnd || 0;
            tpl.value = tpl.value.slice(0, start) + tag + tpl.value.slice(end);
            var pos = start + tag.length;
            tpl.focus();
            tpl.setSelectionRange(pos, pos);
            var card = tpl.closest('[data-card]');
            if (card) renderCard(card);
        });
    });

    // Toggle du label Activé/Désactivé.
    document.querySelectorAll('.toggle-switch input[type="checkbox"]').forEach(function (cb) {
        cb.addEventListener('change', function () {
            var label = this.parentElement.querySelector('.toggle-label');
            if (label) {
                label.textContent = this.checked ? '✅ Activé' : '⛔ Désactivé';
                label.classList.toggle('is-on', this.checked);
            }
        });
    });
})();
</script>
