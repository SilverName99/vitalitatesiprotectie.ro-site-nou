<?php

declare(strict_types=1);

namespace App\Http;

final class Router
{
    private array $routes = [];

    /** @var callable(string):void|null */
    private $notFoundHandler = null;

    /**
     * Ce se întâmplă când nicio rută nu se potrivește. Folosit pentru
     * redirecționarea adreselor vechi înainte de a răspunde 404.
     */
    public function setNotFoundHandler(callable $handler): void
    {
        $this->notFoundHandler = $handler;
    }

    public function get(string $pattern, callable|array $handler): void
    {
        $this->addRoute('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable|array $handler): void
    {
        $this->addRoute('POST', $pattern, $handler);
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }
        if ($path !== '/') {
            $path = rtrim($path, '/');
            if ($path === '') {
                $path = '/';
            }
        }
        $methodRoutes = $this->routes[$method] ?? [];

        foreach ($methodRoutes as $route) {
            [$regex, $handler] = $route;
            if (!preg_match($regex, $path, $matches)) {
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (!is_int($key)) {
                    $params[$key] = $value;
                }
            }

            if (is_array($handler)) {
                [$class, $action] = $handler;
                $controller = new $class();
                if ($params === []) {
                    $controller->{$action}();
                } else {
                    $controller->{$action}($params);
                }
                return;
            }

            if ($params === []) {
                $handler();
            } else {
                $handler($params);
            }
            return;
        }

        if ($this->notFoundHandler !== null) {
            ($this->notFoundHandler)($path);
            return;
        }

        http_response_code(404);
        echo 'Pagina nu a fost găsită.';
    }

    private function addRoute(string $method, string $pattern, callable|array $handler): void
    {
        $regexPattern = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\*\}#', '(?P<$1>.+)', $pattern);
        if (!is_string($regexPattern)) {
            $regexPattern = $pattern;
        }
        $regexPattern = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $regexPattern);
        if (!is_string($regexPattern)) {
            $regexPattern = $pattern;
        }
        $regex = '#^' . $regexPattern . '$#';
        $this->routes[$method][] = [$regex, $handler];
    }
}
