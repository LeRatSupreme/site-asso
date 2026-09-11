<?php

declare(strict_types=1);

/**
 * @var array<string,mixed>       $user
 * @var array{preset:string,from:?string,to:?string} $period
 * @var array<string,string>      $periodOptions
 * @var list<array<string,mixed>> $events lignes enrichies (+ ca, qty, costs, profit)
 * @var float                     $caT
 * @var float                     $costsT
 * @var float                     $profitT
 * @var int                       $count
 * @var list<array<string,mixed>> $allEvents
 * @var list<array<string,mixed>> $recentCosts
 */
?>
<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité</p>
        <h1 class="page-title">Événements</h1>
        <p class="muted">Crée un événement avec le <strong>nom exact du bouton SumUp</strong> : les ventes importées s'y rattachent automatiquement, tu saisis les coûts, et le bénéfice se calcule tout seul.</p>
    </div>
</div>

<div class="admin-actions">
    <?php require AEIC_VIEWS . '/admin/compta/_period_bar.php'; ?>
</div>

<?php
// Événements rentables (ventes rattachées et bénéfice positif ou nul).
$rentables = 0;
foreach ($events as $ev) {
    if ((float) $ev['ca'] > 0 && (float) $ev['profit'] >= 0) {
        $rentables++;
    }
}
?>
<div class="compta-kpis">
    <div class="card surface glass kpi">
        <p class="kpi-label">Revenu événements</p>
        <p class="kpi-value"><?= e(formatPrice($caT)) ?></p>
        <p class="kpi-sub"><?= (int) $count ?> événement<?= $count > 1 ? 's' : '' ?></p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Coûts</p>
        <p class="kpi-value"><?= e(formatPrice($costsT)) ?></p>
        <p class="kpi-sub">dépenses Événements liées</p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Bénéfice net</p>
        <p class="kpi-value <?= $profitT >= 0 ? 'is-positive' : 'is-negative' ?>"><?= e(formatPrice($profitT)) ?></p>
        <p class="kpi-sub">revenu − coûts</p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Événements rentables</p>
        <p class="kpi-value <?= $count > 0 && $rentables === $count ? 'is-positive' : ($rentables === 0 && $count > 0 ? 'is-negative' : '') ?>"><?= $count > 0 ? $rentables . ' / ' . $count : '—' ?></p>
        <p class="kpi-sub">objectif : tous 😉</p>
    </div>
</div>

<div class="compta-grid">
    <section class="card surface glass">
        <h2 class="card-title">Créer un événement</h2>
        <form method="post" action="<?= e(url('/admin/compta/evenements/save')) ?>">
            <?= csrf_field() ?>

            <div class="field">
                <label for="name">Nom</label>
                <input type="text" id="name" name="name" placeholder="ex : Soirée Intégration" autocomplete="off" required>
                <p class="field-help">Doit correspondre EXACTEMENT au libellé SumUp utilisé pour vendre.</p>
            </div>

            <div class="field-row">
                <div class="field">
                    <label for="date_from">Date</label>
                    <input type="date" id="date_from" name="date_from" value="<?= e(date('Y-m-d')) ?>" required>
                </div>
                <div class="field">
                    <label for="date_to">Jusqu'au <span class="muted">(optionnel)</span></label>
                    <input type="date" id="date_to" name="date_to">
                    <p class="field-help">Défaut : même jour.</p>
                </div>
            </div>

            <div class="field">
                <label for="notes">Notes <span class="muted">(optionnel)</span></label>
                <input type="text" id="notes" name="notes" placeholder="ex : édition 2026, prévoir la sonorisation">
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Créer l'événement</button>
                <button type="button" class="btn btn-ghost" onclick="if (confirm('Effacer la saisie en cours ?')) this.form.reset();">Annuler</button>
            </div>
        </form>
    </section>

    <section class="card surface glass" id="ajouter-cout">
        <h2 class="card-title">Ajouter un coût</h2>
        <form method="post" action="<?= e(url('/admin/compta/evenements/couts/save')) ?>">
            <?= csrf_field() ?>

            <div class="field">
                <label for="cost_event">Événement</label>
                <select id="cost_event" name="event_id" required>
                    <?php foreach ($allEvents as $ev): ?>
                        <option value="<?= e((string) $ev['id']) ?>">
                            <?= e((string) $ev['name']) ?> — <?= e(formatDate((string) $ev['date_from'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="field-help">Ex : l'achat de fromage pour la soirée raclette.</p>
            </div>

            <div class="field">
                <label for="cost_date">Date</label>
                <input type="date" id="cost_date" name="spent_at" value="<?= e(date('Y-m-d')) ?>" required>
            </div>

            <div class="field-row">
                <div class="field">
                    <label for="cost_label">Libellé</label>
                    <input type="text" id="cost_label" name="label" placeholder="ex : Fromage" required>
                </div>
                <div class="field">
                    <label for="cost_amount">Montant TTC (€)</label>
                    <input type="text" id="cost_amount" name="amount" placeholder="ex: 25,90" inputmode="decimal" required>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Enregistrer le coût</button>
                <button type="button" class="btn btn-ghost" onclick="if (confirm('Effacer la saisie en cours ?')) this.form.reset();">Annuler</button>
            </div>
        </form>
    </section>
</div>

<details class="card surface glass howto">
    <summary>💡 Comment ça marche ?</summary>
    <div>
        <p>📌 Le <strong>nom de l'événement = bouton SumUp</strong> : les ventes importées en CSV portant ce libellé se rattachent toutes seules.</p>
        <p>📌 Les coûts saisis créent des <strong>dépenses « Événements »</strong> : les <a href="<?= e(url('/admin/compta/budgets')) ?>">budgets</a> et le <a href="<?= e(url('/admin/compta/depenses')) ?>">résultat net</a> restent à jour.</p>
        <p>📌 Si aucune vente ne se rattache, vérifie l'orthographe du nom ou passe par le <a href="<?= e(url('/admin/compta/aliases')) ?>">Mapping libellés</a>. Clique sur le nom d'un événement pour son <strong>détail complet</strong> (ventes rattachées, seuil de rentabilité).</p>
    </div>
</details>

<div class="card surface glass table-wrap">
    <h2 class="card-title">Événements de la période</h2>
    <table class="table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Événement</th>
                <th class="th-num">Entrées</th>
                <th class="th-num">Revenu</th>
                <th class="th-num">Coûts</th>
                <th class="th-num">Bénéfice</th>
                <th class="th-num">Marge</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($events as $ev): ?>
                <?php
                    $ca = (float) $ev['ca'];
                    $qty = (int) $ev['qty'];
                    $costs = (float) $ev['costs'];
                    $profit = (float) $ev['profit'];
                    $margin = $ca > 0.0 ? round($profit / $ca * 100.0, 1) : null;
                ?>
                <tr>
                    <td>
                        <?= e(formatDate((string) $ev['date_from'])) ?>
                        <?php if ((string) $ev['date_to'] !== (string) $ev['date_from']): ?>
                            → <?= e(formatDate((string) $ev['date_to'])) ?>
                        <?php endif; ?>
                    </td>
                    <td><strong><a href="<?= e(url('/admin/compta/evenements/' . rawurlencode((string) $ev['id']))) ?>"><?= e((string) $ev['name']) ?></a></strong></td>
                    <td class="num">
                        <?php if ($qty > 0): ?>
                            <?= $qty ?>
                        <?php else: ?>
                            —<br><span class="badge badge-muted">En attente de ventes</span>
                        <?php endif; ?>
                    </td>
                    <td class="num"><?= e(formatPrice($ca)) ?></td>
                    <td class="num"><?= e(formatPrice($costs)) ?></td>
                    <td class="num <?= $profit >= 0 ? 'is-positive' : 'is-negative' ?>">
                        <strong><?= e(formatPrice($profit)) ?></strong>
                        <?php if ($ca > 0): ?>
                            <span class="badge <?= $profit >= 0 ? 'badge-success' : 'badge-danger' ?>"><?= $profit >= 0 ? 'Rentable' : 'Déficitaire' ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="num"><?= $margin !== null ? e(number_format($margin, 1, ',', ' ')) . ' %' : '—' ?></td>
                    <td class="row-actions">
                        <button type="button" class="btn btn-outline btn-sm" title="Ajouter un coût à cet événement"
                                data-pick-event="<?= e((string) $ev['id']) ?>">＋ Coût</button>
                        <form method="post" action="<?= e(url('/admin/compta/evenements/' . rawurlencode((string) $ev['id']) . '/delete')) ?>"
                              data-confirm="Supprimer cet événement, ses coûts et ses dépenses liées ?">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-danger btn-sm" aria-label="Supprimer">🗑</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($events === []): ?>
                <tr><td colspan="8" class="muted">Aucun événement sur la période.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($recentCosts !== []): ?>
<div class="card surface glass table-wrap">
    <h2 class="card-title">Derniers coûts saisis</h2>
    <table class="table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Événement</th>
                <th>Libellé</th>
                <th class="th-num">Montant</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recentCosts as $c): ?>
                <tr>
                    <td><?= e(formatDate((string) $c['spent_at'])) ?></td>
                    <td><strong><?= e((string) $c['event_name']) ?></strong></td>
                    <td><?= e((string) $c['label']) ?></td>
                    <td class="num"><?= e(formatPrice((float) $c['amount_ttc'])) ?></td>
                    <td class="row-actions">
                        <form method="post" action="<?= e(url('/admin/compta/evenements/' . rawurlencode((string) $c['event_id']) . '/couts/' . rawurlencode((string) $c['id']) . '/delete')) ?>"
                              data-confirm="Supprimer ce coût (et sa dépense liée) ?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="back" value="list">
                            <button type="submit" class="btn btn-danger btn-sm" aria-label="Supprimer">🗑</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script>
(function () {
    // Boutons « ＋ Coût » du tableau : pré-sélectionnent l'événement dans
    // le formulaire, y défilent et focus sur le libellé.
    var costSelect = document.getElementById('cost_event');
    var buttons = document.querySelectorAll('[data-pick-event]');
    if (buttons.length === 0) return;

    Array.prototype.forEach.call(buttons, function (btn) {
        btn.addEventListener('click', function () {
            if (costSelect && btn.getAttribute('data-pick-event')) {
                costSelect.value = btn.getAttribute('data-pick-event');
            }
            var card = document.getElementById('ajouter-cout');
            if (card && card.scrollIntoView) {
                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            var label = document.getElementById('cost_label');
            if (label) label.focus();
        });
    });
})();
</script>
