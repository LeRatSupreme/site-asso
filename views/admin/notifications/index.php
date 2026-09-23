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
            <p class="muted">Rapport quotidien envoyé par SMS via l'API Free Mobile : CA du jour, bénéfice et top produits.</p>
        </div>
        <span class="badge <?= $enabled ? 'badge-success' : 'badge-muted' ?>">
            <?= $enabled ? '✅ Activé' : '⛔ Désactivé' ?>
        </span>
    </div>
</div>

<!-- ===================== Planification ===================== -->
<form method="post" action="<?= e(url('/admin/notifications/save')) ?>">
    <?= csrf_field() ?>

    <section class="card surface glass">
        <h2 class="card-title">🗓️ Planification</h2>

        <div class="field">
            <label class="toggle-switch">
                <input type="checkbox" name="sms_report_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
                <span class="toggle-label <?= $enabled ? 'is-on' : '' ?>"><?= $enabled ? '✅ Activé' : '⛔ Désactivé' ?></span>
            </label>
        </div>

        <div class="field">
            <label>Jours d'envoi</label>
            <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
                <?php foreach ($dayLabels as $n => $label): ?>
                    <label class="btn btn-sm <?= in_array($n, $selectedDays, true) ? 'btn-primary' : 'btn-outline' ?>" style="cursor:pointer;">
                        <input type="checkbox" name="sms_report_days[]" value="<?= $n ?>"
                               <?= in_array($n, $selectedDays, true) ? 'checked' : '' ?>
                               style="display:none;"
                               onchange="this.closest('label').classList.toggle('btn-primary'); this.closest('label').classList.toggle('btn-outline');">
                        <?= e($label) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="field" style="max-width:180px;">
            <label for="sms_report_time">Heure d'envoi</label>
            <input type="time" id="sms_report_time" name="sms_report_time" value="<?= e($time) ?>" required>
        </div>

        <div class="field">
            <label for="sms_report_template">Modèle du message</label>
            <textarea id="sms_report_template" name="sms_report_template" rows="7" style="font-family:monospace;"><?= e($template) ?></textarea>
            <p class="muted" style="font-size:0.82rem;">
                Variables disponibles : <code>{date}</code> <code>{ca}</code> <code>{benefice}</code> <code>{qty}</code> <code>{top}</code> (top 3 produits).
                Laisses vide pour revenir au modèle par défaut. 999 caractères max (limite Free).
            </p>
        </div>

        <div class="settings-save-bar">
            <button type="submit" class="btn btn-primary">💾 Enregistrer la planification</button>
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

<!-- ===================== Test & aperçu ===================== -->
<section class="card surface glass">
    <h2 class="card-title">🧪 Test & aperçu</h2>

    <div class="grid grid-2">
        <div>
            <p class="muted" style="font-size:0.85rem;">Aperçu du rapport pour aujourd'hui
                (CA <?= e(formatPrice($ca)) ?>, bénéfice <?= e(formatPrice($profit)) ?>) :</p>
            <pre class="surface glass" style="padding:0.9rem;border-radius:12px;white-space:pre-wrap;font-size:0.85rem;"><?= e($preview) ?></pre>
        </div>
        <div>
            <p class="muted" style="font-size:0.85rem;">
                Le rapport part automatiquement via le cron du serveur.
                Dernier envoi : <strong><?= $lastSent !== '' ? e(date('d/m/Y', strtotime($lastSent))) : 'jamais' ?></strong>.
            </p>
            <form method="post" action="<?= e(url('/admin/notifications/test')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-outline">📤 Envoyer un rapport de test maintenant</button>
            </form>
        </div>
    </div>
</section>

<script>
// Toggle du label Activé/Désactivé.
document.querySelectorAll('.toggle-switch input[type="checkbox"]').forEach(function(cb) {
    cb.addEventListener('change', function() {
        var label = this.parentElement.querySelector('.toggle-label');
        if (label) {
            label.textContent = this.checked ? '✅ Activé' : '⛔ Désactivé';
            label.classList.toggle('is-on', this.checked);
        }
    });
});
</script>
