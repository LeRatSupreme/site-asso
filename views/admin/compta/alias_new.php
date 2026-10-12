<?php

declare(strict_types=1);

/**
 * Page dédiée « Ajouter une appellation » : le formulaire seul —
 * saisir vite le lien libellé facture/CSV -> nom court, sans le
 * bruit de la liste complète (consultable et éditable dans la page
 * Mapping des libellés).
 *
 * @var list<string> $categories
 * @var list<array<string,mixed>> $pending
 */
?>
<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité</p>
        <h1 class="page-title">Ajouter une appellation</h1>
    </div>
</div>

<div class="compta-grid">
    <section class="card surface glass">
        <h2 class="card-title">Nouvelle appellation</h2>
        <p class="muted">Retient une fois pour toutes le lien entre un <strong>libellé long</strong> et le <strong>nom court</strong> utilisé par l'asso : « MONSTER PIPELINE PUNCH » → « Monster rose ». Les appellations servent aux imports CSV SumUp <em>et</em> aux factures scannées (METRO…) : au prochain scan du même libellé, le nom court est proposé directement. Les corrections faites à l'enregistrement d'achats scannés sont aussi retenues ici automatiquement.</p>

        <form method="post" action="<?= e(url('/admin/compta/aliases/save')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="stay" value="1">

            <div class="field">
                <label for="raw_description">Libellé d'origine <span class="muted">(facture / CSV)</span></label>
                <input type="text" id="raw_description" name="raw_description"
                       placeholder="ex : MONSTER PIPELINE PUNCH 24X0,5L" required
                       autocomplete="off" autofocus>
                <p class="field-help">Copie-le tel quel depuis la facture (désignation de l'article, colisage compris).</p>
            </div>

            <div class="field">
                <label for="product_key">Nom court <span class="muted">(produit)</span></label>
                <input type="text" id="product_key" name="product_key"
                       placeholder="ex : Monster rose" required autocomplete="off">
                <p class="field-help">Le nom utilisé partout ailleurs (ventes, stock) — un nom existant de préférence.</p>
            </div>

            <div class="field">
                <label for="category">Catégorie <span class="muted">(optionnel)</span></label>
                <input type="text" id="category" name="category" list="alias-categories"
                       placeholder="ex : <?= e($categories[0] ?? 'Boissons') ?>" autocomplete="off">
                <datalist id="alias-categories">
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= e((string) $cat) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Ajouter</button>
                <a class="btn btn-ghost" href="<?= e(url('/admin/compta/aliases')) ?>">Voir toutes les appellations</a>
            </div>
        </form>
    </section>

    <section class="card surface glass">
        <h2 class="card-title">Libellés factures non rattachés</h2>
        <p class="muted">Libellés détectés par les scans de factures (METRO…) mais inconnus du catalogue — ex. « EVIEN 50CL » qui devrait être « Eau ». Rattache-les ici : au prochain scan du même libellé, le nom court sera proposé directement. Les libellés déjà résolus disparaissent de la file tout seuls.</p>
        <?php if (($pending ?? []) === []): ?>
            <p class="muted">Aucun libellé en attente : tous les libellés scannés sont reconnus.</p>
        <?php endif; ?>
        <?php foreach (($pending ?? []) as $row): ?>
            <?php $label = (string) $row['label']; ?>
            <form method="post" action="<?= e(url('/admin/compta/aliases/save')) ?>" class="alias-form">
                <?= csrf_field() ?>
                <input type="hidden" name="stay" value="1">
                <div class="alias-row">
                    <div>
                        <code><?= e($label) ?></code>
                        <span class="muted">(<?= (int) $row['occurrences'] ?> fois)</span>
                    </div>
                    <div>
                        <input type="hidden" name="raw_description" value="<?= e($label) ?>">
                        <input type="text" name="product_key" placeholder="ex : Eau" required autocomplete="off">
                        <input type="text" name="category" placeholder="ex : <?= e($categories[0] ?? 'Catégorie') ?>" list="alias-categories" autocomplete="off">
                        <button type="submit" class="btn btn-primary btn-sm">Rattacher</button>
                    </div>
                </div>
            </form>
        <?php endforeach; ?>
    </section>
</div>
