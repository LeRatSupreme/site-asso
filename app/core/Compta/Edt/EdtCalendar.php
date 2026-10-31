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
 *    ce ne sont PAS des cours → filtrés. Règle ANCRÉE, vérifiée sur le
 *    cache réel du 31/10/2026 (les 13 « Fermeture » ont toutes kind=null
 *    et un libellé qui COMMENCE par « fermeture ») : est filtré UNIQUEMENT
 *    un évènement sans type (kind=null) dont subject OU title commence par
 *    « fermeture » — un vrai cours « Fermeture des comptes annuels »
 *    (kind=TD) n'est jamais soustrait.
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
 *  - Horizon glissant : borne FUTURE J+60 forcée côté code (le paramètre
 *    « to » de l'API est ignoré) ; borne PASSÉE J-14 DEMANDÉE via le
 *    paramètre « from », honoré seulement approximativement par l'API
 *    (cache réel du 31/10 : min = J-19 pour un from = J-14). Choix
 *    documenté : PAS de filtre passé côté code — le léger dépassement est
 *    sans effet (la marge ne sert qu'à couvrir la semaine en cours, les
 *    lectures interrogent des dates explicites ou l'avenir), et filtrer
 *    côté code rendrait les fixtures de tests datées invalides avec le
 *    temps. Un jour J-14 strict est exigé, l'ajouter ICI avec un filtre
 *    strcmp($jour, $from) < 0 dans regenerer().
 *  - Budget global de régénération ~8 s PAR LECTURE de calendrier (cf.
 *    BUDGET_REGEN_S) : les ~15 appels séquentiels partagent une échéance
 *    commune contrôlée avant chaque appel — le pire-cas « 15 appels ×
 *    timeout unitaire 10 s ≈ 150 s » sur UNE requête web est impossible.
 *  - Fail-closed silencieux : API down / JSON invalide / réponse 200
 *    valide mais PARTIELLE (sans clé « events » ou arbre sans « groups »)
 *    → échec de régénération → on sert le cache PÉRIMÉ avec le flag
 *    « stale=true » (jamais écraser un bon cache avec des données
 *    partielles) ; sans cache du tout on renvoie un calendrier vide
 *    (stale=true), jamais d'exception.
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

    /**
     * Budget global (secondes) de régénération pour UNE lecture de
     * calendrier : les ~15 appels séquentiels (3 dept × (1 arbre + ses
     * racines)) partagent cette échéance, contrôlée AVANT chaque appel.
     * Au-delà → abort → cache stale servi. Le timeout UNITAIRE curl
     * reste 10 s : un appel démarré juste avant l'échéance peut la
     * dépasser, mais un seul (les suivants sont avortés) et la mémo de
     * requête empêche de re-dépenser le budget d'une lecture à l'autre.
     */
    public const BUDGET_REGEN_S = 8.0;

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
        if ($jour === null) {
            // Date illisible : court-circuit AVANT tout chargement (cache
            // ou réseau) — forme vide du contrat, jamais d'exception.
            return [
                'date'     => $date,
                'cours'    => false,
                'creneaux' => 0,
                'par_dept' => array_fill_keys(self::DEPTS, 0),
                'stale'    => false,
            ];
        }
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
     * vacances en dur — 5 jours ouvrés complets sans créneau = vacances ?),
     * et flag hors_horizon : la semaine est au-delà du dernier jour publié
     * connu (tous évènements confondus, fermetures incluses) → « pas encore
     * publié », à ne pas confondre avec « vacances » ; le label s'adapte.
     * Date illisible : forme vide SANS charger le calendrier (cohérent
     * avec jourInfos), jamais d'exception.
     *
     * @param string        $lundi    Lundi « AAAA-MM-JJ » (tout autre jour est
     *                                rabattu sur le lundi de sa semaine).
     * @param callable|null $fetchUrl Injecteur — défaut curl.
     * @param string|null   $cacheDir Dossier cache.
     *
     * @return array{lundi:string, avec_cours:bool, jours_actifs:int,
     *               creneaux_total:int, par_jour:array<string,int>,
     *               label_auto:string, hors_horizon:bool, stale:bool}
     */
    public static function semaineInfos(string $lundi, ?callable $fetchUrl = null, ?string $cacheDir = null): array
    {
        $jour = self::dateValide($lundi);
        if ($jour === null) {
            // Date illisible : forme vide SANS charger le calendrier —
            // sinon DateTimeImmutable($lundi) lèverait plus bas.
            return [
                'lundi'          => $lundi,
                'avec_cours'     => false,
                'jours_actifs'   => 0,
                'creneaux_total' => 0,
                'par_jour'       => [],
                'label_auto'     => 'Date invalide',
                'hors_horizon'   => true,
                'stale'          => false,
            ];
        }
        $lun = (new \DateTimeImmutable($jour))->modify('monday this week')->format('Y-m-d');
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

        // Horizon publié = dernier jour local connu dans le cache, tous
        // évènements confondus (fermetures incluses : une semaine publiée
        // « en fermeture » est une vacances CONNUE, pas un trou). Vide →
        // rien de publié nulle part → tout est hors horizon.
        $horizon = $cal['horizon'];
        $horsHorizon = $horizon === null || strcmp($lun, $horizon) > 0;

        return [
            'lundi'          => $lun,
            'avec_cours'     => $total > 0,
            'jours_actifs'   => $actifs,
            'creneaux_total' => $total,
            'par_jour'       => $parJour,
            'label_auto'     => $total > 0
                ? 'Semaine de cours'
                : ($horsHorizon ? 'Au-delà de l\'horizon publié' : 'Semaine sans cours (vacances ?)'),
            'hors_horizon'   => $horsHorizon,
            'stale'          => $cal['stale'],
        ];
    }

    /**
     * Plage PUBLIÉE du calendrier chargé : premier et dernier jour local
     * connus, TOUS évènements confondus y compris les « Fermeture » (une
     * semaine publiée « en fermeture » est une vacances CONNUE, pas un
     * trou — c'est le même critère que l'horizon de semaineInfos).
     * Point d'assemblage pour les contextes dérivés (CalendarContext) :
     * savoir qu'une semaine est ENTIÈREMENT publiée (lundi ≥ debut et
     * vendredi ≤ fin) avant de la déclarer « morte », et ne lister que
     * les semaines mortes évaluables d'une période affichée. Calendrier
     * vide (API down sans cache) → [null, null] : tout est alors hors
     * horizon, les consommateurs prudents restent neutres.
     *
     * @return array{debut:?string, fin:?string, stale:bool}
     */
    public static function plagePubliee(?callable $fetchUrl = null, ?string $cacheDir = null): array
    {
        $cal = self::calendrier($fetchUrl, $cacheDir);

        return ['debut' => $cal['debut'], 'fin' => $cal['horizon'], 'stale' => $cal['stale']];
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
     * des évènements « Fermeture » qui ne sont pas des cours. Les ~15
     * appels réseau éventuels partagent le budget BUDGET_REGEN_S.
     * « debut »/« horizon » bornent la plage PUBLIÉE en comptant TOUT
     * évènement connu (fermetures comprises) — cf. plagePubliee().
     *
     * @return array{events:array<string,list<array{_jour:string}>>,
     *               debut:?string, horizon:?string, stale:bool}
     */
    private static function calendrier(?callable $fetchUrl, ?string $cacheDir): array
    {
        $fetch = $fetchUrl ?? [self::class, 'httpGet'];
        $dir = $cacheDir ?? (AEIC_ROOT . '/cache/edt');
        // Échéance commune aux régénérations des 3 départements d'UNE
        // lecture : contrôle avant chaque appel, abort → stale servi.
        $echeance = microtime(true) + self::BUDGET_REGEN_S;

        $events = [];
        $stale = false;
        $horizon = null;
        $debut = null;
        foreach (self::DEPTS as $dept) {
            $payload = self::chargerDept($dept, $fetch, $dir, $echeance);
            $stale = $stale || $payload['stale'];
            $events[$dept] = [];
            foreach ($payload['events'] as $e) {
                $jour = self::jourLocal($e['start'] ?? null);
                if ($jour !== null) {
                    if ($horizon === null || strcmp($jour, $horizon) > 0) {
                        $horizon = $jour; // dernier jour publié, fermetures incluses.
                    }
                    if ($debut === null || strcmp($jour, $debut) < 0) {
                        $debut = $jour; // premier jour connu, fermetures incluses.
                    }
                }
                if (!self::estCours($e)) {
                    continue; // « Fermeture IUT … » : pas un cours.
                }
                if ($jour === null) {
                    continue;
                }
                $e['_jour'] = $jour;
                $events[$dept][] = $e;
            }
        }

        return ['events' => $events, 'debut' => $debut, 'horizon' => $horizon, 'stale' => $stale];
    }

    /**
     * Charge un département : cache frais → direct ; sinon régénération
     * (1 appel /groups + 1 appel par racine) ; en cas d'échec, cache
     * périmé servi avec stale=true (fail-closed silencieux).
     *
     * @param callable(string):?string $fetch
     * @param float                    $echeance Date limite (microtime) du
     *                                           budget global de lecture.
     *
     * @return array{fetched_at:int, roots:list<array{id:int,name:string}>,
     *               events:list<array>, stale:bool}
     */
    private static function chargerDept(string $dept, callable $fetch, string $dir, float $echeance): array
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
            $frais = self::regenerer($dept, $fetch, $echeance);
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
     * @param float                    $echeance Date limite (microtime) du
     *                                           budget global de lecture :
     *                                           contrôlée avant chaque appel,
     *                                           dépassée → null (abort → stale).
     *
     * @return array{fetched_at:int, roots:list<array{id:int,name:string}>, events:list<array>}|null
     */
    private static function regenerer(string $dept, callable $fetch, float $echeance): ?array
    {
        if (microtime(true) >= $echeance) {
            return null; // budget global épuisé : abort, le stale sera servi.
        }
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
        // Borne passée J-14 DEMANDÉE à l'API via « from » (honorée
        // approximativement — cf. docblock de la classe : choix documenté,
        // pas de filtre passé côté code).
        $from = $now->modify('-' . self::PASSE_JOURS . ' day')->format('Y-m-d');
        // Borne future J+60 : forcée côté code (« to » ignoré par l'API).
        $limite = $now->modify('+' . self::HORIZON_JOURS . ' day')->format('Y-m-d');

        $parUid = [];
        foreach ($racines as $r) {
            if (microtime(true) >= $echeance) {
                return null; // budget global épuisé : abort, le stale sera servi.
            }
            $sched = self::jsonFetch(
                $fetch,
                self::API_BASE . '/' . $dept . '/groups/' . $r['id'] . '/schedule?from=' . $from
            );
            if ($sched === null) {
                return null; // une racine manque → régénération avortée.
            }
            if (!is_array($sched['events'] ?? null)) {
                // 200 + JSON valide MAIS sans clé « events » : réponse
                // partielle = ÉCHEC, pas « 0 évènement » — sinon on
                // écraserait un bon cache avec du vide.
                return null;
            }
            foreach ($sched['events'] as $e) {
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
     * cours ». Règle ANCRÉE (cache réel 31/10/2026 : les 13 Fermeture ont
     * toutes kind=null et un libellé commençant par « fermeture ») : est
     * filtré UNIQUEMENT un évènement sans type (kind=null) dont subject
     * OU title COMMENCE par « fermeture » — un vrai cours « Fermeture des
     * comptes annuels » (kind=TD) n'est jamais soustrait.
     *
     * @param array<string,mixed> $e
     */
    private static function estCours(array $e): bool
    {
        if (($e['kind'] ?? null) !== null) {
            return true; // un évènement typé (CM/TD/TP/…) est un cours.
        }
        foreach ([$e['subject'] ?? null, $e['title'] ?? null] as $libelle) {
            if (is_string($libelle) && str_starts_with(mb_strtolower($libelle), 'fermeture')) {
                return false;
            }
        }

        return true;
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
