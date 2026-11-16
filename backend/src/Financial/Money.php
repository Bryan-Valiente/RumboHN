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
        return self::fromScaled(self::scaled($value, true));
    }

    /**
     * Suma valores HNL como enteros escalados internamente, sin delegar
     * cálculos financieros a un float ni exponer unidades menores en el API.
     *
     * @param list<string> $values
     */
    public static function add(string ...$values): string
    {
        $total = 0;
        foreach ($values as $value) {
            $total += self::scaled($value, true);
        }

        return self::fromScaled($total);
    }

    public static function subtract(string $minuend, string $subtrahend): string
    {
        return self::fromScaled(self::scaled($minuend, true) - self::scaled($subtrahend, true));
    }

    public static function compare(string $left, string $right): int
    {
        return self::scaled($left, true) <=> self::scaled($right, true);
    }

    /**
     * Porcentaje entero para presentación; los importes se mantienen como
     * cadenas HNL y no se admiten unidades menores en el contrato.
     */
    public static function percentage(string $part, string $whole): int
    {
        $scaledWhole = self::scaled($whole, false);
        if ($scaledWhole <= 0) {
            throw new InvalidArgumentException('El total HNL debe ser mayor que cero.');
        }

        $scaledPart = max(0, self::scaled($part, true));
        return min(100, intdiv($scaledPart * 100, $scaledWhole));
    }

    public static function nonNegative(string $value): string
    {
        return self::fromScaled(max(0, self::scaled($value, true)));
    }

    private static function scaled(string $value, bool $allowNegative): int
    {
        $value = trim($value);
        $pattern = $allowNegative
            ? '/^-?(0|[1-9][0-9]{0,12})(?:\.([0-9]{1,2}))?$/'
            : '/^(0|[1-9][0-9]{0,12})(?:\.([0-9]{1,2}))?$/';
        if (!preg_match($pattern, $value, $matches)) {
            throw new InvalidArgumentException('El importe almacenado no tiene precisión HNL válida.');
        }

        $negative = str_starts_with($value, '-');
        $integer = (int) ltrim($matches[1], '-');
        $fraction = (int) str_pad($matches[2] ?? '', 2, '0');
        $scaled = ($integer * 100) + $fraction;

        return $negative && $scaled !== 0 ? -$scaled : $scaled;
    }

    private static function fromScaled(int $scaled): string
    {
        $negative = $scaled < 0;
        $absolute = abs($scaled);
        return sprintf(
            '%s%d.%02d',
            $negative ? '-' : '',
            intdiv($absolute, 100),
            $absolute % 100,
        );
    }
}
