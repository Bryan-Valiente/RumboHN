<?php

declare(strict_types=1);

namespace Rumbo\Backend;

use RuntimeException;

final class Config
{
    public function get(string $name, ?string $default = null): ?string
    {
        $value = getenv($name);
        if ($value === false || trim($value) === '') {
            return $default;
        }

        return trim($value);
    }

    public function require(string $name): string
    {
        $value = $this->get($name);
        if ($value === null) {
            throw new RuntimeException(sprintf('Falta la variable de entorno requerida: %s', $name));
        }

        return $value;
    }

    public function isProduction(): bool
    {
        return $this->get('RUMBO_APP_ENV', 'development') === 'production';
    }

    /** @return array{host: string, port: int, name: string, allowedName: string, user: string, password: string} */
    public function database(): array
    {
        $port = filter_var($this->get('RUMBO_DB_PORT', '3306'), FILTER_VALIDATE_INT);
        if ($port === false || $port < 1 || $port > 65535) {
            throw new RuntimeException('RUMBO_DB_PORT no es válido.');
        }

        $name = $this->require('RUMBO_DB_NAME');
        $allowedName = $this->require('RUMBO_DB_ALLOWED_NAME');
        if (!hash_equals($allowedName, $name)) {
            throw new RuntimeException('La base configurada no coincide con RUMBO_DB_ALLOWED_NAME.');
        }

        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new RuntimeException('El nombre de la base contiene caracteres no permitidos.');
        }

        return [
            'host' => $this->require('RUMBO_DB_HOST'),
            'port' => $port,
            'name' => $name,
            'allowedName' => $allowedName,
            'user' => $this->require('RUMBO_DB_USER'),
            'password' => $this->require('RUMBO_DB_PASSWORD'),
        ];
    }
}
