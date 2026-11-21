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

$runtimeRoot = getenv('RUMBO_RUNTIME_ROOT');
if (!is_string($runtimeRoot) || trim($runtimeRoot) === '') {
    $runtimeRoot = dirname(__DIR__);
}
$backendRoot = require rtrim($runtimeRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'bootstrap.php';
$request = Request::fromGlobals();

try {
    $config = new Config();
    $request = Request::fromGlobals($config->basePath());
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
