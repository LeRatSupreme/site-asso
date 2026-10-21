<?php

declare(strict_types=1);

namespace App\Core\Compta\Reco;

/**
 * Hyperparamètres FIXES du moteur de recommandations (documentés, jamais
 * optimisés automatiquement — même choix de mission que Forecast\Hyper).
 * Chaque constante porte son unité et le rôle joué dans les règles R1..R11.
 * Surcharge ponctuelle possible via RecoDataset::parametres (clé
 * « reco_<nom> », voir Hyper::valeur) — jamais en base, jamais dans .env.
 */
final class Hyper
{
    /** Fraction du prix de vente — marge unitaire minimale admise (plancher des prix proposés, garde-fou 1). */
    public const MARGE_MIN = 0.15;

    /** Unités/30 j — ventes minimales pour qu'un produit soit un échantillon exploitable (R1). */
    public const SAMPLE_MIN_UNITES_30J = 20;

    /** Semaines sans vente — ancienneté qualifiant un stock de dormant (R2). */
    public const STOCK_DORMANT_SEMAINES = 8;

    /** Unités — stock minimal pour qualifier un stock de dormant (R2). */
    public const STOCK_DORMANT_MIN = 10;

    /** Fraction du prix — remise minimale proposée sur stock dormant (R2). */
    public const REMISE_DORMANT_BAS = 0.10;

    /** Fraction du prix — remise maximale proposée sur stock dormant (R2). */
    public const REMISE_DORMANT_HAUT = 0.20;

    /** Fraction — part du CA cumulée définissant le « top CA » (R3, ≈ 20 % des produits). */
    public const TOP_CA_PART = 0.20;

    /** Fraction du prix — taux de marge considéré faible (alerte R3). */
    public const MARGE_FAIBLE_SEUIL = 0.25;

    /** Fractions du prix — paliers de hausse testables (R4 : +10 % puis +20 %). */
    public const TEST_PRIX_PALIERS = [0.10, 0.20];

    /** Jours ouverts — durée d'un test de prix (R4). */
    public const TEST_PRIX_JOURS = 14;

    /** Fraction du coût — baisse de coût d'achat recherchée, borne basse (R5). */
    public const COUT_ACHAT_BAS = 0.05;

    /** Fraction du coût — baisse de coût d'achat recherchée, borne haute (R5). */
    public const COUT_ACHAT_HAUT = 0.10;

    /** Fraction — gain de volume espéré d'un test de prix (R4). */
    public const R4_GAIN_VOL = 0.20;

    /** Paniers — taille d'échantillon minimale pour la règle R4. */
    public const R4_SAMPLE_MIN = 8;

    /** Paniers — nombre minimal pour valider une association (R6). */
    public const ASSOC_PANIERS_MIN = 30;

    /** Sans unité — lift minimal d'une association A→B (R6). */
    public const ASSOC_LIFT_MIN = 1.2;

    /** Fraction des paniers — support minimal d'une association (R6). */
    public const ASSOC_SUPPORT_MIN = 0.02;

    /** P(B|A) — confiance minimale d'une association (R6). */
    public const ASSOC_CONF_MIN = 0.05;

    /** Fraction du total duo — remise maximale d'un duo promo (R7). */
    public const DUO_REMISE_MAX = 0.10;

    /** Fraction — part de paniers à 1 article déclenchant l'alerte (R8). */
    public const PANIER_1ARTICLE_SEUIL = 0.85;

    /** Quantile — percentile de montant définissant le « bon panier » (R8). */
    public const PANIER_PERCENTILE = 0.75;

    /** Fraction du panier moyen — coût du cadeau maximal (R9). */
    public const COUT_CADEAU_MAX = 0.15;

    /** Part de la moyenne — demande horaire sous la moyenne qualifiant un creux (R10). */
    public const SEUIL_CREUX_PART = 0.4;

    /** Part de la moyenne — demande horaire au-dessus qualifiant un pic (R10). */
    public const SEUIL_PIC_PART = 1.5;

    /** Unités/7 j — prévision maximale d'un produit « fin de gamme » (R11). */
    public const GAMME_UNITES_MAX = 3;

    /** € — valeur immobilisée minimale pour proposer l'arrêt (R11). */
    public const GAMME_IMMOBILISE_MIN = 30.0;

    /** Fraction — variation relative activant la règle tendance (R9). */
    public const TENDANCE_SEUIL = 0.50;

    /** Unités/30 j — volume minimal pour estimer une tendance fiable (R9). */
    public const TENDANCE_VOL_MIN = 15;

    /** Probabilité — atteinte de l'objectif minimale déclenchant l'urgence (R9). */
    public const PROB_OBJECTIF_MIN = 0.50;

    /** Recommandations — nombre maximal d'actives simultanées (toutes règles). */
    public const MAX_ACTIVES = 5;

    /** Jours — une reco refusée ne revient pas avant ce délai. */
    public const REFUS_JOURS = 30;

    /** Jours ouverts — historique minimal pour publier une reco. */
    public const HIST_MIN_JOURS_OUVERTS = 14;

    /** Jours ouverts — délai après déclenchement avant mesure d'effet. */
    public const EFFET_DECLANCHEMENT_JOURS_OUVERTS = 14;

    /** Jours ouverts — mesure d'effet minimale post-déclenchement. */
    public const EFFET_MIN_JOURS_OUVERTS = 10;

    /** Sans unité — facteurs multipliant le gain médian dans le score. */
    public const CONFIANCE_FACTEURS = ['faible' => 0.5, 'moyenne' => 0.75, 'haute' => 1.0];

    /** Sans unité — effort d'application par règle (diviseur du score, 1 = faible … 3 = élevé). */
    public const EFFORTS = ['R1' => 2, 'R2' => 1, 'R3' => 2, 'R4' => 1, 'R5' => 2, 'R6' => 1, 'R7' => 2, 'R8' => 2, 'R9' => 1, 'R10' => 1, 'R11' => 1];

    /** Recommandations — maximal R11 actives simultanément. */
    public const R11_MAX = 3;

    /** Catégories de produits — exclues de toute recommandation. */
    public const CATEGORIES_EXCLUES = ['Événement', 'Divers'];

    /** Jours — stock de sécurité couvert par une suggestion de réappro. */
    public const SECU_STOCK_JOURS = 3;

    /**
     * Lit un hyperparamètre surchargeable : retourne
     * $parametres['reco_' . $nom] si numérique (int/float/chaîne
     * numérique), sinon $defaut. Toute autre valeur (null, booléen,
     * tableau, chaîne non numérique) retombe sur $defaut.
     *
     * @param array<string,mixed> $parametres
     */
    public static function valeur(array $parametres, string $nom, float|int $defaut): float|int
    {
        $v = $parametres['reco_' . $nom] ?? null;
        if (is_int($v) || is_float($v)) {
            return $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (float) $v;
        }

        return $defaut;
    }
}
