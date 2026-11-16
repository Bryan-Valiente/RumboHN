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

    public static function assertAcceptable(string $password): void
    {
        $length = strlen($password);
        if ($length < 12 || $length > 4096) {
            throw new InvalidArgumentException('La contraseña debe contener entre 12 y 4096 bytes.');
        }
    }
}
