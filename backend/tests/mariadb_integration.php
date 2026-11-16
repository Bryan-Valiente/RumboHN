<?php

declare(strict_types=1);

use Rumbo\Backend\Auth\AccountActivator;
use Rumbo\Backend\Auth\AuthRepository;
use Rumbo\Backend\Auth\AuthService;
use Rumbo\Backend\Auth\SessionAuthenticator;
use Rumbo\Backend\Config;
use Rumbo\Backend\Database;
use Rumbo\Backend\Financial\FinancialSummaryRepository;
use Rumbo\Backend\Financial\MovementRepository;
use Rumbo\Backend\Financial\MovementValidator;
use Rumbo\Backend\Http\ApiException;
use Rumbo\Backend\Http\Request;
use Rumbo\Backend\Profile\ProfileRepository;
use Rumbo\Backend\Tests\Support\FakePasswordResetDelivery;

$backendRoot = require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/Support/FakePasswordResetDelivery.php';

$config = new Config();
$databaseConfig = $config->database();
if ($config->get('RUMBO_APP_ENV') !== 'test'
    || !in_array($databaseConfig['host'], ['127.0.0.1', 'localhost'], true)
    || !preg_match('/_test$/', $databaseConfig['name'])) {
    fwrite(STDERR, "BLOCKED: mariadb_integration.php solo admite una base local con sufijo _test y RUMBO_APP_ENV=test.\n");
    exit(2);
}

$pdo = (new Database($config))->connection();
$authRepository = new AuthRepository($pdo);
$profiles = new ProfileRepository($pdo);
$auth = new AuthService(
    $authRepository,
    $profiles,
    $config,
    new FakePasswordResetDelivery(),
);
$activator = new AccountActivator($authRepository);
$movements = new MovementRepository($pdo);
$summary = new FinancialSummaryRepository($pdo);
$createdUsers = [];
$passed = 0;

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
            throw new RuntimeException(sprintf('Se esperaba %s y se obtuvo %s.', $code, $exception->errorCode));
        }
        return;
    }
    throw new RuntimeException(sprintf('Se esperaba ApiException %s.', $code));
};

$createActive = static function (string $email) use ($auth, $activator, $pdo, &$createdUsers): string {
    $auth->register([
        'email' => $email,
        'password' => 'Rumbo-MariaDB-2026!',
        'displayName' => 'Cuenta sintética MariaDB',
    ]);
    $statement = $pdo->prepare('SELECT id FROM users WHERE email = :email');
    $statement->execute([':email' => $email]);
    $id = $statement->fetchColumn();
    if (!is_string($id)) {
        throw new RuntimeException('No se creó la cuenta sintética MariaDB.');
    }
    $createdUsers[] = $id;
    $activator->activate($id);
    return $id;
};

try {
    $suffix = bin2hex(random_bytes(6));
    $userOne = $createActive('b01-l03-one-' . $suffix . '@example.test');
    $userTwo = $createActive('b01-l03-two-' . $suffix . '@example.test');
    $passed++;
    echo "PASS MariaDB registro, activación y dos titulares\n";

    $login = $auth->login([
        'email' => 'b01-l03-one-' . $suffix . '@example.test',
        'password' => 'Rumbo-MariaDB-2026!',
    ], '127.0.0.1', 'Rumbo-MariaDB-Integration');
    $request = new Request('GET', '/api/v1/auth/session', [], str_repeat('1', 32), '', [
        'rumbo_session' => (string) $login['sessionToken'],
    ]);
    $sessionUser = (new SessionAuthenticator(new Database($config), $config))->authenticate($request);
    $assert($sessionUser->id === $userOne, 'La sesión no recuperó al titular correcto.');
    $assert($sessionUser->roles === ['member'], 'La sesión no recuperó el rol member.');
    $passed++;
    echo "PASS MariaDB sesión hash y rol server-side\n";

    $profiles->update($userOne, ['openingBalance' => '1000.00']);
    $payload = MovementValidator::create([
        'transactionType' => 'income',
        'name' => 'Ingreso sintético MariaDB',
        'category' => 'Pruebas',
        'amount' => '500.00',
        'date' => '2026-11-15',
    ]);
    $created = $movements->create($userOne, $payload, 'mariadb:' . $suffix . ':001');
    $replayed = $movements->create($userOne, $payload, 'mariadb:' . $suffix . ':001');
    $assert($created['replayed'] === false && $replayed['replayed'] === true, 'La idempotencia no distinguió creación y replay.');
    $assert($created['movement']['amount'] === '500.00', 'MariaDB no preservó HNL con dos decimales.');
    $expectApi(
        static fn () => $movements->get($userTwo, (string) $created['movement']['id']),
        'NOT_FOUND',
    );
    $passed++;
    echo "PASS MariaDB idempotencia, HNL y aislamiento cruzado\n";

    $financial = $summary->get($userOne);
    $assert($financial['confirmedIncome'] === '500.00', 'El resumen no conservó el ingreso confirmado.');
    $assert($financial['totalBalance'] === '1500.00', 'El resumen no calculó el saldo esperado.');
    $assert($financial['currency'] === 'HNL', 'El resumen no conservó HNL como moneda autoritativa.');
    $passed++;
    echo "PASS MariaDB vista financiera por titular\n";

    $updatedPayload = MovementValidator::update($created['movement'], ['amount' => '550.50']);
    $updated = $movements->update($userOne, (string) $created['movement']['id'], $updatedPayload);
    $assert($updated['amount'] === '550.50', 'PATCH no conservó precisión HNL.');
    $movements->delete($userOne, (string) $created['movement']['id']);
    $expectApi(
        static fn () => $movements->get($userOne, (string) $created['movement']['id']),
        'NOT_FOUND',
    );
    $passed++;
    echo "PASS MariaDB actualización y borrado lógico\n";
} finally {
    foreach ($createdUsers as $userId) {
        $delete = $pdo->prepare('DELETE FROM users WHERE id = :id');
        $delete->execute([':id' => $userId]);
    }
}

printf("RESULT %d MariaDB integration checks passed\n", $passed);
