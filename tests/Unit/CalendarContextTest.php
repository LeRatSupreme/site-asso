<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Compta\Edt\CalendarContext;
use App\Core\Compta\Edt\EdtCalendar;
use PHPUnit\Framework\TestCase;

/**
 * Contexte calendrier universitaire (mission E2) : facteur de modulation
 * des suggestions de réappro, contexte période (dashboard) et ligne de
 * récap quotidien — tous dérivés d'EdtCalendar via le point d'assemblage
 * CalendarContext. AUCUN réseau : l'HTTP est injecté (même fixture que
 * EdtCalendarTest, dérivée du réel du 30/10/2026).
 *
 * Univers de la fixture (une seule, riche) :
 *  - semaine du 26/10 au 30/10 : uniquement des « Fermeture IUT vacances
 *    scolaires » GEA (fait réel Toussaint) → publiée SANS cours ;
 *  - semaine du 02/11 au 06/11 : cours lundi (GEA), mardi (info),
 *    mercredi (GEII 23:00 UTC biné au 04/11 Paris) + une « Fermeture »
 *    le vendredi 06/11 qui PUBLIE la semaine sans ajouter de cours →
 *    3 jours actifs sur 5 (semaine PARTIELLE prorata 0,6) ;
 *  - lundi 09/11 : un cours info, mais mardi 10/11 → vendredi 13/11 non
 *    publiés → semaine NON ENTIÈREMENT publiée → NEUTRE (prudence).
 *
 * Cache EDT réel vérifié le 31/10/2026 : la semaine 26-30/10 est bien
 * sans aucun cours pour les 3 départements (13 « Fermeture » GEA).
 */
final class CalendarContextTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        EdtCalendar::reinitialiserMemo();
        $this->tmp = sys_get_temp_dir() . '/aeic-calctx-test-' . getmypid() . '-' . uniqid('', true);
        @mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (EdtCalendar::DEPTS as $dept) {
            @unlink($this->tmp . '/' . $dept . '.json');
        }
        @rmdir($this->tmp);
        EdtCalendar::reinitialiserMemo();
    }

    // ---------------------------------------------------------------- fixtures

    /**
     * Injecteur HTTP factice (routeur par motif, même principe
     * qu'EdtCalendarTest) servant l'univers documenté en classe.
     */
    private function fetch(): callable
    {
        $evCours = static function (string $dept, string $uid, string $isoUtc): array {
            return [
                'uid' => $uid, 'start' => $isoUtc, 'end' => $isoUtc,
                'title' => $dept . ' TD', 'subject' => $dept, 'kind' => 'TD',
                'room' => 'S1', 'teachers' => [], 'groups' => [], 'notes' => [],
                'department' => $dept,
            ];
        };
        $fermeture = static function (string $isoUtc): array {
            return [
                'uid' => 'ferm-' . $isoUtc, 'start' => $isoUtc, 'end' => $isoUtc,
                'title' => 'Fermeture IUT vacances scolaires',
                'subject' => 'Fermeture IUT vacances scolaires', 'kind' => null,
                'room' => null, 'teachers' => [], 'groups' => [], 'notes' => [],
                'department' => 'iut-gea',
            ];
        };

        $monde = [
            'tree:iut-info' => ['groups' => [['id' => 21, 'name' => 'BUT1']]],
            'tree:iut-gea' => ['groups' => [['id' => 31, 'name' => 'BUT GEA 1 FI']]],
            'tree:iut-geii' => ['groups' => [['id' => 41, 'name' => 'BUT GEII1']]],
            // Toussaint publiée (fermetures réelles) + reprise 02/11.
            'sched:iut-info:21' => ['department' => 'iut-info', 'events' => [
                $evCours('iut-info', 'i-mar-03', '2026-11-03T08:00:00.000Z'),
                $evCours('iut-info', 'i-lun-09', '2026-11-09T08:00:00.000Z'),
            ]],
            'sched:iut-gea:31' => ['department' => 'iut-gea', 'events' => [
                $fermeture('2026-10-26T07:00:00.000Z'),
                $fermeture('2026-10-27T07:00:00.000Z'),
                $fermeture('2026-10-28T07:00:00.000Z'),
                $fermeture('2026-10-29T07:00:00.000Z'),
                $fermeture('2026-10-30T07:00:00.000Z'),
                $evCours('iut-gea', 'g-lun-02', '2026-11-02T08:00:00.000Z'),
                // Fermeture le VENDREDI 06/11 : la semaine 02/11 est
                // ENTIÈREMENT publiée sans 4e jour de cours.
                $fermeture('2026-11-06T07:00:00.000Z'),
            ]],
            'sched:iut-geii:41' => ['department' => 'iut-geii', 'events' => [
                // 23:00 UTC le 03/11 = mercredi 04/11 en heure de Paris.
                $evCours('iut-geii', 'ge-mer-04', '2026-11-03T23:00:00.000Z'),
            ]],
        ];

        return static function (string $url) use ($monde): ?string {
            if (preg_match('#/api/(iut-[a-z]+)/groups/(\d+)/schedule#', $url, $m)) {
                $rep = $monde['sched:' . $m[1] . ':' . $m[2]] ?? null;
            } elseif (preg_match('#/api/(iut-[a-z]+)/groups$#', $url, $m)) {
                $rep = $monde['tree:' . $m[1]] ?? null;
            } else {
                $rep = null;
            }

            return $rep === null ? null : json_encode($rep, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        };
    }

    private static function paris(string $datetime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($datetime, new \DateTimeZone('Europe/Paris'));
    }

    // --------------------------------------------------- politique par semaine

    /** Politique PURE par semaine : morte publiée → 0, partielle → prorata, données absentes → 1. */
    public function test_facteur_semaine_politique(): void
    {
        $base = ['lundi' => '2026-11-02', 'avec_cours' => true, 'jours_actifs' => 5, 'hors_horizon' => false];

        self::assertSame(1.0, CalendarContext::facteurSemaine($base, '2026-11-06'), 'Semaine 5/5 active → 1.0.');
        self::assertSame(0.6, CalendarContext::facteurSemaine(['jours_actifs' => 3] + $base, '2026-11-06'), 'Semaine partielle 3/5 → 0,6.');
        self::assertSame(
            0.0,
            CalendarContext::facteurSemaine(['avec_cours' => false, 'jours_actifs' => 0] + $base, '2026-11-06'),
            'Semaine publiée sans cours → 0.0 (100 % morte).'
        );
        self::assertSame(
            1.0,
            CalendarContext::facteurSemaine(['hors_horizon' => true, 'avec_cours' => false, 'jours_actifs' => 0] + $base, '2026-12-31'),
            'Hors horizon publié → NEUTRE : des données absentes ne sont pas des vacances.'
        );
        self::assertSame(
            1.0,
            CalendarContext::facteurSemaine($base, '2026-11-05'),
            'Vendredi 06/11 au-delà du dernier jour publié (05/11) → semaine non entièrement publiée → 1.0.'
        );
        self::assertSame(1.0, CalendarContext::facteurSemaine($base, null), 'Aucune publication connue → 1.0.');
        self::assertSame(1.0, CalendarContext::facteurSemaine(['lundi' => 'pas-une-date'], '2026-11-06'), 'Lundi illisible → 1.0 (prudent).');
    }

    // ------------------------------------------------ facteur de réappro (IO)

    /** Mardi de la semaine morte : l'horizon couvre 3 jours morts + la reprise partielle → 0,24. */
    public function test_facteur_reappro_pendant_semaine_morte(): void
    {
        $r = CalendarContext::facteurReappro(self::paris('2026-10-27 12:00'), 7, $this->fetch(), $this->tmp);

        self::assertEqualsWithDelta(0.24, $r['facteur'], 0.0001, '(0 × 3 jours morts + 0,6 × 2 jours de reprise) / 5.');
        self::assertSame(5, $r['jours_ouvrables'], 'Horizon mar→dim : 3 ouvrés de la semaine morte + 2 de la reprise (week-end exclu).');
        self::assertSame(3, $r['jours_sans_cours']);
        self::assertStringContainsString('Semaine sans cours du 26/10 au 30/10', (string) $r['explication']);
        self::assertStringContainsString('Semaine partielle du 02/11 au 06/11 (3 jours de cours sur 5)', (string) $r['explication']);
        self::assertStringContainsString('suggestions réduites ×0,24', (string) $r['explication']);
        // Trace par semaine : facteur de chacune, dates et label dérivés.
        self::assertCount(2, $r['semaines']);
        self::assertSame(0.0, $r['semaines'][0]['facteur']);
        self::assertSame('26/10', $r['semaines'][0]['debut_fr']);
        self::assertSame('30/10', $r['semaines'][0]['fin_fr']);
        self::assertSame(0.6, $r['semaines'][1]['facteur']);
    }

    /** Lundi de la semaine morte avec horizon 5 j : horizon 100 % mort (les 4 ouvrés restants) → 0, suggestions annulées. */
    public function test_facteur_reappro_horizon_totalement_mort(): void
    {
        $r = CalendarContext::facteurReappro(self::paris('2026-10-26 12:00'), 5, $this->fetch(), $this->tmp);

        self::assertSame(0.0, $r['facteur']);
        self::assertSame(4, $r['jours_ouvrables'], 'Horizon mar 27 → sam 31/10 : 4 ouvrés (le samedi ne compte pas).');
        self::assertSame(4, $r['jours_sans_cours']);
        self::assertSame(
            'Semaine sans cours du 26/10 au 30/10 : suggestions annulées',
            $r['explication']
        );
    }

    /** Samedi 31/10 : l'horizon ne couvre plus aucun jour de la semaine morte → facteur de la seule reprise (0,6). */
    public function test_facteur_reappro_apres_la_semaine_morte(): void
    {
        $r = CalendarContext::facteurReappro(self::paris('2026-10-31 12:00'), 7, $this->fetch(), $this->tmp);

        self::assertEqualsWithDelta(0.6, $r['facteur'], 0.0001, 'Dim 01/11 (week-end, hors pondération) + lun→ven 02/11 partiels 3/5.');
        self::assertSame(5, $r['jours_ouvrables']);
        self::assertStringNotContainsString('annulées', (string) $r['explication'], 'Réduction, pas annulation.');
    }

    /** Semaine entièrement publiée hors horizon (09/11 incomplète) : PRUDENCE, suggestions normales. */
    public function test_facteur_reappro_hors_horizon_neutre(): void
    {
        $r = CalendarContext::facteurReappro(self::paris('2026-11-08 12:00'), 7, $this->fetch(), $this->tmp);

        self::assertSame(1.0, $r['facteur'], 'Semaine du 09/11 non entièrement publiée → jamais de réduction sur données absentes.');
        self::assertNull($r['explication']);
    }

    /** Semaine de cours NORMALE (5/5 actifs, entièrement publiée) : facteur 1, aucune explication — les suggestions ne bougent pas. */
    public function test_facteur_reappro_semaine_active_normale(): void
    {
        // Univers dédié : 5 cours du lundi 02/11 au vendredi 06/11 (info),
        // rien d'autre — semaine pleine ET entièrement publiée.
        $fetch = $this->fetch();
        $pleine = static function (string $url) use ($fetch): ?string {
            $rep = $fetch($url);
            if ($rep !== null && str_contains($url, 'iut-info')) {
                $data = json_decode($rep, true);
                if (is_array($data) && isset($data['events'])) {
                    foreach (['02', '03', '04', '05', '06'] as $j) {
                        $data['events'][] = [
                            'uid' => 'i-' . $j,
                            'start' => "2026-11-{$j}T08:00:00.000Z",
                            'end' => "2026-11-{$j}T09:00:00.000Z",
                            'title' => 'info TD', 'subject' => 'info', 'kind' => 'TD', 'room' => 'S1',
                            'teachers' => [], 'groups' => [], 'notes' => [], 'department' => 'iut-info',
                        ];
                    }
                    $rep = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }

            return $rep;
        };
        $r = CalendarContext::facteurReappro(self::paris('2026-10-31 12:00'), 7, $pleine, $this->tmp);

        self::assertSame(1.0, $r['facteur'], 'Semaine 5/5 → aucune modulation (les fixtures actives restent inchangées).');
        self::assertNull($r['explication'], 'Rien à dire en semaine normale.');
        self::assertSame(0, $r['jours_sans_cours']);
        self::assertSame(1.0, $r['semaines'][count($r['semaines']) - 1]['facteur']);
    }

    // ------------------------------------------------- contexte période (dashboard)

    /** Semaines mortes de la période : seule la 26-30/10 est listée, avec ses dates et son label dérivé. */
    public function test_pour_periode_liste_les_semaines_mortes(): void
    {
        $c = CalendarContext::pourPeriode('2026-10-19', '2026-11-15', self::paris('2026-10-28 12:00'), $this->fetch(), $this->tmp);

        self::assertCount(1, $c['semaines_mortes'], 'Semaine 19/10 non publiée (avant le début du cache) : jamais listée morte ; 02/11 a des cours ; 09/11 incomplète.');
        $morte = $c['semaines_mortes'][0];
        self::assertSame('2026-10-26', $morte['lundi']);
        self::assertSame('2026-10-30', $morte['vendredi']);
        self::assertSame('26/10', $morte['debut_fr']);
        self::assertSame('30/10', $morte['fin_fr']);
        self::assertSame('Semaine sans cours (vacances ?)', $morte['label']);
        self::assertFalse($morte['hors_horizon'], 'Une semaine morte listée est toujours entièrement publiée.');

        self::assertSame('2026-10-26', $c['plage_publiee']['debut'], 'Fermetures comprises dans la plage publiée.');
        self::assertSame('2026-11-09', $c['plage_publiee']['fin']);
        self::assertFalse($c['plage_publiee']['stale']);
    }

    /** Journée courante : cours aujourd'hui + prochain jour de cours (contrat du bandeau « reprise »). */
    public function test_pour_periode_journee_courante(): void
    {
        $enConges = CalendarContext::pourPeriode('2026-10-26', '2026-11-01', self::paris('2026-10-28 12:00'), $this->fetch(), $this->tmp);
        self::assertFalse($enConges['journee']['cours'], 'Mercredi 28/10 : Toussaint, pas de cours.');
        self::assertSame('2026-11-02', $enConges['journee']['prochain_cours'], 'Reprise le lundi 02/11.');

        $enCours = CalendarContext::pourPeriode('2026-11-02', '2026-11-08', self::paris('2026-11-03 12:00'), $this->fetch(), $this->tmp);
        self::assertTrue($enCours['journee']['cours'], 'Mardi 03/11 : cours info.');
        self::assertSame('2026-11-03', $enCours['journee']['date']);
        self::assertSame('2026-11-04', $enCours['journee']['prochain_cours'], 'Prochain = mercredi 04/11 (GEII biné en heure locale).');
        self::assertSame('mer. 04/11', $enCours['journee']['prochain_cours_fr']);
    }

    // ------------------------------------------------------ ligne de récap (kiosque/SMS)

    /** Semaine morte : ligne « Vacances universitaires : reprise des cours le 02/11. » (factuelle, dérivée). */
    public function test_ligne_recap_semaine_morte(): void
    {
        $ligne = CalendarContext::ligneRecap(self::paris('2026-10-28 20:00'), $this->fetch(), $this->tmp);

        self::assertSame('Vacances universitaires : reprise des cours le 02/11.', $ligne);
    }

    /** Semaine de cours, prochain cours demain : AUCUNE ligne (semaine normale). */
    public function test_ligne_recap_semaine_normale_silence(): void
    {
        $ligne = CalendarContext::ligneRecap(self::paris('2026-11-03 20:00'), $this->fetch(), $this->tmp);

        self::assertNull($ligne, 'Mardi soir : prochain cours mercredi = demain → rien à signaler.');
    }

    /** Prochain cours > demain (fin de semaine) : ligne « Prochain jour de cours : … ». */
    public function test_ligne_recap_prochain_cours_pas_demain(): void
    {
        $ligne = CalendarContext::ligneRecap(self::paris('2026-11-06 20:00'), $this->fetch(), $this->tmp);

        self::assertSame('Prochain jour de cours : lun. 09/11.', $ligne);
    }

    /** Semaine morte mais rien de publié après : formulation honnête, pas de faux espoir. */
    public function test_ligne_recap_morte_sans_reprise_publiee(): void
    {
        // Univers vide au-delà de Toussaint : la reprise est inconnue.
        $fetch = $this->fetch();
        $ampute = static function (string $url) use ($fetch): ?string {
            $rep = $fetch($url);
            if ($rep !== null && str_contains($url, 'schedule')) {
                $data = json_decode($rep, true);
                if (is_array($data) && isset($data['events'])) {
                    $data['events'] = array_values(array_filter(
                        $data['events'],
                        static fn (array $e): bool => strcmp((string) ($e['start'] ?? ''), '2026-11-01') < 0
                    ));
                    $rep = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }

            return $rep;
        };
        $ligne = CalendarContext::ligneRecap(self::paris('2026-10-28 20:00'), $ampute, $this->tmp);

        self::assertSame('Vacances universitaires : aucune reprise publiée pour l\'instant.', $ligne);
    }

    // ------------------------------------------- neutralité du contexte de test

    /**
     * APP_TESTING : sans injecteurs explicites, CalendarContext sert un
     * calendrier vide HORS-LIGNE (aucun appel réseau depuis une suite de
     * tests) → contexte NEUTRE déterministe (facteur 1.0, rien à dire).
     */
    public function test_app_testing_sans_injecteurs_est_neutre_hors_ligne(): void
    {
        if (!(\defined('APP_TESTING') && \constant('APP_TESTING'))) {
            self::markTestSkipped('APP_TESTING non actif (bootstrap PHPUnit requis).');
        }

        $now = self::paris('2026-10-28 12:00');
        $f = CalendarContext::facteurReappro($now);
        self::assertSame(1.0, $f['facteur']);
        self::assertNull($f['explication']);
        self::assertTrue($f['stale'], 'Calendrier vide en contexte test → stale.');

        $c = CalendarContext::pourPeriode('2026-10-01', '2026-11-30', $now);
        self::assertSame([], $c['semaines_mortes']);
        self::assertNull($c['plage_publiee']['debut']);
        self::assertNull($c['journee']['prochain_cours']);

        self::assertNull(CalendarContext::ligneRecap($now));
    }
}
