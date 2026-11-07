<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Aperçu live des pages CMS (POST /admin/pages/preview) :
 *
 *  - le Fondateur (SUPERADMIN) obtient le MÊME gabarit que la page
 *    publique /p/{slug} (vue pages/page + layout public), rendu depuis les
 *    champs POSTés du formulaire d'édition — document HTML complet ;
 *  - le contenu passe par HtmlSanitizer::clean comme le rendu public :
 *    un <script> saisi est neutralisé (balise ET contenu retirés, attributs
 *    on* supprimés) — preuve par comparaison du fragment « prose » avec le
 *    rendu public de la même chaîne ;
 *  - AUCUNE persistance : ni UPDATE ni INSERT, la ligne pages et les logs
 *    d'audit restent intacts après un aperçu ;
 *  - accès refusé aux non-admin (élève : 403 ; invité : redirection vers
 *    /login) ;
 *  - CSRF actif (vérifié hors APP_TESTING, dans un sous-processus dédié) :
 *    sans jeton ou avec un jeton faux → 403 « jeton CSRF invalide » ; le
 *    jeton est accepté via l'en-tête X-CSRF-Token (sans champ _csrf).
 *
 * Base `aeic_test` requise (les tests sont sautés sinon).
 */
final class AdminPagePreviewTest extends IntegrationTestCase
{
    private string $rootId  = 'u_pageprev_root';
    private string $eleveId = 'u_pageprev_eleve';

    /**
     * Contenu mixte légitime + charges XSS : sert à la fois à l'aperçu et
     * (inséré en base) au rendu public témoin.
     */
    private const CONTENU_MIXTE = '<h2>Section éditée</h2><p>Texte <strong>légitime</strong></p>'
        . '<script>alert(\'xss\')</script><p onclick="voler()">Clic</p>'
        . '<img src="x.png" onerror="evil()" alt="visuel">';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->reset(['users', 'pages', 'audit_logs']);
        $this->seedUser($this->rootId, 'pageprev-root@exemple.fr', 'Password123456', 'SUPERADMIN');
        $this->seedUser($this->eleveId, 'pageprev-eleve@exemple.fr', 'Password123456', 'ELEVE');
    }

    // -----------------------------------------------------------------
    //  Rendu identique au gabarit public
    // -----------------------------------------------------------------

    public function test_l_apercu_rend_le_meme_gabarit_que_la_page_publique(): void
    {
        $this->seedPage('page_prev_publique', 'apercu-publique', 'Titre public', self::CONTENU_MIXTE);

        $public = $this->request('GET', '/p/apercu-publique');
        self::assertSame(200, (int) ($public['code'] ?? 0), 'La page publique témoin doit répondre.');
        $corpsPublic = (string) ($public['body'] ?? '');

        $preview = $this->request('POST', '/admin/pages/preview', [
            'id'      => 'page_prev_publique',
            'title'   => 'Titre public',
            'slug'    => 'apercu-publique',
            'content' => self::CONTENU_MIXTE,
        ], [], $this->rootId);

        self::assertSame(200, (int) ($preview['code'] ?? 0), 'L’aperçu doit répondre 200 pour le Fondateur.');
        $corps = (string) ($preview['body'] ?? '');

        // Document HTML COMPLET : layout public autour de la vue générique.
        self::assertStringStartsWith('<!DOCTYPE html>', $corps, 'L’aperçu doit être un document complet (layout public).');
        self::assertStringContainsString('<title>Titre public</title>', $corps);
        self::assertStringContainsString('<h1 class="page-title">Titre public</h1>', $corps);

        // Mêmes marqueurs structurels que la page publique (gabarit pages/page).
        foreach (['page-hero', 'eyebrow', 'page-title', 'prose surface glass'] as $marqueur) {
            self::assertStringContainsString($marqueur, $corpsPublic);
            self::assertStringContainsString($marqueur, $corps);
        }

        // Preuve du « MÊME rendu » : le fragment de contenu assaini est
        // strictement identique entre aperçu et page publique.
        self::assertSame(
            $this->proseFragment($corpsPublic),
            $this->proseFragment($corps),
            'Le fragment de contenu de l’aperçu doit être identique au rendu public de la même chaîne.'
        );
    }

    public function test_le_contenu_poste_est_sanitizé_script_neutralise(): void
    {
        $r = $this->request('POST', '/admin/pages/preview', [
            'id'      => '',
            'title'   => 'Brouillon',
            'slug'    => 'brouillon',
            'content' => self::CONTENU_MIXTE,
        ], [], $this->rootId);

        self::assertSame(200, (int) ($r['code'] ?? 0));
        $corps = (string) ($r['body'] ?? '');
        $fragment = $this->proseFragment($corps);

        // Le HTML légitime passe…
        self::assertStringContainsString('<h2>Section éditée</h2>', $fragment);
        self::assertStringContainsString('Texte <strong>légitime</strong>', $fragment);
        self::assertStringContainsString('>Clic</p>', $fragment, 'Le texte du <p> survit à la suppression de l’attribut onclick.');

        // …le code exécutable est neutralisé dans le contenu rendu, comme
        // sur /p/{slug} (le layout public porte par ailleurs ses propres
        // <script> légitimes : la preuve porte sur le fragment édité).
        self::assertStringNotContainsString('<script', $fragment);
        self::assertStringNotContainsString('alert(', $corps, 'Le script est retiré AVEC son contenu.');
        self::assertStringNotContainsString('onclick', $fragment);
        self::assertStringNotContainsString('onerror', $fragment);
    }

    // -----------------------------------------------------------------
    //  Aucune persistance
    // -----------------------------------------------------------------

    public function test_l_apercu_n_ecrit_jamais_en_base(): void
    {
        $this->seedPage('page_prev_persist', 'apercu-persist', 'Titre initial', '<p>Contenu initial</p>');

        $avant = $this->fetchPage('page_prev_persist');

        $r = $this->request('POST', '/admin/pages/preview', [
            'id'      => 'page_prev_persist',
            'title'   => 'Titre MODIFIE',
            'slug'    => 'apercu-persist',
            'content' => '<p>Contenu MODIFIE</p>',
        ], [], $this->rootId);

        self::assertSame(200, (int) ($r['code'] ?? 0), 'L’aperçu doit réussir avant la vérification de persistance.');
        self::assertStringContainsString('Titre MODIFIE', (string) ($r['body'] ?? ''), 'L’aperçu rend bien le titre édité (non enregistré).');

        // Ni UPDATE (ligne strictement inchangée, updated_at compris)…
        self::assertSame($avant, $this->fetchPage('page_prev_persist'), 'La ligne pages ne doit pas changer.');

        // …ni INSERT (aucune nouvelle page)…
        self::assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM pages')->fetchColumn(),
            'Aucune page ne doit être créée par un aperçu.'
        );

        // …ni aucune autre écriture applicative (audit d’enregistrement).
        self::assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'page.%'")->fetchColumn(),
            'Aucun audit page.create/page.update ne doit être journalisé par un aperçu.'
        );
    }

    // -----------------------------------------------------------------
    //  Accès refusé aux non-admin
    // -----------------------------------------------------------------

    public function test_acces_refuse_aux_non_admin(): void
    {
        $champs = ['title' => 'Titre', 'slug' => 'slug', 'content' => '<p>x</p>'];

        // Élève : 403 par le routeur (préfixe /admin), avant tout contrôleur.
        $eleve = $this->request('POST', '/admin/pages/preview', $champs, [], $this->eleveId);
        self::assertSame(403, (int) ($eleve['code'] ?? 0), 'Un élève doit être refusé (403) sur l’aperçu admin.');
        self::assertStringNotContainsString('page-hero', (string) ($eleve['body'] ?? ''), 'Aucun rendu de page ne doit fuiter.');

        // Invité : redirection vers la connexion (session vierge, sans
        // l’héritage de la session élève précédente).
        $this->nouvelleSession();
        $invite = $this->request('POST', '/admin/pages/preview', $champs);
        self::assertSame(302, (int) ($invite['code'] ?? 0), 'Un invité doit être renvoyé vers la connexion.');
        self::assertStringContainsString('/login', $this->location($invite));
    }

    // -----------------------------------------------------------------
    //  CSRF (sous-processus hors APP_TESTING : garde du routeur ACTIVE)
    // -----------------------------------------------------------------

    /**
     * Le routeur exige un jeton CSRF pour POST /admin/pages/preview ; le
     * jeton passe aussi par l'en-tête X-CSRF-Token (sans champ _csrf).
     */
    public function test_csrf_exige_un_jeton_valide_et_accepte_le_header(): void
    {
        $champs = ['title' => 'Brouillon CSRF', 'slug' => 'brouillon-csrf', 'content' => '<p>contenu csrf</p>'];

        // Sans jeton : 403 CSRF avant même la garde admin.
        $sans = $this->dispatchSansTesting($champs, '');
        self::assertSame(403, (int) ($sans['code'] ?? 0), 'POST sans jeton CSRF doit être refusé (403).');
        self::assertStringContainsString('jeton CSRF invalide', (string) ($sans['body'] ?? ''));

        // Jeton faux (header) : même refus.
        $faux = $this->dispatchSansTesting($champs, 'pas-le-bon-jeton');
        self::assertSame(403, (int) ($faux['code'] ?? 0), 'POST avec un jeton CSRF erroné doit être refusé (403).');
        self::assertStringContainsString('jeton CSRF invalide', (string) ($faux['body'] ?? ''));

        // Jeton valide transmis UNIQUEMENT par l'en-tête X-CSRF-Token :
        // la requête franchit la garde CSRF puis admin, et rend l'aperçu.
        $valide = $this->dispatchSansTesting($champs, 'jeton-session-valide');
        self::assertSame(200, (int) ($valide['code'] ?? 0), 'Le jeton doit être accepté via l’en-tête X-CSRF-Token.');
        $corps = (string) ($valide['body'] ?? '');
        self::assertStringStartsWith('<!DOCTYPE html>', $corps);
        self::assertStringContainsString('<h1 class="page-title">Brouillon CSRF</h1>', $corps);
        self::assertStringNotContainsString('Erreur 403', $corps);
    }

    // -----------------------------------------------------------------
    //  Helpers
    // -----------------------------------------------------------------

    private function seedPage(string $id, string $slug, string $title, string $content): void
    {
        $this->pdo->prepare(
            'INSERT INTO pages (id, slug, title, content, is_published)
             VALUES (?, ?, ?, ?, 1)'
        )->execute([$id, $slug, $title, $content]);
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchPage(string $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, slug, title, content, meta_title, meta_description, is_published, created_at, updated_at
             FROM pages WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        self::assertIsArray($row, 'La page témoin doit exister en base.');

        return $row;
    }

    /**
     * Extrait le fragment de contenu assaini rendu par la vue générique
     * (div « prose surface glass ») — identique entre /p/{slug} et l'aperçu.
     */
    private function proseFragment(string $body): string
    {
        $ok = preg_match('#<div class="prose surface glass">(.*?)</div>#s', $body, $m);
        self::assertSame(1, $ok, 'Fragment « prose surface glass » introuvable dans le rendu.');

        return (string) $m[1];
    }

    /**
     * Requête POST /admin/pages/preview dans un sous-processus SANS
     * APP_TESTING : la vérification CSRF du routeur (Csrf::checkRequest)
     * est alors ACTIVE — contrairement aux requêtes d'intégration
     * habituelles, qui la court-circuitent.
     *
     * La session du sous-processus porte le compte Fondateur (semé dans
     * setUp) et le token CSRF attendu ; le jeton de la requête est passé
     * UNIQUEMENT par l'en-tête X-CSRF-Token.
     *
     * @param array<string,mixed> $post
     * @return array{code:mixed,body:mixed}
     */
    private function dispatchSansTesting(array $post, string $csrfHeader): array
    {
        $script = $this->ecrireBootSansTesting();
        $sessionId = 'test-pageprev-csrf-' . bin2hex(random_bytes(6));

        $env = array_merge(getenv(), [
            'AEIC_PREV_METHOD'       => 'POST',
            'AEIC_PREV_PATH'         => '/admin/pages/preview',
            'AEIC_PREV_POST'         => json_encode($post, JSON_UNESCAPED_UNICODE),
            'AEIC_PREV_CSRF_HEADER'  => $csrfHeader,
            'AEIC_PREV_USER'         => $this->rootId,
            'AEIC_PREV_SESSION_ID'   => $sessionId,
            'AEIC_PREV_SESSION_TOKEN'=> 'jeton-session-valide',
            'APP_TESTING'            => 'false',
            'APP_URL'                => 'https://example.test',
            'DB_HOST'                => getenv('DB_HOST') ?: '127.0.0.1',
            'DB_NAME'                => getenv('DB_NAME') ?: 'aeic_test',
            'DB_USER'                => getenv('DB_USER') ?: 'aeic',
            'DB_PASS'                => getenv('DB_PASS') ?: '',
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
     * d'intégration (tests/support/db_guard.php).
     */
    private function ecrireBootSansTesting(): string
    {
        $path = dirname(__DIR__) . '/cache/pagepreview-csrf-boot.php';
        @mkdir(dirname($path), 0777, true);

        $boot = <<<'PHP'
<?php

declare(strict_types=1);

/**
 * Boot de test (généré par AdminPagePreviewTest) — dispatche une requête
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

$method    = getenv('AEIC_PREV_METHOD') ?: 'POST';
$path      = getenv('AEIC_PREV_PATH') ?: '/admin/pages/preview';
$postJson  = getenv('AEIC_PREV_POST') ?: '';
$csrfHead  = getenv('AEIC_PREV_CSRF_HEADER') ?: '';
$userId    = getenv('AEIC_PREV_USER') ?: '';
$sessionId = getenv('AEIC_PREV_SESSION_ID') ?: '';
$sessToken = getenv('AEIC_PREV_SESSION_TOKEN') ?: '';

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
if ($sessToken !== '') {
    $_SESSION['_csrf_token'] = $sessToken;
}
session_write_close();
session_start();

$_SERVER['REQUEST_METHOD'] = $method;
$_SERVER['REQUEST_URI'] = $path;
$_SERVER['HTTPS'] = 'on';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'PHPUnit/PagePreviewCsrf';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['SERVER_NAME'] = 'example.test';
$_SERVER['HTTP_HOST'] = 'example.test';
if ($csrfHead !== '') {
    // L'en-tête X-CSRF-Token arrive mappé en HTTP_* par le SAPI.
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $csrfHead;
}

$_POST = [];
if ($postJson !== '') {
    $decoded = json_decode($postJson, true);
    if (is_array($decoded)) {
        $_POST = $decoded;
    }
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
    $router->dispatch($method, $path);
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
