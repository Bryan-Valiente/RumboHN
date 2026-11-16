<?php

declare(strict_types=1);

namespace Rumbo\Backend;

use Rumbo\Backend\Auth\AuthenticatedUser;
use Rumbo\Backend\Auth\AuthRepository;
use Rumbo\Backend\Auth\AuthService;
use Rumbo\Backend\Auth\PasswordResetDelivery;
use Rumbo\Backend\Auth\SessionCookieManager;
use Rumbo\Backend\Auth\UnavailablePasswordResetDelivery;
use Rumbo\Backend\Financial\FinancialSummaryRepository;
use Rumbo\Backend\Financial\MovementRepository;
use Rumbo\Backend\Financial\MovementValidator;
use Rumbo\Backend\Http\ApiException;
use Rumbo\Backend\Http\JsonResponse;
use Rumbo\Backend\Http\Request;
use Rumbo\Backend\Http\Router;
use Rumbo\Backend\Idempotency\IdempotencyKey;
use Rumbo\Backend\Pagination\CursorCodec;
use Rumbo\Backend\Profile\ProfileRepository;

final class Routes
{
    public static function register(
        Router $router,
        Database $database,
        Config $config,
        string $catalogPath,
        ?PasswordResetDelivery $delivery = null,
    ): void {
        /** @var list<array{0: string, 1: string, 2: string}> $catalog */
        $catalog = require $catalogPath;
        $delivery ??= new UnavailablePasswordResetDelivery();
        $cookies = new SessionCookieManager($config);

        $profiles = static fn (): ProfileRepository => new ProfileRepository($database->connection());
        $auth = static fn (): AuthService => new AuthService(
            new AuthRepository($database->connection()),
            $profiles(),
            $config,
            $delivery,
        );
        $movements = static fn (): MovementRepository => new MovementRepository($database->connection());
        $summary = static fn (): FinancialSummaryRepository => new FinancialSummaryRepository($database->connection());

        $handlers = [];
        $handlers['GET /api/v1/health'] = static function (Request $request) use ($database): never {
            try {
                $database->connection()->query('SELECT 1')->fetchColumn();
                JsonResponse::success(['service' => 'rumbo-api', 'status' => 'ok', 'database' => 'available'], $request->requestId);
            } catch (\Throwable) {
                JsonResponse::error(503, 'SERVICE_UNAVAILABLE', 'El servicio no está disponible.', $request->requestId);
            }
        };

        $handlers['POST /api/v1/auth/register'] = static function (Request $request) use ($config, $auth): never {
            if (!$config->registrationEnabled()) {
                throw new ApiException(503, 'AUTH_NOT_AVAILABLE', 'El registro todavía no está habilitado.');
            }
            $auth()->register($request->json());
            JsonResponse::success(['status' => 'pending-verification'], $request->requestId, 202);
        };

        $handlers['POST /api/v1/auth/login'] = static function (Request $request) use ($config, $auth, $cookies): never {
            if (!$config->loginEnabled()) {
                throw new ApiException(503, 'AUTH_NOT_AVAILABLE', 'El inicio de sesión todavía no está habilitado.');
            }
            $result = $auth()->login($request->json(), $request->clientIp, $request->userAgent);
            $cookies->issue(
                (string) $result['sessionToken'],
                (string) $result['csrfToken'],
                (int) $result['expiresAtUnix'],
            );
            JsonResponse::success([
                'profile' => $result['profile'],
                'session' => ['expiresAt' => $result['expiresAt']],
                'csrfToken' => $result['csrfToken'],
            ], $request->requestId);
        };

        $handlers['POST /api/v1/auth/logout'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
        ) use ($auth, $cookies): never {
            self::requireUser($user);
            $auth()->logout($user->sessionId);
            $cookies->clear();
            JsonResponse::success(['loggedOut' => true], $request->requestId);
        };

        $handlers['GET /api/v1/auth/session'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
        ) use ($profiles): never {
            self::requireUser($user);
            JsonResponse::success([
                'profile' => $profiles()->get($user->id),
                'session' => ['expiresAt' => $user->sessionExpiresAt],
                'csrfToken' => $user->csrfToken,
            ], $request->requestId);
        };

        $handlers['POST /api/v1/auth/password/forgot'] = static function (Request $request) use ($config, $auth, $delivery): never {
            if (!$config->passwordRecoveryEnabled() || !$delivery->isAvailable()) {
                throw new ApiException(503, 'AUTH_NOT_AVAILABLE', 'La recuperación de contraseña todavía no está habilitada.');
            }
            $service = $auth();
            $service->forgotPassword($request->json());
            JsonResponse::success([
                'message' => 'Si la cuenta es elegible, se enviarán instrucciones de recuperación.',
            ], $request->requestId, 202);
        };

        $handlers['POST /api/v1/auth/password/reset'] = static function (Request $request) use ($config, $auth, $cookies, $delivery): never {
            if (!$config->passwordRecoveryEnabled() || !$delivery->isAvailable()) {
                throw new ApiException(503, 'AUTH_NOT_AVAILABLE', 'La recuperación de contraseña todavía no está habilitada.');
            }
            $service = $auth();
            $service->resetPassword($request->json());
            $cookies->clear();
            JsonResponse::success(['passwordReset' => true], $request->requestId);
        };

        $handlers['GET /api/v1/me/profile'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
        ) use ($profiles): never {
            self::requireUser($user);
            JsonResponse::success($profiles()->get($user->id), $request->requestId);
        };

        $handlers['PATCH /api/v1/me/profile'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
        ) use ($profiles): never {
            self::requireUser($user);
            JsonResponse::success($profiles()->update($user->id, $request->json()), $request->requestId);
        };

        $handlers['GET /api/v1/movements'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
        ) use ($movements, $config): never {
            self::requireUser($user);
            $limitValue = $request->query('limit');
            $limit = $limitValue === null ? 25 : filter_var($limitValue, FILTER_VALIDATE_INT);
            if ($limit === false || $limit < 1 || $limit > 100) {
                throw new ApiException(422, 'VALIDATION_ERROR', 'El límite debe estar entre 1 y 100.', ['field' => 'limit']);
            }
            $codec = new CursorCodec($config->securityPepper());
            $cursorValue = $request->query('cursor');
            $cursor = null;
            if ($cursorValue !== null) {
                if (!is_string($cursorValue)) {
                    throw new ApiException(422, 'VALIDATION_ERROR', 'El cursor no es válido.', ['field' => 'cursor']);
                }
                $cursor = $codec->decode($cursorValue);
            }
            $result = $movements()->list($user->id, $limit, $cursor);
            $nextCursor = null;
            if ($result['hasMore'] && $result['items'] !== []) {
                $last = $result['items'][count($result['items']) - 1];
                $nextCursor = $codec->encode((string) $last['date'], (string) $last['id']);
            }
            JsonResponse::success($result['items'], $request->requestId, 200, [
                'nextCursor' => $nextCursor,
                'hasMore' => $result['hasMore'],
            ]);
        };

        $handlers['POST /api/v1/movements'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
        ) use ($movements): never {
            self::requireUser($user);
            try {
                $key = IdempotencyKey::normalize($request->header('idempotency-key'));
            } catch (\InvalidArgumentException $exception) {
                throw new ApiException(422, 'VALIDATION_ERROR', $exception->getMessage(), ['field' => 'Idempotency-Key']);
            }
            $result = $movements()->create($user->id, MovementValidator::create($request->json()), $key);
            JsonResponse::success(
                $result['movement'],
                $request->requestId,
                $result['replayed'] ? 200 : 201,
                ['idempotencyReplayed' => $result['replayed']],
            );
        };

        $handlers['GET /api/v1/movements/{movementId}'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
            array $params,
        ) use ($movements): never {
            self::requireUser($user);
            JsonResponse::success($movements()->get($user->id, (string) $params['movementId']), $request->requestId);
        };

        $handlers['PATCH /api/v1/movements/{movementId}'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
            array $params,
        ) use ($movements): never {
            self::requireUser($user);
            $repository = $movements();
            $current = $repository->get($user->id, (string) $params['movementId']);
            $updated = MovementValidator::update($current, $request->json());
            JsonResponse::success($repository->update($user->id, (string) $params['movementId'], $updated), $request->requestId);
        };

        $handlers['DELETE /api/v1/movements/{movementId}'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
            array $params,
        ) use ($movements): never {
            self::requireUser($user);
            $movements()->delete($user->id, (string) $params['movementId']);
            JsonResponse::success(['id' => (string) $params['movementId'], 'deleted' => true], $request->requestId);
        };

        $handlers['GET /api/v1/financial-summary'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
        ) use ($summary): never {
            self::requireUser($user);
            JsonResponse::success($summary()->get($user->id), $request->requestId);
        };

        foreach ($catalog as [$method, $path, $access]) {
            $key = $method . ' ' . $path;
            $handler = $handlers[$key] ?? static function (
                Request $request,
                ?AuthenticatedUser $user,
                array $params,
            ): never {
                self::requireUser($user);
                throw new ApiException(501, 'NOT_IMPLEMENTED', 'La operación autenticada todavía no está implementada.');
            };
            $router->add($method, $path, $access, $handler);
        }
    }

    private static function requireUser(?AuthenticatedUser $user): void
    {
        if (!$user instanceof AuthenticatedUser) {
            throw new ApiException(401, 'AUTH_REQUIRED', 'Se requiere una sesión válida.');
        }
    }
}
