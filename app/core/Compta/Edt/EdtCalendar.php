<?php

declare(strict_types=1);

namespace App\Core\Compta\Edt;

/**
 * Cœur calendrier universitaire AEIC — point d'assemblage UNIQUE de l'EDT
 * ULCO Calais (IUT INFO + GEA + GEII, source https://edt-ulco.fr, API
 * publique sans clé). Toute page analytics qui a besoin de « y a-t-il
 * cours aujourd'hui / cette semaine / prochain cours » passe par ici —
 * jamais de recalcul parallèle dans un contrôleur.
 *
 * DÉCOUVERTE API (validée en réel le 30/10/2026, documentée pour v1) :
 *  - GET /api/departments → ids Calais : iut-info, iut-gea, iut-geii.
 *  - GET /api/{dept}/groups → arbre des groupes ; les RACINES de
 *    « groups » sont les nœuds année/formation (INFO : BUT1/2/3 ;
 *    GEA : 6 racines FI+FC × 3 ans ; GEII : 3 racines, avec un niveau
 *    « Semestre » intermédiaire). Les ids changent chaque année → ils
 *    sont découverts DYNAMIQUEMENT à chaque régénération, jamais en dur.
 *  - GET /api/{dept}/groups/{id}/schedule?from=AAAA-MM-JJ → événements
 *    {uid, start, end, title, subject, kind, room, groups[]} en UTC ISO.
 *    Un id de NŒUD ANNÉE renvoie TOUT son sous-arbre (BUT2=819 → 559
 *    événements, 7 sous-groupes) → 1 appel par racine suffit.
 *    Les ids séparés par virgules SONT REFUSÉS (400 « Identifiant de
 *    ressource invalide » / 404) ; le paramètre « to » est ignoré.
 *    Stratégie retenue (minimum d'appels possible) : 1 appel par racine,
 *    soit 12 au total (3 INFO + 6 GEA + 3 GEII) par régénération.
 *  - Les réponses de racines DISTINCTES se CHEVAUCHENT (un évènement
 *    multi-groupes apparaît dans chaque sous-arbre concerné : 352 uids
 *    en doublant sur les 6 racines GEA) → déduplication par « uid »
 *    obligatoire à l'agrégation.
 *  - Les semaines de fermeture (Toussaint, Noël) portent des évènements
 *    « Fermeture IUT vacances scolaires » (kind=null, tous groupes) :
 *    ce ne sont PAS des cours → filtrés (subject/title ~ « fermeture »).
 *    Aucun nom de vacances n'est codé en dur : « sans cours » est un
 *    fait dérivé des données après filtrage.
 *  - Flux alternatif /api/{dept}/groups/{id}/calendar.ics ÉCARTÉ :
 *    même périmètre/poids que schedule (BUT2 : 559 VEVENTS, 133 Ko)
 *    mais format à parser soi-même et champs structurés perdus.
 *  - Horizon publié variable selon le dept (INFO ~11 sem., GEII ~4 sem.
 *    au 30/10) : on agrège ce qui est publié, « prochain cours » est
 *    donc borné par cet horizon.
 *
 * CACHE (cache/edt/{dept}.json, un fichier par département) :
 *  - TTL 6 h, régénération LAZY au premier appel après expiration (pas
 *    de cron dédié en v1 : chaque lecture expire au plus 6 h après la
 *    dernière, suffisant pour de l'analytique de tendance).
 *  - Horizon glissant : événements de J-14 (marge pour la semaine en
 *    cours) à J+60 à partir de la date de régénération.
 *  - Fail-closed silencieux : API down / JSON invalide → on sert le
 *    cache PÉRIMÉ avec le flag « stale=true » ; sans cache du tout on
 *    renvoie un calendrier vide (stale=true), jamais d'exception.
 *
 * Les dates des évènements sont converties en Europe/Paris (campus de
 * Calais) avant regroupement par jour : un évènement à 23:00 UTC tombe
 * le jour suivant en heure locale.
 */
final class EdtCalendar
{
    /** Départements agrégés (ids Calais confirmés par /api/departments). */
    public const DEPTS = ['iut-info', 'iut-gea', 'iut-geii'];

    private const API_BASE = 'https://edt-ulco.fr/api';
    private const TZ = 'Europe/Paris';

    /** TTL du cache fichier : 6 h. */
    public const CACHE_TTL_S = 21600;

    /** Horizon glissant conservé dans le cache (jours après « maintenant »). */
    public const HORIZON_JOURS = 60;

    /** Marge de passé conservée (jours avant « maintenant »). */
    public const PASSE_JOURS = 14;

    /** Version de structure du cache (invalide les fichiers antérieurs). */
    private const CACHE_VERSION = 1;

    /** @var array<string,array{payload:array,stale:bool}> mémo par requête (dept|dir) */
    private static array $memo = [];

    /**
     * Injecteur HTTP par défaut : curl maison (conventions du projet),
     * timeout court, échec silencieux (null). Remplaçable en test.
     *
     * @param string $url URL absolue.
     * @return string|null Corps brut, ou null (échec réseau/HTTP ≠ 200).
     */
    public static function httpGet(string $url): ?string
    {
        if ($url === '' || !function_exists('curl_init')) {
            return null;
        }
        $ch = @curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'AEIC-edt',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 2,
        ]);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ($body !== false && $http === 200 && is_string($body)) ? $body : null;
    }

    /**
     * Infos d'un jour : y a-t-il cours, combien de créneaux, par département.
     *
     * @param string        $date     Jour « AAAA-MM-JJ ».
     * @param callable|null $fetchUrl Injecteur (string $url): ?string — défaut curl.
     * @param string|null   $cacheDir Dossier cache — défaut AEIC_ROOT/cache/edt.
     *
     * @return array{date:string, cours:bool, creneaux:int,
     *               par_dept:array<string,int>, stale:bool}
     */
    public static function jourInfos(string $date, ?callable $fetchUrl = null, ?string $cacheDir = null): array
    {
        $jour = self::dateValide($date);
        $cal = self::calendrier($fetchUrl, $cacheDir);

        $parDept = [];
        foreach (self::DEPTS as $dept) {
            $parDept[$dept] = 0;
            foreach ($cal['events'][$dept] ?? [] as $e) {
                if (($e['_jour'] ?? null) === $jour) {
                    $parDept[$dept]++;
                }
            }
        }

        return [
            'date'     => $jour ?? $date,
            'cours'    => array_sum($parDept) > 0,
            'creneaux' => array_sum($parDept),
            'par_dept' => $parDept,
            'stale'    => $cal['stale'],
        ];
    }

    /**
     * Infos d'une semaine (lundi → vendredi ouvrés) : cours, jours actifs,
     * total créneaux, label automatique DÉRIVÉ DES DONNÉES (aucun nom de
     * vacances en dur — 5 jours ouvrés complets sans créneau = vacances ?).
     *
     * @param string        $lundi    Lundi « AAAA-MM-JJ » (tout autre jour est
     *                                rabattu sur le lundi de sa semaine).
     * @param callable|null $fetchUrl Injecteur — défaut curl.
     * @param string|null   $cacheDir Dossier cache.
     *
     * @return array{lundi:string, avec_cours:bool, jours_actifs:int,
     *               creneaux_total:int, par_jour:array<string,int>,
     *               label_auto:string, stale:bool}
     */
    public static function semaineInfos(string $lundi, ?callable $fetchUrl = null, ?string $cacheDir = null): array
    {
        $jour = self::dateValide($lundi);
        $lun = $jour !== null
            ? (new \DateTimeImmutable($jour))->modify('monday this week')->format('Y-m-d')
            : $lundi;
        $cal = self::calendrier($fetchUrl, $cacheDir);

        $parJour = [];
        for ($i = 0; $i < 5; $i++) {
            $d = (new \DateTimeImmutable($lun))->modify("+{$i} day")->format('Y-m-d');
            $parJour[$d] = 0;
            foreach (self::DEPTS as $dept) {
                foreach ($cal['events'][$dept] ?? [] as $e) {
                    if (($e['_jour'] ?? null) === $d) {
                        $parJour[$d]++;
                    }
                }
            }
        }

        $total = array_sum($parJour);
        $actifs = count(array_filter($parJour, static fn (int $n): bool => $n > 0));

        return [
            'lundi'          => $lun,
            'avec_cours'     => $total > 0,
            'jours_actifs'   => $actifs,
            'creneaux_total' => $total,
            'par_jour'       => $parJour,
            'label_auto'     => $total === 0
                ? 'Semaine sans cours (vacances ?)'
                : 'Semaine de cours',
            'stale'          => $cal['stale'],
        ];
    }

    /**
     * Première date STRICTEMENT postérieure à $date avec au moins un
     * créneau (tous départements confondus), bornée par l'horizon publié
     * (null si aucun cours connu dans l'horizon du cache).
     *
     * @return string|null « AAAA-MM-JJ » ou null.
     */
    public static function prochainJourCours(string $date, ?callable $fetchUrl = null, ?string $cacheDir = null): ?string
    {
        $jour = self::dateValide($date);
        if ($jour === null) {
            return null;
        }
        $cal = self::calendrier($fetchUrl, $cacheDir);

        $dates = [];
        foreach (self::DEPTS as $dept) {
            foreach ($cal['events'][$dept] ?? [] as $e) {
                $d = $e['_jour'] ?? null;
                if ($d !== null && strcmp($d, $jour) > 0) {
                    $dates[$d] = true;
                }
            }
        }
        if ($dates === []) {
            return null;
        }
        $min = array_keys($dates);
        sort($min);

        return $min[0];
    }

    /**
     * Vide la mémo interne (payloads par département). Usage : tests qui
     * manipulent le cache fichier entre deux lectures, ou CLI longuive.
     */
    public static function reinitialiserMemo(): void
    {
        self::$memo = [];
    }

    /**
     * Charge le calendrier des 3 départements (cache → lazy fetch),
     * normalise chaque évènement : jour local (Europe/Paris) et filtrage
     * des évènements « Fermeture » qui ne sont pas des cours.
     *
     * @return array{events:array<string,list<array{_jour:string}>>, stale:bool}
     */
    private static function calendrier(?callable $fetchUrl, ?string $cacheDir): array
    {
        $fetch = $fetchUrl ?? [self::class, 'httpGet'];
        $dir = $cacheDir ?? (AEIC_ROOT . '/cache/edt');

        $events = [];
        $stale = false;
        foreach (self::DEPTS as $dept) {
            $payload = self::chargerDept($dept, $fetch, $dir);
            $stale = $stale || $payload['stale'];
            $events[$dept] = [];
            foreach ($payload['events'] as $e) {
                if (!self::estCours($e)) {
                    continue; // « Fermeture IUT … » : pas un cours.
                }
                $jour = self::jourLocal($e['start'] ?? null);
                if ($jour === null) {
                    continue;
                }
                $e['_jour'] = $jour;
                $events[$dept][] = $e;
            }
        }

        return ['events' => $events, 'stale' => $stale];
    }

    /**
     * Charge un département : cache frais → direct ; sinon régénération
     * (1 appel /groups + 1 appel par racine) ; en cas d'échec, cache
     * périmé servi avec stale=true (fail-closed silencieux).
     *
     * @param callable(string):?string $fetch
     *
     * @return array{fetched_at:int, roots:list<array{id:int,name:string}>,
     *               events:list<array>, stale:bool}
     */
    private static function chargerDept(string $dept, callable $fetch, string $dir): array
    {
        $cle = $dept . '|' . $dir;
        if (isset(self::$memo[$cle])) {
            return self::$memo[$cle];
        }

        $fichier = $dir . '/' . $dept . '.json';
        $cache = self::lireCache($fichier);
        $stale = false;

        if ($cache !== null && (time() - $cache['fetched_at']) < self::CACHE_TTL_S) {
            $payload = $cache; // frais : servi tel quel.
        } else {
            $frais = self::regenerer($dept, $fetch);
            if ($frais !== null) {
                $payload = $frais;
                self::ecrireCache($fichier, $frais, $dir);
            } elseif ($cache !== null) {
                $payload = $cache; // API down : on sert le périmé.
                $stale = true;
            } else {
                // Aucune donnée nulle part : calendrier vide silencieux.
                $payload = ['fetched_at' => 0, 'roots' => [], 'events' => []];
                $stale = true;
            }
        }
        $payload['stale'] = $stale;

        return self::$memo[$cle] = $payload;
    }

    /**
     * Régénère un département : découvre les racines de l'arbre (jamais
     * en dur), agrège leur EDT (1 appel par racine), déduplique par uid
     * (les racines se chevauchent : évènements multi-groupes).
     *
     * @param callable(string):?string $fetch
     *
     * @return array{fetched_at:int, roots:list<array{id:int,name:string}>, events:list<array>}|null
     */
    private static function regenerer(string $dept, callable $fetch): ?array
    {
        $tree = self::jsonFetch($fetch, self::API_BASE . '/' . $dept . '/groups');
        if ($tree === null) {
            return null;
        }
        $racines = [];
        foreach (is_array($tree['groups'] ?? null) ? $tree['groups'] : [] as $g) {
            if (is_array($g) && isset($g['id'])) {
                $racines[] = ['id' => (int) $g['id'], 'name' => (string) ($g['name'] ?? '')];
            }
        }
        if ($racines === []) {
            return null; // arbre vide/inattendu : ne pas écraser le cache.
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
        $from = $now->modify('-' . self::PASSE_JOURS . ' day')->format('Y-m-d');
        $limite = $now->modify('+' . self::HORIZON_JOURS . ' day')->format('Y-m-d');

        $parUid = [];
        foreach ($racines as $r) {
            $sched = self::jsonFetch(
                $fetch,
                self::API_BASE . '/' . $dept . '/groups/' . $r['id'] . '/schedule?from=' . $from
            );
            if ($sched === null) {
                return null; // une racine manque → régénération avortée.
            }
            foreach (is_array($sched['events'] ?? null) ? $sched['events'] : [] as $e) {
                if (!is_array($e) || !is_string($e['uid'] ?? null)) {
                    continue;
                }
                $jour = self::jourLocal($e['start'] ?? null);
                if ($jour === null || strcmp($jour, $limite) > 0) {
                    continue; // hors horizon glissant.
                }
                // Dédup inter-racines : premier exemplaire gagne.
                $parUid[$e['uid']] ??= $e;
            }
        }

        return [
            'fetched_at' => time(),
            'roots'      => $racines,
            'events'     => array_values($parUid),
        ];
    }

    /**
     * Un évènement « Fermeture … » (vacances scolaires) n'est pas un
     * cours : il ne compte ni dans les créneaux ni dans « y a-t-il
     * cours ». Détection par libellé, sans liste de vacances en dur.
     *
     * @param array<string,mixed> $e
     */
    private static function estCours(array $e): bool
    {
        $libelle = mb_strtolower(((string) ($e['subject'] ?? '')) . ' ' . ((string) ($e['title'] ?? '')));

        return !str_contains($libelle, 'fermeture');
    }

    /**
     * Jour local (Europe/Paris) du début d'un évènement UTC ISO8601.
     */
    private static function jourLocal(mixed $start): ?string
    {
        if (!is_string($start) || $start === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($start))
                ->setTimezone(new \DateTimeZone(self::TZ))
                ->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Valide « AAAA-MM-JJ » (retourne la forme canonique ou null).
     */
    private static function dateValide(string $date): ?string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($d === false) {
            return null;
        }

        return $d->format('Y-m-d');
    }

    /**
     * @param callable(string):?string $fetch
     *
     * @return array<string,mixed>|null
     */
    private static function jsonFetch(callable $fetch, string $url): ?array
    {
        $brut = $fetch($url);
        if (!is_string($brut) || $brut === '') {
            return null;
        }
        try {
            $data = json_decode($brut, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * @return array{fetched_at:int, roots:list<array{id:int,name:string}>, events:list<array>}|null
     */
    private static function lireCache(string $fichier): ?array
    {
        $brut = @file_get_contents($fichier);
        if ($brut === false) {
            return null;
        }
        try {
            $data = json_decode($brut, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (
            !is_array($data)
            || ($data['version'] ?? null) !== self::CACHE_VERSION
            || !is_int($data['fetched_at'] ?? null)
            || !is_array($data['events'] ?? null)
        ) {
            return null;
        }

        return $data;
    }

    /**
     * @param array{fetched_at:int, roots:list<array{id:int,name:string}>, events:list<array>} $payload
     */
    private static function ecrireCache(string $fichier, array $payload, string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return; // cache impossible : on vit sans (en mémoire le temps
                    // de la requête, via la mémo).
        }
        $payload['version'] = self::CACHE_VERSION;
        @file_put_contents($fichier, json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: '{}');
    }
}
