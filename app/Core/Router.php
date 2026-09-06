<?php declare(strict_types=1);

namespace App\Core;

/**
 * Static whitelist router. Routes are declared in app/routes.php as
 *   ['GET', '/inicio', HomeController::class, 'index']
 *   [['GET', 'POST'], '/login', AuthController::class, 'login']
 * Placeholders `{name}` match [a-z0-9-]{1,40}; an optional trailing segment is
 * written as `[/{name}]`. Trailing slashes are ignored. A path that matches but
 * with the wrong verb yields 405; nothing matched yields 404.
 */
final class Router
{
    /** @var list<array{methods: list<string>, pattern: string, regex: string, controller: class-string, action: string}> */
    private array $routes = [];

    public const PARAM_REGEX = '[a-z0-9-]{1,40}';

    /** @param string|list<string> $methods @param class-string $controller */
    public function add(string|array $methods, string $pattern, string $controller, string $action): void
    {
        $methods = array_map('strtoupper', (array)$methods);
        $this->routes[] = [
            'methods'    => $methods,
            'pattern'    => $pattern,
            'regex'      => $this->compile($pattern),
            'controller' => $controller,
            'action'     => $action,
        ];
    }

    /** @param list<array{0: string|list<string>, 1: string, 2: class-string, 3: string}> $table */
    public function load(array $table): void
    {
        foreach ($table as $row) {
            $this->add($row[0], $row[1], $row[2], $row[3]);
        }
    }

    public function dispatch(Request $request): Response
    {
        $path    = $request->path();
        $method  = $request->method();
        $allowed = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            if (!in_array($method, $route['methods'], true)) {
                $allowed = array_merge($allowed, $route['methods']);
                continue;
            }
            $params = [];
            foreach ($m as $k => $v) {
                if (is_string($k) && $v !== '') {
                    $params[$k] = $v;
                }
            }
            return $this->invoke($route['controller'], $route['action'], $request, $params);
        }

        if ($allowed !== []) {
            throw new HttpException(405, 'Método não permitido', ['Allow' => implode(', ', array_unique($allowed))]);
        }
        throw new HttpException(404, 'Página não encontrada');
    }

    /** @param class-string $controller @param array<string, string> $params */
    private function invoke(string $controller, string $action, Request $request, array $params): Response
    {
        if (!class_exists($controller) || !method_exists($controller, $action)) {
            throw new \LogicException(sprintf('Rota inválida: %s@%s', $controller, $action));
        }
        $instance = new $controller();
        $response = $instance->{$action}($request, $params);
        if (!$response instanceof Response) {
            throw new \LogicException(sprintf('%s@%s deve devolver Response', $controller, $action));
        }
        return $response;
    }

    private function compile(string $pattern): string
    {
        $pattern = '/' . trim($pattern, '/');
        if ($pattern === '/') {
            return '#^/$#';
        }
        $groups = [];
        $token  = static function (string $group) use (&$groups): string {
            $key = '~~' . count($groups) . '~~';   // '~' survives preg_quote untouched
            $groups[$key] = $group;
            return $key;
        };
        // Optional segment: [/{name}] -> (?:/(?P<name>...))?
        $tmp = preg_replace_callback(
            '#\[/\{([a-z_][a-z0-9_]*)\}\]#i',
            static fn(array $m): string => $token('(?:/(?P<' . $m[1] . '>' . self::PARAM_REGEX . '))?'),
            $pattern
        ) ?? $pattern;
        // Required segment: {name}
        $tmp = preg_replace_callback(
            '#\{([a-z_][a-z0-9_]*)\}#i',
            static fn(array $m): string => $token('(?P<' . $m[1] . '>' . self::PARAM_REGEX . ')'),
            $tmp
        ) ?? $tmp;
        // Everything literal is quoted, then the groups are put back.
        $regex = strtr(preg_quote($tmp, '#'), $groups);
        return '#^' . $regex . '$#';
    }
}
