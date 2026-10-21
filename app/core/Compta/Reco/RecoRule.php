<?php

declare(strict_types=1);

namespace App\Core\Compta\Reco;

/**
 * Contrat commun des règles R1..R11 du moteur de recommandations.
 * Une règle est une classe STATIQUE PURE : aucun accès DB, aucune
 * horloge, aucun aléa — la même entrée produit toujours la même sortie.
 * Le moteur appelle evaluer() sur chaque règle puis valide chaque
 * candidat brut via GuardRails::candidatValide avant scoring.
 */
interface RecoRule
{
    /** Identifiant stable de la règle ('R1'..'R11'), repris dans chaque candidat. */
    public static function regle(): string;

    /**
     * Évalue la règle sur le contexte et produit ses candidats bruts
     * (0..n), à la forme attendue par GuardRails::candidatValide :
     * regle, keys, produits, titre, pourquoi, action, gain (bas/median/
     * haut), confiance, effort, hypotheses, comment_mesurer — et
     * prix_effectifs si des prix sont proposés. Les seuils se lisent
     * via RecoContext::param() avec les constantes Hyper en défaut ;
     * la demande vient TOUJOURS de la prévision
     * (RecoProduct::unitesPrevues7j), jamais recalculée.
     *
     * @return list<array<string,mixed>>
     */
    public static function evaluer(RecoContext $ctx): array;
}
