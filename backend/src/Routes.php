<?php

declare(strict_types=1);

namespace Rumbo\Backend;

use Rumbo\Backend\Auth\AuthenticatedUser;
use Rumbo\Backend\Http\ApiException;
use Rumbo\Backend\Http\JsonResponse;
use Rumbo\Backend\Http\Request;
use Rumbo\Backend\Http\Router;

final class Routes
{
    public static function register(Router $router, Database $database, string $catalogPath): void
    {
        /** @var list<array{0: string, 1: string, 2: string}> $catalog */
        $catalog = require $catalogPath;

        foreach ($catalog as [$method, $path, $access]) {
            if ($path === '/api/v1/health') {
                $router->add($method, $path, false, static function (Request $request) use ($database): never {
                    try {
                        $database->connection()->query('SELECT 1')->fetchColumn();
                        JsonResponse::success(['service' => 'rumbo-api', 'status' => 'ok', 'database' => 'available'], $request->requestId);
                    } catch (\Throwable) {
                        JsonResponse::error(503, 'SERVICE_UNAVAILABLE', 'El servicio no está disponible.', $request->requestId);
                    }
                });
                continue;
            }

            if ($access === 'disabled') {
                $router->add($method, $path, false, static function (Request $request): never {
                    throw new ApiException(503, 'AUTH_NOT_AVAILABLE', 'La autenticación todavía no está habilitada.');
                });
                continue;
            }

            $router->add($method, $path, true, static function (
                Request $request,
                ?AuthenticatedUser $user,
                array $params,
            ): never {
                if (!$user instanceof AuthenticatedUser) {
                    throw new ApiException(401, 'AUTH_REQUIRED', 'Se requiere una sesión válida.');
                }

                throw new ApiException(501, 'NOT_IMPLEMENTED', 'La operación autenticada todavía no está implementada.');
            });
        }
    }
}
