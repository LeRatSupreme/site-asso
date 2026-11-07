<?php

declare(strict_types=1);

namespace App\Core\Compta\Forecast;

/**
 * Hyperparamètres FIXES du modèle (documentés, jamais optimisés sur
 * ~4 semaines de données — choix de mission).
 */
final class Hyper
{
    /** Fuseau de référence de l'asso. */
    public const TZ = 'Europe/Paris';

    /** Rétrécissement partial pooling (jour de semaine + horaire). */
    public const K_POOL = 2.0;

    /** Lissage exponentiel du niveau (alpha SES). */
    public const ALPHA_SES = 0.3;

    /** Amortissement de la tendance (phi). Beta implicite = 1 - phi. */
    public const PHI_DAMP = 0.9;

    /**
     * Jours ouverts minimaux pour ACTIVER la tendance : en dessous, b est
     * forcé à 0 à chaque pas (SES niveau seul). À ~9 jours ouverts, une
     * tendance estimée est du PUR BRUIT (le dump prod montrait −5,26 %/jour
     * appris sur du bruit alterné) ; l'erreur est asymétrique — sous-estimer
     * coûte quelques euros de préparation, sur-estimer fait jeter — et le
     * SES niveau seul est le choix classique à faible effectif.
     */
    public const TREND_MIN_OPEN_DAYS = 30;

    /**
     * Plafond de la tendance active (échelle log ≈ ±%/jour) : b est clampé
     * à [−TREND_CAP_DAILY ; +TREND_CAP_DAILY] après chaque mise à jour.
     * ±1 %/jour laisse passer toute croissance/déclin réaliste de la
     * cafétéria tout en bornant l'extrapolation (converge vers b/(1−φ)).
     */
    public const TREND_CAP_DAILY = 0.01;

    /** Prior Gamma(a, a) de la mise à jour horaire (moyenne 1, écart-type ~35 %). */
    public const A_PRIOR = 8.0;

    /** Seuil du z robuste (MAD) pour déclarer un jour atypique. */
    public const Z_ATYPICAL = 3.5;

    /** Poids d'un jour atypique (les autres pèsent 1.0). */
    public const W_ATYPICAL = 0.3;

    /** Part tronquée de chaque côté pour la moyenne du panier (10 %). */
    public const TRIM = 0.10;

    /** Part horaire cumulée minimale pour calculer un écart (« trop tôt »). */
    public const S_MIN = 0.10;

    /** Nombre minimal de résidus de backtest pour la calibration conforme. */
    public const CONFORMAL_MIN = 15;

    /** Tirages Monte Carlo pour la loi prédictive. */
    public const N_DRAWS = 5000;

    /** Jours ouverts minimaux d'historique pour afficher un % d'écart. */
    public const INSUFFICIENT_DAYS = 7;

    /** Occurrences minimales d'un jour de semaine (sinon moyenne globale + confiance faible). */
    public const RARE_DOW = 3;

    /** Le backtest commence au 10e jour ouvert. */
    public const BACKTEST_START = 10;

    /**
     * Borne du backtest à origine glissante : 90 derniers jours ouverts
     * max (budget temps — l'historique complet est tronqué à cette
     * borne, la fenêtre d'apprentissage du modèle reste inchangée).
     */
    public const BACKTEST_MAX_DAYS = 90;

    /**
     * Plafond des tirages Monte Carlo du BACKTEST (défaut moteur :
     * min(draws, 2000)) : la couverture 80 % reste valide à 2000 tirages
     * et le budget temps reste borné sur un historique long.
     */
    public const BACKTEST_DRAWS_MAX = 2000;

    /** Borne inférieure de la dispersion NegBin (surdispersion maximale admise). */
    public const R_MIN = 2.0;

    /** Borne supérieure de la dispersion NegBin (≈ Poisson). */
    public const R_MAX = 200.0;

    /** Borne inférieure du facteur de largeur conforme. */
    public const CONFORMAL_F_MIN = 0.5;

    /** Borne supérieure du facteur de largeur conforme. */
    public const CONFORMAL_F_MAX = 3.0;

    /** Objectif hebdomadaire par défaut (€) en l'absence de paramétrage. */
    public const WEEKLY_GOAL_DEFAULT = 500.0;
}
