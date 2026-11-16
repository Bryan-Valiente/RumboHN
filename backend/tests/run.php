<?php

declare(strict_types=1);

use Rumbo\Backend\Auth\SessionAuthenticator;
use Rumbo\Backend\Config;
use Rumbo\Backend\Database;
use Rumbo\Backend\Financial\Money;
use Rumbo\Backend\Http\Router;
use Rumbo\Backend\Idempotency\IdempotencyKey;
use Rumbo\Backend\PasswordHasher;
use Rumbo\Backend\SqlMigrationRunner;
use Rumbo\Backend\TransactionRunner;

$backendRoot = require dirname(__DIR__) . '/src/bootstrap.php';
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
        echo "FAIL {$name}: {$exception->getMessage()}\n";
    }
};

$assert = static function (bool $condition, string $message = 'Aserción fallida.'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$throws = static function (callable $callback): void {
    try {
        $callback();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException('Se esperaba una excepción.');
};

$test('HNL normaliza dos decimales sin float', static function () use ($assert): void {
    $assert(Money::normalizeHnl('15000') === '15000.00');
    $assert(Money::normalizeHnl('0.5') === '0.50');
    $assert(Money::normalizeHnl('9999999999999.99') === '9999999999999.99');
});

$test('HNL rechaza números JSON, signos, precisión inválida y cero', static function () use ($throws): void {
    $throws(static fn () => Money::normalizeHnl(15000.00));
    $throws(static fn () => Money::normalizeHnl('-1.00'));
    $throws(static fn () => Money::normalizeHnl('1.001'));
    $throws(static fn () => Money::normalizeHnl('1e3'));
    $throws(static fn () => Money::normalizeHnl('0.00'));
});

$test('Contraseñas usan hash verificable sin texto plano', static function () use ($assert, $throws): void {
    $password = 'Rumbo-demo-segura-2026';
    $hash = PasswordHasher::hash($password);
    $assert($hash !== $password);
    $assert(!str_contains($hash, $password));
    $assert(PasswordHasher::verify($password, $hash));
    $assert(!PasswordHasher::verify('incorrecta-segura-2026', $hash));
    $throws(static fn () => PasswordHasher::hash('corta'));
});

$test('Clave de idempotencia tiene formato limitado', static function () use ($assert, $throws): void {
    $assert(IdempotencyKey::normalize('payment:demo-001') === 'payment:demo-001');
    $throws(static fn () => IdempotencyKey::normalize('short'));
    $throws(static fn () => IdempotencyKey::normalize('contains spaces'));
});

$test('Migración es aditiva, HNL decimal e InnoDB', static function () use ($backendRoot, $assert): void {
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

$test('Catálogo conserva endpoints y protege finanzas', static function () use ($backendRoot, $assert): void {
    $routes = require $backendRoot . '/config/routes.php';
    $assert(count($routes) === 29, sprintf('Cantidad inesperada de rutas: %d.', count($routes)));
    $health = array_values(array_filter($routes, static fn (array $route): bool => $route[1] === '/api/v1/health'));
    $assert(count($health) === 1 && $health[0][2] === 'public');
    foreach ($routes as $route) {
        if (str_starts_with($route[1], '/api/v1/movements')
            || str_starts_with($route[1], '/api/v1/budgets')
            || str_starts_with($route[1], '/api/v1/goals')
            || str_starts_with($route[1], '/api/v1/reminders')
            || str_starts_with($route[1], '/api/v1/reports')
            || str_starts_with($route[1], '/api/v1/notifications')
            || $route[1] === '/api/v1/notification-preferences') {
            $assert($route[2] === 'protected', sprintf('Ruta financiera no protegida: %s', $route[1]));
        }
    }

    $router = new Router(new SessionAuthenticator(new Database(new Config())));
    foreach ($routes as [$method, $path]) {
        $router->add($method, $path, false, static fn () => null);
    }
});

$test('Router reconoce parámetros opacos sin consultar la base', static function () use ($assert): void {
    $router = new Router(new SessionAuthenticator(new Database(new Config())));
    $router->add('GET', '/api/v1/movements/{movementId}', false, static function ($request, $user, array $params): never {
        throw new RuntimeException('matched:' . ($params['movementId'] ?? 'missing'));
    });
    $request = new \Rumbo\Backend\Http\Request('GET', '/api/v1/movements/abc-123', [], str_repeat('a', 32), '');
    try {
        $router->dispatch($request);
    } catch (RuntimeException $exception) {
        $assert($exception->getMessage() === 'matched:abc-123');
        return;
    }
    throw new RuntimeException('La ruta parametrizada no coincidió.');
});

$test('Configuración bloquea una base distinta a la permitida', static function () use ($throws): void {
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
        putenv('RUMBO_DB_PASSWORD=not-a-real-secret');
        $throws(static fn () => (new Config())->database());
    } finally {
        foreach ($previous as $name => $value) {
            $value === false ? putenv($name) : putenv(sprintf('%s=%s', $name, $value));
        }
    }
});

$test('Sesiones usan consulta preparada y hash de token', static function () use ($backendRoot, $assert): void {
    $source = file_get_contents($backendRoot . '/src/Auth/SessionAuthenticator.php');
    $assert(is_string($source));
    $assert(str_contains($source, "hash('sha256'"));
    $assert(str_contains($source, '->prepare('));
    $assert(str_contains($source, 's.user_id'));
    $assert(str_contains($source, "u.status = :active_status"));
});

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $test('TransactionRunner confirma y revierte atómicamente', static function () use ($assert, $throws): void {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE test_values (value TEXT NOT NULL)');
        TransactionRunner::run($pdo, static function (PDO $connection): void {
            $connection->exec("INSERT INTO test_values (value) VALUES ('committed')");
        });
        $assert((int) $pdo->query('SELECT COUNT(*) FROM test_values')->fetchColumn() === 1);
        $throws(static fn () => TransactionRunner::run($pdo, static function (PDO $connection): void {
            $connection->exec("INSERT INTO test_values (value) VALUES ('rolled-back')");
            throw new RuntimeException('forced rollback');
        }));
        $assert((int) $pdo->query('SELECT COUNT(*) FROM test_values')->fetchColumn() === 1);
    });
} else {
    $skipped++;
    echo "SKIP TransactionRunner: pdo_sqlite no disponible.\n";
}

printf("RESULT %d passed, %d failed, %d skipped\n", $passed, $failed, $skipped);
exit($failed === 0 ? 0 : 1);
