<?php

declare(strict_types=1);

namespace Rumbo\Backend\Financial;

use PDO;
use Rumbo\Backend\Http\ApiException;
use Rumbo\Backend\Support\Clock;
use Rumbo\Backend\Support\Dates;
use Rumbo\Backend\Support\SystemClock;
use Rumbo\Backend\Support\Uuid;
use Rumbo\Backend\TransactionRunner;

final class MovementRepository
{
    private readonly Clock $clock;

    public function __construct(private readonly PDO $pdo, ?Clock $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * @param array{date: string, id: string}|null $cursor
     * @return array{items: list<array<string, mixed>>, hasMore: bool}
     */
    public function list(string $userId, int $limit, ?array $cursor): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'El límite debe estar entre 1 y 100.', ['field' => 'limit']);
        }

        $sql = 'SELECT * FROM financial_transactions
                WHERE user_id = :user_id AND deleted_at IS NULL';
        $parameters = [':user_id' => $userId];
        if ($cursor !== null) {
            $sql .= ' AND (occurred_on < :cursor_date OR (occurred_on = :cursor_date AND id < :cursor_id))';
            $parameters[':cursor_date'] = $cursor['date'];
            $parameters[':cursor_id'] = $cursor['id'];
        }
        $sql .= ' ORDER BY occurred_on DESC, id DESC LIMIT ' . ($limit + 1);
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $rows = $statement->fetchAll();
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        return [
            'items' => array_map(fn (array $row): array => $this->map($row), $rows),
            'hasMore' => $hasMore,
        ];
    }

    /** @return array<string, mixed> */
    public function get(string $userId, string $movementId): array
    {
        $row = $this->findRow($userId, $movementId);
        if ($row === null) {
            throw new ApiException(404, 'NOT_FOUND', 'El movimiento no existe.');
        }
        return $this->map($row);
    }

    /**
     * @param array<string, mixed> $movement
     * @return array{movement: array<string, mixed>, replayed: bool}
     */
    public function create(string $userId, array $movement, string $idempotencyKey): array
    {
        $canonical = $movement;
        ksort($canonical);
        $encoded = json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $requestHash = hash('sha256', $encoded, true);

        return TransactionRunner::run($this->pdo, function (PDO $pdo) use (
            $userId,
            $movement,
            $idempotencyKey,
            $requestHash,
        ): array {
            $existing = $this->findIdempotency($userId, $idempotencyKey, true);
            if ($existing !== null) {
                if (!hash_equals((string) $existing['request_hash'], $requestHash)) {
                    throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', 'La clave de idempotencia ya se usó con otra solicitud.');
                }
                if ((string) $existing['state'] === 'completed' && is_string($existing['resource_id'])) {
                    return [
                        'movement' => $this->get($userId, (string) $existing['resource_id']),
                        'replayed' => true,
                    ];
                }
                throw new ApiException(409, 'CONFLICT', 'La operación con esa clave aún no está disponible.');
            }

            $now = $this->clock->now();
            $insertKey = $pdo->prepare(
                'INSERT INTO idempotency_keys
                    (user_id, operation_scope, idempotency_key, request_hash, state, resource_type,
                     resource_id, response_status, created_at, completed_at, expires_at)
                 VALUES (:user_id, :scope, :idempotency_key, :request_hash, :state, NULL,
                         NULL, NULL, :created_at, NULL, :expires_at)'
            );
            $insertKey->bindValue(':user_id', $userId);
            $insertKey->bindValue(':scope', 'movements.create');
            $insertKey->bindValue(':idempotency_key', $idempotencyKey);
            $insertKey->bindValue(':request_hash', $requestHash, PDO::PARAM_LOB);
            $insertKey->bindValue(':state', 'processing');
            $insertKey->bindValue(':created_at', Dates::database($now));
            $insertKey->bindValue(':expires_at', Dates::database($now->modify('+24 hours')));
            $insertKey->execute();

            $movementId = Uuid::v4();
            $insert = $pdo->prepare(
                'INSERT INTO financial_transactions
                    (id, user_id, transaction_type, name, category, amount_hnl, currency, occurred_on,
                     occurred_at, status, source_type, recurrence, is_quick_expense, notes,
                     created_at, updated_at, deleted_at)
                 VALUES
                    (:id, :user_id, :transaction_type, :name, :category, :amount_hnl, :currency, :occurred_on,
                     :occurred_at, :status, :source_type, :recurrence, :is_quick_expense, :notes,
                     :created_at, :updated_at, NULL)'
            );
            $this->bindMovement($insert, $movementId, $userId, $movement, $now);
            $insert->execute();

            $complete = $pdo->prepare(
                'UPDATE idempotency_keys
                 SET state = :state, resource_type = :resource_type, resource_id = :resource_id,
                     response_status = :response_status, completed_at = :completed_at
                 WHERE user_id = :user_id AND operation_scope = :scope AND idempotency_key = :idempotency_key'
            );
            $complete->execute([
                ':state' => 'completed',
                ':resource_type' => 'movement',
                ':resource_id' => $movementId,
                ':response_status' => 201,
                ':completed_at' => Dates::database($now),
                ':user_id' => $userId,
                ':scope' => 'movements.create',
                ':idempotency_key' => $idempotencyKey,
            ]);

            return ['movement' => $this->get($userId, $movementId), 'replayed' => false];
        });
    }

    /** @param array<string, mixed> $movement @return array<string, mixed> */
    public function update(string $userId, string $movementId, array $movement): array
    {
        return TransactionRunner::run($this->pdo, function (PDO $pdo) use ($userId, $movementId, $movement): array {
            $row = $this->findRow($userId, $movementId, true);
            if ($row === null) {
                throw new ApiException(404, 'NOT_FOUND', 'El movimiento no existe.');
            }
            if ((string) $row['source_type'] === 'reminder-payment') {
                throw new ApiException(409, 'CONFLICT', 'Los pagos de recordatorio se administran desde su operación de pago.');
            }

            $statement = $pdo->prepare(
                'UPDATE financial_transactions
                 SET transaction_type = :transaction_type, name = :name, category = :category,
                     amount_hnl = :amount_hnl, currency = :currency, occurred_on = :occurred_on,
                     occurred_at = :occurred_at, status = :status, source_type = :source_type,
                     recurrence = :recurrence, is_quick_expense = :is_quick_expense,
                     notes = :notes, updated_at = :updated_at
                 WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL'
            );
            $this->bindMovement($statement, $movementId, $userId, $movement, $this->clock->now(), false);
            $statement->execute();
            return $this->get($userId, $movementId);
        });
    }

    public function delete(string $userId, string $movementId): void
    {
        TransactionRunner::run($this->pdo, function (PDO $pdo) use ($userId, $movementId): void {
            $row = $this->findRow($userId, $movementId, true);
            if ($row === null) {
                throw new ApiException(404, 'NOT_FOUND', 'El movimiento no existe.');
            }
            if ((string) $row['source_type'] === 'reminder-payment') {
                throw new ApiException(409, 'CONFLICT', 'Los pagos de recordatorio se revierten desde su operación de pago.');
            }
            $now = Dates::database($this->clock->now());
            $statement = $pdo->prepare(
                'UPDATE financial_transactions
                 SET status = :status, deleted_at = :deleted_at, updated_at = :updated_at
                 WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL'
            );
            $statement->execute([
                ':status' => 'voided',
                ':deleted_at' => $now,
                ':updated_at' => $now,
                ':id' => $movementId,
                ':user_id' => $userId,
            ]);
        });
    }

    /** @return array<string, mixed>|null */
    private function findRow(string $userId, string $movementId, bool $lock = false): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM financial_transactions
             WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL
             LIMIT 1' . ($lock ? $this->forUpdate() : '')
        );
        $statement->execute([':id' => $movementId, ':user_id' => $userId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function findIdempotency(string $userId, string $key, bool $lock): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT request_hash, state, resource_id
             FROM idempotency_keys
             WHERE user_id = :user_id AND operation_scope = :scope AND idempotency_key = :idempotency_key
             LIMIT 1' . ($lock ? $this->forUpdate() : '')
        );
        $statement->execute([
            ':user_id' => $userId,
            ':scope' => 'movements.create',
            ':idempotency_key' => $key,
        ]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $movement */
    private function bindMovement(
        \PDOStatement $statement,
        string $movementId,
        string $userId,
        array $movement,
        \DateTimeImmutable $now,
        bool $includeCreatedAt = true,
    ): void {
        $statement->bindValue(':id', $movementId);
        $statement->bindValue(':user_id', $userId);
        $statement->bindValue(':transaction_type', (string) $movement['transactionType']);
        $statement->bindValue(':name', (string) $movement['name']);
        $statement->bindValue(':category', (string) $movement['category']);
        $statement->bindValue(':amount_hnl', (string) $movement['amount']);
        $statement->bindValue(':currency', Money::CURRENCY);
        $statement->bindValue(':occurred_on', (string) $movement['date']);
        $statement->bindValue(':occurred_at', $movement['occurredAt'], $movement['occurredAt'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':status', (string) $movement['status']);
        $statement->bindValue(':source_type', (string) $movement['sourceType']);
        $statement->bindValue(':recurrence', (string) $movement['recurrence']);
        $statement->bindValue(':is_quick_expense', $movement['isQuickExpense'] ? 1 : 0, PDO::PARAM_INT);
        $statement->bindValue(':notes', $movement['notes'], $movement['notes'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        if ($includeCreatedAt) {
            $statement->bindValue(':created_at', Dates::database($now));
        }
        $statement->bindValue(':updated_at', Dates::database($now));
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function map(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'userId' => (string) $row['user_id'],
            'transactionType' => (string) $row['transaction_type'],
            'name' => (string) $row['name'],
            'category' => (string) $row['category'],
            'amount' => Money::formatDatabase((string) $row['amount_hnl']),
            'currency' => Money::CURRENCY,
            'date' => (string) $row['occurred_on'],
            'occurredAt' => Dates::iso($row['occurred_at'] === null ? null : (string) $row['occurred_at']),
            'status' => (string) $row['status'],
            'sourceType' => (string) $row['source_type'],
            'recurrence' => (string) $row['recurrence'],
            'isQuickExpense' => (bool) $row['is_quick_expense'],
            'notes' => $row['notes'] === null ? null : (string) $row['notes'],
            'createdAt' => Dates::iso((string) $row['created_at']),
            'updatedAt' => Dates::iso((string) $row['updated_at']),
        ];
    }

    private function forUpdate(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }
}
