<?php

declare(strict_types=1);

namespace App\Core\Compta\Reco;

/**
 * Produit tel que vu par le moteur de recommandations : agrégat PUR
 * (aucun accès DB) alimenté par l'appelant — ventes 30 j, prix/coût
 * unitaires, stock géré et prévision 7 j du moteur Forecast.
 *
 * coutRaison explique pourquoi le coût n'est PAS utilisable pour une
 * règle tarifaire :
 *  - 'cout_inconnu'           : aucun coût d'achat connu ;
 *  - 'cout_zero'              : coût connu mais nul (donnée suspecte) ;
 *  - 'prix_inferieur_au_cout' : prix ≤ coût (donnée suspecte).
 * null = coût utilisable, la marge est calculable (garde-fou 2).
 */
final class RecoProduct
{
    public readonly string $key;

    public readonly string $label;

    public readonly string $category;

    /** Unités vendues sur les 30 derniers jours. */
    public readonly float $qty30;

    /** CA (€) sur les 30 derniers jours. */
    public readonly float $ca30;

    /** Prix unitaire de vente (€), null si inconnu. */
    public readonly ?float $prix;

    /** Coût unitaire d'achat (€), null si inconnu. */
    public readonly ?float $cout;

    /** Raison du coût suspect (cf. docblock de classe), null si utilisable. */
    public readonly ?string $coutRaison;

    /** Stock géré (unités), null = produit non géré en stock. */
    public readonly ?int $stock;

    /** Prévision 7 j du moteur Forecast (unités) + intervalle 80 %. */
    public readonly float $unitesPrevues7j;

    public readonly float $prevLo;

    public readonly float $prevHi;

    /** Catégorie vide → « Non classé ». */
    public function __construct(
        string $key,
        string $label,
        string $category,
        float $qty30 = 0.0,
        float $ca30 = 0.0,
        ?float $prix = null,
        ?float $cout = null,
        ?string $coutRaison = null,
        ?int $stock = null,
        float $unitesPrevues7j = 0.0,
        float $prevLo = 0.0,
        float $prevHi = 0.0
    ) {
        $this->key = $key;
        $this->label = $label;
        $this->category = $category === '' ? 'Non classé' : $category;
        $this->qty30 = $qty30;
        $this->ca30 = $ca30;
        $this->prix = $prix;
        $this->cout = $cout;
        $this->coutRaison = $coutRaison;
        $this->stock = $stock;
        $this->unitesPrevues7j = $unitesPrevues7j;
        $this->prevLo = $prevLo;
        $this->prevHi = $prevHi;
    }

    /** Coût utilisable pour une règle tarifaire (coût connu > 0 et prix > coût). */
    public function coutUtilisable(): bool
    {
        return $this->cout !== null && $this->cout > 0.0 && $this->prix !== null && $this->prix > $this->cout;
    }

    /** Marge unitaire (€) = prix − coût ; null si coût non utilisable. */
    public function margeUnitaire(): ?float
    {
        return $this->coutUtilisable() ? $this->prix - $this->cout : null;
    }

    /** Taux de marge = (prix − coût) / prix ; null si coût non utilisable. */
    public function tauxMarge(): ?float
    {
        return $this->coutUtilisable() ? ($this->prix - $this->cout) / $this->prix : null;
    }
}
