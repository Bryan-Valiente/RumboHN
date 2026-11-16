<?php

declare(strict_types=1);

use Rumbo\Backend\Admin\AdminRepository;
use Rumbo\Backend\Auth\AccountActivator;
use Rumbo\Backend\Auth\AuthRepository;
use Rumbo\Backend\Auth\AuthService;
use Rumbo\Backend\Auth\UnavailablePasswordResetDelivery;
use Rumbo\Backend\Config;
use Rumbo\Backend\Financial\BudgetRepository;
use Rumbo\Backend\Financial\FinancialReportRepository;
use Rumbo\Backend\Financial\GoalRepository;
use Rumbo\Backend\Financial\Money;
use Rumbo\Backend\Financial\MovementRepository;
use Rumbo\Backend\Financial\MovementValidator;
use Rumbo\Backend\Financial\ReminderRepository;
use Rumbo\Backend\Http\ApiException;
use Rumbo\Backend\Notifications\NotificationRepository;
use Rumbo\Backend\Notifications\NotificationScheduler;
use Rumbo\Backend\Profile\ProfileRepository;
use Rumbo\Backend\Tests\Support\SqliteTestDatabase;

$backendRoot = require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/Support/SqliteTestDatabase.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP B01-L04 integration: pdo_sqlite no disponible.\n";
    exit(0);
}

$environment = [
    'RUMBO_APP_ENV' => 'test',
    'RUMBO_ALLOWED_ORIGIN' => 'https://rumbo.test',
    'RUMBO_SECURITY_PEPPER' => 'b01-l04-synthetic-test-pepper-only-not-a-secret',
    'RUMBO_SESSION_TTL_SECONDS' => '3600',
    'RUMBO_PASSWORD_RESET_TTL_SECONDS' => '1800',
    'RUMBO_LOGIN_ATTEMPT_WINDOW_SECONDS' => '900',
    'RUMBO_LOGIN_MAX_ATTEMPTS' => '5',
];
foreach ($environment as $name => $value) {
    putenv($name . '=' . $value);
}

$passed = 0;
$failed = 0;
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
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$expectApi = static function (callable $callback, string $code): void {
    try {
        $callback();
    } catch (ApiException $exception) {
        if ($exception->errorCode !== $code) {
            throw new RuntimeException('Se esperaba ' . $code . ' y se obtuvo ' . $exception->errorCode . '.');
        }
        return;
    }
    throw new RuntimeException('No se recibió la excepción esperada ' . $code . '.');
};

$pdo = SqliteTestDatabase::create();
$config = new Config();
$authRepository = new AuthRepository($pdo);
$profiles = new ProfileRepository($pdo);
$auth = new AuthService($authRepository, $profiles, $config, new UnavailablePasswordResetDelivery());
$activator = new AccountActivator($authRepository);
$createActive = static function (string $email, string $name) use ($auth, $activator, $pdo): string {
    $auth->register([
        'email' => $email,
        'password' => 'Rumbo-L04-Integration-2026!',
        'displayName' => $name,
    ]);
    $find = $pdo->prepare('SELECT id FROM users WHERE email = :email');
    $find->execute([':email' => $email]);
    $userId = $find->fetchColumn();
    if (!is_string($userId)) {
        throw new RuntimeException('No se creó la cuenta de prueba.');
    }
    $activator->activate($userId);
    return $userId;
};

$userOne = $createActive('l04-uno@example.test', 'Cuenta Uno');
$userTwo = $createActive('l04-dos@example.test', 'Cuenta Dos');
$movements = new MovementRepository($pdo);
$budgets = new BudgetRepository($pdo);
$goals = new GoalRepository($pdo);
$reminders = new ReminderRepository($pdo);
$notifications = new NotificationRepository($pdo);
$reports = new FinancialReportRepository($pdo);

$test('HNL conserva precisión exacta sin contratos de unidades menores', static function () use ($assert): void {
    $assert(Money::add('1000.00', '0.01', '-0.01') === '1000.00', 'La suma exacta HNL falló.');
    $assert(Money::subtract('1000.00', '225.25') === '774.75', 'La resta exacta HNL falló.');
    $assert(Money::percentage('250.00', '1000.00') === 25, 'El avance de meta no fue exacto.');
});

$test('Presupuesto asocia egresos confirmados por categoría y período', static function () use ($movements, $budgets, $userOne, $assert): void {
    $movements->create($userOne, MovementValidator::create([
        'transactionType' => 'expense',
        'name' => 'Compra sintética',
        'category' => 'Alimentación',
        'amount' => '225.25',
        'date' => '2026-11-16',
    ]), 'l04:budget-expense');
    $budget = $budgets->create($userOne, [
        'category' => 'Alimentación',
        'periodStart' => '2026-11-01',
        'periodEnd' => '2026-11-30',
        'limit' => '600.00',
    ]);
    $assert($budget['used'] === '225.25', 'El presupuesto no acumuló el egreso confirmado.');
    $assert($budget['remaining'] === '374.75', 'El presupuesto no calculó el restante.');
});

$test('Metas bloquean aportes duplicados y aíslan titulares', static function () use ($goals, $userOne, $userTwo, $assert, $expectApi): void {
    $goal = $goals->create($userOne, [
        'name' => 'Fondo sintético',
        'target' => '1000.00',
        'preferredFrequency' => 'twice-monthly',
        'twiceMonthlyDayOne' => 5,
        'twiceMonthlyDayTwo' => 20,
    ]);
    $created = $goals->contribute($userOne, $goal['id'], [
        'amount' => '250.00',
        'contributedOn' => '2026-11-16',
    ], 'l04:goal:001');
    $replayed = $goals->contribute($userOne, $goal['id'], [
        'amount' => '250.00',
        'contributedOn' => '2026-11-16',
    ], 'l04:goal:001');
    $assert($created['replayed'] === false && $replayed['replayed'] === true, 'El aporte no respetó idempotencia.');
    $assert($created['goal']['contributed'] === '250.00' && $created['goal']['remaining'] === '750.00', 'La meta no calculó progreso.');
    $expectApi(static fn () => $goals->contributions($userTwo, $goal['id']), 'NOT_FOUND');
});

$test('Pago recurrente crea un único movimiento confirmado y siguiente obligación', static function () use ($reminders, $movements, $userOne, $assert): void {
    $reminder = $reminders->create($userOne, [
        'name' => 'Servicio sintético',
        'category' => 'Servicios',
        'expectedAmount' => '410.00',
        'dueDate' => '2026-01-31',
        'recurrence' => 'monthly',
        'reminderDaysBefore' => 3,
    ]);
    $payment = $reminders->confirmPayment($userOne, $reminder['id'], [
        'paidOn' => '2026-11-16',
        'notes' => 'Pago de prueba',
    ], 'l04:reminder:001');
    $replay = $reminders->confirmPayment($userOne, $reminder['id'], [
        'paidOn' => '2026-11-16',
        'notes' => 'Pago de prueba',
    ], 'l04:reminder:001');
    $movement = $movements->get($userOne, $payment['payment']['transactionId']);
    $assert($movement['amount'] === '410.00' && $movement['sourceType'] === 'reminder-payment', 'No se creó el movimiento de pago.');
    $assert($payment['nextReminder'] !== null && $payment['nextReminder']['dueDate'] === '2026-02-28', 'La recurrencia mensual segura falló.');
    $assert($replay['replayed'] === true, 'El pago repetido creó una deducción nueva.');
});

$test('Notificaciones guardan preferencia, estado leído y deduplicación', static function () use ($notifications, $userOne, $userTwo, $assert, $expectApi): void {
    $preferences = $notifications->updatePreferences($userOne, [
        'morningEnabled' => true,
        'morningTime' => '07:15',
        'timezone' => 'America/Tegucigalpa',
    ]);
    $assert($preferences['morningTime'] === '07:15', 'La preferencia de hora no se conservó.');
    $assert($notifications->record($userOne, 'morning', 'Prueba', 'Notificación sintética', 'info', 'l04:notice') === true, 'No se creó la notificación.');
    $assert($notifications->record($userOne, 'morning', 'Prueba', 'Notificación sintética', 'info', 'l04:notice') === false, 'La notificación duplicada no fue bloqueada.');
    $list = $notifications->list($userOne, true, 10);
    $assert($list['unreadCount'] === 1, 'El contador no leído no es correcto.');
    $read = $notifications->markRead($userOne, $list['items'][0]['id'], true);
    $assert($read['readAt'] !== null, 'No se marcó la notificación como leída.');
    $expectApi(static fn () => $notifications->markRead($userTwo, $list['items'][0]['id'], true), 'NOT_FOUND');
});

$test('Reporte filtrado y scheduler usan datos del titular sin exponer otro usuario', static function () use ($reports, $notifications, $pdo, $userOne, $assert): void {
    $report = $reports->daily($userOne, FinancialReportRepository::filters('2026-11-01', '2026-11-30', 'Alimentación'));
    $assert($report['summary']['confirmedExpenses'] === '225.25', 'El reporte filtrado no respetó categoría y período.');
    $preferences = $pdo->prepare('UPDATE notification_preferences SET timezone = :timezone, morning_time = :time WHERE user_id = :user_id');
    $preferences->execute([':timezone' => 'UTC', ':time' => '07:00:00', ':user_id' => $userOne]);
    $result = (new NotificationScheduler($pdo, $notifications))->process(new DateTimeImmutable('2026-11-16 07:00:00', new DateTimeZone('UTC')));
    $assert($result['processedUsers'] >= 2, 'El scheduler no procesó cuentas activas.');
});

$test('Administración exige cambios auditables y no modifica roles', static function () use ($pdo, $userOne, $userTwo, $assert): void {
    $grant = $pdo->prepare('INSERT INTO user_roles (user_id, role_code, assigned_by_user_id, assigned_at) VALUES (:user_id, :role, NULL, :at)');
    $grant->execute([':user_id' => $userOne, ':role' => 'admin', ':at' => '2026-11-16 00:00:00.000000']);
    $admin = new AdminRepository($pdo);
    $dashboard = $admin->dashboard();
    $assert($dashboard['metrics']['totalUsers'] === 2, 'El dashboard administrativo no contó usuarios.');
    $updated = $admin->manageAccount($userOne, $userTwo, ['action' => 'suspend'], str_repeat('a', 32));
    $assert($updated['status'] === 'disabled', 'La suspensión administrativa falló.');
    $audit = $admin->auditLogs(10);
    $assert(count($audit) === 1 && $audit[0]['action'] === 'account.suspend', 'No quedó una auditoría administrativa.');
    $roles = $pdo->prepare('SELECT COUNT(*) FROM user_roles WHERE user_id = :user_id AND role_code = :role');
    $roles->execute([':user_id' => $userTwo, ':role' => 'admin']);
    $assert((int) $roles->fetchColumn() === 0, 'El flujo administrativo elevó roles indebidamente.');
});

printf("RESULT %d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
