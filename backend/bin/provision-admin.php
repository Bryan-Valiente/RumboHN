<?php

declare(strict_types=1);

use Rumbo\Backend\Admin\AdminProvisioner;
use Rumbo\Backend\Config;
use Rumbo\Backend\Database;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este comando solo puede ejecutarse desde CLI.\n");
    exit(1);
}

$username = getenv('RUMBO_ADMIN_USERNAME') ?: 'rumbohn2026';
$password = getenv('RUMBO_ADMIN_PASSWORD');
$displayName = getenv('RUMBO_ADMIN_DISPLAY_NAME') ?: 'Operador RUMBO HN';
if (!is_string($username) || trim($username) === '') {
    fwrite(STDERR, "Falta RUMBO_ADMIN_USERNAME en el mecanismo de secretos del operador.\n");
    exit(2);
}

if (!is_string($password) || $password === '') {
    $isTty = function_exists('stream_isatty')
        ? stream_isatty(STDIN)
        : (function_exists('posix_isatty') && posix_isatty(STDIN));
    if (!$isTty) {
        fwrite(STDERR, "RUMBO_ADMIN_PASSWORD requiere un terminal interactivo seguro.\n");
        exit(2);
    }
    fwrite(STDERR, "Contraseña administrativa (no se mostrará): ");
    $stty = shell_exec('stty -g');
    shell_exec('stty -echo');
    try {
        $password = fgets(STDIN);
    } finally {
        if (is_string($stty) && $stty !== '') {
            shell_exec('stty ' . escapeshellarg(trim($stty)));
        }
        fwrite(STDERR, PHP_EOL);
    }
    $password = is_string($password) ? rtrim($password, "\r\n") : '';
}
if ($password === '') {
    fwrite(STDERR, "La contraseña administrativa no puede estar vacía.\n");
    exit(2);
}

require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $config = new Config();
    $pdo = (new Database($config))->connection();
    (new AdminProvisioner($pdo, $config))->provision($username, $password, $displayName);
    fwrite(STDOUT, "Cuenta administrativa provisionada. Las credenciales no se imprimieron.\n");
} finally {
    putenv('RUMBO_ADMIN_PASSWORD');
}
