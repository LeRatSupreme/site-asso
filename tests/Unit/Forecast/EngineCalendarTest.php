<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Core\Compta\Forecast\DayCurrent;
use App\Core\Compta\Forecast\ForecastDataset;
use App\Core\Compta\Forecast\ForecastEngine;
use App\Core\Compta\Forecast\ProductStat;
use PHPUnit\Framework\TestCase;

/**
 * Facteur CALENDRIER UNIVERITAIRE du moteur (mission E2, option
 * « calendar ») : la modulation n'agit QUE sur les quantités de commande
 * (hi, safety_demand, suggestion) — JAMAIS sur unites/lo/part (prévision
 * d'affichage, invariant de calibration CA), le bénéfice (dérivé des
 * mêmes unites) ni les prix/coûts unitaires.
 *
 * Fixtures (exigences de la mission) :
 *  - semaine future MORTE (facteur 0) → suggestions ANNULÉES + ligne
 *    d'explication dans notes + facteur tracé dans anomalies ;
 *  - semaine PARTIELLE (0 < f < 1) → suggestions réduites au floor ;
 *  - semaine ACTIVE / facteur 1.0 → sorties STRICTEMENT identiques (les
 *    tests du moteur existants, dont les fixtures sont en période de
 *    cours, ne doivent pas bouger) ;
 *  - PAS d'option calendar → anomalies.facteur_calendrier null, sorties
 *    inchangées.
 *
 * Série synthétique plate (mêmes conventions qu'EngineTest) : 28 jours
 * ouverts lun-ven, motif [70,60,65,75,90], panier 1.25.
 */
final class EngineCalendarTest extends TestCase
{
    private const MOTIF = [70, 60, 65, 75, 90]; // N par jour : lun..ven

    /**
     * Produits de la fenêtre : stock faible pour Eau (suggestion > 0),
     * stock confortable pour Chips (suggestion 0), prix et coûts connus.
     *
     * @return list<ProductStat>
     */
    private static function produits(): array
    {
        return [
            new ProductStat('a', 'Eau', 'Boissons', 600, 1200.0, 2.0, 1.0, false, 10, '2026-10-01 10:00:00'),
            new ProductStat('b', 'Chips', 'Snacks', 300, 600.0, 2.0, 1.0, false, 400, '2026-10-01 10:00:00'),
        ];
    }

    /** @return array<string,DayCurrent> */
    private static function serie(int $jours): array
    {
        $days = [];
        $d = new \DateTimeImmutable('2026-09-07'); // lundi
        while (count($days) < $jours) {
            $dow = (int) $d->format('N') - 1;
            if ($dow <= 4) {
                $n = self::MOTIF[$dow];
                $key = $d->format('Y-m-d');
                $days[$key] = new DayCurrent($key, $n * 1.25, $n, self::horaire($n), array_fill(0, $n, 1.25));
            }
            $d = $d->modify('+1 day');
        }

        return $days;
    }

    /** @return array<int,int> */
    private static function horaire(int $n): array
    {
        $h = array_fill(0, 24, 0);
        $base = intdiv($n, 10);
        $reste = $n - $base * 10;
        for ($i = 0; $i < 10; $i++) {
            $h[8 + $i] = $base + ($i < $reste ? 1 : 0);
        }

        return $h;
    }

    private static function paris(string $datetime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($datetime, new \DateTimeZone('Europe/Paris'));
    }

    /**
     * @return array<string,mixed>
     */
    private static function moteur(array $opts = []): array
    {
        return ForecastEngine::forecast(
            new ForecastDataset(self::serie(28), self::produits()),
            self::paris('2026-10-19 23:59'),
            '23:59',
            ['seed' => 42, 'draws' => 1000] + $opts
        );
    }

    /**
     * Indexe les lignes produits par clé.
     *
     * @param list<array<string,mixed>> $rows
     *
     * @return array<string,array<string,mixed>>
     */
    private static function parCle(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['key']] = $r;
        }

        return $out;
    }

    /** Sans option calendar : trace null, comportement historique intact. */
    public function test_sans_option_calendar_trace_null(): void
    {
        $r = self::moteur();

        self::assertNull($r['anomalies']['facteur_calendrier']);
        self::assertGreaterThanOrEqual(1, count($r['produits']), 'Des produits sont suggérés (Eau : stock 10 << demande).');
    }

    /** Facteur 1.0 (semaine de cours normale) : NO-OP strict sur toutes les sorties consommées. */
    public function test_facteur_1_est_un_no_op_strict(): void
    {
        $sans = self::moteur();
        $avec = self::moteur(['calendar' => ['facteur' => 1.0, 'explication' => null]]);

        foreach (['produits', 'jours', 'jours_semaine', 'semaine', 'mois30', 'benefice', 'notes', 'hier', 'horaire'] as $cle) {
            self::assertSame($sans[$cle], $avec[$cle], "Facteur neutre : « $cle » strictement identique (fixtures actives intactes).");
        }
        self::assertSame(1.0, $avec['anomalies']['facteur_calendrier'], 'Le facteur neutre est quand même tracé.');
        self::assertNull($sans['anomalies']['facteur_calendrier']);
        self::assertStringNotContainsString('Calendrier universitaire', implode(' | ', $avec['notes']), 'Aucune note calendrier en semaine normale.');
    }

    /** Semaine future MORTE (facteur 0) : suggestions ANNULÉES, explication dans notes, unites/coûts intacts. */
    public function test_semaine_morte_annule_les_suggestions(): void
    {
        $ref = self::moteur();
        $r = self::moteur(['calendar' => [
            'facteur'     => 0.0,
            'explication' => 'Semaine sans cours du 26/10 au 30/10 : suggestions annulées',
        ]]);

        self::assertSame(0.0, $r['anomalies']['facteur_calendrier'], 'Facteur appliqué tracé dans le journal du moteur.');
        self::assertContains(
            'Semaine sans cours du 26/10 au 30/10 : suggestions annulées',
            $r['notes'],
            'La ligne d\'explication passe TELLE QUELLE dans la sortie (une seule source du libellé).'
        );

        $avant = self::parCle($ref['produits']);
        $apres = self::parCle($r['produits']);
        $clesAvant = array_keys($avant);
        $clesApres = array_keys($apres);
        sort($clesAvant);
        sort($clesApres);
        self::assertSame($clesAvant, $clesApres, 'Mêmes lignes (un produit vendu reste listé, à 0 — l\'ordre du tri par suggestion change, pas le contenu).');
        foreach ($apres as $cle => $p) {
            self::assertSame(0, $p['suggestion'], "$cle : suggestion annulée en semaine morte.");
            self::assertSame(0, $p['safety_demand'], "$cle : demande de sécurité annulée.");
            self::assertSame(0.0, $p['hi'], "$cle : quantile haut (entrée du besoin de la liste de courses) annulé.");
            self::assertSame(0.0, $p['lo'], "$cle : borne basse de la demande annulée (intervalle cohérent).");

            // INTACTS : prévision d'affichage, parts, prix, coûts (le
            // facteur n'agit JAMAIS sur les valeurs de coût).
            self::assertEqualsWithDelta($avant[$cle]['unites'], $p['unites'], 1.0e-9, "$cle : unites inchangés.");
            self::assertSame($avant[$cle]['part'], $p['part'], "$cle : part inchangée.");
            self::assertSame($avant[$cle]['unitPrice'], $p['unitPrice'], "$cle : prix unitaire inchangé.");
            self::assertSame($avant[$cle]['unitCost'], $p['unitCost'], "$cle : coût unitaire inchangé.");
            self::assertSame($avant[$cle]['stock'], $p['stock'], "$cle : stock inchangé.");
        }

        // Bénéfice dérivé des MÊMES unites (intacts) → strictement identique.
        self::assertEqualsWithDelta(
            $ref['benefice']['total_marge_7j'],
            $r['benefice']['total_marge_7j'],
            0.0001,
            'Le bénéfice prévu ne bouge pas : il vit à l\'échelle des unites, pas des suggestions.'
        );

        // Les prévisions de CA ne sont PAS modulées par le calendrier
        // (contrainte : le facteur n'agit que sur les quantités suggérées).
        self::assertEqualsWithDelta(
            array_sum(array_map(static fn (array $j): float => (float) $j['ca'], $ref['jours'])),
            array_sum(array_map(static fn (array $j): float => (float) $j['ca'], $r['jours'])),
            1.0e-9
        );
    }

    /** Semaine PARTIELLE (facteur 0,5) : suggestions réduites au floor, unites intacts. */
    public function test_semaine_partielle_reduit_au_floor(): void
    {
        $ref = self::moteur();
        $r = self::moteur(['calendar' => [
            'facteur'     => 0.5,
            'explication' => 'Semaine partielle du 02/11 au 06/11 (3 jours de cours sur 5) : suggestions réduites ×0,5',
        ]]);

        self::assertSame(0.5, $r['anomalies']['facteur_calendrier']);
        self::assertContains('Semaine partielle du 02/11 au 06/11 (3 jours de cours sur 5) : suggestions réduites ×0,5', $r['notes']);

        $avant = self::parCle($ref['produits']);
        $apres = self::parCle($r['produits']);
        $auMoinsUne = false;
        foreach ($apres as $cle => $p) {
            self::assertSame(
                (int) floor((int) $avant[$cle]['suggestion'] * 0.5),
                $p['suggestion'],
                "$cle : floor(suggestion × 0,5) — arrondi vers le bas (sur-commander pendant une semaine creuse fait jeter)."
            );
            self::assertSame((int) floor((int) $avant[$cle]['safety_demand'] * 0.5), $p['safety_demand']);
            self::assertEqualsWithDelta(floor((float) $avant[$cle]['lo'] * 0.5), $p['lo'], 1.0e-9, "$cle : bornes de la demande au même facteur (intervalle cohérent).");
            self::assertEqualsWithDelta(floor((float) $avant[$cle]['hi'] * 0.5), $p['hi'], 1.0e-9);
            self::assertEqualsWithDelta($avant[$cle]['unites'], $p['unites'], 1.0e-9, "$cle : unites intacts.");
            if ($p['suggestion'] > 0) {
                $auMoinsUne = true;
            }
        }
        self::assertTrue($auMoinsUne, 'La fixture suggère quelque chose : la réduction est observable.');
    }

    /** Hors horizon publié → l'APPELANT reste prudent : il passe facteur 1.0 et le moteur n'écrit rien. */
    public function test_hors_horizon_politique_prudente_au_niveau_calendrier(): void
    {
        // La politique « hors_horizon → 1.0 » vit dans
        // CalendarContext::facteurSemaine (testée par CalendarContextTest) ;
        // ici on vérifie la CONSÉQUENCE moteur : facteur 1.0 → no-op strict,
        // les suggestions ne sont JAMAIS réduites sur des données absentes.
        $r = self::moteur(['calendar' => ['facteur' => 1.0, 'explication' => null]]);

        self::assertSame(1.0, $r['anomalies']['facteur_calendrier']);
        $avecSuggestions = array_filter($r['produits'], static fn (array $p): bool => (int) ($p['suggestion'] ?? 0) > 0);
        self::assertNotEmpty($avecSuggestions, 'Suggestions NORMALES sur données absentes.');
    }

    /** Borne : un facteur absurde ne peut ni majorer les suggestions ni les rendre négatifs. */
    public function test_facteur_hors_bornes_est_borne(): void
    {
        $ref = self::moteur();
        $r = self::moteur(['calendar' => ['facteur' => 7.5, 'explication' => null]]);

        self::assertSame(1.0, $r['anomalies']['facteur_calendrier'], 'Borné à [0 ; 1].');
        self::assertSame(
            array_map(static fn (array $p) => $p['suggestion'], $ref['produits']),
            array_map(static fn (array $p) => $p['suggestion'], $r['produits']),
            'Un facteur > 1 ne majore JAMAIS les suggestions.'
        );
    }
}
