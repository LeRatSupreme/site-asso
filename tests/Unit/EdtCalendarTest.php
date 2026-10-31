<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Compta\Edt\EdtCalendar;
use PHPUnit\Framework\TestCase;

/**
 * Cœur calendrier universitaire (EDT ULCO Calais) : agrégation par jour,
 * par semaine, prochain jour de cours, cache stale, racines découvertes
 * dynamiquement. AUCUN réseau — l'HTTP est injecté (même principe que
 * PublicAssistant redirige ses dépendances vers un tmp).
 *
 * Les fixtures sont DÉRIVÉES DU RÉEL du 30/10/2026 (échantillons
 * verbatim de edt-ulco.fr), dont le fait structurant : la semaine du
 * 26/10 au 30/10 (Toussaint) ne porte que des évènements « Fermeture
 * IUT vacances scolaires » (GEA) — zéro cours pour les 3 départements.
 */
final class EdtCalendarTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/aeic-edt-test-' . getmypid() . '-' . uniqid('', true);
        @mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->tmp . '/iut-info.json');
        @unlink($this->tmp . '/iut-gea.json');
        @unlink($this->tmp . '/iut-geii.json');
        @rmdir($this->tmp);
    }

    // ---------------------------------------------------------------- fixtures

    /**
     * Injecteur HTTP factice à ROUTEUR D'URL : sert /groups et
     * /groups/{id}/schedule par motif (le paramètre « from » est glissant
     * — calculé « maintenant - 14 j » par la classe — donc jamais clé
     * exacte) et renvoie null pour toute URL inconnue (API down).
     *
     * @param array<string,array> $monde Clé motif → réponse décodée.
     *
     * @return array{fn:callable(string):?string, count():int, urls():list<string>}
     */
    private function makeFetch(array $monde): array
    {
        $n = 0;
        $urls = [];
        $router = static function (string $url) use ($monde): ?array {
            // /api/{dept}/groups → arbre ; /api/{dept}/groups/{id}/schedule?from=… → EDT.
            if (preg_match('#/api/(iut-[a-z]+)/groups/(\d+)/schedule#', $url, $m)) {
                return $monde['sched:' . $m[1] . ':' . $m[2]] ?? null;
            }
            if (preg_match('#/api/(iut-[a-z]+)/groups$#', $url, $m)) {
                return $monde['tree:' . $m[1]] ?? null;
            }

            return null;
        };

        return [
            'fn'    => function (string $url) use ($router, &$n, &$urls): ?string {
                $n++;
                $urls[] = $url;
                $rep = $router($url);

                return $rep === null
                    ? null
                    : json_encode($rep, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            },
            'count' => static function () use (&$n): int {
                return $n;
            },
            'urls'  => static function () use (&$urls): array {
                return $urls;
            },
        ];
    }

    /** Évènement de cours iut-info, verbatim EDT BUT2-TD3-APP (03/11/2026). */
    private static function evInfo(string $jourIsoUtc, string $uidSuffix = '0'): array
    {
        return [
            'uid'       => 'ADE-test-info-' . $uidSuffix,
            'start'     => $jourIsoUtc,
            'end'       => '2026-11-03T09:00:00.000Z',
            'title'     => 'R3-01 Dev WEB TD3 M.GRUWEZ',
            'subject'   => 'R3-01 Dev WEB M.GRUWEZ',
            'kind'      => 'TD',
            'room'      => 'S128',
            'teachers'  => [],
            'groups'    => ['BUT2-TD3-APP'],
            'notes'     => [],
            'department' => 'iut-info',
        ];
    }

    /** « Fermeture IUT vacances scolaires », verbatim GEA 26/10/2026 (Toussaint). */
    private static function evFermetureGea(string $jourIsoUtc): array
    {
        return [
            'uid'       => 'ADE-test-ferm-' . $jourIsoUtc,
            'start'     => $jourIsoUtc,
            'end'       => $jourIsoUtc,
            'title'     => 'Fermeture IUT vacances scolaires',
            'subject'   => 'Fermeture IUT vacances scolaires',
            'kind'      => null,
            'room'      => null,
            'teachers'  => [],
            'groups'    => ['BUT GEA 1 FI', 'BUT GEA 1 FC', 'BUT GEA 2 FC', 'BUT GEA 2 FI'],
            'notes'     => [],
            'department' => 'iut-gea',
        ];
    }

    /** Évènement de cours GEII, verbatim EDT GEII1 (03/11/2026). */
    private static function evGeii(string $jourIsoUtc): array
    {
        return [
            'uid'       => 'ADE-test-geii-1',
            'start'     => $jourIsoUtc,
            'end'       => '2026-11-03T10:00:00.000Z',
            'title'     => 'GEII1 Mathématiques TD1',
            'subject'   => 'GEII1 Mathématiques',
            'kind'      => 'TD',
            'room'      => 'S215',
            'teachers'  => [],
            'groups'    => ['GEII1TD1'],
            'notes'     => [],
            'department' => 'iut-geii',
        ];
    }

    /**
     * Univers de test minimal dérivé du réel :
     *  - iut-info : racines découvertes dynamiquement (ids futurs 12001/12002),
     *    2 cours le mardi 03/11 (BUT2-TD3-APP) + 1 le jeudi 05/11 ;
     *  - iut-gea : SEULEMENT des « Fermeture » du 26 au 30/10 (fait réel
     *    Toussaint) + 1 cours le 03/11 ;
     *  - iut-geii : 1 cours le 03/11 à 23:00 UTC → jour local 04/11
     *    (Europe/Paris, DST terminée le 25/10) ;
     *  - l'évènement « Fermeture » (multi-groupes) apparaît dans DEUX
     *    réponses de racines GEA avec le même uid → déduplication.
     *
     * Clés « tree:{dept} » / « sched:{dept}:{racine} » — le routeur ignore
     * le « from » glissant, les tests restent valides n'importe quel jour.
     *
     * @return array<string,array>
     */
    private static function mondeReel(): array
    {
        $evGeii23h = self::evGeii('2026-11-03T23:00:00.000Z'); // → 04/11 heure locale.
        $fermetures = static fn (): array => array_map(
            static fn (int $j): array => self::evFermetureGea(sprintf('2026-10-%02dT07:00:00.000Z', $j)),
            [26, 27, 28, 29, 30]
        );

        return [
            // Arbres (racines = ids dynamiques, PAS ceux d'aujourd'hui).
            'tree:iut-info' => [
                'department' => 'iut-info',
                'groups'     => [
                    ['id' => 12001, 'name' => 'BUT1', 'path' => 'IUT INFO.BUT1', 'depth' => 2, 'children' => []],
                    ['id' => 12002, 'name' => 'BUT2', 'path' => 'IUT INFO.BUT2', 'depth' => 2, 'children' => []],
                ],
            ],
            'tree:iut-gea' => [
                'department' => 'iut-gea',
                'groups'     => [
                    ['id' => 13001, 'name' => 'BUT GEA 1 FC', 'depth' => 2, 'children' => []],
                    ['id' => 13002, 'name' => 'BUT GEA 1 FI', 'depth' => 2, 'children' => []],
                ],
            ],
            'tree:iut-geii' => [
                'department' => 'iut-geii',
                'groups'     => [
                    ['id' => 14001, 'name' => 'BUT GEII1', 'depth' => 2, 'children' => []],
                ],
            ],

            // EDT par racine — iut-info.
            'sched:iut-info:12001' => [
                'department' => 'iut-info', 'events' => [self::evInfo('2026-11-03T07:30:00.000Z', 'a')],
            ],
            'sched:iut-info:12002' => [
                'department' => 'iut-info',
                'events'     => [
                    self::evInfo('2026-11-03T07:30:00.000Z', 'b'),
                    self::evInfo('2026-11-05T07:30:00.000Z', 'c'),
                ],
            ],
            // GEA : chaque racine renvoie les 5 « Fermeture » de Toussaint
            // (chevauchement réel, mêmes uids).
            'sched:iut-gea:13001' => ['department' => 'iut-gea', 'events' => $fermetures()],
            'sched:iut-gea:13002' => ['department' => 'iut-gea', 'events' => $fermetures()],
            'sched:iut-geii:14001' => [
                'department' => 'iut-geii',
                'events'     => [$evGeii23h],
            ],
        ];
    }

    // ------------------------------------------------------------------ tests

    /** Jour AVEC cours : 03/11/2026, séances connues BUT2-TD3-APP. */
    public function test_jour_avec_cours(): void
    {
        $f = $this->makeFetch(self::mondeReel());
        $infos = EdtCalendar::jourInfos('2026-11-03', $f['fn'], $this->tmp);

        self::assertTrue($infos['cours'], 'Le 03/11/2026 a des cours (BUT2-TD3-APP).');
        self::assertSame(2, $infos['creneaux'], '2 séances iut-info ce jour-là.');
        self::assertSame(2, $infos['par_dept']['iut-info']);
        self::assertSame(0, $infos['par_dept']['iut-gea'], 'GEA n\'a rien le 03/11 dans la fixture.');
        self::assertSame(0, $infos['par_dept']['iut-geii'], 'GEII 23:00 UTC tombe le 04/11 local, pas le 03.');
        self::assertFalse($infos['stale']);
    }

    /**
     * Jour SANS cours pendant la Toussaint : 28/10/2026 — seul GEA publie
     * des « Fermeture IUT vacances scolaires » (verbatim réel), ce n'est
     * pas un cours → filtré, tous départements à 0.
     */
    public function test_jour_sans_cours_toussaint(): void
    {
        $f = $this->makeFetch(self::mondeReel());
        $infos = EdtCalendar::jourInfos('2026-10-28', $f['fn'], $this->tmp);

        self::assertFalse($infos['cours'], 'Toussaint : pas de cours (les « Fermeture » ne comptent pas).');
        self::assertSame(0, $infos['creneaux']);
        self::assertSame(0, $infos['par_dept']['iut-gea'], 'Évènements Fermeture filtrés du décompte.');
        self::assertSame(0, $infos['par_dept']['iut-info']);
        self::assertSame(0, $infos['par_dept']['iut-geii']);
    }

    /**
     * Semaine de vacances 26/10 → 30/10 : 5 jours ouvrés complets sans
     * cours, label DÉRIVÉ DES DONNÉES (aucun nom de vacances en dur).
     */
    public function test_semaine_vacances_toussaint(): void
    {
        $f = $this->makeFetch(self::mondeReel());
        $s = EdtCalendar::semaineInfos('2026-10-26', $f['fn'], $this->tmp);

        self::assertSame('2026-10-26', $s['lundi']);
        self::assertFalse($s['avec_cours']);
        self::assertSame(0, $s['jours_actifs']);
        self::assertSame(0, $s['creneaux_total']);
        self::assertSame('Semaine sans cours (vacances ?)', $s['label_auto']);
    }

    /** Semaine de reprise 02/11 : cours, jours actifs, label « de cours ». */
    public function test_semaine_avec_cours(): void
    {
        $f = $this->makeFetch(self::mondeReel());
        $s = EdtCalendar::semaineInfos('2026-11-02', $f['fn'], $this->tmp);

        self::assertTrue($s['avec_cours']);
        self::assertSame('Semaine de cours', $s['label_auto']);
        self::assertSame(3, $s['jours_actifs'], 'Mardi 03/11 (info×2), mercredi 04/11 (geii local), jeudi 05/11 (info).');
        self::assertSame(4, $s['creneaux_total']);
        // Le mercredi compte l'évènement GEII 23:00 UTC biné au 04/11 local.
        self::assertSame(1, $s['par_jour']['2026-11-04']);
    }

    /** Fuseau : un évènement 23:00 UTC est biné au jour LOCAL suivant. */
    public function test_binning_fuseau_europe_paris(): void
    {
        $f = $this->makeFetch(self::mondeReel());
        $infos = EdtCalendar::jourInfos('2026-11-04', $f['fn'], $this->tmp);

        self::assertSame(1, $infos['par_dept']['iut-geii'], '23:00 UTC le 03/11 = 00:00 le 04/11 à Paris (CET).');
    }

    /**
     * Déduplication inter-racines : les « Fermeture » (même uid) reviennent
     * dans les 2 racines GEA ; le cache ne doit garder qu'un exemplaire —
     * et surtout aucun cours fantôme ne doit apparaître en Toussaint.
     * Vérifié via le contenu du cache écrit.
     */
    public function test_dedup_inter_racines_dans_le_cache(): void
    {
        $f = $this->makeFetch(self::mondeReel());
        EdtCalendar::jourInfos('2026-11-03', $f['fn'], $this->tmp);

        $cache = json_decode((string) @file_get_contents($this->tmp . '/iut-gea.json'), true);
        self::assertIsArray($cache);
        $uids = array_column($cache['events'], 'uid');
        self::assertCount(5, $uids, '5 Fermeture × 2 racines → 5 uids uniques.');
        self::assertCount(count(array_unique($uids)), $uids, 'Aucun uid dupliqué dans le cache.');
        // Racines découvertes dynamiquement, pas en dur.
        self::assertSame(13001, $cache['roots'][0]['id']);
        self::assertSame(13002, $cache['roots'][1]['id']);
    }

    /** Prochain jour de cours après une date (borne : horizon du cache). */
    public function test_prochain_jour_cours(): void
    {
        $f = $this->makeFetch(self::mondeReel());

        self::assertSame(
            '2026-11-03',
            EdtCalendar::prochainJourCours('2026-10-30', $f['fn'], $this->tmp),
            'Après le vendredi 30/10 (Toussaint), reprise le mardi 03/11.'
        );
        self::assertSame(
            '2026-11-04',
            EdtCalendar::prochainJourCours('2026-11-03', $f['fn'], $this->tmp),
            'Strictement postérieur : le 04/11 (évènement GEII biné en heure locale).'
        );
        self::assertNull(
            EdtCalendar::prochainJourCours('2026-11-05', $f['fn'], $this->tmp),
            'Rien de publié après le 05/11 dans la fixture → null.'
        );
    }

    /**
     * Cache : frais (TTL 6 h), on ne refait AUCUN appel réseau ; expiré
     * avec API down, on sert le PÉRIMÉ avec stale=true (fail-closed).
     */
    public function test_cache_puis_stale_si_api_down(): void
    {
        $monde = self::mondeReel();
        $f = $this->makeFetch($monde);
        $infos = EdtCalendar::jourInfos('2026-11-03', $f['fn'], $this->tmp);
        self::assertTrue($infos['cours']);

        $appelsApresGel = ($f['count'])();
        self::assertGreaterThan(0, $appelsApresGel, 'La première lecture a régénéré (appels réels).');

        // Cache frais : zéro nouvel appel HTTP, mêmes infos.
        $f2 = $this->makeFetch([]); // tout appel échouerait : prouve le zéro-appel.
        $infos2 = EdtCalendar::jourInfos('2026-11-03', $f2['fn'], $this->tmp);
        self::assertTrue($infos2['cours'], 'Servi depuis le cache frais.');
        self::assertSame(0, ($f2['count'])(), 'Aucun appel réseau quand le cache est frais.');
        self::assertFalse($infos2['stale']);

        // TTL écoulé + API down : cache périmé servi, flag stale=true.
        EdtCalendar::reinitialiserMemo(); // la mémo ne doit pas masquer le fichier.
        $cacheFile = $this->tmp . '/iut-info.json';
        $cache = json_decode((string) file_get_contents($cacheFile), true);
        $cache['fetched_at'] = time() - EdtCalendar::CACHE_TTL_S - 1;
        file_put_contents($cacheFile, json_encode($cache));

        $f3 = $this->makeFetch([]); // API down : toutes les URL → null.
        $infos3 = EdtCalendar::jourInfos('2026-11-03', $f3['fn'], $this->tmp);
        self::assertTrue($infos3['cours'], 'Cache périmé servi plutôt que rien (fail-closed).');
        self::assertTrue($infos3['stale'], 'Le consommateur voit stale=true.');
    }

    /** API down SANS cache : calendrier vide silencieux, pas d'exception. */
    public function test_api_down_sans_cache(): void
    {
        $f = $this->makeFetch([]);
        $infos = EdtCalendar::jourInfos('2026-11-03', $f['fn'], $this->tmp);

        self::assertFalse($infos['cours']);
        self::assertSame(0, $infos['creneaux']);
        self::assertTrue($infos['stale']);
        self::assertNull(EdtCalendar::prochainJourCours('2026-11-03', $f['fn'], $this->tmp));
    }

    /**
     * Structure de la réponse jour : les clés du contrat public, somme
     * par_dept == creneaux, tous départements présents.
     */
    public function test_contrat_jour_infos(): void
    {
        $f = $this->makeFetch(self::mondeReel());
        $infos = EdtCalendar::jourInfos('2026-11-03', $f['fn'], $this->tmp);

        self::assertSame(
            ['date', 'cours', 'creneaux', 'par_dept', 'stale'],
            array_keys($infos)
        );
        self::assertSame(
            EdtCalendar::DEPTS,
            array_keys($infos['par_dept']),
            'par_dept liste toujours les 3 départements Calais.'
        );
        self::assertSame(array_sum($infos['par_dept']), $infos['creneaux']);
    }
}
