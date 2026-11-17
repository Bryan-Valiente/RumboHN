<?php

declare(strict_types=1);

namespace Rumbo\Backend\Financial;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Rumbo\Backend\Http\ApiException;

final class FinancialInput
{
    /** @param array<string, mixed> $data @param list<string> $allowed */
    public static function assertAllowedKeys(array $data, array $allowed): void
    {
        $unexpected = array_values(array_diff(array_keys($data), $allowed));
        if ($unexpected !== []) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'La solicitud contiene campos no permitidos.', [
                'fields' => $unexpected,
            ]);
        }
    }

    public static function hnl(mixed $value, string $field, bool $allowZero = false): string
    {
        try {
            return Money::normalizeHnl($value, $allowZero);
        } catch (InvalidArgumentException $exception) {
            throw new ApiException(422, 'VALIDATION_ERROR', $exception->getMessage(), ['field' => $field]);
        }
    }

    public static function date(mixed $value, string $field): string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'La fecha debe usar YYYY-MM-DD.', ['field' => $field]);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'La fecha no es válida.', ['field' => $field]);
        }

        return $value;
    }

    public static function nullableDate(mixed $value, string $field): ?string
    {
        return $value === null ? null : self::date($value, $field);
    }

    /** @param list<string> $allowed */
    public static function enum(mixed $value, string $field, array $allowed): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'El valor no está permitido.', ['field' => $field]);
        }

        return $value;
    }

    public static function string(mixed $value, string $field, int $minimum, int $maximum): string
    {
        if (!is_string($value)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'El campo no tiene un formato válido.', ['field' => $field]);
        }

        $value = trim($value);
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length < $minimum || $length > $maximum) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'El campo está fuera del tamaño permitido.', ['field' => $field]);
        }

        return $value;
    }

    public static function nullableString(mixed $value, string $field, int $maximum): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = self::string($value, $field, 0, $maximum);
        return $string === '' ? null : $string;
    }

    public static function nullablePositiveInt(mixed $value, string $field, int $minimum, int $maximum): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'El campo debe ser un entero dentro del rango permitido.', [
                'field' => $field,
            ]);
        }
        return $value;
    }

    public static function boolean(mixed $value, string $field): bool
    {
        if (!is_bool($value)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'El campo debe ser booleano.', ['field' => $field]);
        }
        return $value;
    }

    public static function time(mixed $value, string $field): string
    {
        if (!is_string($value) || !preg_match('/^\d{2}:\d{2}$/', $value)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'La hora debe usar HH:MM.', ['field' => $field]);
        }
        [$hours, $minutes] = array_map('intval', explode(':', $value));
        if ($hours > 23 || $minutes > 59) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'La hora no es válida.', ['field' => $field]);
        }
        return $value . ':00';
    }
}
