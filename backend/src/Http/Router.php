<?php

declare(strict_types=1);

namespace Rumbo\Backend\Http;

use Rumbo\Backend\Auth\AuthenticatedUser;
use Rumbo\Backend\Auth\SessionAuthenticator;

final class Router
{
    /** @var list<array{method: string, path: string, regex: string, protected: bool, handler: callable}> */
    private array $routes = [];

    public function __construct(private readonly SessionAuthenticator $authenticator)
    {
    }

    public function add(string $method, string $path, bool $protected, callable $handler): void
    {
        $normalizedPath = '/' . ltrim($path, '/');
        $quoted = preg_quote($normalizedPath, '#');
        $regex = preg_replace('/\\\{([A-Za-z][A-Za-z0-9_]*)\\\}/', '(?P<$1>[A-Za-z0-9-]{1,64})', $quoted);
        if ($regex === null) {
            throw new \RuntimeException('No se pudo compilar la ruta.');
        }

        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $normalizedPath,
            'regex' => '#^' . $regex . '$#',
            'protected' => $protected,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): never
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method || !preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }

            $params = array_filter($matches, static fn (string|int $key): bool => is_string($key), ARRAY_FILTER_USE_KEY);
            $user = $route['protected'] ? $this->authenticator->authenticate($request) : null;
            ($route['handler'])($request, $user, $params);
            throw new \LogicException('El handler de API debe finalizar la respuesta.');
        }

        throw new ApiException(404, 'NOT_FOUND', 'La ruta solicitada no existe.');
    }
}
