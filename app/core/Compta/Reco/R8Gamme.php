<?php

declare(strict_types=1);

namespace App\Core\Compta\Reco;

/**
 * R8 — Fin de gamme : stock immobilisé sur un produit qui s'écoule
 * lentement.
 *
 * Produits filtrés à coût utilisable, stock géré ≥ 1 et demande
 * désaisonnalisée < « reco_gamme_unites_max » : si la valeur immobilisée
 * (stock × coût) dépasse strictement « reco_gamme_immobilise_seuil_eur »,
 * on propose de réduire ou d'écouler le produit.
 *
 * Gain hebdomadaire (€) : stock × marge unitaire × hypothèse
 * d'écoulement (scénario médian 25 %, fourchette 0–50 %). Aucun prix
 * n'est proposé : pas de prix_effectifs.
 */
final class R8Gamme
{
    /** Fraction du stock — hypothèse d'écoulement borne basse : rien ne se vend (gain nul). */
    public const ECOULEMENT_PCT_BAS = 0.0;

    /** Fraction du stock — hypothèse d'écoulement scénario médian : 25 % du stock écoulé. */
    public const ECOULEMENT_PCT_MEDIAN = 0.25;

    /** Fraction du stock — hypothèse d'écoulement borne haute : 50 % du stock écoulé. */
    public const ECOULEMENT_PCT_HAUT = 0.50;

    /** Unités — stock minimal pour qu'un produit soit considéré géré (0 = rupture, null = non suivi). */
    public const STOCK_MIN = 1;

    public static function regle(): string
    {
        return 'R8';
    }

    /**
     * Candidats R8 (0..n) : réduire ou écouler les produits fin de gamme.
     *
     * @return list<array<string,mixed>>
     */
    public static function evaluer(RecoContext $ctx): array
    {
        $maxUnites = (float) $ctx->param('gamme_unites_max', Hyper::GAMME_UNITES_MAX);
        $seuilImmobilise = (float) $ctx->param('gamme_immobilise_seuil_eur', Hyper::GAMME_IMMOBILISE_MIN);

        $candidats = [];
        foreach ($ctx->produitsFiltres() as $p) {
            if (!$p->coutUtilisable()) {
                continue;
            }
            if ($p->stock === null || $p->stock < self::STOCK_MIN) {
                continue;
            }
            if (!($ctx->qtyDesaison($p->key) < $maxUnites)) {
                continue;
            }
            $immobilise = (float) $p->stock * (float) $p->cout;
            if (!($immobilise > $seuilImmobilise)) {
                continue;
            }

            $marge = (float) $p->margeUnitaire();
            $gain = GuardRails::fourchette(
                (float) $p->stock * $marge * self::ECOULEMENT_PCT_BAS,
                (float) $p->stock * $marge * self::ECOULEMENT_PCT_MEDIAN,
                (float) $p->stock * $marge * self::ECOULEMENT_PCT_HAUT
            );
            $candidats[] = [
                'regle'           => self::regle(),
                'keys'            => [$p->key],
                'produits'        => [['key' => $p->key, 'label' => $p->label]],
                'titre'           => 'Produit fin de gamme au stock immobilisé',
                'pourquoi'        => self::nombre($ctx->qtyDesaison($p->key)) . ' u/30 j · ' . self::eur($immobilise) . ' € immobilisés',
                'action'          => 'Réduire ou écouler ' . $p->label . ' (décommander ou promo de fin de série)',
                'gain'            => $gain,
                'confiance'       => 'moyenne',
                'effort'          => Hyper::EFFORTS['R8'],
                'hypotheses'      => [
                    'Hypothèse d\'écoulement : ' . number_format(self::ECOULEMENT_PCT_MEDIAN * 100.0, 0, ',', '')
                        . ' % du stock sur la fenêtre (fourchette 0–'
                        . number_format(self::ECOULEMENT_PCT_HAUT * 100.0, 0, ',', '') . ' %)',
                    'Marge unitaire constante sur la fenêtre',
                ],
                'comment_mesurer' => 'Stock restant et CA du produit après 30 jours',
            ];
        }

        return $candidats;
    }

    /** Nombre à 1 décimale, virgule décimale. */
    private static function nombre(float $x): string
    {
        return number_format($x, 1, ',', '');
    }

    /** Montant en € (2 décimales, virgule décimale, sans séparateur de milliers). */
    private static function eur(float $x): string
    {
        return number_format($x, 2, ',', '');
    }
}
