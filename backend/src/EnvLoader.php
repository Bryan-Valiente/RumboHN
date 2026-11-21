<?php

declare(strict_types=1);

namespace Rumbo\Backend;

use RuntimeException;

final class EnvLoader
{
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('No se pudo leer el archivo de entorno.');
        }

        foreach ($lines as $lineNumber => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $separator = strpos($line, '=');
            if ($separator === false) {
                throw new RuntimeException(sprintf('Variable de entorno inválida en la línea %d.', $lineNumber + 1));
            }

            $name = trim(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $name)) {
                throw new RuntimeException(sprintf('Nombre de variable inválido en la línea %d.', $lineNumber + 1));
            }

            if ((str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }

            if (getenv($name) !== false) {
                continue;
            }

            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
        }
    }
}
