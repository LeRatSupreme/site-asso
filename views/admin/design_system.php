<?php

declare(strict_types=1);

/**
 * Design system — page de démonstration des composants.
 *
 * Document HTML autonome (rendu sans layout via Controller::renderBare) :
 * styles tokens.css + components.css, interactions design-system.js et
 * confirm.js. Un <h2> par composant, exemples factuels, aucun style en
 * ligne, aucune couleur hors tokens.
 */
?>
<!doctype html>
<html lang="fr" class="ds">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Design system — AEIC</title>
    <link rel="stylesheet" href="<?= e(assetVersioned('css/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= e(assetVersioned('css/components.css')) ?>">
    <script src="<?= e(rootAssetVersioned('/assets/js/confirm.js')) ?>" defer></script>
    <script src="<?= e(rootAssetVersioned('/assets/js/design-system.js')) ?>" defer></script>
</head>
<body>
<div class="ds-page">

    <h1>Design system</h1>
    <p>Bibliothèque de composants de l'interface d'administration. Chaque
    composant est présenté avec un exemple : les couleurs et tailles
    proviennent de tokens.css, les classes de components.css, les
    interactions de design-system.js.</p>

    <section>
        <h2>Fil d'Ariane</h2>
        <nav class="ds-breadcrumb" aria-label="Fil d'Ariane">
            <ol>
                <li><a href="<?= e(url('admin')) ?>">Administration</a></li>
                <li><span aria-current="page">Design system</span></li>
            </ol>
        </nav>
    </section>

    <section>
        <h2>Carte KPI</h2>
        <div class="ds-grid">
            <div class="kpi kpi--ok">
                <span class="kpi-label">Argent encaissé</span>
                <span class="kpi-value num"><?= e(formatPrice(1234.56)) ?></span>
                <span class="kpi-hint">du 1er au 21 octobre — vs <?= e(formatPrice(1120.00)) ?> sur la même période en septembre</span>
            </div>
            <div class="kpi">
                <span class="kpi-label">Ventes du jour</span>
                <span class="kpi-value num"><?= e(formatPrice(86.40)) ?></span>
                <span class="kpi-hint">21 octobre, toutes catégories</span>
            </div>
            <div class="kpi kpi--warn">
                <span class="kpi-label">Pertes déclarées</span>
                <span class="kpi-value num"><?= e(formatPrice(42.00)) ?></span>
                <span class="kpi-hint">3 déclarations ce mois-ci</span>
            </div>
            <div class="kpi kpi--ko">
                <span class="kpi-label">Écart de caisse</span>
                <span class="kpi-value num"><?= e(formatPrice(15.30)) ?></span>
                <span class="kpi-hint">comptage du 20 octobre</span>
            </div>
        </div>
    </section>

    <section>
        <h2>Badge d'état</h2>
        <p>
            <span class="badge badge--ok">Payé</span>
            <span class="badge badge--warn">À surveiller</span>
            <span class="badge badge--ko">Retard</span>
            <span class="badge badge--neutral">Brouillon</span>
            <span class="badge badge--accent">Nouveau</span>
        </p>
    </section>

    <section>
        <h2>Bandeau d'alerte</h2>
        <div class="alert alert--ok" role="status">Import SumUp terminé : 128 ventes importées ce matin.</div>
        <div class="alert alert--info" role="status">Le rapport hebdomadaire est disponible dans les analyses.</div>
        <div class="alert alert--warn" role="alert">Import SumUp en retard : dernier import il y a 16 jours.</div>
        <div class="alert alert--ko" role="alert">Écart de caisse de <?= e(formatPrice(15.30)) ?> détecté lors du comptage du 20 octobre.</div>
    </section>

    <section>
        <h2>Boutons</h2>
        <div class="ds-toolbar">
            <button type="button" class="btn btn--primary">Enregistrer</button>
            <button type="button" class="btn btn--secondary">Exporter CSV</button>
            <button type="button" class="btn btn--ghost">Annuler</button>
            <button type="button" class="btn btn--danger">Supprimer</button>
            <button type="button" class="btn btn--secondary btn--sm">Petite action</button>
            <button type="button" class="btn btn--primary" disabled>Indisponible</button>
            <a class="btn btn--danger" href="#fenetre-de-confirmation"
               data-confirm="Action irréversible : cette suppression ne peut pas être annulée.">Action irréversible</a>
        </div>
        <p class="field__hint">Le bouton « Action irréversible » ouvre la fenêtre de confirmation
        gérée par confirm.js (attribut data-confirm).</p>
    </section>

    <section>
        <h2>Formulaire</h2>
        <form class="ds-form" action="#" method="get">
            <div class="field">
                <label class="field__label" for="ds-montant">Montant</label>
                <input type="text" id="ds-montant" name="montant" inputmode="decimal" placeholder="12,50">
                <span class="field__hint">format 12,50</span>
            </div>
            <div class="field">
                <label class="field__label" for="ds-date">Date</label>
                <input type="date" id="ds-date" name="date">
            </div>
            <div class="field">
                <label class="field__label" for="ds-categorie">Catégorie</label>
                <select id="ds-categorie" name="categorie">
                    <option value="boissons">Boissons</option>
                    <option value="restauration">Restauration</option>
                    <option value="snack">Snack</option>
                </select>
            </div>
            <div class="field">
                <label class="field__label">
                    <input type="checkbox" name="alerte_stock" value="1">
                    Alerte de stock activée
                </label>
                <span class="field__hint">Un message est affiché quand le stock atteint zéro.</span>
            </div>
            <div class="field">
                <button type="submit" class="btn btn--primary">Enregistrer</button>
            </div>
        </form>
    </section>

    <section>
        <h2>Onglets</h2>
        <div data-tabs>
            <div class="ds-tabs" role="tablist" aria-label="Exemple d'onglets">
                <button type="button" role="tab" id="ds-tab-semaine" aria-controls="ds-panel-semaine" aria-selected="true">Semaine</button>
                <button type="button" role="tab" id="ds-tab-mois" aria-controls="ds-panel-mois" aria-selected="false">Mois</button>
                <button type="button" role="tab" id="ds-tab-annee" aria-controls="ds-panel-annee" aria-selected="false">Année</button>
            </div>
            <div role="tabpanel" id="ds-panel-semaine" aria-labelledby="ds-tab-semaine" tabindex="0">
                <p>Ventes des sept derniers jours : <?= e(formatPrice(486.20)) ?>, en hausse par rapport à la semaine précédente.</p>
            </div>
            <div role="tabpanel" id="ds-panel-mois" aria-labelledby="ds-tab-mois" tabindex="0">
                <p>Ventes du mois en cours : <?= e(formatPrice(1832.75)) ?>, toutes catégories confondues.</p>
            </div>
            <div role="tabpanel" id="ds-panel-annee" aria-labelledby="ds-tab-annee" tabindex="0">
                <p>Bilan de l'année : consultez le détail mois par mois dans le bilan annuel.</p>
            </div>
        </div>
    </section>

    <section>
        <h2>Menu déroulant</h2>
        <div class="ds-menu">
            <button type="button" class="btn btn--secondary ds-menu-trigger" aria-haspopup="true" aria-expanded="false">Actions</button>
            <ul class="ds-menu-list">
                <li><button type="button" class="ds-menu-item">Marquer comme traitée</button></li>
                <li><button type="button" class="ds-menu-item">Dupliquer</button></li>
                <li><a class="ds-menu-item" href="#tableau">Voir le détail</a></li>
            </ul>
        </div>
    </section>

    <section>
        <h2>Tableau</h2>
        <div class="ds-toolbar">
            <input type="text" data-table-filter="#table-ventes" aria-label="Filtrer le tableau" placeholder="Filtrer (ex. boissons)">
            <input type="search" data-table-search="#table-ventes" aria-label="Rechercher dans le tableau" placeholder="Rechercher…">
            <button type="button" class="btn btn--secondary btn--sm">Exporter CSV</button>
        </div>
        <div class="table-wrap table-wrap--sticky">
            <table class="ds-table" id="table-ventes" data-mobile-cards>
                <thead>
                    <tr>
                        <th scope="col" aria-sort="none">Référence</th>
                        <th scope="col" aria-sort="none">Date</th>
                        <th scope="col" aria-sort="none">Produit</th>
                        <th scope="col" aria-sort="none">Catégorie</th>
                        <th scope="col" class="num" aria-sort="none">Quantité</th>
                        <th scope="col" class="num" aria-sort="none">Montant</th>
                        <th scope="col" aria-sort="none">Paiement</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td data-label="Référence">V-1026</td>
                        <td data-label="Date">21/10/2026</td>
                        <td data-label="Produit">Coca 33 cl</td>
                        <td data-label="Catégorie">Boissons</td>
                        <td class="num" data-label="Quantité">2</td>
                        <td class="num" data-label="Montant"><?= e(formatPrice(3.00)) ?></td>
                        <td data-label="Paiement"><span class="badge badge--ok">Payé</span></td>
                    </tr>
                    <tr>
                        <td data-label="Référence">V-1025</td>
                        <td data-label="Date">21/10/2026</td>
                        <td data-label="Produit">Sandwich jambon-beurre</td>
                        <td data-label="Catégorie">Restauration</td>
                        <td class="num" data-label="Quantité">1</td>
                        <td class="num" data-label="Montant"><?= e(formatPrice(3.50)) ?></td>
                        <td data-label="Paiement"><span class="badge badge--ok">Payé</span></td>
                    </tr>
                    <tr>
                        <td data-label="Référence">V-1024</td>
                        <td data-label="Date">20/10/2026</td>
                        <td data-label="Produit">Eau 50 cl</td>
                        <td data-label="Catégorie">Boissons</td>
                        <td class="num" data-label="Quantité">4</td>
                        <td class="num" data-label="Montant"><?= e(formatPrice(4.00)) ?></td>
                        <td data-label="Paiement"><span class="badge badge--ok">Payé</span></td>
                    </tr>
                    <tr>
                        <td data-label="Référence">V-1023</td>
                        <td data-label="Date">20/10/2026</td>
                        <td data-label="Produit">Cookie</td>
                        <td data-label="Catégorie">Snack</td>
                        <td class="num" data-label="Quantité">3</td>
                        <td class="num" data-label="Montant"><?= e(formatPrice(4.50)) ?></td>
                        <td data-label="Paiement"><span class="badge badge--warn">À surveiller</span></td>
                    </tr>
                    <tr>
                        <td data-label="Référence">V-1022</td>
                        <td data-label="Date">19/10/2026</td>
                        <td data-label="Produit">Café expresso</td>
                        <td data-label="Catégorie">Boissons</td>
                        <td class="num" data-label="Quantité">5</td>
                        <td class="num" data-label="Montant"><?= e(formatPrice(7.50)) ?></td>
                        <td data-label="Paiement"><span class="badge badge--ok">Payé</span></td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4" class="total" data-label="Total">Total (5 ventes)</td>
                        <td class="num" data-label="Quantité">15</td>
                        <td class="num" data-label="Montant"><?= e(formatPrice(22.50)) ?></td>
                        <td data-label="Paiement"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>

    <section>
        <h2>Ligne extensible</h2>
        <div class="table-wrap">
            <table class="ds-table" id="table-vente-detail" data-mobile-cards>
                <thead>
                    <tr>
                        <th scope="col">Vente</th>
                        <th scope="col">Date</th>
                        <th scope="col" class="num">Total</th>
                        <th scope="col"><span class="field__hint">Détail</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td data-label="Vente">V-1027</td>
                        <td data-label="Date">21/10/2026</td>
                        <td class="num" data-label="Total"><?= e(formatPrice(9.00)) ?></td>
                        <td data-label="Détail">
                            <button type="button" class="ds-expand" aria-expanded="false">Afficher le détail</button>
                        </td>
                    </tr>
                    <tr class="ds-row-detail">
                        <td colspan="4">
                            <strong>Contenu de la vente V-1027</strong>
                            <ul>
                                <li>Sandwich jambon-beurre — 1 × <?= e(formatPrice(3.50)) ?></li>
                                <li>Coca 33 cl — 2 × <?= e(formatPrice(1.50)) ?></li>
                                <li>Cookie — 1 × <?= e(formatPrice(2.50)) ?></li>
                            </ul>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <section>
        <h2>Info-bulle « ? »</h2>
        <p>
            <strong>Argent encaissé</strong>
            <button type="button" class="ds-help" aria-label="Aide : argent encaissé">?<span class="ds-help-tip" role="tooltip">Somme réellement reçue sur le compte, une fois les frais de paiement déduits.</span></button>
            : le montant qui arrive vraiment sur le compte bancaire.
        </p>
        <p>
            <strong>Ce qu'on gagne sur les ventes</strong>
            <button type="button" class="ds-help" aria-label="Aide : ce qu'on gagne sur les ventes">?<span class="ds-help-tip" role="tooltip">Différence entre le prix de vente et le coût d'achat des produits vendus, avant les autres dépenses.</span></button>
            : la marge réalisée sur les produits vendus.
        </p>
    </section>

    <section>
        <h2>État vide</h2>
        <div class="ds-empty">
            <p class="ds-empty-title">Pas de données pour cette période.</p>
            <p>Modifiez la période ou importez un rapport pour voir les ventes.</p>
            <button type="button" class="btn btn--ghost">Réinitialiser les filtres</button>
        </div>
    </section>

    <section>
        <h2>Squelette de chargement</h2>
        <div class="card">
            <div class="ds-skeleton"></div>
            <div class="ds-skeleton"></div>
            <div class="ds-skeleton"></div>
        </div>
    </section>

    <section id="fenetre-de-confirmation">
        <h2>Fenêtre de confirmation</h2>
        <p>Composant <code>.ds-dialog</code> (élément &lt;dialog&gt;). En production,
        la fenêtre est ouverte par confirm.js quand un élément porte data-confirm ;
        l'exemple ci-dessous est affiché statiquement pour montrer le style.</p>
        <dialog class="ds-dialog" open>
            <p><strong>Supprimer la vente V-1027 ?</strong></p>
            <p>Cette action est irréversible : la ligne sera définitivement retirée du journal.</p>
            <div class="ds-toolbar">
                <button type="button" class="btn btn--ghost">Annuler</button>
                <button type="button" class="btn btn--danger">Supprimer</button>
            </div>
        </dialog>
    </section>

    <footer class="ds-footer">
        <p>Page de démonstration — composants du design system AEIC.
        Documentation : docs/design-system.md</p>
    </footer>

</div>
</body>
</html>
