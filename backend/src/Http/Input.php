<?php

declare(strict_types=1);

namespace Rumbo\Backend\Http;

final class Input
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

    /** @param array<string, mixed> $data */
    public static function string(array $data, string $field, int $minimum, int $maximum): string
    {
        $value = $data[$field] ?? null;
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

    /** @param array<string, mixed> $data */
    public static function rawString(array $data, string $field, int $minimum, int $maximum): string
    {
        $value = $data[$field] ?? null;
        if (!is_string($value)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'El campo no tiene un formato válido.', ['field' => $field]);
        }
        $length = strlen($value);
        if ($length < $minimum || $length > $maximum) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'El campo está fuera del tamaño permitido.', ['field' => $field]);
        }
        return $value;
    }

    /** @param array<string, mixed> $data */
    public static function boolean(array $data, string $field): bool
    {
        $value = $data[$field] ?? null;
        if (!is_bool($value)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'El campo debe ser booleano.', ['field' => $field]);
        }

        return $value;
    }
}
