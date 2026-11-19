<?php

declare(strict_types=1);

namespace Rumbo\Backend\Idempotency;

use InvalidArgumentException;

final class IdempotencyKey
{
    public static function normalize(?string $value): string
    {
        $value = trim((string) $value);
        if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $value)) {
            throw new InvalidArgumentException('La clave de idempotencia debe contener entre 8 y 128 caracteres seguros.');
        }

        return $value;
    }
}
