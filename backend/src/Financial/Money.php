<?php

declare(strict_types=1);

namespace Rumbo\Backend\Financial;

use InvalidArgumentException;

final class Money
{
    public const CURRENCY = 'HNL';

    public static function normalizeHnl(mixed $value, bool $allowZero = false): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('El importe HNL debe enviarse como cadena decimal.');
        }

        $value = trim($value);
        if (!preg_match('/^(0|[1-9][0-9]{0,12})(?:\.([0-9]{1,2}))?$/', $value, $matches)) {
            throw new InvalidArgumentException('El importe HNL debe tener hasta 13 enteros y dos decimales, sin signo ni notación científica.');
        }

        $fraction = str_pad($matches[2] ?? '', 2, '0');
        $normalized = sprintf('%s.%s', $matches[1], $fraction);
        if (!$allowZero && preg_match('/^0\.00$/', $normalized)) {
            throw new InvalidArgumentException('El importe HNL debe ser mayor que cero.');
        }

        return $normalized;
    }

    public static function formatDatabase(string $value, bool $allowZero = true): string
    {
        return self::normalizeHnl($value, $allowZero);
    }

    public static function formatDatabaseSigned(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^-?(0|[1-9][0-9]{0,12})(?:\.([0-9]{1,2}))?$/', $value, $matches)) {
            throw new InvalidArgumentException('El importe almacenado no tiene precisión HNL válida.');
        }

        $negative = str_starts_with($value, '-') && !preg_match('/^-0(?:\.0{1,2})?$/', $value);
        $integer = ltrim($matches[1], '-');
        $fraction = str_pad($matches[2] ?? '', 2, '0');
        return sprintf('%s%s.%s', $negative ? '-' : '', $integer, $fraction);
    }
}
