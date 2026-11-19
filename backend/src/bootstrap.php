<?php

declare(strict_types=1);

$backendRoot = dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($backendRoot): void {
    $prefix = 'Rumbo\\Backend\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = $backendRoot . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

\Rumbo\Backend\EnvLoader::load((string) (getenv('RUMBO_ENV_FILE') ?: $backendRoot . '/.env'));

return $backendRoot;
