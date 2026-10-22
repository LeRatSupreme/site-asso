<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Core\Router;
use App\Models\Model;
use PHPUnit\Framework\TestCase;

/**
 * Tests du routeur : matching, extraction de paramètres, 404/405.
 *
 * On instancie un faux contrôleur pour ne pas dépendre d'I/O.
 *
 * Les tests de dispatch couvrent aussi le blocage en écriture du rôle
 * PRESIDENT (lecture seule) : tout POST /admin/* reçoit un 403 avant
 * l'appel contrôleur, y compris en APP_TESTING (le court-circuit CSRF ne
 * lève pas ce bloc). La base de test, quand elle est joignable, sert
 * uniquement à ce qu'Auth::refreshUser() trouve bien le compte simulé
 * (sinon elle déconnecterait la session et le test perdrait son sens) ;
 * sans base, l'échec de connexion est avalé par Auth et la session de
 * test reste en place.
 */
final class RouterTest extends TestCase
{
    use TestDatabaseTrait;

    private ?\PDO $pdo = null;

    protected function setUp(): void
    {
        // Session sans cookie (le sous-processus Auth peut appeler
        // setcookie/logout : pas d'en-tête à émettre en CLI). Une session
        // laissée ouverte par un test précédent (ordre aléatoire) est
        // refermée d'abord : les ini de session n'exigent sinon pas de
        // changement à chaud.
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }
        ini_set('session.use_cookies', '0');
        @session_start();
        $_SESSION = [];
        FakeController::$called = false;

        // Base de test si joignable : on branche les modèles dessus et on
        // sème les comptes simulés (ids dédiés, sans toucher aux autres).
        $this->pdo = $this->connect();
        if ($this->pdo !== null) {
            Model::setTestPdo($this->pdo);
            $this->pdo->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([
                'u_router_president',
                'u_router_admin',
            ]);
            foreach ([
                ['u_router_president', 'router-president@test.fr', Auth::ROLE_PRESIDENT],
                ['u_router_admin', 'router-admin@test.fr', Auth::ROLE_ADMIN],
            ] as [$id, $email, $role]) {
                $this->pdo->prepare(
                    'INSERT INTO users (id, prenom, nom, email, password, role, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, 1)'
                )->execute([$id, 'Router', 'Test', $email, null, $role]);
            }
        } else {
            Model::setTestPdo(null);
        }
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([
                'u_router_president',
                'u_router_admin',
            ]);
        }
        Model::setTestPdo(null);
        $_SESSION = [];
        FakeController::$called = false;
    }

    /**
     * Simule une requête via Router::dispatch et renvoie [code, sortie].
     *
     * @return array{0:int, 1:string}
     */
    private function dispatchCapture(string $method, string $path, string $userId, string $role): array
    {
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_role'] = $role;
        $_SESSION['_last_regen'] = time();

        $router = new Router();
        $router->post('/admin/analytics/objectif', [FakeController::class, 'index']);
        $router->get('/admin', [FakeController::class, 'index']);

        ob_start();
        try {
            $router->dispatch($method, $path);
        } finally {
            $out = (string) ob_get_clean();
        }

        return [http_response_code() ?: 200, $out];
    }

    public function test_dispatch_post_admin_bloque_pour_president(): void
    {
        [$code, $out] = $this->dispatchCapture(
            'POST',
            '/admin/analytics/objectif',
            'u_router_president',
            Auth::ROLE_PRESIDENT
        );

        self::assertSame(403, $code, 'Un président ne doit jamais POSTer sous /admin.');
        self::assertStringContainsString('Consultation seule', $out);
        self::assertFalse(FakeController::$called, 'Le contrôleur ne doit pas être atteint.');
    }

    public function test_dispatch_post_admin_passe_pour_admin(): void
    {
        [$code, $out] = $this->dispatchCapture(
            'POST',
            '/admin/analytics/objectif',
            'u_router_admin',
            Auth::ROLE_ADMIN
        );

        self::assertStringNotContainsString('Erreur 403', $out);
        self::assertTrue(FakeController::$called, 'Un ADMIN atteint le contrôleur (bloc président uniquement).');
        self::assertNotSame(403, $code);
    }

    public function test_dispatch_get_admin_autorise_pour_president(): void
    {
        // La lecture reste possible : seul le POST est visé par le bloc.
        [$code, $out] = $this->dispatchCapture('GET', '/admin', 'u_router_president', Auth::ROLE_PRESIDENT);

        self::assertStringNotContainsString('Erreur 403', $out);
        self::assertTrue(FakeController::$called, 'Un président peut consulter les pages admin (GET).');
        self::assertNotSame(403, $code);
    }

    public function test_match_route_get_simple(): void
    {
        $router = new Router();
        $router->get('/', [FakeController::class, 'index']);

        $match = $router->match('GET', '/');

        self::assertSame(200, $match['status']);
        self::assertSame([FakeController::class, 'index'], $match['handler']);
        self::assertSame([], $match['params']);
    }

    public function test_match_head_est_traite_comme_get(): void
    {
        // Sémantique HTTP : HEAD = GET sans corps. Les fetchers de sitemap
        // (Google Search Console, moniteurs) sondent souvent en HEAD.
        $router = new Router();
        $router->get('/sitemap.xml', [FakeController::class, 'index']);

        $match = $router->match('HEAD', '/sitemap.xml');

        self::assertSame(200, $match['status']);
        self::assertSame([FakeController::class, 'index'], $match['handler']);
    }

    public function test_head_sur_une_route_post_reste_405(): void
    {
        $router = new Router();
        $router->post('/jeux/score', [FakeController::class, 'index']);

        $match = $router->match('HEAD', '/jeux/score');

        self::assertSame(405, $match['status']);
    }

    public function test_match_extraire_parametre_slug(): void
    {
        $router = new Router();
        $router->get('/events/{slug}', [FakeController::class, 'show']);

        $match = $router->match('GET', '/events/soiree-integration-2026');

        self::assertSame(200, $match['status']);
        self::assertSame(['slug' => 'soiree-integration-2026'], $match['params']);
    }

    public function test_match_extraire_parametre_entier(): void
    {
        $router = new Router();
        $router->get('/events/{id:int}', [FakeController::class, 'show']);

        $match = $router->match('GET', '/events/42');

        self::assertSame(200, $match['status']);
        self::assertSame(['id' => '42'], $match['params']);
    }

    public function test_match_404_si_route_inconnue(): void
    {
        $router = new Router();
        $router->get('/', [FakeController::class, 'index']);

        $match = $router->match('GET', '/page-inconnue');

        self::assertSame(404, $match['status']);
    }

    public function test_match_405_si_mauvaise_methode(): void
    {
        $router = new Router();
        $router->get('/events', [FakeController::class, 'index']);

        $match = $router->match('POST', '/events');

        self::assertSame(405, $match['status']);
    }

    public function test_match_ignore_la_query_string(): void
    {
        $router = new Router();
        $router->get('/events/{slug}', [FakeController::class, 'show']);

        $match = $router->match('GET', '/events/lan?ref=home');

        self::assertSame(200, $match['status']);
        self::assertSame(['slug' => 'lan'], $match['params']);
    }

    public function test_match_decode_les_parametres_url_encodes(): void
    {
        // Les clés produits contiennent espaces/accents (ex. « Chips BBQ »),
        // passés %20 dans l'URL : le paramètre extrait doit être décodé.
        $router = new Router();
        $router->post('/admin/compta/inventaire/{key}/discontinue', [FakeController::class, 'show']);

        $match = $router->match('POST', '/admin/compta/inventaire/Chips%20BBQ/discontinue');

        self::assertSame(200, $match['status']);
        self::assertSame(['key' => 'Chips BBQ'], $match['params']);
    }

    public function test_match_decode_conserve_le_plus_litteral(): void
    {
        // Dans un chemin, « + » est littéral (seul %20 représente l'espace).
        $router = new Router();
        $router->get('/tag/{name}', [FakeController::class, 'show']);

        $match = $router->match('GET', '/tag/rouge%20et%20bleu%2Bvert');

        self::assertSame(200, $match['status']);
        self::assertSame(['name' => 'rouge et bleu+vert'], $match['params']);
    }
}

/**
 * Contrôleur factice utilisé uniquement pour les tests.
 */
final class FakeController
{
    public static bool $called = false;

    public function index(): void
    {
        self::$called = true;
    }

    public function show(string $slug = ''): void
    {
        self::$called = true;
    }
}
