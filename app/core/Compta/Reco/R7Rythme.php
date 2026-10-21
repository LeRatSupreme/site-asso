<?php

declare(strict_types=1);

namespace App\Core\Compta\Reco;

use App\Core\Compta\Forecast\Stats;

/**
 * R7 — Rythme horaire : creux et pics de fréquentation.
 *
 * À partir des parts de demande par heure (RecoContext::partsHoraires,
 * dérivées du cumul forecast['horaire']['s']) : une heure est « creuse »
 * sous « reco_seuil_creux_part » × moyenne des parts positives, « pic »
 * au-dessus de « reco_seuil_pic_part » × moyenne. UN SEUL candidat
 * combiné est émis si au moins un creux ET un pic existent : happy hour
 * sur les meilleurs produits (taux de marge) aux heures creuses,
 * préparation renforcée aux pics.
 *
 * Gain hebdomadaire (€) : CA 7 j prévu (unitesPrevues7j × prix des
 * produits à coût utilisable) × part de demande des heures creuses ×
 * uplift 10 % × taux de marge médian (secours 0,30 si null). Aucun
 * prix n'est proposé : pas de prix_effectifs.
 */
final class R7Rythme
{
    /** Sans unité — taux de marge de secours si moins de 3 produits à coût fiable (hypothèse documentée). */
    public const TAUX_MARGE_SECOURS = 0.30;

    /** Fraction — uplift de CA visé en heure creuse via happy hour (hypothèse). */
    public const UPLIFT_CREUX = 0.10;

    /** Produits — nombre maximal de produits cités dans l'action (meilleurs taux de marge). */
    public const PRODUITS_MIS_AVANT = 3;

    public static function regle(): string
    {
        return 'R7';
    }

    /**
     * Candidat R7 (0 ou 1) : happy hour aux creux, préparation aux pics.
     *
     * @return list<array<string,mixed>>
     */
    public static function evaluer(RecoContext $ctx): array
    {
        $parts = $ctx->partsHoraires();
        if ($parts === null) {
            return [];
        }
        $positives = array_values(array_filter($parts, static fn (float|int $v): bool => (float) $v > 0.0));
        if ($positives === []) {
            return [];
        }
        $moyenne = Stats::mean($positives);
        $seuilCreux = (float) $ctx->param('seuil_creux_part', Hyper::SEUIL_CREUX_PART);
        $seuilPic = (float) $ctx->param('seuil_pic_part', Hyper::SEUIL_PIC_PART);

        $creux = [];
        $pics = [];
        foreach ($parts as $h => $part) {
            if ((float) $part < $seuilCreux * $moyenne) {
                $creux[] = (int) $h;
            }
            if ((float) $part > $seuilPic * $moyenne) {
                $pics[] = (int) $h;
            }
        }
        if ($creux === [] || $pics === []) {
            return [];
        }

        [$top, $ca7j] = self::produitsEtCa($ctx);
        if ($top === []) {
            return [];
        }

        $partCreuse = 0.0;
        foreach ($creux as $h) {
            $partCreuse += (float) $parts[$h];
        }
        $somme = array_sum($parts);
        $secours = $ctx->medianeTauxMarge() === null;
        $taux = $secours ? self::TAUX_MARGE_SECOURS : (float) $ctx->medianeTauxMarge();
        $median = $ca7j * $partCreuse * self::UPLIFT_CREUX * $taux;
        $gain = GuardRails::fourchette($median * 0.5, $median, $median * 1.5);

        $hypotheses = [
            'Uplift de +' . number_format(self::UPLIFT_CREUX * 100.0, 0, ',', '')
                . ' % du CA des heures creuses (hypothèse)',
        ];
        if ($secours) {
            $hypotheses[] = 'Taux de marge de secours ' . number_format(self::TAUX_MARGE_SECOURS, 2, ',', '')
                . ' faute de 3 produits à coût fiable (hypothèse)';
        }

        return [[
            'regle'           => self::regle(),
            'keys'            => array_map(static fn (RecoProduct $p): string => $p->key, $top),
            'produits'        => array_map(
                static fn (RecoProduct $p): array => ['key' => $p->key, 'label' => $p->label],
                $top
            ),
            'titre'           => 'Lisser la fréquentation : heure creuse et pics',
            'pourquoi'        => self::pct($somme > 0.0 ? $partCreuse / (float) $somme : 0.0)
                . ' % de la demande est dans les heures creuses · CA prévu 7 j : ' . self::eur($ca7j) . ' €',
            'action'          => 'Happy hour ' . self::plagesHeures($creux) . ' sur '
                . implode(', ', array_map(static fn (RecoProduct $p): string => $p->label, $top))
                . ' ; aux pics (' . implode(', ', array_map(static fn (int $h): string => (string) $h, $pics))
                . ') : préparer les duos à l\'avant et renforcer le service',
            'gain'            => $gain,
            'confiance'       => 'moyenne',
            'effort'          => Hyper::EFFORTS['R7'],
            'hypotheses'      => $hypotheses,
            'comment_mesurer' => 'Parts horaires et CA par heure avant/après 14 jours ouverts',
        ]];
    }

    /**
     * Produits à mettre en avant (coût utilisable, meilleurs taux de
     * marge, égalités : ordre d'entrée) et CA 7 j prévu associé
     * (unitesPrevues7j × prix, produits à coût utilisable uniquement).
     *
     * @return array{0:list<RecoProduct>,1:float}
     */
    private static function produitsEtCa(RecoContext $ctx): array
    {
        $eligibles = [];
        $ca7j = 0.0;
        foreach ($ctx->produitsFiltres() as $p) {
            if (!$p->coutUtilisable()) {
                continue;
            }
            $eligibles[] = $p;
            $ca7j += $p->unitesPrevues7j * (float) $p->prix;
        }
        usort($eligibles, static fn (RecoProduct $a, RecoProduct $b): int => (float) $b->tauxMarge() <=> (float) $a->tauxMarge());

        return [array_slice($eligibles, 0, self::PRODUITS_MIS_AVANT), $ca7j];
    }

    /**
     * Compacte une liste d'heures en plages : [0,1,2,3,14] → « 0–3, 14 ».
     *
     * @param list<int> $heures
     */
    private static function plagesHeures(array $heures): string
    {
        $plages = [];
        $debut = null;
        $prec = null;
        foreach ($heures as $h) {
            if ($debut === null) {
                $debut = $h;
                $prec = $h;
                continue;
            }
            if ($h === $prec + 1) {
                $prec = $h;
                continue;
            }
            $plages[] = $debut === $prec ? (string) $debut : $debut . '–' . $prec;
            $debut = $h;
            $prec = $h;
        }
        if ($debut !== null) {
            $plages[] = $debut === $prec ? (string) $debut : $debut . '–' . $prec;
        }

        return implode(', ', $plages);
    }

    /** Montant en € (2 décimales, virgule décimale, sans séparateur de milliers). */
    private static function eur(float $x): string
    {
        return number_format($x, 2, ',', '');
    }

    /** Part (0..1) en pourcentage à 1 décimale, virgule décimale. */
    private static function pct(float $x): string
    {
        return number_format($x * 100.0, 1, ',', '');
    }
}
