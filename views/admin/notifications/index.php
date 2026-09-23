<?php

declare(strict_types=1);

/**
 * Page « Notifications SMS » — rapport quotidien Free Mobile.
 *
 * @var bool $enabled
 * @var string $days      CSV ISO-8601 « 1,2,3,4,5 »
 * @var string $time      « 20:00 »
 * @var string $template
 * @var list<array{label:string, user:string, pass:string}> $recipients
 * @var string $lastSent
 * @var string $preview
 * @var array<string,string> $vars        Variables réelles du jour
 * @var array<string, list<array{var:string, desc:string}>> $varGroups
 * @var float $ca
 * @var float $profit
 */

use App\Core\SmsReport;

$dayLabels = [
    1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi',
    4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche',
];
$selectedDays = array_map('intval', explode(',', $days));
?>
<div class="compta-head">
    <div class="compta-head-row">
        <div>
            <p class="eyebrow">Système</p>
            <h1 class="page-title">Notifications SMS</h1>
            <p class="muted">Rapport quotidien envoyé par SMS via l'API Free Mobile : CA, bénéfice, top produits et plus.</p>
        </div>
        <span class="badge <?= $enabled ? 'badge-success' : 'badge-muted' ?>">
            <?= $enabled ? '✅ Activé' : '⛔ Désactivé' ?>
        </span>
    </div>
</div>

<!-- ===================== Planification ===================== -->
<form method="post" action="<?= e(url('/admin/notifications/save')) ?>">
    <?= csrf_field() ?>

    <section class="card surface glass" id="sms-planif">
        <h2 class="card-title">🗓️ Planification</h2>

        <div class="sms-planif-row">
            <div class="sms-planif-enabled">
                <label class="toggle-switch">
                    <input type="checkbox" name="sms_report_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                    <span class="toggle-label <?= $enabled ? 'is-on' : '' ?>"><?= $enabled ? '✅ Activé' : '⛔ Désactivé' ?></span>
                </label>
            </div>

            <div class="field sms-planif-time">
                <label for="sms_report_time">⏰ Heure d'envoi</label>
                <input type="time" id="sms_report_time" name="sms_report_time" value="<?= e($time) ?>" required>
            </div>
        </div>

        <div class="field">
            <label>📅 Jours d'envoi
                <span class="sms-quick-days">
                    <button type="button" class="btn btn-ghost btn-sm" data-days-preset="1,2,3,4,5">Lun-Ven</button>
                    <button type="button" class="btn btn-ghost btn-sm" data-days-preset="6,7">Week-end</button>
                    <button type="button" class="btn btn-ghost btn-sm" data-days-preset="1,2,3,4,5,6,7">Tous</button>
                    <button type="button" class="btn btn-ghost btn-sm" data-days-preset="">Aucun</button>
                </span>
            </label>
            <div class="sms-days" id="sms-days">
                <?php foreach ($dayLabels as $n => $label): ?>
                    <label class="sms-day-chip<?= in_array($n, $selectedDays, true) ? ' is-on' : '' ?>">
                        <input type="checkbox" name="sms_report_days[]" value="<?= $n ?>"
                               <?= in_array($n, $selectedDays, true) ? 'checked' : '' ?>>
                        <span><?= e($label) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <hr>

        <h2 class="card-title">✉️ Modèle du message</h2>

        <div class="sms-editor">
            <div class="sms-editor-input">
                <textarea id="sms_report_template" name="sms_report_template" rows="14" maxlength="999"
                          class="sms-template-area"><?= e($template) ?></textarea>
                <div class="sms-editor-foot">
                    <button type="button" class="btn btn-ghost btn-sm" id="sms-template-reset">↺ Modèle par défaut</button>
                    <span class="sms-count" id="sms-char-count">0 / 999</span>
                </div>
                <p class="muted" style="font-size:0.82rem;">
                    💡 Clique sur une variable pour l'insérer dans le message. Laisse vide pour revenir au modèle par défaut.
                </p>
            </div>

            <div class="sms-editor-vars">
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
            </div>
        </div>

        <div class="field">
            <label>👀 Aperçu live (données réelles du jour)</label>
            <pre class="sms-preview surface glass" id="sms-preview"><?= e($preview) ?></pre>
        </div>

        <div class="settings-save-bar">
            <button type="submit" class="btn btn-primary btn-lg">💾 Enregistrer la planification</button>
        </div>
    </section>
</form>

<!-- ===================== Destinataires ===================== -->
<section class="card surface glass">
    <h2 class="card-title">📱 Destinataires (lignes Free Mobile)</h2>
    <p class="muted" style="font-size:0.85rem;">
        Pour chaque ligne : active l'option « Notifications par SMS » dans l'espace abonné Free Mobile,
        puis note l'identifiant (8 chiffres) et la clé générée. Le SMS n'arrive que sur la ligne concernée.
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

<!-- ===================== Test & envoi ===================== -->
<section class="card surface glass">
    <h2 class="card-title">🧪 Test</h2>
    <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:center;">
        <p class="muted" style="margin:0;font-size:0.85rem;">
            Dernier envoi automatique : <strong><?= $lastSent !== '' ? e(date('d/m/Y', strtotime($lastSent))) : 'jamais' ?></strong>.
        </p>
        <form method="post" action="<?= e(url('/admin/notifications/test')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-outline">📤 Envoyer un rapport de test maintenant</button>
        </form>
    </div>
</section>

<script>
window.AEIC_SMS_VARS = <?= json_encode($vars, JSON_UNESCAPED_UNICODE) ?>;
window.AEIC_SMS_DEFAULT_TEMPLATE = <?= json_encode(SmsReport::DEFAULT_TEMPLATE, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script>
(function () {
    var textarea = document.getElementById('sms_report_template');
    var preview = document.getElementById('sms-preview');
    var counter = document.getElementById('sms-char-count');
    var MAX = 999;
    var vars = window.AEIC_SMS_VARS || {};
    var defaultTemplate = window.AEIC_SMS_DEFAULT_TEMPLATE || '';
    if (!textarea || !preview) return;

    function render() {
        var tpl = textarea.value;
        var msg = tpl;
        Object.keys(vars).forEach(function (k) {
            msg = msg.split(k).join(vars[k]);
        });
        preview.textContent = msg.trim();

        var len = Array.from(tpl).length;
        counter.textContent = len + ' / ' + MAX;
        counter.classList.toggle('is-warn', len > 800 && len <= MAX);
        counter.classList.toggle('is-over', len > MAX);
    }

    function insertVar(name) {
        var tag = '{' + name + '}';
        var start = textarea.selectionStart || 0;
        var end = textarea.selectionEnd || 0;
        var value = textarea.value;
        textarea.value = value.slice(0, start) + tag + value.slice(end);
        var pos = start + tag.length;
        textarea.focus();
        textarea.setSelectionRange(pos, pos);
        render();
    }

    document.querySelectorAll('.sms-var-chip').forEach(function (chip) {
        chip.addEventListener('click', function () { insertVar(chip.getAttribute('data-var')); });
    });

    document.querySelectorAll('[data-days-preset]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var preset = (btn.getAttribute('data-days-preset') || '').split(',').filter(Boolean);
            document.querySelectorAll('#sms-days input[type="checkbox"]').forEach(function (cb) {
                cb.checked = preset.indexOf(cb.value) !== -1;
                cb.closest('.sms-day-chip').classList.toggle('is-on', cb.checked);
            });
        });
    });

    document.querySelectorAll('#sms-days input[type="checkbox"]').forEach(function (cb) {
        cb.addEventListener('change', function () {
            cb.closest('.sms-day-chip').classList.toggle('is-on', cb.checked);
        });
    });

    var reset = document.getElementById('sms-template-reset');
    if (reset) {
        reset.addEventListener('click', function () {
            textarea.value = defaultTemplate;
            render();
        });
    }

    textarea.addEventListener('input', render);
    render();
})();
</script>
