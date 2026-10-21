<?php

declare(strict_types=1);

namespace App\Core\Compta\Reco;

/**
 * R11 — Qualité des données (règle PRIORITAIRE, sans prix_effectifs) :
 * signale ce qui empêche le moteur de recommander correctement.
 *
 * Trois familles de candidats, dans cet ordre :
 *  1. Coûts suspects — produit à coutRaison ∈ {cout_inconnu, cout_zero,
 *     prix_inferieur_au_cout} ET (qty30 > 0 OU prévu dans
 *     forecast['produits']) → demander la saisie du coût ;
 *  2. Doublons de noms — libellés égaux après normalisation (trim,
 *     minuscules, espaces réduits) sur ≥ 2 fiches → demander la fusion ;
 *  3. Produits sans stock — labels de forecast['anomalies']
 *     ['produits_sans_stock'] → demander la vérification du stock (clé
 *     du produit si trouvé au catalogue, sinon préfixée PREFIXE, les
 *     clés vides étant refusées par GuardRails).
 *
 * CAP : « reco_r11_max » candidats au total (Hyper::R11_MAX par défaut).
 * Gains non chiffrés (0/0/0) : c'est de la donnée, pas du CA.
 */
final class R11QualiteDonnees
{
    /** Raisons de coût suspect déclenchant la demande de saisie (garde-fou 2). */
    public const COUTS_SUSPECTS = ['cout_inconnu', 'cout_zero', 'prix_inferieur_au_cout'];

    /** Préfixe de clé pour un produit signalé sans stock mais introuvable au catalogue. */
    public const PREFIXE_SANS_STOCK = 'sans_stock:';

    public static function regle(): string
    {
        return 'R11';
    }

    /**
     * Candidats R11 (0..reco_r11_max) : coûts, puis doublons, puis stocks.
     *
     * @return list<array<string,mixed>>
     */
    public static function evaluer(RecoContext $ctx): array
    {
        $candidats = self::candidatsCouts($ctx);
        foreach (self::candidatsDoublons($ctx) as $c) {
            $candidats[] = $c;
        }
        foreach (self::candidatsSansStock($ctx) as $c) {
            $candidats[] = $c;
        }

        $max = (int) $ctx->param('r11_max', Hyper::R11_MAX);

        return array_slice($candidats, 0, max(0, $max));
    }

    /**
     * 1) Coûts suspects : demander la saisie d'un coût d'achat fiable.
     *
     * @return list<array<string,mixed>>
     */
    private static function candidatsCouts(RecoContext $ctx): array
    {
        $prevus = [];
        foreach ((array) ($ctx->dataset()->forecast['produits'] ?? []) as $fp) {
            if (is_array($fp) && is_string($fp['label'] ?? null)) {
                $prevus[$fp['label']] = true;
            }
        }

        $candidats = [];
        foreach ($ctx->produitsFiltres() as $p) {
            if ($p->coutRaison === null || !in_array($p->coutRaison, self::COUTS_SUSPECTS, true)) {
                continue;
            }
            if (!($p->qty30 > 0.0 || isset($prevus[$p->label]))) {
                continue;
            }
            $prixTxt = $p->prix !== null ? self::eur((float) $p->prix) . ' €' : 'inconnu';
            $candidats[] = [
                'regle'           => self::regle(),
                'keys'            => [$p->key],
                'produits'        => [['key' => $p->key, 'label' => $p->label]],
                'titre'           => 'Renseigner le coût de ' . $p->label,
                'pourquoi'        => number_format($p->qty30, 0, ',', '') . ' u/30 j · prix affiché ' . $prixTxt,
                'action'          => 'Saisir le coût d\'achat de ' . $p->label
                    . ' dans les achats/fiches produit — sans coût fiable, aucune reco de prix ou promo n\'est possible sur ce produit',
                'gain'            => GuardRails::fourchette(0.0, 0.0, 0.0),
                'confiance'       => 'haute',
                'effort'          => Hyper::EFFORTS['R11'],
                'hypotheses'      => ['Pas de gain chiffré (qualité des données)'],
                'comment_mesurer' => 'Coût renseigné puis réexécution : les recos tarifaires redeviennent possibles sur ce produit',
            ];
        }

        return $candidats;
    }

    /**
     * 2) Doublons de noms : fiches distinctes au même libellé normalisé.
     *
     * @return list<array<string,mixed>>
     */
    private static function candidatsDoublons(RecoContext $ctx): array
    {
        $groupes = [];
        foreach ($ctx->produitsFiltres() as $p) {
            $groupes[self::normaliser($p->label)][$p->key] = $p;
        }

        $candidats = [];
        foreach ($groupes as $nom => $ps) {
            if (count($ps) < 2) {
                continue;
            }
            $keys = array_keys($ps);
            $candidats[] = [
                'regle'           => self::regle(),
                'keys'            => $keys,
                'produits'        => array_map(
                    static fn (RecoProduct $p): array => ['key' => $p->key, 'label' => $p->label],
                    array_values($ps)
                ),
                'titre'           => 'Fusionner les fiches « ' . $nom . ' »',
                'pourquoi'        => count($ps) . ' fiches au même nom (« ' . $nom . ' »)',
                'action'          => 'Fusionner les fiches « ' . $nom . ' » en une seule référence (même produit vendu sous plusieurs fiches)',
                'gain'            => GuardRails::fourchette(0.0, 0.0, 0.0),
                'confiance'       => 'haute',
                'effort'          => Hyper::EFFORTS['R11'],
                'hypotheses'      => ['Pas de gain chiffré (qualité des données)'],
                'comment_mesurer' => 'Une seule fiche par produit après fusion, historique de ventes conservé',
            ];
        }

        return $candidats;
    }

    /**
     * 3) Produits sans stock : signalés par forecast['anomalies']
     * ['produits_sans_stock'] (labels). Clé du produit si trouvé au
     * catalogue (produitsFiltres, 1re occurrence), sinon clé préfixée
     * PREFIXE_SANS_STOCK (GuardRails refuse les clés vides).
     *
     * @return list<array<string,mixed>>
     */
    private static function candidatsSansStock(RecoContext $ctx): array
    {
        $anomalies = (array) ($ctx->dataset()->forecast['anomalies']['produits_sans_stock'] ?? []);

        $candidats = [];
        foreach ($anomalies as $label) {
            if (!is_string($label) || $label === '') {
                continue;
            }
            $p = self::produitParLabel($ctx->produitsFiltres(), $label);
            $key = $p !== null ? $p->key : self::PREFIXE_SANS_STOCK . $label;
            $pourquoi = $p !== null
                ? number_format($p->qty30, 0, ',', '') . ' u/30 j · produit vendu mais non suivi en stock'
                : 'produit signalé sans stock mais absent du catalogue';
            $candidats[] = [
                'regle'           => self::regle(),
                'keys'            => [$key],
                'produits'        => [['key' => $key, 'label' => $label]],
                'titre'           => 'Vérifier le stock de ' . $label,
                'pourquoi'        => $pourquoi,
                'action'          => 'Vérifier le stock de ' . $label . ' (vendu ou prévu mais non suivi en stock)',
                'gain'            => GuardRails::fourchette(0.0, 0.0, 0.0),
                'confiance'       => 'haute',
                'effort'          => Hyper::EFFORTS['R11'],
                'hypotheses'      => ['Pas de gain chiffré (qualité des données)'],
                'comment_mesurer' => 'Stock renseigné et suivi fiable sur la fenêtre suivante',
            ];
        }

        return $candidats;
    }

    /** Normalisation d'un libellé : minuscules, espaces réduits à un seul, sans extrémités. */
    private static function normaliser(string $label): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower(trim($label))));
    }

    /** Premier produit du catalogue filtré portant exactement ce label. */
    private static function produitParLabel(array $produits, string $label): ?RecoProduct
    {
        foreach ($produits as $p) {
            if ($p->label === $label) {
                return $p;
            }
        }

        return null;
    }

    /** Montant en € (2 décimales, virgule décimale, sans séparateur de milliers). */
    private static function eur(float $x): string
    {
        return number_format($x, 2, ',', '');
    }
}
