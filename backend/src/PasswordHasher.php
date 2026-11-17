<?php

declare(strict_types=1);

namespace Rumbo\Backend;

use InvalidArgumentException;
use RuntimeException;

final class PasswordHasher
{
    public static function hash(string $password): string
    {
        self::assertAcceptable($password);
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        $hash = password_hash($password, $algorithm);
        if ($hash === false) {
            throw new RuntimeException('No se pudo proteger la contraseña.');
        }

        return $hash;
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        return password_needs_rehash($hash, $algorithm);
    }

    public static function assertAcceptable(string $password): void
    {
        $length = strlen($password);
        if ($length < 12 || $length > 128) {
            throw new InvalidArgumentException('La contraseña debe contener entre 12 y 128 bytes.');
        }

        $classes = 0;
        $classes += preg_match('/[a-z]/', $password) === 1 ? 1 : 0;
        $classes += preg_match('/[A-Z]/', $password) === 1 ? 1 : 0;
        $classes += preg_match('/[0-9]/', $password) === 1 ? 1 : 0;
        $classes += preg_match('/[^A-Za-z0-9]/', $password) === 1 ? 1 : 0;
        if ($classes < 3) {
            throw new InvalidArgumentException('La contraseña debe combinar al menos tres tipos de caracteres.');
        }
    }
}
