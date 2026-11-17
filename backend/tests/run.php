<?php

declare(strict_types=1);

use Rumbo\Backend\Auth\AccountActivator;
use Rumbo\Backend\Auth\AdminAuthorizer;
use Rumbo\Backend\Auth\AuthenticatedUser;
use Rumbo\Backend\Auth\AuthRepository;
use Rumbo\Backend\Auth\AuthService;
use Rumbo\Backend\Auth\CsrfGuard;
use Rumbo\Backend\Auth\SessionAuthenticator;
use Rumbo\Backend\Auth\SessionCookieManager;
use Rumbo\Backend\Config;
use Rumbo\Backend\Database;
use Rumbo\Backend\Admin\AdminProvisioner;
use Rumbo\Backend\Financial\FinancialSummaryRepository;
use Rumbo\Backend\Financial\Money;
use Rumbo\Backend\Financial\MovementRepository;
use Rumbo\Backend\Financial\MovementValidator;
use Rumbo\Backend\Http\ApiException;
use Rumbo\Backend\Http\OriginGuard;
use Rumbo\Backend\Http\Request;
use Rumbo\Backend\Http\Router;
use Rumbo\Backend\Idempotency\IdempotencyKey;
use Rumbo\Backend\Pagination\CursorCodec;
use Rumbo\Backend\PasswordHasher;
use Rumbo\Backend\Profile\ProfileRepository;
use Rumbo\Backend\Security\Token;
use Rumbo\Backend\SqlMigrationRunner;
use Rumbo\Backend\Tests\Support\FakePasswordResetDelivery;
use Rumbo\Backend\Tests\Support\FrozenClock;
use Rumbo\Backend\Tests\Support\SqliteTestDatabase;
use Rumbo\Backend\TransactionRunner;

$backendRoot = require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/Support/FrozenClock.php';
require __DIR__ . '/Support/FakePasswordResetDelivery.php';
require __DIR__ . '/Support/SqliteTestDatabase.php';

$syntheticEnvironment = [
    'RUMBO_APP_ENV' => 'development',
    'RUMBO_ALLOWED_ORIGIN' => 'https://rumbo.test',
    'RUMBO_SECURITY_PEPPER' => 'synthetic-test-pepper-only-2026-not-a-secret',
    'RUMBO_SESSION_TTL_SECONDS' => '3600',
    'RUMBO_PASSWORD_RESET_TTL_SECONDS' => '1800',
    'RUMBO_LOGIN_ATTEMPT_WINDOW_SECONDS' => '900',
    'RUMBO_LOGIN_MAX_ATTEMPTS' => '5',
    'RUMBO_COOKIE_SECURE' => 'false',
    'RUMBO_LOGIN_ENABLED' => 'false',
    'RUMBO_REGISTRATION_ENABLED' => 'false',
    'RUMBO_ACCOUNT_VERIFICATION_READY' => 'false',
    'RUMBO_PASSWORD_RECOVERY_ENABLED' => 'false',
];
foreach ($syntheticEnvironment as $name => $value) {
    putenv(sprintf('%s=%s', $name, $value));
}

$passed = 0;
$failed = 0;
$skipped = 0;

$test = static function (string $name, callable $callback) use (&$passed, &$failed): void {
    try {
        $callback();
        $passed++;
        echo "PASS {$name}\n";
    } catch (Throwable $exception) {
        $failed++;
        echo "FAIL {$name}: " . get_class($exception) . ': ' . $exception->getMessage() . "\n";
    }
};

$assert = static function (bool $condition, string $message = 'Aserción fallida.'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assertSame = static function (mixed $expected, mixed $actual, string $message = ''): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message !== '' ? $message : sprintf(
            'Se esperaba %s y se obtuvo %s.',
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
};

$throws = static function (callable $callback): Throwable {
    try {
        $callback();
    } catch (Throwable $exception) {
        return $exception;
    }
    throw new RuntimeException('Se esperaba una excepción.');
};

$throwsApi = static function (callable $callback, string $code) use ($throws, $assertSame): ApiException {
    $exception = $throws($callback);
    if (!$exception instanceof ApiException) {
        throw new RuntimeException('Se esperaba ApiException y se obtuvo ' . get_class($exception));
    }
    $assertSame($code, $exception->errorCode);
    return $exception;
};

$test('HNL normaliza dos decimales y saldos firmados sin float', static function () use ($assertSame): void {
    $assertSame('15000.00', Money::normalizeHnl('15000'));
    $assertSame('0.50', Money::normalizeHnl('0.5'));
    $assertSame('9999999999999.99', Money::normalizeHnl('9999999999999.99'));
    $assertSame('-25.50', Money::formatDatabaseSigned('-25.5'));
    $assertSame('0.00', Money::formatDatabaseSigned('-0.00'));
});

$test('HNL rechaza número JSON, signo, precisión inválida y cero', static function () use ($throws): void {
    $throws(static fn () => Money::normalizeHnl(15000.00));
    $throws(static fn () => Money::normalizeHnl('-1.00'));
    $throws(static fn () => Money::normalizeHnl('1.001'));
    $throws(static fn () => Money::normalizeHnl('1e3'));
    $throws(static fn () => Money::normalizeHnl('0.00'));
});

$test('Movimientos exigen fecha de calendario e ISO-8601 UTC estrictos', static function () use ($throwsApi): void {
    $base = [
        'transactionType' => 'expense',
        'name' => 'Prueba',
        'category' => 'Pruebas',
        'amount' => '1.00',
        'date' => '2026-11-15',
    ];
    MovementValidator::create([...$base, 'occurredAt' => '2026-11-15T12:30:00Z']);
    $throwsApi(static fn () => MovementValidator::create([...$base, 'occurredAt' => 'tomorrow']), 'VALIDATION_ERROR');
    $throwsApi(static fn () => MovementValidator::create([...$base, 'occurredAt' => '2026-02-30T12:30:00Z']), 'VALIDATION_ERROR');
    $throwsApi(static fn () => MovementValidator::create([...$base, 'date' => '2026-02-30']), 'VALIDATION_ERROR');
});

$test('Contraseñas usan Argon2id cuando está disponible y nunca texto plano', static function () use ($assert, $throws): void {
    $password = 'Rumbo-demo-segura-2026!';
    $hash = PasswordHasher::hash($password);
    $assert($hash !== $password);
    $assert(!str_contains($hash, $password));
    $assert(PasswordHasher::verify($password, $hash));
    $assert(!PasswordHasher::verify('Incorrecta-segura-2026!', $hash));
    if (defined('PASSWORD_ARGON2ID')) {
        $assert(strtolower((string) password_get_info($hash)['algoName']) === 'argon2id');
    }
    $throws(static fn () => PasswordHasher::hash('corta'));
    $throws(static fn () => PasswordHasher::hash('solamenteminusculas'));
});

$test('Clave de idempotencia y cursor firmado tienen formato limitado', static function () use ($assertSame, $throwsApi): void {
    $assertSame('movement:demo-001', IdempotencyKey::normalize('movement:demo-001'));
    $codec = new CursorCodec('synthetic-cursor-signing-key');
    $cursor = $codec->encode('2026-11-15', 'abc-123');
    $assertSame(['date' => '2026-11-15', 'id' => 'abc-123'], $codec->decode($cursor));
    $throwsApi(static fn () => $codec->decode($cursor . 'tampered'), 'VALIDATION_ERROR');
});

$test('Migración 001 sigue aditiva, HNL decimal e InnoDB', static function () use ($backendRoot, $assert): void {
    $sql = file_get_contents($backendRoot . '/migrations/001_initial_schema.sql');
    $assert(is_string($sql));
    $statements = SqlMigrationRunner::statements($sql);
    $normalized = implode(";\n", $statements);
    $assert(count($statements) >= 20, 'La migración está incompleta.');
    $assert(!preg_match('/\b(DROP|TRUNCATE|RENAME)\b/i', $normalized), 'La migración contiene DDL destructivo.');
    $assert(!stripos($normalized, 'amountCents'), 'La migración usa amountCents.');
    $assert(substr_count(strtoupper($normalized), 'DECIMAL(15,2)') >= 10, 'Faltan importes DECIMAL(15,2).');
    $tables = preg_match_all('/CREATE TABLE IF NOT EXISTS\s+[a-z_]+/i', $normalized);
    $engines = preg_match_all('/ENGINE=INNODB/i', $normalized);
    $assert($tables === 18, sprintf('Se esperaban 18 tablas y se detectaron %d.', $tables));
    $assert($engines === $tables, 'No todas las tablas usan InnoDB.');
});

$test('Catálogo mantiene auth y finanzas protegidas, incluida la superficie admin', static function () use ($backendRoot, $assert, $assertSame): void {
    $routes = require $backendRoot . '/config/routes.php';
    $assertSame(44, count($routes));
    $indexed = [];
    foreach ($routes as $route) {
        $indexed[$route[0] . ' ' . $route[1]] = $route[2];
    }
    $assertSame('public', $indexed['POST /api/v1/auth/login']);
    $assertSame('public', $indexed['POST /api/v1/admin/auth/login']);
    $assertSame('public', $indexed['POST /api/v1/auth/register']);
    $assertSame('protected', $indexed['GET /api/v1/movements']);
    $assertSame('protected', $indexed['GET /api/v1/financial-summary']);
    $assertSame('protected', $indexed['POST /api/v1/reminders/{reminderId}/payments']);
    $assertSame('admin', $indexed['GET /api/v1/admin/dashboard']);
    $assert(in_array('admin', array_column($routes, 2), true), 'Falta el acceso administrativo separado.');
});

$test('Router reconoce parámetros opacos sin consultar la base', static function () use ($assertSame): void {
    $router = new Router(new SessionAuthenticator(new Database(new Config())));
    $router->add('GET', '/api/v1/movements/{movementId}', false, static function ($request, $user, array $params): never {
        throw new RuntimeException('matched:' . ($params['movementId'] ?? 'missing'));
    });
    $request = new Request('GET', '/api/v1/movements/abc-123', [], str_repeat('a', 32), '');
    try {
        $router->dispatch($request);
    } catch (RuntimeException $exception) {
        $assertSame('matched:abc-123', $exception->getMessage());
        return;
    }
    throw new RuntimeException('La ruta parametrizada no coincidió.');
});

$test('Configuración bloquea base distinta y funciones públicas quedan cerradas por defecto', static function () use ($throws, $assert): void {
    $names = ['RUMBO_DB_HOST', 'RUMBO_DB_PORT', 'RUMBO_DB_NAME', 'RUMBO_DB_ALLOWED_NAME', 'RUMBO_DB_USER', 'RUMBO_DB_PASSWORD'];
    $previous = [];
    foreach ($names as $name) {
        $previous[$name] = getenv($name);
    }
    try {
        putenv('RUMBO_DB_HOST=127.0.0.1');
        putenv('RUMBO_DB_PORT=3306');
        putenv('RUMBO_DB_NAME=otro_proyecto');
        putenv('RUMBO_DB_ALLOWED_NAME=rumbo_autorizada');
        putenv('RUMBO_DB_USER=rumbo');
        putenv(sprintf('%s=%s', 'RUMBO_DB_PASSWORD', 'synthetic-test-value'));
        $throws(static fn () => (new Config())->database());
        $config = new Config();
        $assert(!$config->loginEnabled() && !$config->registrationEnabled() && !$config->passwordRecoveryEnabled());
    } finally {
        foreach ($previous as $name => $value) {
            $value === false ? putenv($name) : putenv(sprintf('%s=%s', $name, $value));
        }
    }
});

$test('Origen exacto y opciones de cookie aplican SameSite y HttpOnly', static function () use ($assert, $throwsApi): void {
    $config = new Config();
    OriginGuard::assertAllowed(
        new Request('POST', '/api/v1/auth/login', ['origin' => 'https://rumbo.test'], str_repeat('b', 32), '{}'),
        $config,
    );
    $throwsApi(static fn () => OriginGuard::assertAllowed(
        new Request('POST', '/api/v1/auth/login', ['origin' => 'https://evil.test'], str_repeat('b', 32), '{}'),
        $config,
    ), 'FORBIDDEN');
    $manager = new SessionCookieManager($config);
    $session = $manager->sessionOptions(time() + 3600);
    $csrf = $manager->csrfOptions(time() + 3600);
    $assert($session['httponly'] === true && $session['samesite'] === 'Strict');
    $assert($csrf['httponly'] === false && $csrf['samesite'] === 'Strict');
    putenv('RUMBO_APP_ENV=production');
    try {
        $assert((new SessionCookieManager(new Config()))->sessionOptions(time() + 3600)['secure'] === true);
    } finally {
        putenv('RUMBO_APP_ENV=development');
    }
});

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $skipped += 9;
    echo "SKIP 9 integration tests: pdo_sqlite no disponible.\n";
} else {
    $context = static function (): array {
        $pdo = SqliteTestDatabase::create();
        $clock = new FrozenClock(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $config = new Config();
        $delivery = new FakePasswordResetDelivery();
        $authRepository = new AuthRepository($pdo);
        $profiles = new ProfileRepository($pdo);
        $auth = new AuthService($authRepository, $profiles, $config, $delivery, $clock);
        return [
            'pdo' => $pdo,
            'clock' => $clock,
            'config' => $config,
            'delivery' => $delivery,
            'authRepository' => $authRepository,
            'profiles' => $profiles,
            'auth' => $auth,
            'activator' => new AccountActivator($authRepository, $clock),
        ];
    };

    $createActive = static function (array $ctx, string $email, string $password = 'Rumbo-Segura-2026!'): string {
        $ctx['auth']->register([
            'email' => $email,
            'password' => $password,
            'displayName' => 'Persona Sintética',
        ]);
        $statement = $ctx['pdo']->prepare('SELECT id FROM users WHERE email = :email');
        $statement->execute([':email' => strtolower($email)]);
        $id = $statement->fetchColumn();
        if (!is_string($id)) {
            throw new RuntimeException('No se creó la cuenta sintética.');
        }
        $ctx['activator']->activate($id);
        return $id;
    };

    $test('Registro guarda hash, perfil miembro pendiente y activación inicia trial una sola vez', static function () use ($context, $assert, $assertSame): void {
        $ctx = $context();
        $ctx['auth']->register([
            'email' => 'persona@example.test',
            'password' => 'Rumbo-Segura-2026!',
            'displayName' => 'Persona Prueba',
        ]);
        $user = $ctx['pdo']->query("SELECT * FROM users WHERE email = 'persona@example.test'")->fetch();
        $assert(is_array($user));
        $assertSame('pending', $user['status']);
        $assert((string) $user['password_hash'] !== 'Rumbo-Segura-2026!');
        $assert(PasswordHasher::verify('Rumbo-Segura-2026!', (string) $user['password_hash']));
        $profileBefore = $ctx['profiles']->get((string) $user['id']);
        $assertSame(null, $profileBefore['trial']['startedAt']);
        $assertSame(['member'], $profileBefore['roles']);

        $ctx['activator']->activate((string) $user['id']);
        $active = $ctx['profiles']->get((string) $user['id']);
        $assertSame('active', $active['accountStatus']);
        $start = new DateTimeImmutable((string) $active['trial']['startedAt']);
        $end = new DateTimeImmutable((string) $active['trial']['endsAt']);
        $assertSame(7 * 86400, $end->getTimestamp() - $start->getTimestamp());
        $ctx['clock']->advance('+1 day');
        $ctx['activator']->activate((string) $user['id']);
        $assertSame($active['trial'], $ctx['profiles']->get((string) $user['id'])['trial']);
    });

    $test('Login crea sesión hash; expiración, revocación y estado se aplican', static function () use ($context, $createActive, $assert, $assertSame, $throwsApi): void {
        $ctx = $context();
        $userId = $createActive($ctx, 'login@example.test');
        $login = $ctx['auth']->login([
            'email' => 'login@example.test',
            'password' => 'Rumbo-Segura-2026!',
        ], '127.0.0.1', 'Rumbo-Test-Agent');
        $storedHash = $ctx['pdo']->query('SELECT token_hash FROM auth_sessions LIMIT 1')->fetchColumn();
        $assert(is_string($storedHash));
        $assert($storedHash !== $login['sessionToken']);
        $assert(hash_equals(Token::hash((string) $login['sessionToken']), $storedHash));

        $request = new Request(
            'GET',
            '/api/v1/auth/session',
            [],
            str_repeat('c', 32),
            '',
            ['rumbo_session' => (string) $login['sessionToken']],
        );
        $authenticator = new SessionAuthenticator($ctx['pdo'], $ctx['config']);
        $authenticated = $authenticator->authenticate($request);
        $assertSame($userId, $authenticated->id);
        $assertSame(['member'], $authenticated->roles);
        $assert(is_string($authenticated->sessionExpiresAt) && str_ends_with($authenticated->sessionExpiresAt, 'Z'));

        $ctx['auth']->logout((string) $login['sessionId']);
        $throwsApi(static fn () => $authenticator->authenticate($request), 'AUTH_INVALID');

        $login2 = $ctx['auth']->login([
            'email' => 'login@example.test',
            'password' => 'Rumbo-Segura-2026!',
        ], null, null);
        $ctx['pdo']->exec("UPDATE auth_sessions SET expires_at = '2000-01-01 00:00:00.000000' WHERE id = " . $ctx['pdo']->quote((string) $login2['sessionId']));
        $expired = new Request('GET', '/api/v1/auth/session', [], str_repeat('d', 32), '', [
            'rumbo_session' => (string) $login2['sessionToken'],
        ]);
        $throwsApi(static fn () => $authenticator->authenticate($expired), 'AUTH_INVALID');
    });

    $test('Provisión administrativa usa username sin crear correo falso y login separado', static function () use ($context, $assert, $assertSame, $throwsApi): void {
        $ctx = $context();
        (new AdminProvisioner($ctx['pdo'], $ctx['config']))->provision(
            'rumbohn2026',
            'Rumbo-Admin-Segura-2026!',
            'Operador RUMBO',
        );
        $record = $ctx['pdo']->query("SELECT email, username, status FROM users WHERE username = 'rumbohn2026'")->fetch();
        $assert(is_array($record));
        $assertSame(null, $record['email']);
        $assertSame('active', $record['status']);

        $login = $ctx['auth']->loginAdmin([
            'username' => 'rumbohn2026',
            'password' => 'Rumbo-Admin-Segura-2026!',
        ], '127.0.0.1', 'Rumbo-Admin-Test');
        $assertSame('rumbohn2026', $login['profile']['username']);
        $assertSame(null, $login['profile']['email']);
        $assertSame(['admin', 'member'], $login['profile']['roles']);

        $request = new Request('GET', '/api/v1/auth/session', [], str_repeat('b', 32), '', [
            'rumbo_session' => (string) $login['sessionToken'],
        ]);
        $authenticated = (new SessionAuthenticator($ctx['pdo'], $ctx['config']))->authenticate($request);
        $assertSame(null, $authenticated->email);
        AdminAuthorizer::assertAdmin($authenticated);
        $throwsApi(static fn () => $ctx['auth']->loginAdmin([
            'username' => 'rumbohn2026',
            'password' => 'Rumbo-Admin-Incorrecta-2026!',
        ], '127.0.0.1', null), 'AUTH_INVALID');
    });

    $test('CSRF protege mutaciones por cookie y permite bearer explícito', static function () use ($context, $createActive, $throwsApi): void {
        $ctx = $context();
        $createActive($ctx, 'csrf@example.test');
        $login = $ctx['auth']->login([
            'email' => 'csrf@example.test',
            'password' => 'Rumbo-Segura-2026!',
        ], null, null);
        $authenticator = new SessionAuthenticator($ctx['pdo'], $ctx['config']);
        $cookieRequest = new Request('POST', '/api/v1/movements', [
            'x-csrf-token' => (string) $login['csrfToken'],
        ], str_repeat('e', 32), '{}', [
            'rumbo_session' => (string) $login['sessionToken'],
            'rumbo_csrf' => (string) $login['csrfToken'],
        ]);
        $cookieUser = $authenticator->authenticate($cookieRequest);
        (new CsrfGuard($ctx['config']))->assertValid($cookieRequest, $cookieUser);

        $missing = new Request('POST', '/api/v1/movements', [], str_repeat('f', 32), '{}', [
            'rumbo_session' => (string) $login['sessionToken'],
        ]);
        $throwsApi(static fn () => (new CsrfGuard($ctx['config']))->assertValid($missing, $authenticator->authenticate($missing)), 'CSRF_INVALID');

        $bearerRequest = new Request('POST', '/api/v1/movements', [
            'authorization' => 'Bearer ' . $login['sessionToken'],
        ], str_repeat('a', 32), '{}');
        (new CsrfGuard($ctx['config']))->assertValid($bearerRequest, $authenticator->authenticate($bearerRequest));
    });

    $test('Rate limiting persiste y login no enumera cuentas', static function () use ($context, $createActive, $throwsApi, $assertSame): void {
        $ctx = $context();
        $createActive($ctx, 'rate@example.test');
        $wrong = $throwsApi(static fn () => $ctx['auth']->login([
            'email' => 'rate@example.test',
            'password' => 'Rumbo-Incorrecta-2026!',
        ], '127.0.0.2', null), 'AUTH_INVALID');
        $missing = $throwsApi(static fn () => $ctx['auth']->login([
            'email' => 'missing@example.test',
            'password' => 'Rumbo-Incorrecta-2026!',
        ], '127.0.0.3', null), 'AUTH_INVALID');
        $assertSame($wrong->getMessage(), $missing->getMessage());

        for ($attempt = 2; $attempt <= 5; $attempt++) {
            $throwsApi(static fn () => $ctx['auth']->login([
                'email' => 'rate@example.test',
                'password' => 'Rumbo-Incorrecta-2026!',
            ], '127.0.0.2', null), 'AUTH_INVALID');
        }
        $throwsApi(static fn () => $ctx['auth']->login([
            'email' => 'rate@example.test',
            'password' => 'Rumbo-Segura-2026!',
        ], '127.0.0.2', null), 'RATE_LIMITED');
        $assertSame('6', $ctx['pdo']->query("SELECT COUNT(*) FROM auth_attempts WHERE succeeded = 0 AND ip_hash IS NOT NULL")->fetchColumn());
    });

    $test('Recuperación fake usa token hash, vence por uso y revoca sesiones', static function () use ($context, $createActive, $assert, $assertSame, $throwsApi): void {
        $ctx = $context();
        $createActive($ctx, 'recovery@example.test');
        $login = $ctx['auth']->login([
            'email' => 'recovery@example.test',
            'password' => 'Rumbo-Segura-2026!',
        ], null, null);
        $ctx['auth']->forgotPassword(['email' => 'recovery@example.test']);
        $ctx['auth']->forgotPassword(['email' => 'unknown@example.test']);
        $assertSame(1, count($ctx['delivery']->deliveries));
        $token = $ctx['delivery']->deliveries[0]['token'];
        $stored = $ctx['pdo']->query('SELECT token_hash FROM password_reset_tokens LIMIT 1')->fetchColumn();
        $assert(is_string($stored) && $stored !== $token && hash_equals(Token::hash($token), $stored));

        $ctx['auth']->resetPassword(['token' => $token, 'password' => 'Rumbo-Nueva-2026!']);
        $revoked = $ctx['pdo']->query('SELECT revoked_at FROM auth_sessions WHERE id = ' . $ctx['pdo']->quote((string) $login['sessionId']))->fetchColumn();
        $assert(is_string($revoked) && $revoked !== '');
        $throwsApi(static fn () => $ctx['auth']->resetPassword([
            'token' => $token,
            'password' => 'Rumbo-Otra-2026!',
        ]), 'AUTH_INVALID');
        $throwsApi(static fn () => $ctx['auth']->login([
            'email' => 'recovery@example.test',
            'password' => 'Rumbo-Segura-2026!',
        ], null, null), 'AUTH_INVALID');
        $newLogin = $ctx['auth']->login([
            'email' => 'recovery@example.test',
            'password' => 'Rumbo-Nueva-2026!',
        ], null, null);
        $assert(isset($newLogin['sessionToken']));
    });

    $test('Perfil limita onboarding y no permite cambiar estado, trial ni roles', static function () use ($context, $createActive, $assertSame, $throwsApi): void {
        $ctx = $context();
        $userId = $createActive($ctx, 'profile@example.test');
        $profile = $ctx['profiles']->update($userId, [
            'openingBalance' => '15000.00',
            'onboardingCompleted' => true,
            'timezone' => 'America/Tegucigalpa',
        ]);
        $assertSame('15000.00', $profile['openingBalance']);
        $assertSame(true, $profile['onboardingCompleted']);
        $throwsApi(static fn () => $ctx['profiles']->update($userId, ['openingBalance' => '1.00']), 'CONFLICT');
        $throwsApi(static fn () => $ctx['profiles']->update($userId, ['accountStatus' => 'active']), 'VALIDATION_ERROR');
        $throwsApi(static fn () => $ctx['profiles']->update($userId, ['trialEndsAt' => '2099-01-01']), 'VALIDATION_ERROR');
        $throwsApi(static fn () => $ctx['profiles']->update($userId, ['roles' => ['admin']]), 'VALIDATION_ERROR');
    });

    $test('Autorización administrativa separa miembro y administrador en middleware y router', static function () use ($context, $createActive, $throwsApi, $assertSame): void {
        $member = new AuthenticatedUser('user-1', 'member@example.test', 'session-1', ['member']);
        $throwsApi(static fn () => AdminAuthorizer::assertAdmin($member), 'FORBIDDEN');
        $admin = new AuthenticatedUser('user-2', 'admin@example.test', 'session-2', ['member', 'admin']);
        AdminAuthorizer::assertAdmin($admin);

        $ctx = $context();
        $userId = $createActive($ctx, 'router-admin@example.test');
        $login = $ctx['auth']->login([
            'email' => 'router-admin@example.test',
            'password' => 'Rumbo-Segura-2026!',
        ], null, null);
        $router = new Router(
            new SessionAuthenticator($ctx['pdo'], $ctx['config']),
            new CsrfGuard($ctx['config']),
        );
        $router->add('GET', '/api/v1/admin/test', 'admin', static function (): never {
            throw new RuntimeException('admin-handler-reached');
        });
        $request = new Request('GET', '/api/v1/admin/test', [], str_repeat('9', 32), '', [
            'rumbo_session' => (string) $login['sessionToken'],
        ]);
        $throwsApi(static fn () => $router->dispatch($request), 'FORBIDDEN');

        $ctx['pdo']->prepare(
            'INSERT INTO user_roles (user_id, role_code, assigned_by_user_id, assigned_at)
             VALUES (:user_id, :role_code, NULL, :assigned_at)'
        )->execute([
            ':user_id' => $userId,
            ':role_code' => 'admin',
            ':assigned_at' => '2026-11-15 12:00:00.000000',
        ]);
        try {
            $router->dispatch($request);
        } catch (RuntimeException $exception) {
            $assertSame('admin-handler-reached', $exception->getMessage());
            return;
        }
        throw new RuntimeException('La ruta administrativa no alcanzó el handler con rol admin.');
    });

    $test('Movimientos CRUD son idempotentes, paginados y aislados entre dos usuarios', static function () use ($context, $createActive, $assert, $assertSame, $throwsApi): void {
        $ctx = $context();
        $userOne = $createActive($ctx, 'one@example.test');
        $userTwo = $createActive($ctx, 'two@example.test');
        $repository = new MovementRepository($ctx['pdo'], $ctx['clock']);
        $firstPayload = MovementValidator::create([
            'transactionType' => 'expense',
            'name' => 'Café sintético',
            'category' => 'Alimentación',
            'amount' => '89.00',
            'date' => '2026-11-15',
            'sourceType' => 'quick-expense',
            'isQuickExpense' => true,
        ]);
        $throwsApi(static fn () => MovementValidator::create([
            'transactionType' => 'expense',
            'name' => 'Intento de titular ajeno',
            'category' => 'Pruebas',
            'amount' => '1.00',
            'date' => '2026-11-15',
            'userId' => $userTwo,
        ]), 'VALIDATION_ERROR');
        $created = $repository->create($userOne, $firstPayload, 'movement:test-001');
        $replayed = $repository->create($userOne, $firstPayload, 'movement:test-001');
        $assertSame(false, $created['replayed']);
        $assertSame(true, $replayed['replayed']);
        $assertSame($created['movement']['id'], $replayed['movement']['id']);
        $assertSame('1', $ctx['pdo']->query('SELECT COUNT(*) FROM financial_transactions')->fetchColumn());
        $different = $firstPayload;
        $different['amount'] = '90.00';
        $throwsApi(static fn () => $repository->create($userOne, $different, 'movement:test-001'), 'IDEMPOTENCY_CONFLICT');
        $throwsApi(static fn () => $repository->get($userTwo, (string) $created['movement']['id']), 'NOT_FOUND');

        $second = $repository->create($userOne, MovementValidator::create([
            'transactionType' => 'income',
            'name' => 'Ingreso sintético',
            'category' => 'Trabajo',
            'amount' => '500.00',
            'date' => '2026-11-14',
        ]), 'movement:test-002');
        $pageOne = $repository->list($userOne, 1, null);
        $assertSame(true, $pageOne['hasMore']);
        $codec = new CursorCodec('synthetic-cursor-signing-key');
        $cursor = $codec->decode($codec->encode(
            (string) $pageOne['items'][0]['date'],
            (string) $pageOne['items'][0]['id'],
        ));
        $pageTwo = $repository->list($userOne, 1, $cursor);
        $assertSame((string) $second['movement']['id'], (string) $pageTwo['items'][0]['id']);

        $updatedPayload = MovementValidator::update($created['movement'], ['amount' => '25.50']);
        $updated = $repository->update($userOne, (string) $created['movement']['id'], $updatedPayload);
        $assertSame('25.50', $updated['amount']);
        $repository->delete($userOne, (string) $created['movement']['id']);
        $throwsApi(static fn () => $repository->get($userOne, (string) $created['movement']['id']), 'NOT_FOUND');
        $assert($repository->list($userTwo, 25, null)['items'] === []);
    });

    $test('Resumen usa confirmados, separa ahorro/pendientes y evita doble deducción', static function () use ($context, $createActive, $assertSame): void {
        $ctx = $context();
        $userId = $createActive($ctx, 'summary@example.test');
        $ctx['profiles']->update($userId, ['openingBalance' => '1000.00']);
        $movements = new MovementRepository($ctx['pdo'], $ctx['clock']);
        $movements->create($userId, MovementValidator::create([
            'transactionType' => 'income', 'name' => 'Ingreso', 'category' => 'Trabajo',
            'amount' => '500.00', 'date' => '2026-11-15', 'status' => 'confirmed',
        ]), 'summary:income-001');
        $movements->create($userId, MovementValidator::create([
            'transactionType' => 'expense', 'name' => 'Egreso', 'category' => 'Hogar',
            'amount' => '100.00', 'date' => '2026-11-15', 'status' => 'confirmed',
        ]), 'summary:expense-001');
        $movements->create($userId, MovementValidator::create([
            'transactionType' => 'expense', 'name' => 'Planificado', 'category' => 'Hogar',
            'amount' => '300.00', 'date' => '2026-11-16', 'status' => 'planned',
        ]), 'summary:planned-001');
        $ctx['pdo']->prepare(
            'INSERT INTO goal_contributions
                (id, goal_id, user_id, amount_hnl, currency, contributed_on, idempotency_key, notes, created_at)
             VALUES (:id, :goal_id, :user_id, :amount, :currency, :date, :key, NULL, :created_at)'
        )->execute([
            ':id' => 'contribution-1', ':goal_id' => 'goal-1', ':user_id' => $userId,
            ':amount' => '50.00', ':currency' => 'HNL', ':date' => '2026-11-15',
            ':key' => 'goal:test-001', ':created_at' => '2026-11-15 12:00:00.000000',
        ]);
        $ctx['pdo']->prepare(
            'INSERT INTO reminders (id, user_id, expected_amount_hnl, status, archived_at)
             VALUES (:id, :user_id, :amount, :status, NULL)'
        )->execute([':id' => 'reminder-1', ':user_id' => $userId, ':amount' => '200.00', ':status' => 'upcoming']);

        $summary = new FinancialSummaryRepository($ctx['pdo']);
        $first = $summary->get($userId);
        $assertSame('500.00', $first['confirmedIncome']);
        $assertSame('100.00', $first['confirmedExpenses']);
        $assertSame('1400.00', $first['totalBalance']);
        $assertSame('50.00', $first['savingsAllocated']);
        $assertSame('1350.00', $first['availableBalance']);
        $assertSame('200.00', $first['pendingObligations']);
        $assertSame('1200.00', $first['projectedBalance']);

        $ctx['pdo']->exec("UPDATE reminders SET status = 'paid' WHERE id = 'reminder-1'");
        $movements->create($userId, MovementValidator::create([
            'transactionType' => 'expense', 'name' => 'Pago confirmado', 'category' => 'Obligaciones',
            'amount' => '200.00', 'date' => '2026-11-15', 'status' => 'confirmed',
        ]), 'summary:payment-001');
        $paid = $summary->get($userId);
        $assertSame('300.00', $paid['confirmedExpenses']);
        $assertSame('0.00', $paid['pendingObligations']);
        $assertSame('1200.00', $paid['totalBalance']);
        $assertSame('1200.00', $paid['projectedBalance']);
    });
}

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $test('TransactionRunner confirma y revierte atómicamente', static function () use ($assertSame, $throws): void {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE test_values (value TEXT NOT NULL)');
        TransactionRunner::run($pdo, static function (PDO $connection): void {
            $connection->exec("INSERT INTO test_values (value) VALUES ('committed')");
        });
        $assertSame('1', (string) $pdo->query('SELECT COUNT(*) FROM test_values')->fetchColumn());
        $throws(static fn () => TransactionRunner::run($pdo, static function (PDO $connection): void {
            $connection->exec("INSERT INTO test_values (value) VALUES ('rolled-back')");
            throw new RuntimeException('forced rollback');
        }));
        $assertSame('1', (string) $pdo->query('SELECT COUNT(*) FROM test_values')->fetchColumn());
    });
} else {
    $skipped++;
    echo "SKIP TransactionRunner: pdo_sqlite no disponible.\n";
}

printf("RESULT %d passed, %d failed, %d skipped\n", $passed, $failed, $skipped);
exit($failed === 0 ? 0 : 1);
