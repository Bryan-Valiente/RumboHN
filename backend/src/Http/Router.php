<?php

declare(strict_types=1);

namespace Rumbo\Backend\Http;

use Rumbo\Backend\Auth\AdminAuthorizer;
use Rumbo\Backend\Auth\AuthenticatedUser;
use Rumbo\Backend\Auth\CsrfGuard;
use Rumbo\Backend\Auth\SessionAuthenticator;
use Rumbo\Backend\Config;

final class Router
{
    /** @var list<array{method: string, path: string, regex: string, access: string, handler: callable}> */
    private array $routes = [];
    private readonly CsrfGuard $csrfGuard;

    public function __construct(private readonly SessionAuthenticator $authenticator, ?CsrfGuard $csrfGuard = null)
    {
        $this->csrfGuard = $csrfGuard ?? new CsrfGuard(new Config());
    }

    public function add(string $method, string $path, bool|string $access, callable $handler): void
    {
        $normalizedPath = '/' . ltrim($path, '/');
        $quoted = preg_quote($normalizedPath, '#');
        $regex = preg_replace('/\\\{([A-Za-z][A-Za-z0-9_]*)\\\}/', '(?P<$1>[A-Za-z0-9-]{1,64})', $quoted);
        if ($regex === null) {
            throw new \RuntimeException('No se pudo compilar la ruta.');
        }

        if (is_bool($access)) {
            $access = $access ? 'protected' : 'public';
        }
        if (!in_array($access, ['public', 'protected', 'admin'], true)) {
            throw new \InvalidArgumentException('El nivel de acceso de la ruta no es válido.');
        }

        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $normalizedPath,
            'regex' => '#^' . $regex . '$#',
            'access' => $access,
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
            $user = $route['access'] === 'public' ? null : $this->authenticator->authenticate($request);
            if ($user instanceof AuthenticatedUser) {
                $this->csrfGuard->assertValid($request, $user);
                if ($route['access'] === 'admin') {
                    AdminAuthorizer::assertAdmin($user);
                }
            }
            ($route['handler'])($request, $user, $params);
            throw new \LogicException('El handler de API debe finalizar la respuesta.');
        }

        throw new ApiException(404, 'NOT_FOUND', 'La ruta solicitada no existe.');
    }
}
