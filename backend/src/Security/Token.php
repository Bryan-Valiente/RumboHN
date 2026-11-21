<?php

declare(strict_types=1);

namespace Rumbo\Backend\Security;

final class Token
{
    public static function random(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token, true);
    }

    public static function keyedHash(string $value, string $pepper): string
    {
        return hash_hmac('sha256', $value, $pepper, true);
    }

    public static function csrf(string $sessionToken, string $pepper): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'csrf:' . $sessionToken, $pepper, true)), '+/', '-_'), '=');
    }
}
