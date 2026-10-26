<?php

declare(strict_types=1);

/**
 * Trésorerie — « Où est l'argent ? » : trois blocs + un total, pour le
 * trésorier (traçabilité de l'argent de l'association).
 *
 * Chaque bloc est STRICTEMENT la valeur de sa source unique (contrôleur
 * AdminComptaController::tresorerie) — cette vue n'effectue AUCUN calcul,
 * elle affiche les montants reçus tels quels :
 *   caisse : CashLedger::balance()                 (liquide en caisse) ;
 *   banque : Σ dépôts − frais bancaires de dépôt   (dépôts effectués) ;
 *   stock  : AnalyticsForecastData::
 *            stockValueAtLastCost(stockBook())     (stock valorisé au
 *            dernier coût connu — produits sans coût connus exclus).
 *
 * @var array<string,mixed> $user
 * @var float $caisse       Solde théorique de la caisse (CashLedger::balance).
 * @var float $banque       Dépôts − frais (net crédité en banque).
 * @var float $banqueDepots Total des dépôts effectués (montants déposés).
 * @var float $banqueFrais  Total des frais bancaires prélevés sur les dépôts.
 * @var float $stock        Valeur du stock restant au dernier coût connu.
 * @var float $total        Somme d'affichage : caisse + banque + stock.
 */
?>
<style>
    /* Bloc « Total » : même matériau que les KPI compta, mise en avant
       pleine largeur (styles locaux préfixés, aucun impact ailleurs). */
    .treso-total {
        border-left: none;
        text-align: center;
        background: linear-gradient(160deg, rgba(72, 189, 211, 0.14), rgba(97, 80, 170, 0.07));
        border-color: rgba(72, 189, 211, 0.35);
    }
    .treso-total .kpi-value { color: var(--primary, #48bdd3); }
</style>

<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité</p>
        <h1 class="page-title">Où est l'argent&nbsp;?</h1>
        <p class="muted">Tout ce que possède l'association, réparti en trois endroits — photo arrêtée à l'instant.</p>
    </div>
</div>

<div class="compta-kpis">
    <div class="card surface glass kpi" data-bloc="caisse">
        <p class="kpi-label">Caisse</p>
        <p class="kpi-value <?= $caisse >= 0 ? '' : 'is-negative' ?>"><?= e(formatPrice($caisse)) ?></p>
        <p class="kpi-sub">caisse physique — liquide théoriquement présent (ventes en liquide + fond de caisse − dépôts, réaligné à chaque comptage)</p>
    </div>

    <div class="card surface glass kpi" data-bloc="banque">
        <p class="kpi-label">Banque</p>
        <p class="kpi-value <?= $banque >= 0 ? '' : 'is-negative' ?>"><?= e(formatPrice($banque)) ?></p>
        <p class="kpi-sub">dépôts effectués − frais&nbsp;: <?= e(formatPrice($banqueDepots)) ?> déposés, moins <?= e(formatPrice($banqueFrais)) ?> de frais bancaires prélevés par dépôt</p>
    </div>

    <div class="card surface glass kpi" data-bloc="stock">
        <p class="kpi-label">Stock valorisé</p>
        <p class="kpi-value"><?= e(formatPrice($stock)) ?></p>
        <p class="kpi-sub">stock au dernier coût connu — marchandises encore en rayon&nbsp;; produits sans coût connu exclus de la valorisation</p>
    </div>
</div>

<div class="card surface glass kpi treso-total" data-bloc="total">
    <p class="kpi-label">Total</p>
    <p class="kpi-value"><?= e(formatPrice($total)) ?></p>
    <p class="kpi-sub">caisse + banque + stock valorisés, au même endroit</p>
</div>

<details class="card surface glass" style="padding: 0.85rem 1.1rem; margin-top: 1.5rem;">
    <summary style="cursor: pointer; font-weight: 800;">D'où viennent ces chiffres&nbsp;?</summary>
    <ul class="muted" style="margin: 0.7rem 0 0.2rem; padding-left: 1.1rem; line-height: 1.7;">
        <li><strong>Caisse</strong>&nbsp;: solde théorique du livre de caisse (ventes en liquide + fond de caisse − dépôts ± ajustements de comptage).</li>
        <li><strong>Banque</strong>&nbsp;: chaque dépôt saisi sort de la caisse et crédit la banque, moins les frais bancaires prélevés par dépôt (comptabilisés automatiquement en dépense « FRAIS »).</li>
        <li><strong>Stock valorisé</strong>&nbsp;: chaque produit restant × son dernier coût d'achat connu. Les produits sans coût connu sont exclus (aucune valeur inventée)&nbsp;; les ventes par carte (SumUp) ne sont ni en caisse ni en banque ici.</li>
    </ul>
</details>
