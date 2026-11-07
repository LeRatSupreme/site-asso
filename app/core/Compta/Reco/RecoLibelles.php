<?php

declare(strict_types=1);

namespace App\Core\Compta\Reco;

/**
 * Libellés HUMAINS des règles du moteur de recommandations (source unique
 * pour toutes les vues : carte analytics, kiosque, historique).
 *
 * RÈGLE DE MISSION : JAMAIS de code de règle (R1, R4, R7…) visible dans
 * l'interface. Chaque code est traduit par un intitulé décrivant ce que
 * fait réellement la règle (cf. docblocks des classes du dossier) ; tout
 * code inconnu retombe sur un libellé générique neutre — jamais le code brut.
 */
final class RecoLibelles
{
    /**
     * Libellés par code de règle (R1..R11), alignés sur le comportement
     * réel de chaque classe du cœur :
     *  - R1  : offre de fin de semaine pour écouler un stock dormant ;
     *  - R2  : réapprovisionnement avant rupture / stock critique ;
     *  - R3  : marge faible sur un produit populaire (micro-optimisation
     *          tarifaire, jamais émise automatiquement) ;
     *  - R4  : mise en avant d'un produit à forte marge sous-vendu ;
     *  - R5  : promotion d'un duo de produits complémentaires ;
     *  - R6  : seuil cadeau pour compléter les paniers à 1 article ;
     *  - R7  : prix réduit sur la fenêtre creuse de la journée ;
     *  - R8  : produit fin de gamme au stock immobilisé ;
     *  - R9  : demande en forte hausse ou forte baisse vs mois précédent ;
     *  - R10 : objectif hebdomadaire en risque (urgence assemblée) ;
     *  - R11 : coûts, doublons ou stocks à corriger (qualité des données).
     */
    private const REGLES = [
        'R1'  => 'Stock à écouler',
        'R2'  => 'Rupture imminente',
        'R3'  => 'Marge à améliorer',
        'R4'  => 'Forte marge peu vendue',
        'R5'  => 'Duo de produits',
        'R6'  => 'Paniers à compléter',
        'R7'  => 'Heure creuse',
        'R8'  => 'Fin de gamme',
        'R9'  => 'Demande en forte variation',
        'R10' => 'Objectif de la semaine',
        'R11' => 'Données à compléter',
    ];

    /** Libellé générique neutre pour un code inconnu (jamais le code brut). */
    private const GENERIQUE = 'Suggestion';

    /**
     * Libellé humain d'un code de règle : intitulé dédié pour R1..R11,
     * libellé générique sinon (code vide, inconnu ou futur).
     */
    public static function regle(string $code): string
    {
        return self::REGLES[$code] ?? self::GENERIQUE;
    }
}
