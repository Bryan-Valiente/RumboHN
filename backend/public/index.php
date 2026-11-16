<?php

declare(strict_types=1);

use Rumbo\Backend\Auth\CsrfGuard;
use Rumbo\Backend\Auth\SessionAuthenticator;
use Rumbo\Backend\Config;
use Rumbo\Backend\Database;
use Rumbo\Backend\Http\ApiException;
use Rumbo\Backend\Http\JsonResponse;
use Rumbo\Backend\Http\OriginGuard;
use Rumbo\Backend\Http\Request;
use Rumbo\Backend\Http\Router;
use Rumbo\Backend\Routes;

$backendRoot = require dirname(__DIR__) . '/src/bootstrap.php';
$request = Request::fromGlobals();

try {
    $config = new Config();
    OriginGuard::assertAllowed($request, $config);
    $database = new Database($config);
    $router = new Router(new SessionAuthenticator($database, $config), new CsrfGuard($config));
    Routes::register($router, $database, $config, $backendRoot . '/config/routes.php');
    $router->dispatch($request);
} catch (ApiException $exception) {
    JsonResponse::error(
        $exception->status,
        $exception->errorCode,
        $exception->getMessage(),
        $request->requestId,
        $exception->details,
    );
} catch (Throwable) {
    JsonResponse::error(500, 'INTERNAL_ERROR', 'Ocurrió un error interno.', $request->requestId);
}
