<?php

declare(strict_types=1);

use Rumbo\Backend\Admin\AdminProvisioner;
use Rumbo\Backend\Config;
use Rumbo\Backend\Database;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este comando solo puede ejecutarse desde CLI.\n");
    exit(1);
}

$email = getenv('RUMBO_ADMIN_EMAIL');
$password = getenv('RUMBO_ADMIN_PASSWORD');
$displayName = getenv('RUMBO_ADMIN_DISPLAY_NAME') ?: 'Operador RUMBO HN';
if (!is_string($email) || trim($email) === '' || !is_string($password) || $password === '') {
    fwrite(STDERR, "Faltan RUMBO_ADMIN_EMAIL o RUMBO_ADMIN_PASSWORD en el mecanismo de secretos del operador.\n");
    exit(2);
}

require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $config = new Config();
    $pdo = (new Database($config))->connection();
    (new AdminProvisioner($pdo, $config))->provision($email, $password, $displayName);
    fwrite(STDOUT, "Cuenta administrativa provisionada. Las credenciales no se imprimieron.\n");
} finally {
    putenv('RUMBO_ADMIN_PASSWORD');
}
