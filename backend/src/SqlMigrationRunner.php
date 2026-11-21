<?php

declare(strict_types=1);

namespace Rumbo\Backend;

use PDO;
use RuntimeException;

final class SqlMigrationRunner
{
    /** @return list<string> */
    public static function statements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $quote = null;
        $lineComment = false;
        $blockComment = false;
        $length = strlen($sql);

        for ($index = 0; $index < $length; $index++) {
            $character = $sql[$index];
            $next = $index + 1 < $length ? $sql[$index + 1] : '';

            if ($lineComment) {
                if ($character === "\n") {
                    $lineComment = false;
                    $buffer .= $character;
                }
                continue;
            }

            if ($blockComment) {
                if ($character === '*' && $next === '/') {
                    $blockComment = false;
                    $index++;
                }
                continue;
            }

            if ($quote === null && $character === '-' && $next === '-' && ($index + 2 >= $length || ctype_space($sql[$index + 2]))) {
                $lineComment = true;
                $index++;
                continue;
            }

            if ($quote === null && $character === '/' && $next === '*') {
                $blockComment = true;
                $index++;
                continue;
            }

            if ($quote === null && ($character === "'" || $character === '"' || $character === '`')) {
                $quote = $character;
                $buffer .= $character;
                continue;
            }

            if ($quote !== null) {
                $buffer .= $character;
                if ($character === '\\' && $index + 1 < $length) {
                    $buffer .= $sql[++$index];
                    continue;
                }
                if ($character === $quote) {
                    if ($next === $quote) {
                        $buffer .= $sql[++$index];
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }

            if ($character === ';') {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $character;
        }

        if ($quote !== null || $blockComment) {
            throw new RuntimeException('La migración SQL contiene una cadena o comentario sin cerrar.');
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            $statements[] = $tail;
        }

        return $statements;
    }

    public static function apply(PDO $pdo, string $sql): int
    {
        $count = 0;
        foreach (self::statements($sql) as $statement) {
            $pdo->exec($statement);
            $count++;
        }
        return $count;
    }
}
