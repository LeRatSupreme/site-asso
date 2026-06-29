<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Routeur HTTP simple.
 *
 * Usage :
 *   $router = new Router();
 *   $router->get('/', [HomeController::class, 'index']);
 *   $router->get('/events/{slug}', [EventController::class, 'show']);
 *   $router->dispatch($_SERVER['REQUEST_METHOD'], $path);
 *
 * Les paramètres d'URL sont notés {nom} ; ils acceptent par défaut tout
 * segment sans slash. On peut contraindre via un suffixe de type :
 *   {id:int}  -> [0-9]+
 *   {slug}    -> [^/]+
 */
final class Router
{
    /** @var array<int, array{method:string, pattern:string, handler:array{0:class-string,1:string}, params:array<string,string>}> */
    private array $routes = [];

    /** Handler affiché en cas de 404. */
    private ?array $notFoundHandler = null;

    /** Handler affiché en cas de 405 (méthode non autorisée). */
    private ?array $methodNotAllowedHandler = null;

    /**
     * Enregistre une route GET.
     *
     * @param array{0:class-string,1:string} $handler
     */
    public function get(string $path, array $handler): self
    {
        return $this->add('GET', $path, $handler);
    }

    /**
     * Enregistre une route POST.
     *
     * @param array{0:class-string,1:string} $handler
     */
    public function post(string $path, array $handler): self
    {
        return $this->add('POST', $path, $handler);
    }

    /**
     * Définit le contrôleur chargé des erreurs 404.
     *
     * @param array{0:class-string,1:string} $handler
     */
    public function notFound(array $handler): self
    {
        $this->notFoundHandler = $handler;

        return $this;
    }

    /**
     * Définit le contrôleur chargé des erreurs 405.
     *
     * @param array{0:class-string,1:string} $handler
     */
    public function methodNotAllowed(array $handler): self
    {
        $this->methodNotAllowedHandler = $handler;

        return $this;
    }

    /**
     * Tente de faire correspondre une requête à une route et renvoie le résultat.
     *
     * @return array{status:int, handler:?array, params:array<string,string>}
     */
    public function match(string $method, string $path): array
    {
        $method = strtoupper($method);
        $path = '/' . trim(parse_url($path, PHP_URL_PATH) ?? $path, '/');

        $pathExists = false;

        foreach ($this->routes as $route) {
            $pattern = $this->compile($route['pattern'], $route['params']);
            if (preg_match($pattern, $path, $matches)) {
                $pathExists = true;

                if ($route['method'] !== $method) {
                    continue;
                }

                $params = [];
                foreach ($route['params'] as $name => $_) {
                    $params[$name] = $matches[$name];
                }

                return ['status' => 200, 'handler' => $route['handler'], 'params' => $params];
            }
        }

        if ($pathExists) {
            return ['status' => 405, 'handler' => $this->methodNotAllowedHandler, 'params' => []];
        }

        return ['status' => 404, 'handler' => $this->notFoundHandler, 'params' => []];
    }

    /**
     * Dispatche la requête : instancie le contrôleur et appelle l'action.
     */
    public function dispatch(string $method, string $path): void
    {
        $method = strtoupper($method);

        // Toute requête POST doit présenter un token CSRF valide.
        if ($method === 'POST' && !Csrf::checkRequest()) {
            http_response_code(403);
            echo '<h1>Erreur 403 — jeton CSRF invalide.</h1>';

            return;
        }

        $match = $this->match($method, $path);

        $handler = $match['handler'];
        if ($handler === null) {
            http_response_code($match['status']);
            echo '<h1>Erreur ' . $match['status'] . '</h1>';

            return;
        }

        http_response_code($match['status']);

        [$class, $action] = $handler;
        $controller = new $class();
        $controller->{$action}(...array_values($match['params']));
    }

    /**
     * @param array{0:class-string,1:string} $handler
     */
    private function add(string $method, string $path, array $handler): self
    {
        $params = [];
        if (preg_match_all('#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([a-zA-Z_]+))?\}#', $path, $m, PREG_SET_ORDER)) {
            foreach ($m as $set) {
                $params[$set[1]] = $set[2] ?? '';
            }
        }

        $this->routes[] = [
            'method'  => strtoupper($method),
            'pattern' => $path,
            'handler' => $handler,
            'params'  => $params,
        ];

        return $this;
    }

    /**
     * Compile un pattern de route en expression régulière.
     *
     * @param array<string,string> $params
     */
    private function compile(string $pattern, array $params): string
    {
        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([a-zA-Z_]+))?\}#',
            static function (array $m) use ($params): string {
                $name = $m[1];
                $type = $params[$name] ?? '';
                $constraint = match ($type) {
                    'int', 'id' => '[0-9]+',
                    'slug'      => '[a-zA-Z0-9\-_]+',
                    default     => '[^/]+',
                };

                return '(?P<' . $name . '>' . $constraint . ')';
            },
            $pattern
        );

        // Le délimiteur '#' autorise les '/' littéraux sans échappement.
        return '#^' . $regex . '$#';
    }
}
