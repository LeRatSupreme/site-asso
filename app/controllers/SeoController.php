<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Logger;
use App\Models\Event;
use App\Models\Page;
use App\Models\Poll;
use App\Models\Setting;

/**
 * SEO : sitemap.xml dynamique et recherche globale.
 *
 * Liste les pages statiques, les événements publiés, les sondages publiés
 * et les pages CMS publiées.
 *
 * Résilience (S) : la génération interroge des modèles « silencieux »
 * (Event::published, Poll::published… renvoient [] en cas de panne de la
 * base, sans exception) — sous charge, un sitemap AMPUTÉ pouvait donc être
 * servi à Google (465 o observés dans les logs le 29/10, contre 2 571 o à
 * froid ; statut « Impossible de récupérer » collant dans la Search
 * Console). Dorénavant :
 *   - chaque génération COMPLÈTE est mise en cache (« dernier bon » XML,
 *     cache/seo/sitemap.xml, écriture atomique) ;
 *   - chaque étape en échec est journalisée (Logger::warning, étape + erreur,
 *     jamais de stack trace) ;
 *   - une génération dégradée sert le dernier bon XML du cache, avec un
 *     max-age réduit (300 s) pour que Google retente vite ;
 *   - sans cache de secours (premier déploiement), on sert ce qui est
 *     générable — JAMAIS de 500.
 */
final class SeoController extends Controller
{
    /**
     * Garde-fou « taille » : poids minimal (octets) d'un sitemap complet.
     * Les 16 pages statiques pèsent ~2,5 Ko ; le XML amputé observé sous
     * charge faisait 465 o. 1 Ko laisse une marge sous ce plancher.
     */
    private const MIN_COMPLETE_BYTES = 1024;

    /**
     * Durée de vie maximale du cache « dernier bon » (7 jours). Au-delà, le
     * cache est ignoré : mieux vaut servir un XML fraîchement générable
     * (incomplet mais exact) que des URLs disparues depuis une semaine.
     */
    private const CACHE_MAX_AGE_S = 7 * 86400;

    /** Chemin du cache « dernier bon » (redirigeable pour les tests). */
    private static ?string $cachePath = null;

    /** Sonde « base disponible », remplaçable pour les tests unitaires. */
    private static ?\Closure $dbProbe = null;

    /**
     * Redirige le cache dernier-bon (tests unitaires : tests n'écrivent
     * jamais dans le vrai cache/seo du serveur).
     */
    public static function setCachePathForTests(string $path): void
    {
        self::$cachePath = $path;
    }

    /**
     * Remplace la sonde de base pour les tests unitaires (sans base).
     */
    public static function setDbProbeForTests(?\Closure $probe): void
    {
        self::$dbProbe = $probe;
    }

    /**
     * Annule les branchements de test (cache + sonde).
     */
    public static function resetTestHooks(): void
    {
        self::$cachePath = null;
        self::$dbProbe = null;
    }

    /**
     * Chemin du cache dernier-bon.
     */
    public static function cachePath(): string
    {
        if (self::$cachePath !== null) {
            return self::$cachePath;
        }
        $root = defined('AEIC_ROOT') ? AEIC_ROOT : dirname(__DIR__, 2);

        return $root . '/cache/seo/sitemap.xml';
    }

    public function sitemap(): void
    {
        try {
            $gen = self::generate();
        } catch (\Throwable $e) {
            // Improbable (chaque étape est déjà sous try/catch), mais on ne
            // doit JAMAIS répondre 500 à un crawler sur /sitemap.xml.
            Logger::warning('sitemap: échec inattendu de la génération', ['erreur' => self::errBrief($e)]);
            $gen = null;
        }

        // La session n'est pas démarrée pour ces routes (cf. database.php) :
        // un sitemap ne doit porter NI cookie PHPSESSID (atypique pour les
        // fetchers stricts), NI les « Pragma: no-cache » / « Expires: 1981 »
        // que session_start() pose et qui contredisent le Cache-Control
        // public. Retrait explicite : le garde-fou reste lisible même si la
        // session revenait un jour sur ce chemin.
        header_remove('Pragma');
        header_remove('Expires');
        header('Content-Type: application/xml; charset=utf-8');
        // PAS de X-Robots-Tag: noindex ici : un sitemap ne doit jamais être
        // noindexé, Google Search Console refuse alors de le lire.

        if ($gen !== null && $gen['ok']) {
            // Chemin nominal — en-têtes inchangés : Cache-Control public
            // est la SEULE directive de cache posée.
            header('Cache-Control: public, max-age=3600');
            self::writeCache($gen['xml']);
            echo $gen['xml'];

            return;
        }

        // Génération dégradée : on sert le DERNIER BON sitemap du cache.
        // max-age réduit (5 min) : Google doit retenter une régénération
        // complète au plus vite, pas dans une heure.
        $raisons = $gen['raisons'] ?? ['exception_generation'];
        $cache = $gen['cache'] ?? null;
        header('Cache-Control: public, max-age=300');

        if ($cache !== null) {
            Logger::warning('sitemap: génération dégradée, cache servi', [
                'raisons'         => $raisons,
                'age_cache_s'     => $cache['age'],
                'taille_cache'    => strlen($cache['xml']),
            ]);
            echo $cache['xml'];

            return;
        }

        // Premier déploiement (cache absent) ou cache corrompu/périmé :
        // on sert ce qui est générable — jamais de 500.
        Logger::warning('sitemap: génération dégradée, aucun cache de secours', ['raisons' => $raisons]);
        echo $gen['xml'] ?? self::fallbackSitemap();
    }

    /**
     * Génère le XML du sitemap (testable isolément, sans effet de bord HTTP).
     *
     * Une génération COMPLÈTE met à jour le cache « dernier bon » ; une
     * génération dégradée est journalisée et le XML générable est renvoyé
     * tel quel (c'est sitemap() qui décide de servir le cache à la place).
     */
    public static function buildSitemap(): string
    {
        $gen = self::generate();

        if ($gen['ok']) {
            self::writeCache($gen['xml']);
        } else {
            Logger::warning('sitemap: génération dégradée', ['raisons' => $gen['raisons']]);
        }

        return $gen['xml'];
    }

    /**
     * Génère et évalue le sitemap.
     *
     * @return array{xml: string, ok: bool, raisons: list<string>, cache: array{xml: string, age: int, urls_dynamiques: int}|null}
     */
    private static function generate(): array
    {
        $base = rtrim(APP_URL, '/');
        $raisons = [];

        // Convertit un chemin local (/uploads/x.jpg) en URL absolue.
        $abs = static function (string $u) use ($base): string {
            $u = trim($u);
            if ($u === '') {
                return '';
            }

            return preg_match('#^https?://#i', $u) === 1 ? $u : $base . '/' . ltrim($u, '/');
        };

        // Sonde base : les modèles avalent leurs exceptions et renvoient []
        // (« échouent silencieusement ») — impossible de distinguer « pas de
        // contenu » d'une panne depuis eux. Une requête SELECT 1 fait lever
        // l'erreur réelle (journalisée) et évite d'interroger six fois une
        // base déjà morte.
        $dbUp = self::dbUp($raisons);
        if ($dbUp) {
            $events = self::fetch('evenements', $raisons, static fn (): array => Event::published());
            $polls  = self::fetch('sondages', $raisons, static fn (): array => Poll::published());
        } else {
            $events = [];
            $polls  = [];
        }

        // Dernière mise à jour réelle des listes d'événements et de sondages
        // (sert de lastmod aux pages index).
        $eventsLastmod = null;
        $pollsLastmod = null;
        foreach ($events as $ev) {
            foreach (['updated_at', 'date'] as $k) {
                if (!empty($ev[$k])) {
                    $ts = strtotime((string) $ev[$k]);
                    if ($ts !== false && ($eventsLastmod === null || $ts > strtotime((string) $eventsLastmod))) {
                        $eventsLastmod = date('Y-m-d', $ts);
                    }
                }
            }
        }
        foreach ($polls as $poll) {
            if (!empty($poll['updated_at'])) {
                $ts = strtotime((string) $poll['updated_at']);
                if ($ts !== false && ($pollsLastmod === null || $ts > strtotime((string) $pollsLastmod))) {
                    $pollsLastmod = date('Y-m-d', $ts);
                }
            }
        }

        // Pages statiques publiques.
        $static = [
            '/'                 => ['priority' => '1.0', 'changefreq' => 'daily'],
            '/events'           => ['priority' => '0.9', 'changefreq' => 'daily', 'lastmod' => $eventsLastmod],
            '/presentation'     => ['priority' => '0.8', 'changefreq' => 'monthly'],
            '/sondages'         => ['priority' => '0.8', 'changefreq' => 'daily', 'lastmod' => $pollsLastmod],
            '/team'             => ['priority' => '0.7', 'changefreq' => 'monthly'],
            '/jeux'             => ['priority' => '0.7', 'changefreq' => 'weekly'],
            '/galerie'          => ['priority' => '0.6', 'changefreq' => 'weekly', 'images' => true],
            '/jeux/wordle'      => ['priority' => '0.6', 'changefreq' => 'daily'],
            '/jeux/enigme'      => ['priority' => '0.6', 'changefreq' => 'daily'],
            '/jeux/memory'      => ['priority' => '0.6', 'changefreq' => 'weekly'],
            '/jeux/snake'       => ['priority' => '0.6', 'changefreq' => 'weekly'],
            '/jeux/tetris'      => ['priority' => '0.6', 'changefreq' => 'weekly'],
            '/legal'            => ['priority' => '0.4', 'changefreq' => 'yearly'],
            '/privacy'          => ['priority' => '0.4', 'changefreq' => 'yearly'],
            '/cgu'              => ['priority' => '0.4', 'changefreq' => 'yearly'],
            '/jeux/leaderboard' => ['priority' => '0.4', 'changefreq' => 'daily'],
        ];

        $urls = [];

        // Image Open Graph de l'accueil (Setting::get échoue silencieusement :
        // absence d'image = pas une dégradation, juste un enrichissement absent).
        $ogImage = $abs(Setting::get('og_image', ''));
        if ($ogImage !== '') {
            $static['/']['images'] = [['loc' => $ogImage]];
        }

        foreach ($static as $p => $meta) {
            $urls[] = [
                'loc'       => $base . $p,
                'priority'  => $meta['priority'],
                'changefreq' => $meta['changefreq'],
                'lastmod'   => $meta['lastmod'] ?? null,
                'images'    => $meta['images'] ?? [],
            ];
        }
        $staticPaths = array_map(static fn (string $p): string => ltrim($p, '/'), array_keys($static));

        // Événements publiés (+ visuel de l'événement en image sitemap).
        foreach ($events as $ev) {
            if (!empty($ev['slug'])) {
                $images = [];
                if (!empty($ev['image'])) {
                    $img = $abs((string) $ev['image']);
                    if ($img !== '') {
                        $images[] = ['loc' => $img, 'title' => (string) ($ev['title'] ?? '')];
                    }
                }
                $urls[] = [
                    'loc'      => $base . '/events/' . rawurlencode((string) $ev['slug']),
                    'priority' => '0.7',
                    'changefreq' => 'weekly',
                    'lastmod'  => !empty($ev['date']) ? date('Y-m-d', strtotime((string) $ev['date'])) : null,
                    'images'   => $images,
                ];
            }
        }

        // Sondages publiés.
        foreach ($polls as $poll) {
            if (!empty($poll['slug'])) {
                $urls[] = [
                    'loc'      => $base . '/sondages/' . rawurlencode((string) $poll['slug']),
                    'priority' => '0.6',
                    'changefreq' => 'daily',
                    'lastmod'  => !empty($poll['updated_at']) ? date('Y-m-d', strtotime((string) $poll['updated_at'])) : null,
                ];
            }
        }

        // Photos de la galerie : médias uploadés + photos d'événements passés
        // (plafonnées pour garder un XML léger ; la spec autorise 1000 images/URL).
        $galerieImages = [];
        if ($dbUp && self::$dbProbe === null) {
            try {
                $stmt = db()->query(
                    'SELECT url, alt, name
                     FROM media
                     ORDER BY created_at DESC
                     LIMIT 50'
                );
                foreach ($stmt->fetchAll() as $m) {
                    $img = $abs((string) ($m['url'] ?? ''));
                    if ($img !== '') {
                        $galerieImages[] = ['loc' => $img, 'caption' => (string) ($m['alt'] ?? $m['name'] ?? '')];
                    }
                }
                foreach (self::step('photos_evenements', $raisons, static fn (): array => Event::pastPhotos()) as $row) {
                    if (count($galerieImages) >= 100) {
                        break;
                    }
                    $img = $abs((string) ($row['url'] ?? ''));
                    if ($img !== '') {
                        $galerieImages[] = ['loc' => $img, 'caption' => (string) ($row['caption'] ?? '')];
                    }
                }
            } catch (\Throwable $e) {
                $raisons[] = 'etape_medias';
                Logger::warning('sitemap: étape en échec', ['etape' => 'medias', 'erreur' => self::errBrief($e)]);
            }
        }
        foreach ($urls as &$u) {
            if (($u['images'] ?? null) === true) {
                $u['images'] = $galerieImages;
            }
        }
        unset($u);

        // Pages CMS publiées.
        if ($dbUp && self::$dbProbe === null) {
            foreach (self::step('pages_cms', $raisons, static fn (): array => Page::all()) as $pg) {
                if (!empty($pg['is_published']) && !empty($pg['slug'])) {
                    $slug = (string) $pg['slug'];
                    // Les pages CMS qui doublonnent une page statique
                    // (/p/presentation vs /presentation) sont exclues du
                    // sitemap pour éviter le contenu dupliqué.
                    if (in_array($slug, $staticPaths, true)) {
                        continue;
                    }
                    $urls[] = [
                        'loc'      => $base . '/p/' . rawurlencode($slug),
                        'priority' => '0.5',
                        'changefreq' => 'monthly',
                        'lastmod'  => !empty($pg['updated_at']) ? date('Y-m-d', strtotime((string) $pg['updated_at'])) : null,
                    ];
                }
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        // Feuille XSL : rend le sitemap lisible dans un navigateur
        // (les moteurs l'ignorent et parsent le XML brut).
        $xml .= '<?xml-stylesheet type="text/xsl" href="' . htmlspecialchars($base . '/sitemap.xsl', ENT_XML1) . '"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
            . ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
        foreach ($urls as $u) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . htmlspecialchars((string) $u['loc'], ENT_XML1) . "</loc>\n";
            if (!empty($u['lastmod'])) {
                $xml .= '    <lastmod>' . $u['lastmod'] . "</lastmod>\n";
            }
            if (!empty($u['changefreq'])) {
                $xml .= '    <changefreq>' . $u['changefreq'] . "</changefreq>\n";
            }
            if (!empty($u['priority'])) {
                $xml .= '    <priority>' . $u['priority'] . "</priority>\n";
            }
            foreach ((array) ($u['images'] ?? []) as $img) {
                if (empty($img['loc'])) {
                    continue;
                }
                $xml .= "    <image:image>\n";
                $xml .= '      <image:loc>' . htmlspecialchars((string) $img['loc'], ENT_XML1) . "</image:loc>\n";
                foreach (['title', 'caption'] as $k) {
                    $v = trim((string) ($img[$k] ?? ''));
                    if ($v !== '') {
                        $xml .= '      <image:' . $k . '>' . htmlspecialchars($v, ENT_XML1) . '</image:' . $k . ">\n";
                    }
                }
                $xml .= "    </image:image>\n";
            }
            $xml .= "  </url>\n";
        }
        $xml .= '</urlset>' . "\n";

        // ---- Évaluation de la génération --------------------------------
        $rootLoc = $base . '/';
        $minUrls = count($static);

        $cache = self::readCache();

        // Sections dynamiques vides alors qu'elles existaient dans le
        // dernier bon sitemap : c'est LE détecteur des modèles « silencieux »
        // (ils renvoient [] sans exception, même sonde verte — ex. base
        // saturée qui refuse les requêtes suivantes). Les trois sections ne
        // disparaissent légitimement JAMAIS toutes en même temps ; en
        // revanche, un site qui n'a jamais eu de contenu dynamique (cache
        // sans URL dynamique) n'est jamais dégradé pour autant.
        if ($cache !== null
            && $cache['urls_dynamiques'] > 0
            && self::countDynamicUrls($xml) === 0) {
            $raisons[] = 'sections_dynamiques_vides';
            Logger::warning('sitemap: sections dynamiques vides alors que le dernier bon sitemap en listait', [
                'urls_dynamiques_cache' => $cache['urls_dynamiques'],
            ]);
        }

        $ok = $raisons === [] && self::looksComplete($xml, $rootLoc, $minUrls);
        if (!$ok && $raisons === []) {
            $raisons[] = 'xml_suspect';
            Logger::warning('sitemap: XML généré suspect, cache non mis à jour', [
                'taille'    => strlen($xml),
                'urls'      => substr_count($xml, '<url>'),
                'min_urls'  => $minUrls,
                'min_octets' => self::MIN_COMPLETE_BYTES,
            ]);
        }

        return ['xml' => $xml, 'ok' => $ok, 'raisons' => $raisons, 'cache' => $cache];
    }

    /**
     * Sonde « base disponible ».
     *
     * @param list<string> $raisons Complétée avec 'base_indisponible' en cas d'échec.
     */
    private static function dbUp(array &$raisons): bool
    {
        $probe = self::$dbProbe ?? static fn (): bool => db()->query('SELECT 1') !== false;
        try {
            return $probe() === true;
        } catch (\Throwable $e) {
            $raisons[] = 'base_indisponible';
            Logger::warning('sitemap: étape sonde base en échec', ['erreur' => self::errBrief($e)]);

            return false;
        }
    }

    /**
     * Exécute une étape de génération en journalisant son échec
     * (étape + erreur, sans stack trace).
     *
     * En mode test (sonde branchée via setDbProbeForTests), AUCUNE requête
     * réelle n'est émise : le retour de la sonde simule l'état de la base
     * et le contenu dynamique est considéré vide.
     *
     * @param list<string> $raisons Complétée avec 'etape_<nom>' en cas d'exception.
     * @return array<int|string,mixed>
     */
    private static function fetch(string $nom, array &$raisons, \Closure $fn): array
    {
        if (self::$dbProbe !== null) {
            return [];
        }

        return self::step($nom, $raisons, $fn);
    }

    /**
     * Exécute une étape de génération en journalisant son échec
     * (étape + erreur, sans stack trace).
     *
     * @param list<string> $raisons Complétée avec 'etape_<nom>' en cas d'exception.
     * @return array<int|string,mixed>
     */
    private static function step(string $nom, array &$raisons, \Closure $fn): array
    {
        try {
            $result = $fn();
        } catch (\Throwable $e) {
            $raisons[] = 'etape_' . $nom;
            Logger::warning('sitemap: étape en échec', ['etape' => $nom, 'erreur' => self::errBrief($e)]);

            return [];
        }

        return is_array($result) ? $result : [];
    }

    /**
     * Résumé d'erreur pour les logs : classe + message tronqué. JAMAIS de
     * stack trace ; rien ne fuit vers la réponse HTTP.
     */
    private static function errBrief(\Throwable $e): string
    {
        return mb_substr(get_class($e) . ' : ' . $e->getMessage(), 0, 180);
    }

    /**
     * Garde-fou « génération réussie » (public pour être testé unitairement).
     *
     * Un sitemap est considéré COMPLET s'il :
     *   1. contient l'URL racine,
     *   2. se termine par '</urlset>' (génération menée à son terme),
     *   3. ET pèse plus de MIN_COMPLETE_BYTES OU liste au moins $minUrls
     *      entrées <url>.
     *
     * $minUrls = nombre de pages STATIQUES du site (16 aujourd'hui) : ces
     * pages viennent du code et jamais de la base — toute génération menée
     * à terme les contient TOUTES, quel que soit le contenu dynamique. Un
     * site avec peu de pages légitimes (aucun événement, aucun sondage,
     * aucune page CMS) passe donc TOUJOURS le garde-fou : le seuil est le
     * plancher structurel du site, pas un nombre magique qui imposerait du
     * contenu dynamique et forcerait à servir un cache périmé. À l'inverse,
     * le XML amputé observé le 29/10 (465 o, sections manquantes) n'atteint
     * ni le seuil de taille ni le plancher d'URLs.
     */
    public static function looksComplete(string $xml, string $rootLoc, int $minUrls): bool
    {
        if (!str_contains($xml, '<loc>' . $rootLoc . '</loc>')) {
            return false;
        }
        if (!str_ends_with(rtrim($xml), '</urlset>')) {
            return false;
        }

        return strlen($xml) > self::MIN_COMPLETE_BYTES
            || substr_count($xml, '<url>') >= $minUrls;
    }

    /**
     * Nombre d'URLs dynamiques (détail événement / sondage / page CMS) d'un
     * XML — sert à détecter l'évidement des sections dynamiques.
     */
    private static function countDynamicUrls(string $xml): int
    {
        return (int) preg_match_all('#<loc>[^<]*/(?:events|sondages|p)/[^<]*</loc>#', $xml);
    }

    /**
     * Écriture atomique du dernier bon XML : fichier temporaire + rename
     * (LOCK_EX sur l'écriture). Un lecteur concurrent voit toujours un
     * fichier complet, jamais un XML à moitié écrit.
     */
    private static function writeCache(string $xml): void
    {
        $path = self::cachePath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            Logger::warning('sitemap: répertoire de cache inexploitable', ['repertoire' => $dir]);

            return;
        }

        $tmp = $path . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $xml, LOCK_EX) === false) {
            Logger::warning('sitemap: échec d\'écriture du cache', ['chemin' => $tmp]);

            return;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            Logger::warning('sitemap: échec de publication du cache', ['chemin' => $path]);
        }
    }

    /**
     * Dernier bon XML en cache, sain et frais ; null sinon.
     *
     * @return array{xml: string, age: int, urls_dynamiques: int}|null
     */
    private static function readCache(): ?array
    {
        $path = self::cachePath();
        if (!is_file($path)) {
            return null;
        }

        $xml = @file_get_contents($path);
        if (!is_string($xml) || $xml === '') {
            return null;
        }

        $rootLoc = '<loc>' . rtrim(APP_URL, '/') . '/</loc>';
        if (!str_contains($xml, $rootLoc) || !str_ends_with(rtrim($xml), '</urlset>')) {
            // Cache corrompu (écriture interrompue, disque plein…) : on ne
            // peut pas le servir tel quel à Google.
            Logger::warning('sitemap: cache de secours corrompu, ignoré', ['taille' => strlen($xml)]);

            return null;
        }

        $age = max(0, time() - (int) (@filemtime($path) ?: time()));
        if ($age > self::CACHE_MAX_AGE_S) {
            Logger::warning('sitemap: cache de secours périmé, ignoré', ['age_s' => $age]);

            return null;
        }

        return ['xml' => $xml, 'age' => $age, 'urls_dynamiques' => self::countDynamicUrls($xml)];
    }

    /**
     * Sitemap minimal de tout repos : génération elle-même en échec et
     * aucun cache — on répond quand même un XML valide (jamais de 500).
     */
    private static function fallbackSitemap(): string
    {
        $rootLoc = htmlspecialchars(rtrim(APP_URL, '/') . '/', ENT_XML1);

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
            . "  <url>\n    <loc>{$rootLoc}</loc>\n  </url>\n"
            . "</urlset>\n";
    }

    /**
     * Recherche globale (GET /search?q=...).
     *
     * Cherche dans les événements (titre, excerpt), les sondages (titre)
     * et les pages (titre), et renvoie du JSON (max 10 résultats).
     */
    public function search(): void
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $results = [];

        if ($q !== '' && mb_strlen($q) >= 2) {
            $like = '%' . str_replace(['%', '_', '\\'], ['\%', '\_', '\\\\'], $q) . '%';

            // Événements (titre + excerpt).
            foreach (Event::search($like, 5) as $row) {
                $results[] = [
                    'type'    => 'Événement',
                    'title'   => (string) ($row['title'] ?? ''),
                    'url'     => url('/events/' . rawurlencode((string) ($row['slug'] ?? ''))),
                    'excerpt' => trim(strip_tags((string) ($row['excerpt'] ?? ''))),
                ];
            }

            // Sondages (titre).
            foreach (Poll::search($like, 3) as $row) {
                $results[] = [
                    'type'    => 'Sondage',
                    'title'   => (string) ($row['title'] ?? ''),
                    'url'     => url('/sondages/' . rawurlencode((string) ($row['slug'] ?? ''))),
                    'excerpt' => trim(strip_tags((string) ($row['description'] ?? ''))),
                ];
            }

            // Pages (titre).
            foreach (Page::search($like, 3) as $row) {
                $results[] = [
                    'type'    => 'Page',
                    'title'   => (string) ($row['title'] ?? ''),
                    'url'     => url('/p/' . rawurlencode((string) ($row['slug'] ?? ''))),
                    'excerpt' => '',
                ];
            }

            $results = array_slice($results, 0, 10);
        }

        $this->json($results);
    }
}
