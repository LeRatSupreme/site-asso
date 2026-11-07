<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Ordre des pages CMS réordonnable (POST /admin/pages/reorder, colonne
 * pages.position — migration 2026_pages_position.sql) :
 *
 *  - le reorder réécrit les positions en masse : l'ordre AVANT/APRÈS est
 *    prouvé en base (positions 1..N d'un seul tenant, dans l'ordre du
 *    payload) ;
 *  - robustesse : ids inconnus/doublons ignorés, pages absentes du
 *    payload (créées entre-temps) gardées APRÈS dans leur ordre courant ;
 *  - payload invalide (champ ids manquant, non-liste, liste vide) → 400
 *    JSON, base inchangée ;
 *  - accès refusé aux non-admin : élève 403 (routeur), invité 302 vers
 *    /login (routeur), Président 403 (POST interdit), rôle sans le
 *    module Contenu (CAFETERIA) 403 JSON par la garde du contrôleur ;
 *  - CSRF actif (vérifié hors APP_TESTING, sous-processus dédié) : sans
 *    jeton ou jeton faux → 403 « jeton CSRF invalide » ; jeton valide
 *    via l'en-tête X-CSRF-Token → 200, positions réécrites ;
 *  - la liste admin GET /admin/pages rend les pages dans l'ordre des
 *    positions (position AVANT titre : « Zzz » position 1 s'affiche
 *    avant « Aaa » position 2) ;
 *  - le réordonnancement est journalisé (audit page.reorder).
 *
 * Base `aeic_test` requise (les tests sont sautés sinon) ; la colonne
 * position est appliquée au mieux (migration best-effort) si absente.
 */
final class AdminPageReorderTest extends IntegrationTestCase
{
    private string $rootId      = 'u_pagereord_root';
    private string $eleveId     = 'u_pagereord_eleve';
    private string $presidentId = 'u_pagereord_pres';
    private string $cafeteriaId = 'u_pagereord_cafe';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->ensurePositionColumn();
        $this->reset(['users', 'pages', 'audit_logs']);
        $this->seedUser($this->rootId, 'pagereord-root@exemple.fr', 'Password123456', 'SUPERADMIN');
        $this->seedUser($this->eleveId, 'pagereord-eleve@exemple.fr', 'Password123456', 'ELEVE');
        $this->seedUser($this->presidentId, 'pagereord-pres@exemple.fr', 'Password123456', 'PRESIDENT');
        // CAFETERIA : rôle admin mais SANS le module Contenu — la garde
        // du contrôleur doit le refuser en JSON.
        $this->seedUser($this->cafeteriaId, 'pagereord-cafe@exemple.fr', 'Password123456', 'CAFETERIA');
    }

    // -----------------------------------------------------------------
    //  Réécriture des positions (ordre avant/après prouvé)
    // -----------------------------------------------------------------

    public function test_le_reorder_reecrit_les_positions_ordre_avant_apres(): void
    {
        $this->seedPage('page_r_alpha', 'alpha', 'Alpha', 1);
        $this->seedPage('page_r_beta', 'beta', 'Beta', 2);
        $this->seedPage('page_r_gamma', 'gamma', 'Gamma', 3);

        self::assertSame(
            ['page_r_alpha', 'page_r_beta', 'page_r_gamma'],
            $this->idsOrdonnes(),
            'AVANT : ordre initial alpha, beta, gamma (positions 1, 2, 3).'
        );

        $r = $this->request('POST', '/admin/pages/reorder', [
            'ids' => ['page_r_gamma', 'page_r_alpha', 'page_r_beta'],
        ], [], $this->rootId);

        self::assertSame(200, (int) ($r['code'] ?? 0), 'Le reorder doit répondre 200 pour le Fondateur.');
        self::assertSame(['ok' => true], $this->jsonDecode($r), 'Réponse JSON minimale {ok:true}.');

        // APRÈS : positions réécrites 1..N dans l'ordre du payload.
        self::assertSame(
            ['page_r_gamma', 'page_r_alpha', 'page_r_beta'],
            $this->idsOrdonnes(),
            'APRÈS : gamma, alpha, beta — positions 1, 2, 3.'
        );
        self::assertSame(1, (int) $this->positionDe('page_r_gamma'));
        self::assertSame(2, (int) $this->positionDe('page_r_alpha'));
        self::assertSame(3, (int) $this->positionDe('page_r_beta'));

        // Réordonnancement journalisé.
        self::assertSame(
            1,
            (int) $this->pdo
                ->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'page.reorder'")
                ->fetchColumn(),
            'Le reorder doit être journalisé (audit page.reorder).'
        );
    }

    public function test_ids_inconnus_ignores_et_pages_absentes_du_payload_gardees_apres(): void
    {
        $this->seedPage('page_r_a', 'a', 'A', 1);
        $this->seedPage('page_r_b', 'b', 'B', 2);
        $this->seedPage('page_r_c', 'c', 'C', 3);
        $this->seedPage('page_r_d', 'd', 'D', 4);

        // Id inconnu (page supprimée dans un autre onglet) + doublon +
        // ordre partiel : seuls a et d bougent, b et c restent APRÈS dans
        // leur ordre courant.
        $r = $this->request('POST', '/admin/pages/reorder', [
            'ids' => ['page_r_fantome', 'page_r_d', 'page_r_d', 'page_r_a'],
        ], [], $this->rootId);

        self::assertSame(200, (int) ($r['code'] ?? 0));
        self::assertSame(
            ['page_r_d', 'page_r_a', 'page_r_b', 'page_r_c'],
            $this->idsOrdonnes(),
            'Payload partiel : d, a devant ; b, c gardés après dans leur ordre courant.'
        );
        // Renumérotation d'un seul tenant : 1..4, aucun trou.
        self::assertSame(1, (int) $this->positionDe('page_r_d'));
        self::assertSame(2, (int) $this->positionDe('page_r_a'));
        self::assertSame(3, (int) $this->positionDe('page_r_b'));
        self::assertSame(4, (int) $this->positionDe('page_r_c'));

        // Aucune page créée ni supprimée par le reorder.
        self::assertSame(4, (int) $this->pdo->query('SELECT COUNT(*) FROM pages')->fetchColumn());
    }

    // -----------------------------------------------------------------
    //  Payload invalide
    // -----------------------------------------------------------------

    public function test_payload_invalide_renvoie_400_json_sans_toucher_a_la_base(): void
    {
        $this->seedPage('page_r_x', 'x', 'X', 1);
        $this->seedPage('page_r_y', 'y', 'Y', 2);

        foreach ([['ids' => 'pas-une-liste'], ['autre' => 1], ['ids' => []]] as $payload) {
            $r = $this->request('POST', '/admin/pages/reorder', $payload, [], $this->rootId);

            self::assertSame(400, (int) ($r['code'] ?? 0), 'Payload invalide (' . json_encode($payload) . ') : 400 attendu.');
            $corps = $this->jsonDecode($r);
            self::assertFalse((bool) ($corps['ok'] ?? null), 'Réponse d’erreur JSON {ok:false}.');
            self::assertNotSame('', (string) ($corps['error'] ?? ''), 'Message d’erreur présent.');
        }

        self::assertSame(
            ['page_r_x', 'page_r_y'],
            $this->idsOrdonnes(),
            'Aucune position ne doit bouger sur un payload invalide.'
        );
    }

    // -----------------------------------------------------------------
    //  Accès refusé aux non-admin / rôles sans le module
    // -----------------------------------------------------------------

    public function test_acces_refuse_aux_non_admin(): void
    {
        $this->seedPage('page_r_g', 'g', 'G', 1);
        $payload = ['ids' => ['page_r_g']];

        // Élève : 403 par le routeur (préfixe /admin), avant tout contrôleur.
        $eleve = $this->request('POST', '/admin/pages/reorder', $payload, [], $this->eleveId);
        self::assertSame(403, (int) ($eleve['code'] ?? 0), 'Un élève doit être refusé (403) sur le reorder.');
        self::assertStringNotContainsString('"ok":true', (string) ($eleve['body'] ?? ''), 'Aucune confirmation ok:true ne doit fuiter.');

        // Président : consultation seule — tout POST /admin/* est refusé.
        $president = $this->request('POST', '/admin/pages/reorder', $payload, [], $this->presidentId);
        self::assertSame(403, (int) ($president['code'] ?? 0), 'Le Président (lecture seule) doit être refusé en POST.');

        // Rôle sans le module Contenu (CAFETERIA) : 403 JSON par la garde
        // du contrôleur (il passe le routeur : rôle admin, ni élève ni
        // Président).
        $cafe = $this->request('POST', '/admin/pages/reorder', $payload, [], $this->cafeteriaId);
        self::assertSame(403, (int) ($cafe['code'] ?? 0), 'Un rôle sans le module Contenu doit être refusé (403).');
        self::assertSame(
            ['ok' => false, 'error' => 'Accès refusé.'],
            $this->jsonDecode($cafe),
            'Refus de la garde module en JSON, jamais de redirection HTML.'
        );

        // Invité : redirection vers la connexion (session vierge).
        $this->nouvelleSession();
        $invite = $this->request('POST', '/admin/pages/reorder', $payload);
        self::assertSame(302, (int) ($invite['code'] ?? 0), 'Un invité doit être renvoyé vers la connexion.');
        self::assertStringContainsString('/login', $this->location($invite));

        // Aucun refus n'a écrit la moindre position ni le moindre audit.
        self::assertSame(1, (int) $this->positionDe('page_r_g'), 'Position intacte après les refus.');
        self::assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'page.reorder'")->fetchColumn(),
            'Aucun audit page.reorder ne doit être journalisé sur un refus.'
        );
    }

    // -----------------------------------------------------------------
    //  CSRF (sous-processus hors APP_TESTING : garde du routeur ACTIVE)
    // -----------------------------------------------------------------

    public function test_csrf_exige_un_jeton_valide_et_accepte_le_header(): void
    {
        $this->seedPage('page_r_cs1', 'cs1', 'CSRF 1', 1);
        $this->seedPage('page_r_cs2', 'cs2', 'CSRF 2', 2);

        // Sans jeton : 403 CSRF avant même la garde admin.
        $sans = $this->dispatchSansTesting(['page_r_cs2', 'page_r_cs1'], '');
        self::assertSame(403, (int) ($sans['code'] ?? 0), 'POST sans jeton CSRF doit être refusé (403).');
        self::assertStringContainsString('jeton CSRF invalide', (string) ($sans['body'] ?? ''));

        // Jeton faux (header) : même refus.
        $faux = $this->dispatchSansTesting(['page_r_cs2', 'page_r_cs1'], 'pas-le-bon-jeton');
        self::assertSame(403, (int) ($faux['code'] ?? 0), 'POST avec un jeton CSRF erroné doit être refusé (403).');
        self::assertStringContainsString('jeton CSRF invalide', (string) ($faux['body'] ?? ''));

        self::assertSame(
            ['page_r_cs1', 'page_r_cs2'],
            $this->idsOrdonnes(),
            'Aucune position ne doit bouger sur un refus CSRF.'
        );

        // Jeton valide transmis UNIQUEMENT par l'en-tête X-CSRF-Token :
        // la requête franchit la garde CSRF puis le module Contenu, et
        // réécrit réellement les positions.
        $valide = $this->dispatchSansTesting(['page_r_cs2', 'page_r_cs1'], 'jeton-session-valide');
        self::assertSame(200, (int) ($valide['code'] ?? 0), 'Le jeton doit être accepté via l’en-tête X-CSRF-Token.');
        self::assertSame(['ok' => true], $this->jsonDecode($valide), 'Réponse {ok:true} après réordonnancement.');
        self::assertSame(
            ['page_r_cs2', 'page_r_cs1'],
            $this->idsOrdonnes(),
            'Le reorder doit être appliqué (cs2 puis cs1) malgré le payload passé en formulaire.'
        );
    }

    // -----------------------------------------------------------------
    //  Liste admin triée par position
    // -----------------------------------------------------------------

    public function test_la_liste_admin_est_triée_par_position(): void
    {
        // Position AVANT titre : « Zzz » (position 1) doit s'afficher
        // avant « Aaa » (position 2) et « Mmm » (position 3).
        $this->seedPage('page_r_zzz', 'zzz', 'Zzz dernière lettre', 1);
        $this->seedPage('page_r_mmm', 'mmm', 'Mmm milieu', 3);
        $this->seedPage('page_r_aaa', 'aaa', 'Aaa première lettre', 2);

        $r = $this->request('GET', '/admin/pages', [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0));

        $corps = (string) ($r['body'] ?? '');
        $pZzz = strpos($corps, 'Zzz dernière lettre');
        $pAaa = strpos($corps, 'Aaa première lettre');
        $pMmm = strpos($corps, 'Mmm milieu');

        self::assertNotFalse($pZzz, 'La page Zzz doit figurer dans la liste.');
        self::assertNotFalse($pAaa, 'La page Aaa doit figurer dans la liste.');
        self::assertNotFalse($pMmm, 'La page Mmm doit figurer dans la liste.');
        self::assertTrue($pZzz < $pAaa && $pAaa < $pMmm, 'Ordre du rendu = positions 1 (Zzz), 2 (Aaa), 3 (Mmm) — pas l’ordre alphabétique.');
    }

    // -----------------------------------------------------------------
    //  Helpers
    // -----------------------------------------------------------------

    private function seedPage(string $id, string $slug, string $title, int $position): void
    {
        $this->pdo->prepare(
            'INSERT INTO pages (id, slug, title, content, is_published, position)
             VALUES (?, ?, ?, ?, 1, ?)'
        )->execute([$id, $slug, $title, '<p>Contenu ' . $title . '</p>', $position]);
    }

    /**
     * Ordre courant des pages selon le tri canonique du modèle
     * (position, titre, id) — même règle que la liste admin.
     *
     * @return list<string>
     */
    private function idsOrdonnes(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id FROM pages ORDER BY position ASC, title ASC, id ASC'
        );

        /** @var list<string> $ids */
        $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        return $ids;
    }

    private function positionDe(string $id): int
    {
        $stmt = $this->pdo->prepare('SELECT position FROM pages WHERE id = ?');
        $stmt->execute([$id]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array{body?:mixed} $response
     * @return array<string,mixed>
     */
    private function jsonDecode(array $response): array
    {
        $data = json_decode((string) ($response['body'] ?? ''), true);
        self::assertIsArray($data, 'La réponse du endpoint doit être du JSON.');

        return $data;
    }

    /**
     * Colonne pages.position (migration 2026_pages_position.sql, posée
     * par dev-database) : appliquée au mieux sur la base de test si elle
     * manque — chaque statement est indépendant et idempotent.
     */
    private function ensurePositionColumn(): void
    {
        try {
            $this->pdo->query('SELECT position FROM pages LIMIT 1');

            return; // Déjà présente.
        } catch (\Throwable) {
            // Absente : on applique la migration.
        }

        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/2026_pages_position.sql');
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt !== '' && !str_starts_with($stmt, '--')) {
                try {
                    $this->pdo->exec($stmt);
                } catch (\Throwable) {
                    // Statement de commentaire / déjà appliqué : ignoré.
                }
            }
        }

        $this->pdo->query('SELECT position FROM pages LIMIT 1'); // Doit exister maintenant.
    }

    /**
     * Requête POST /admin/pages/reorder dans un sous-processus SANS
     * APP_TESTING : la vérification CSRF du routeur (Csrf::checkRequest)
     * est alors ACTIVE. Le payload passe par $_POST (le corps brut
     * php://input n'est pas alimentable en CLI) ; le jeton passe
     * UNIQUEMENT par l'en-tête X-CSRF-Token.
     *
     * @param list<string> $ids
     * @return array{code:mixed,body:mixed}
     */
    private function dispatchSansTesting(array $ids, string $csrfHeader): array
    {
        $script = $this->ecrireBootSansTesting();
        $sessionId = 'test-pagereord-csrf-' . bin2hex(random_bytes(6));

        $env = array_merge(getenv(), [
            'AEIC_REO_PATH'        => '/admin/pages/reorder',
            'AEIC_REO_IDS'         => json_encode($ids, JSON_UNESCAPED_UNICODE),
            'AEIC_REO_CSRF_HEADER' => $csrfHeader,
            'AEIC_REO_USER'        => $this->rootId,
            'AEIC_REO_SESSION_ID'  => $sessionId,
            'APP_TESTING'          => 'false',
            'APP_URL'              => 'https://example.test',
            'DB_HOST'              => getenv('DB_HOST') ?: '127.0.0.1',
            'DB_NAME'              => getenv('DB_NAME') ?: 'aeic_test',
            'DB_USER'              => getenv('DB_USER') ?: 'aeic',
            'DB_PASS'              => getenv('DB_PASS') ?: '',
        ]);

        $proc = @proc_open([PHP_BINARY, $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($proc, 'Impossible de lancer le sous-processus CSRF.');
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        self::assertStringNotContainsString('Fatal error', $stderr, 'Erreur fatale dans le sous-processus CSRF : ' . $stderr);

        $marker = "\n--AEIC_TEST_RESPONSE--\n";
        $pos = strpos($stdout, $marker);
        self::assertNotFalse($pos, 'Réponse du sous-processus CSRF illisible : ' . substr($stdout, 0, 500));

        $data = json_decode(substr($stdout, $pos + strlen($marker)), true);
        self::assertIsArray($data, 'JSON de réponse CSRF invalide.');

        return ['code' => $data['code'] ?? null, 'body' => $data['body'] ?? ''];
    }

    /**
     * Écrit (à chaque appel, contenu stable) le mini-boot du sous-processus
     * dans tests/cache/ (ignoré par Git) : amorce de l'application SANS
     * config.env ni APP_TESTING — garde aeic_test comme le runner
     * d'intégration (tests/support/db_guard.php). Même mécanique que
     * AdminPagePreviewTest.
     */
    private function ecrireBootSansTesting(): string
    {
        $path = dirname(__DIR__) . '/cache/pagereorder-csrf-boot.php';
        @mkdir(dirname($path), 0777, true);

        $boot = <<<'PHP'
<?php

declare(strict_types=1);

/**
 * Boot de test (généré par AdminPageReorderTest) — dispatche une requête
 * SANS APP_TESTING : la vérification CSRF du routeur est active.
 */

putenv('APP_TESTING=false');

define('AEIC_ROOT', dirname(__DIR__, 2));
define('AEIC_PUBLIC', AEIC_ROOT . '/public');
define('AEIC_VIEWS', AEIC_ROOT . '/views');
define('APP_ENV', 'dev');
define('APP_DEBUG', false);
define('APP_TESTING', false);
define('APP_URL', 'https://example.test');

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once AEIC_ROOT . '/vendor/autoload.php';
require_once AEIC_ROOT . '/app/config/routes.php';
require_once AEIC_ROOT . '/tests/support/db_guard.php';

function env(string $key, string $default = ''): string
{
    $value = getenv($key);

    return ($value === false || $value === '') ? $default : $value;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    aeic_tests_assert_test_database(env('DB_NAME', 'aeic_test'));

    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', env('DB_HOST', '127.0.0.1'), env('DB_NAME', 'aeic_test')),
        env('DB_USER', 'aeic'),
        env('DB_PASS', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    return $pdo;
}

$path      = getenv('AEIC_REO_PATH') ?: '/admin/pages/reorder';
$idsJson   = getenv('AEIC_REO_IDS') ?: '[]';
$csrfHead  = getenv('AEIC_REO_CSRF_HEADER') ?: '';
$userId    = getenv('AEIC_REO_USER') ?: '';
$sessionId = getenv('AEIC_REO_SESSION_ID') ?: '';

// Session contrôlée, sans cookie (même mécanique que tests/Integration/runner.php).
$savePath = AEIC_ROOT . '/tests/cache/test-sessions';
if (!is_dir($savePath)) {
    @mkdir($savePath, 0777, true);
}
ini_set('session.save_path', $savePath);
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
ini_set('session.use_strict_mode', '0');
if ($sessionId !== '') {
    session_id($sessionId);
}
session_start();

// Utilisateur forcé (comptes semés par le test, base aeic_test).
if ($userId !== '') {
    $stmt = db()->prepare('SELECT id, role FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $u = $stmt->fetch();
    if ($u !== false) {
        $_SESSION['user_id'] = (string) $u['id'];
        $_SESSION['user_role'] = (string) $u['role'];
    }
}
$_SESSION['_csrf_token'] = 'jeton-session-valide';
session_write_close();
session_start();

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = $path;
$_SERVER['HTTPS'] = 'on';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'PHPUnit/PageReorderCsrf';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['SERVER_NAME'] = 'example.test';
$_SERVER['HTTP_HOST'] = 'example.test';
if ($csrfHead !== '') {
    // L'en-tête X-CSRF-Token arrive mappé en HTTP_* par le SAPI.
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $csrfHead;
}

// Payload en formulaire classique (le contrôleur accepte JSON body OU
// $_POST — en CLI, php://input reste vide) : ids[]=…
$_POST = ['ids' => []];
$ids = json_decode($idsJson, true);
if (is_array($ids)) {
    $_POST['ids'] = $ids;
}

ob_start();
register_shutdown_function(static function (): void {
    $body = (string) ob_get_clean();
    echo "\n--AEIC_TEST_RESPONSE--\n" . json_encode(
        ['code' => http_response_code(), 'body' => $body],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
});

$router = new App\Core\Router();
aeic_register_routes($router);

try {
    $router->dispatch('POST', $path);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Erreur 500</h1>';
}
PHP;

        file_put_contents($path, $boot);
        self::assertFileExists($path, 'Le boot CSRF du sous-processus doit être écrit.');

        return $path;
    }
}
