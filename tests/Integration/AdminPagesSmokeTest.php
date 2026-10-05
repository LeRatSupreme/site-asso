<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Router;

/**
 * Parcours de TOUTES les pages GET de l'application (« smoke test » global).
 *
 * Les routes sont énumérées DYNAMIQUEMENT depuis app/config/routes.php :
 * toute nouvelle page ajoutée au routeur est automatiquement couverte, et
 * une page qui casse (exception non capturée, variable manquante, warning
 * PHP affiché…) fait échouer la suite au lieu de passer inaperçue en
 * production.
 *
 * Pour chaque page, on vérifie :
 *   - pas de 500 (exception non capturée par le runner) ;
 *   - pas de fatal / parse error / warning / notice PHP dans la sortie ;
 *   - pas de stack trace exposée ;
 *   - code HTTP 200 (ou 302 pour les redirections légitimes : login déjà
 *     authentifié, logout, set-lang…).
 *
 * Les routes paramétrées ({slug}, {id}…) sont appelées avec des valeurs
 * inexistantes : le contrôleur doit répondre proprement (200, 302 «
 * introuvable » ou 404) mais jamais planter.
 *
 * Accessibilité : un invité ne doit accéder à aucune page /admin/* (403 par
 * le routeur) et /eleve/* doit le renvoyer vers /login ; un élève est refusé
 * (403) sur tout l'espace admin ; les pages élève restent accessibles à un
 * élève.
 *
 * Base `aeic_test` requise (les tests sont sautés sinon).
 */
final class AdminPagesSmokeTest extends IntegrationTestCase
{
    private string $rootId  = 'u_smoke_root';
    private string $eleveId = 'u_smoke_eleve';

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->requireDatabase();
        // wordle_words : l'API /jeux/wordle/word renvoie un 404 légitime
        // (« no_word_available ») si aucun mot n'existe — on en seed un
        // pour que la page réponde 200 comme en production.
        $this->reset(['users', 'wordle_words']);
        $this->seedUser($this->rootId, 'smoke-root@exemple.fr', 'Password123456', 'SUPERADMIN');
        $this->seedUser($this->eleveId, 'smoke-eleve@exemple.fr', 'Password123456', 'ELEVE');

        $pdo->prepare(
            "INSERT INTO wordle_words (word, language, length, difficulty, is_active)
             VALUES ('POMME', 'fr', 5, 'facile', 1)"
        )->execute();
    }

    // -----------------------------------------------------------------
    //  Énumération dynamique des routes
    // -----------------------------------------------------------------

    /**
     * Routes GET déclarées dans app/config/routes.php.
     *
     * @return list<string> Patterns bruts (ex. « /admin/events/{slug} »).
     */
    private function getRoutes(): array
    {
        require_once AEIC_ROOT . '/app/config/routes.php';

        $router = new Router();
        aeic_register_routes($router);

        $prop = new \ReflectionProperty(Router::class, 'routes');
        $prop->setAccessible(true);

        $paths = [];
        foreach ($prop->getValue($router) as $route) {
            if ($route['method'] === 'GET') {
                $paths[] = (string) $route['pattern'];
            }
        }

        return $paths;
    }

    /**
     * Pages GET sans paramètre (parcours complet attendu : 200/302).
     *
     * @return list<array{path:string, parameterized:false}>
     */
    private function concretePaths(): array
    {
        $paths = [];
        foreach ($this->getRoutes() as $pattern) {
            if (!str_contains($pattern, '{')) {
                $paths[] = ['path' => $pattern, 'parameterized' => false];
            }
        }

        return $paths;
    }

    /**
     * Pages GET paramétrées, appelées avec des valeurs inexistantes
     * (réponse gracieuse attendue : 200, 302 ou 404 — jamais 500).
     *
     * @return list<array{path:string, parameterized:true}>
     */
    private function parameterizedPaths(): array
    {
        $paths = [];
        foreach ($this->getRoutes() as $pattern) {
            if (str_contains($pattern, '{')) {
                $paths[] = [
                    'path'          => (string) preg_replace('#\{[^}]+\}#', 'inexistant-smoke', $pattern),
                    'parameterized' => true,
                ];
            }
        }

        return $paths;
    }

    // -----------------------------------------------------------------
    //  Parcours complet
    // -----------------------------------------------------------------

    public function test_toutes_les_pages_get_repondent_sans_erreur(): void
    {
        $pages = array_merge($this->concretePaths(), $this->parameterizedPaths());

        // Garde-fou : si l'énumération casse, le test ne doit pas passer
        // silencieusement sur une liste vide.
        self::assertGreaterThanOrEqual(
            50,
            count($pages),
            'Énumération des routes GET suspecte (moins de 50 pages) : vérifier app/config/routes.php.'
        );

        $failures = [];
        foreach ($pages as $page) {
            $path    = $page['path'];
            $r       = $this->request('GET', $path, [], [], $this->rootId);
            $code    = (int) ($r['code'] ?? 0);
            $body    = (string) ($r['body'] ?? '');

            $erreur = $this->bodyError($body);
            if ($erreur !== null) {
                $failures[] = sprintf('GET %s → %s (code %d)', $path, $erreur, $code);
                continue;
            }

            // Route paramétrée appelée avec une valeur inexistante : le
            // contrôleur DOIT répondre gracieusement, 404 inclus (page
            // « introuvable », JSON d'erreur…). Les codes 500/403 restent
            // des échecs — un 403 sur une ressource inexistante révèle un
            // guard posé avant la recherche, un 500 un crash.
            $autorisés = $page['parameterized'] ? [200, 302, 404] : [200, 302];
            if (!in_array($code, $autorisés, true)) {
                $failures[] = sprintf(
                    'GET %s → code HTTP %d inattendu : %s',
                    $path,
                    $code,
                    $this->excerpt($body)
                );
            }
        }

        self::assertSame(
            [],
            $failures,
            count($failures) . " page(s) en erreur :\n" . implode("\n", $failures)
        );
    }

    // -----------------------------------------------------------------
    //  Contrôles d'accès (invités / élèves)
    // -----------------------------------------------------------------

    public function test_zones_protegees_refusees_aux_invites_et_eleves(): void
    {
        // Invité : /admin → redirection vers la connexion, avec la page
        // demandée mémorisée (callbackUrl) pour y revenir après login.
        $r = $this->request('GET', '/admin');
        self::assertSame(302, (int) ($r['code'] ?? 0), 'Un invité doit être redirigé vers la connexion.');
        $loc = $this->location($r);
        self::assertStringContainsString('/login', $loc);
        self::assertStringContainsString('callbackUrl', $loc);

        // Invité : /eleve → redirection vers /login.
        $r = $this->request('GET', '/eleve');
        self::assertSame(302, (int) ($r['code'] ?? 0), 'Un invité doit être redirigé depuis /eleve.');
        self::assertStringContainsString('/login', $this->location($r));

        // Élève : refusé sur tout l'espace admin (403), y compris compta et système.
        foreach (['/admin', '/admin/compta', '/admin/users', '/admin/caisses'] as $path) {
            $r = $this->request('GET', $path, [], [], $this->eleveId);
            self::assertSame(
                403,
                (int) ($r['code'] ?? 0),
                sprintf('Un élève doit être refusé (403) sur %s.', $path)
            );
        }
    }

    public function test_pages_eleve_accessibles_a_un_eleve(): void
    {
        $failures = [];
        foreach (['/eleve', '/eleve/profile', '/eleve/inscriptions', '/eleve/commandes', '/eleve/cafeteria'] as $path) {
            $r      = $this->request('GET', $path, [], [], $this->eleveId);
            $code   = (int) ($r['code'] ?? 0);
            $body   = (string) ($r['body'] ?? '');
            $erreur = $this->bodyError($body);

            if ($erreur !== null || $code !== 200) {
                $failures[] = sprintf(
                    'GET %s (élève) → code %d %s',
                    $path,
                    $code,
                    $erreur ?? $this->excerpt($body)
                );
            }
        }

        self::assertSame([], $failures, implode("\n", $failures));
    }

    // -----------------------------------------------------------------
    //  Helpers
    // -----------------------------------------------------------------

    /**
     * Détecte les marqueurs d'erreur dans un corps de page rendu.
     */
    private function bodyError(string $body): ?string
    {
        $marqueurs = [
            'Erreur 500'          => 'exception non capturée',
            'Fatal error'         => 'erreur fatale PHP',
            'Parse error'         => 'erreur de syntaxe PHP',
            'Warning:'            => 'warning PHP affiché',
            'Deprecated:'         => 'dépréciation PHP affichée',
            'Stack trace'         => 'stack trace exposée',
            'Undefined variable'  => 'variable non définie',
            'Undefined property'  => 'propriété non définie',
            'Undefined array key' => 'clé de tableau non définie',
        ];

        foreach ($marqueurs as $marqueur => $libellé) {
            if (str_contains($body, $marqueur)) {
                return $libellé . ' (« ' . $marqueur . ' »)';
            }
        }

        return null;
    }

    /**
     * Extrait court et lisible d'un corps de réponse (messages d'échec).
     */
    private function excerpt(string $body): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');

        return mb_substr($text, 0, 200);
    }
}
