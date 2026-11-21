<?php

declare(strict_types=1);

namespace Rumbo\Backend;

use Rumbo\Backend\Admin\AdminRepository;
use Rumbo\Backend\Auth\AuthenticatedUser;
use Rumbo\Backend\Auth\AuthRepository;
use Rumbo\Backend\Auth\AuthService;
use Rumbo\Backend\Auth\PasswordResetDelivery;
use Rumbo\Backend\Auth\SessionCookieManager;
use Rumbo\Backend\Auth\UnavailablePasswordResetDelivery;
use Rumbo\Backend\Financial\BudgetRepository;
use Rumbo\Backend\Financial\FinancialInput;
use Rumbo\Backend\Financial\FinancialReportRepository;
use Rumbo\Backend\Financial\FinancialSummaryRepository;
use Rumbo\Backend\Financial\GoalRepository;
use Rumbo\Backend\Financial\MovementRepository;
use Rumbo\Backend\Financial\MovementValidator;
use Rumbo\Backend\Financial\ReminderRepository;
use Rumbo\Backend\Http\ApiException;
use Rumbo\Backend\Http\JsonResponse;
use Rumbo\Backend\Http\Request;
use Rumbo\Backend\Http\Router;
use Rumbo\Backend\Idempotency\IdempotencyKey;
use Rumbo\Backend\Notifications\NotificationRepository;
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
        $budgets = static fn (): BudgetRepository => new BudgetRepository($database->connection());
        $goals = static fn (): GoalRepository => new GoalRepository($database->connection());
        $reminders = static fn (): ReminderRepository => new ReminderRepository($database->connection());
        $notifications = static fn (): NotificationRepository => new NotificationRepository($database->connection());
        $reports = static fn (): FinancialReportRepository => new FinancialReportRepository($database->connection());
        $admin = static fn (): AdminRepository => new AdminRepository(
            $database->connection(),
            $config->registrationUsesEmailVerification(),
        );

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
            JsonResponse::success([
                'status' => $config->registrationMode() === 'admin_approval'
                    ? 'pending-admin-approval'
                    : 'pending-verification',
            ], $request->requestId, 202);
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
            $auth()->forgotPassword($request->json());
            JsonResponse::success([
                'message' => 'Si la cuenta es elegible, se enviarán instrucciones de recuperación.',
            ], $request->requestId, 202);
        };

        $handlers['POST /api/v1/auth/password/reset'] = static function (Request $request) use ($config, $auth, $cookies, $delivery): never {
            if (!$config->passwordRecoveryEnabled() || !$delivery->isAvailable()) {
                throw new ApiException(503, 'AUTH_NOT_AVAILABLE', 'La recuperación de contraseña todavía no está habilitada.');
            }
            $auth()->resetPassword($request->json());
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

        $handlers['POST /api/v1/admin/auth/login'] = static function (Request $request) use ($config, $auth, $cookies): never {
            if (!$config->loginEnabled()) {
                throw new ApiException(503, 'AUTH_NOT_AVAILABLE', 'El inicio de sesión todavía no está habilitado.');
            }
            $result = $auth()->loginAdmin($request->json(), $request->clientIp, $request->userAgent);
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

        $handlers['POST /api/v1/movements'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
        ) use ($movements): never {
            self::requireUser($user);
            $result = $movements()->create(
                $user->id,
                MovementValidator::create($request->json()),
                self::idempotencyKey($request),
            );
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
        ) use ($summary, $reports): never {
            self::requireUser($user);
            $from = $request->query('from');
            $to = $request->query('to');
            $category = $request->query('category');
            if ($from === null && $to === null && $category === null) {
                JsonResponse::success($summary()->get($user->id), $request->requestId);
            }
            JsonResponse::success(
                $reports()->summary($user->id, FinancialReportRepository::filters(
                    is_string($from) ? $from : null,
                    is_string($to) ? $to : null,
                    is_string($category) ? $category : null,
                )),
                $request->requestId,
            );
        };

        $handlers['GET /api/v1/budgets'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
        ) use ($budgets): never {
            self::requireUser($user);
            $activeOn = $request->query('activeOn');
            if ($activeOn !== null && !is_string($activeOn)) {
                throw new ApiException(422, 'VALIDATION_ERROR', 'La fecha no es válida.', ['field' => 'activeOn']);
            }
            $date = $activeOn === null ? null : FinancialInput::date($activeOn, 'activeOn');
            JsonResponse::success($budgets()->list($user->id, $date), $request->requestId);
        };

        $handlers['POST /api/v1/budgets'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
        ) use ($budgets): never {
            self::requireUser($user);
            JsonResponse::success($budgets()->create($user->id, $request->json()), $request->requestId, 201);
        };

        $handlers['PATCH /api/v1/budgets/{budgetId}'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
            array $params,
        ) use ($budgets): never {
            self::requireUser($user);
            JsonResponse::success(
                $budgets()->update($user->id, (string) $params['budgetId'], $request->json()),
                $request->requestId,
            );
        };

        $handlers['DELETE /api/v1/budgets/{budgetId}'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
            array $params,
        ) use ($budgets): never {
            self::requireUser($user);
            $id = (string) $params['budgetId'];
            $budgets()->delete($user->id, $id);
            JsonResponse::success(['id' => $id, 'deleted' => true], $request->requestId);
        };

        $handlers['GET /api/v1/goals'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
        ) use ($goals): never {
            self::requireUser($user);
            JsonResponse::success(
                $goals()->list($user->id, self::queryBoolean($request, 'includeArchived', false)),
                $request->requestId,
            );
        };

        $handlers['POST /api/v1/goals'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
        ) use ($goals): never {
            self::requireUser($user);
            JsonResponse::success($goals()->create($user->id, $request->json()), $request->requestId, 201);
        };

        $handlers['PATCH /api/v1/goals/{goalId}'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
            array $params,
        ) use ($goals): never {
            self::requireUser($user);
            JsonResponse::success($goals()->update($user->id, (string) $params['goalId'], $request->json()), $request->requestId);
        };

        $handlers['DELETE /api/v1/goals/{goalId}'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
            array $params,
        ) use ($goals): never {
            self::requireUser($user);
            $id = (string) $params['goalId'];
            $goals()->archive($user->id, $id);
            JsonResponse::success(['id' => $id, 'archived' => true], $request->requestId);
        };

        $handlers['GET /api/v1/goals/{goalId}/contributions'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
            array $params,
        ) use ($goals): never {
            self::requireUser($user);
            JsonResponse::success($goals()->contributions($user->id, (string) $params['goalId']), $request->requestId);
        };

        $handlers['POST /api/v1/goals/{goalId}/contributions'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
            array $params,
        ) use ($goals): never {
            self::requireUser($user);
            $result = $goals()->contribute(
                $user->id,
                (string) $params['goalId'],
                $request->json(),
                self::idempotencyKey($request),
            );
            JsonResponse::success(
                $result,
                $request->requestId,
                $result['replayed'] ? 200 : 201,
                ['idempotencyReplayed' => $result['replayed']],
            );
        };

        $handlers['GET /api/v1/reminders'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
        ) use ($reminders): never {
            self::requireUser($user);
            JsonResponse::success(
                $reminders()->list($user->id, self::queryBoolean($request, 'includeArchived', false)),
                $request->requestId,
            );
        };

        $handlers['POST /api/v1/reminders'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
        ) use ($reminders): never {
            self::requireUser($user);
            JsonResponse::success($reminders()->create($user->id, $request->json()), $request->requestId, 201);
        };

        $handlers['PATCH /api/v1/reminders/{reminderId}'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
            array $params,
        ) use ($reminders): never {
            self::requireUser($user);
            JsonResponse::success(
                $reminders()->update($user->id, (string) $params['reminderId'], $request->json()),
                $request->requestId,
            );
        };

        $handlers['DELETE /api/v1/reminders/{reminderId}'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
            array $params,
        ) use ($reminders): never {
            self::requireUser($user);
            $id = (string) $params['reminderId'];
            $reminders()->archive($user->id, $id);
            JsonResponse::success(['id' => $id, 'archived' => true], $request->requestId);
        };

        $handlers['GET /api/v1/reminders/{reminderId}/payments'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
            array $params,
        ) use ($reminders): never {
            self::requireUser($user);
            JsonResponse::success(
                $reminders()->payments($user->id, (string) $params['reminderId']),
                $request->requestId,
            );
        };

        $handlers['POST /api/v1/reminders/{reminderId}/payments'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
            array $params,
        ) use ($reminders): never {
            self::requireUser($user);
            $result = $reminders()->confirmPayment(
                $user->id,
                (string) $params['reminderId'],
                $request->json(),
                self::idempotencyKey($request),
            );
            JsonResponse::success(
                $result,
                $request->requestId,
                $result['replayed'] ? 200 : 201,
                ['idempotencyReplayed' => $result['replayed']],
            );
        };

        $handlers['GET /api/v1/reports/daily'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
        ) use ($reports): never {
            self::requireUser($user);
            $from = $request->query('from');
            $to = $request->query('to');
            $category = $request->query('category');
            JsonResponse::success(
                $reports()->daily($user->id, FinancialReportRepository::filters(
                    is_string($from) ? $from : null,
                    is_string($to) ? $to : null,
                    is_string($category) ? $category : null,
                )),
                $request->requestId,
            );
        };

        $handlers['GET /api/v1/notifications'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
        ) use ($notifications): never {
            self::requireUser($user);
            $limit = $request->query('limit');
            if ($limit !== null && (!is_string($limit) || filter_var($limit, FILTER_VALIDATE_INT) === false)) {
                throw new ApiException(422, 'VALIDATION_ERROR', 'El límite no es válido.', ['field' => 'limit']);
            }
            $result = $notifications()->list(
                $user->id,
                self::queryBoolean($request, 'unreadOnly', false),
                $limit === null ? 30 : (int) $limit,
            );
            JsonResponse::success($result['items'], $request->requestId, 200, ['unreadCount' => $result['unreadCount']]);
        };

        $handlers['PATCH /api/v1/notifications/{notificationId}'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
            array $params,
        ) use ($notifications): never {
            self::requireUser($user);
            $data = $request->json();
            if (array_keys($data) !== ['read'] || !is_bool($data['read'] ?? null)) {
                throw new ApiException(422, 'VALIDATION_ERROR', 'La solicitud debe contener el estado read booleano.', [
                    'field' => 'read',
                ]);
            }
            JsonResponse::success(
                $notifications()->markRead($user->id, (string) $params['notificationId'], $data['read']),
                $request->requestId,
            );
        };

        $handlers['GET /api/v1/notification-preferences'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
        ) use ($notifications): never {
            self::requireUser($user);
            JsonResponse::success($notifications()->preferences($user->id), $request->requestId);
        };

        $handlers['PATCH /api/v1/notification-preferences'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
        ) use ($notifications): never {
            self::requireUser($user);
            JsonResponse::success($notifications()->updatePreferences($user->id, $request->json()), $request->requestId);
        };

        $handlers['GET /api/v1/admin/dashboard'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
        ) use ($admin): never {
            self::requireUser($user);
            JsonResponse::success($admin()->dashboard(), $request->requestId);
        };

        $handlers['GET /api/v1/admin/users'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
        ) use ($admin): never {
            self::requireUser($user);
            $limit = $request->query('limit');
            $offset = $request->query('offset');
            $search = $request->query('search');
            $status = $request->query('status');
            if (($limit !== null && !is_string($limit)) || ($offset !== null && !is_string($offset))) {
                throw new ApiException(422, 'VALIDATION_ERROR', 'La paginación no es válida.');
            }
            $result = $admin()->users(
                is_string($search) ? $search : null,
                is_string($status) ? $status : null,
                $limit === null ? 25 : (int) $limit,
                $offset === null ? 0 : (int) $offset,
            );
            JsonResponse::success($result['items'], $request->requestId, 200, ['total' => $result['total']]);
        };

        $handlers['GET /api/v1/admin/users/{userId}'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
            array $params,
        ) use ($admin): never {
            self::requireUser($user);
            JsonResponse::success($admin()->user((string) $params['userId']), $request->requestId);
        };

        $handlers['POST /api/v1/admin/users/{userId}/account'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
            array $params,
        ) use ($admin): never {
            self::requireUser($user);
            JsonResponse::success(
                $admin()->manageAccount($user->id, (string) $params['userId'], $request->json(), $request->requestId),
                $request->requestId,
            );
        };

        $handlers['GET /api/v1/admin/system'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
        ) use ($admin): never {
            self::requireUser($user);
            JsonResponse::success($admin()->systemStatus(), $request->requestId);
        };

        $handlers['GET /api/v1/admin/audit-logs'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
        ) use ($admin): never {
            self::requireUser($user);
            $limit = $request->query('limit');
            JsonResponse::success(
                $admin()->auditLogs($limit === null ? 30 : (int) $limit),
                $request->requestId,
            );
        };

        $handlers['GET /api/v1/admin/auth-events'] = static function (
            Request $request,
            ?AuthenticatedUser $user,
        ) use ($admin): never {
            self::requireUser($user);
            $limit = $request->query('limit');
            JsonResponse::success(
                $admin()->authenticationEvents($limit === null ? 30 : (int) $limit),
                $request->requestId,
            );
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

    private static function idempotencyKey(Request $request): string
    {
        try {
            return IdempotencyKey::normalize($request->header('idempotency-key'));
        } catch (\InvalidArgumentException $exception) {
            throw new ApiException(422, 'VALIDATION_ERROR', $exception->getMessage(), ['field' => 'Idempotency-Key']);
        }
    }

    private static function queryBoolean(Request $request, string $field, bool $default): bool
    {
        $value = $request->query($field);
        if ($value === null || $value === '') {
            return $default;
        }
        if ($value === 'true' || $value === '1') {
            return true;
        }
        if ($value === 'false' || $value === '0') {
            return false;
        }
        throw new ApiException(422, 'VALIDATION_ERROR', 'El parámetro booleano no es válido.', ['field' => $field]);
    }
}
