<?php

declare(strict_types=1);

namespace App\Core\Compta\Reco;

/**
 * R2 — Rupture ou stock critique : ne jamais vendre à vide ce qui se
 * vendra presque sûrement d'après la PRÉVISION 7 j (sortie Forecast,
 * jamais recalculée).
 *
 * Déclenchement (par produit filtré) : prévision strictement positive
 * ET stock géré nul OU stock < ceil(unitesPrevues7j × «
 * reco_secu_stock_jours » (3) ÷ 7) — le seuil de sécurité couvre
 * SECU_STOCK_JOURS jours de demande prévue.
 *
 * Gain hebdomadaire (€) = demande prévue × marge unitaire (médiane
 * avec la prévision centrale, bas avec prevLo, haut avec prevHi) ;
 * coût inconnu → gain nul (R2 n'est pas tarifaire : le candidat reste
 * émis, sans prix_effectifs). Confiance « haute » si la quantité
 * désaisonnalisée ≥ Hyper::SAMPLE_MIN_UNITES_30J, sinon « moyenne »
 * avec l'hypothèse « demande estimée » explicite.
 */
final class R2Rupture implements RecoRule
{
    public static function regle(): string
    {
        return 'R2';
    }

    /**
     * Candidats R2 (0..n) : réapprovisionner avant rupture.
     *
     * @return list<array<string,mixed>>
     */
    public static function evaluer(RecoContext $ctx): array
    {
        $secu = (float) $ctx->param('secu_stock_jours', Hyper::SECU_STOCK_JOURS);

        $candidats = [];
        foreach ($ctx->produitsFiltres() as $p) {
            $prev = $p->unitesPrevues7j;
            if (!($prev > 0.0) || $p->stock === null) {
                continue;
            }
            $seuil = (int) ceil($prev * $secu / 7.0);
            if (!($p->stock === 0 || $p->stock < $seuil)) {
                continue;
            }
            $candidats[] = self::candidat($ctx, $p, $prev, $seuil);
        }

        return $candidats;
    }

    /**
     * Candidat de réapprovisionnement : commander ce qui manque pour
     * remonter au seuil de sécurité.
     *
     * @return array<string,mixed>
     */
    private static function candidat(RecoContext $ctx, RecoProduct $p, float $prev, int $seuil): array
    {
        $qty = $ctx->qtyDesaison($p->key);
        $marge = $p->margeUnitaire();
        $m = $marge ?? 0.0;
        $gain = GuardRails::fourchette($p->prevLo * $m, $prev * $m, $p->prevHi * $m);
        $confiance = $qty >= Hyper::SAMPLE_MIN_UNITES_30J ? 'haute' : 'moyenne';
        $quantite = max($seuil - $p->stock, 1);

        $hypotheses = [];
        if ($confiance === 'moyenne') {
            $hypotheses[] = 'Demande estimée = hypothèse (' . self::nombre($qty, 0) . ' u./30 j < ' . Hyper::SAMPLE_MIN_UNITES_30J . ')';
        }
        if ($marge === null) {
            $hypotheses[] = 'Coût inconnu : gain non chiffré (0 €)';
        }

        return [
            'regle'           => self::regle(),
            'keys'            => [$p->key],
            'produits'        => [['key' => $p->key, 'label' => $p->label]],
            'titre'           => 'Rupture imminente : ' . $p->label,
            'pourquoi'        => $p->stock . ' u. en stock · ' . self::nombre($prev) . ' u./sem prévues',
            'action'          => 'Commander ' . $quantite . ' u. de ' . $p->label . ' dès maintenant (voir liste de courses)',
            'gain'            => $gain,
            'confiance'       => $confiance,
            'effort'          => Hyper::EFFORTS['R2'],
            'hypotheses'      => $hypotheses,
            'comment_mesurer' => 'Ruptures et ventes perdues sur les 7 prochains jours',
        ];
    }

    /** Nombre à 1 décimale, virgule décimale. */
    private static function nombre(float $x, int $dec = 1): string
    {
        return number_format($x, $dec, ',', '');
    }
}
