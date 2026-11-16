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

    public function boolean(string $name, bool $default = false): bool
    {
        $value = $this->get($name);
        if ($value === null) {
            return $default;
        }

        $normalized = strtolower($value);
        if ($normalized === 'true') {
            return true;
        }
        if ($normalized === 'false') {
            return false;
        }
        throw new RuntimeException(sprintf('%s debe ser true o false.', $name));
    }

    public function integer(string $name, int $default, int $minimum, int $maximum): int
    {
        $value = $this->get($name, (string) $default);
        if ($value === null || !preg_match('/^[0-9]+$/', $value)) {
            throw new RuntimeException(sprintf('%s debe ser un entero.', $name));
        }

        $parsed = (int) $value;
        if ($parsed < $minimum || $parsed > $maximum) {
            throw new RuntimeException(sprintf('%s está fuera del rango permitido.', $name));
        }

        return $parsed;
    }

    public function securityPepper(): string
    {
        $pepper = $this->require('RUMBO_SECURITY_PEPPER');
        if (strlen($pepper) < 32) {
            throw new RuntimeException('RUMBO_SECURITY_PEPPER debe contener al menos 32 bytes.');
        }

        return $pepper;
    }

    public function sessionTtlSeconds(): int
    {
        return $this->integer('RUMBO_SESSION_TTL_SECONDS', 604800, 900, 2592000);
    }

    public function passwordResetTtlSeconds(): int
    {
        return $this->integer('RUMBO_PASSWORD_RESET_TTL_SECONDS', 1800, 300, 86400);
    }

    public function loginAttemptWindowSeconds(): int
    {
        return $this->integer('RUMBO_LOGIN_ATTEMPT_WINDOW_SECONDS', 900, 60, 86400);
    }

    public function loginMaxAttempts(): int
    {
        return $this->integer('RUMBO_LOGIN_MAX_ATTEMPTS', 5, 2, 100);
    }

    public function cookieSecure(): bool
    {
        return $this->isProduction() || $this->boolean('RUMBO_COOKIE_SECURE', false);
    }

    public function loginEnabled(): bool
    {
        return $this->boolean('RUMBO_LOGIN_ENABLED', false);
    }

    public function registrationEnabled(): bool
    {
        return $this->boolean('RUMBO_REGISTRATION_ENABLED', false)
            && $this->boolean('RUMBO_ACCOUNT_VERIFICATION_READY', false);
    }

    public function passwordRecoveryEnabled(): bool
    {
        return $this->boolean('RUMBO_PASSWORD_RECOVERY_ENABLED', false);
    }

    public function sessionCookieName(): string
    {
        return 'rumbo_session';
    }

    public function csrfCookieName(): string
    {
        return 'rumbo_csrf';
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
