<?php

declare(strict_types=1);

namespace Rumbo\Backend;

use PDO;
use Throwable;

final class TransactionRunner
{
    /** @template T @param callable(PDO): T $operation @return T */
    public static function run(PDO $pdo, callable $operation): mixed
    {
        $pdo->beginTransaction();

        try {
            $result = $operation($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}
