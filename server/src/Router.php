<?php

declare(strict_types=1);

namespace App;

final class Router
{
    /** @var list<array{string, string, callable}> */
    private array $routes = [];

    /** Pattern placeholders like {id} match digits. */
    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = '#^' . preg_replace('/\{(\w+)\}/', '(?P<$1>\d+)', $pattern) . '$#';
        $this->routes[] = [$method, $regex, $handler];
    }

    public function dispatch(Request $request): ?Response
    {
        $allowed = false;
        foreach ($this->routes as [$method, $regex, $handler]) {
            if (!preg_match($regex, $request->path, $m)) {
                continue;
            }
            if ($method !== $request->method && !($method === 'GET' && $request->method === 'HEAD')) {
                $allowed = true;
                continue;
            }
            $params = array_map('intval', array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY));
            return $handler($request, ...$params);
        }
        return $allowed ? new Response('Method Not Allowed', 405) : null;
    }
}
