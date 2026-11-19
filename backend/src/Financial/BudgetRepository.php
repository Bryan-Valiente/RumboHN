<?php

declare(strict_types=1);

namespace Rumbo\Backend\Financial;

use PDO;
use PDOException;
use Rumbo\Backend\Http\ApiException;
use Rumbo\Backend\Support\Dates;
use Rumbo\Backend\Support\SystemClock;
use Rumbo\Backend\Support\Uuid;
use Rumbo\Backend\TransactionRunner;

final class BudgetRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(string $userId, ?string $activeOn = null): array
    {
        $sql = 'SELECT b.*,
                       CAST(COALESCE(SUM(CASE
                           WHEN f.transaction_type = \'expense\'
                            AND f.status = \'confirmed\'
                            AND f.deleted_at IS NULL
                           THEN f.amount_hnl ELSE 0.00 END), 0.00) AS DECIMAL(15,2)) AS used_hnl
                FROM budgets b
                LEFT JOIN financial_transactions f
                  ON f.user_id = b.user_id
                 AND f.category = b.category
                 AND f.occurred_on BETWEEN b.period_start AND b.period_end
                WHERE b.user_id = :user_id';
        $parameters = [':user_id' => $userId];
        if ($activeOn !== null) {
            $sql .= ' AND b.period_start <= :active_on AND b.period_end >= :active_on';
            $parameters[':active_on'] = $activeOn;
        }
        $sql .= ' GROUP BY b.id
                  ORDER BY b.period_start DESC, b.category ASC, b.id ASC';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return array_map(fn (array $row): array => $this->map($row), $statement->fetchAll());
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function create(string $userId, array $data): array
    {
        $budget = self::validate($data);
        $id = Uuid::v4();
        $now = Dates::database((new SystemClock())->now());

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO budgets
                    (id, user_id, category, period_start, period_end, limit_hnl, currency, color, created_at, updated_at)
                 VALUES
                    (:id, :user_id, :category, :period_start, :period_end, :limit_hnl, :currency, :color, :created_at, :updated_at)'
            );
            $statement->execute([
                ':id' => $id,
                ':user_id' => $userId,
                ':category' => $budget['category'],
                ':period_start' => $budget['periodStart'],
                ':period_end' => $budget['periodEnd'],
                ':limit_hnl' => $budget['limit'],
                ':currency' => Money::CURRENCY,
                ':color' => $budget['color'],
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw new ApiException(409, 'CONFLICT', 'Ya existe un presupuesto para esa categoría y período.');
            }
            throw $exception;
        }

        return $this->get($userId, $id);
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    public function update(string $userId, string $budgetId, array $changes): array
    {
        FinancialInput::assertAllowedKeys($changes, ['category', 'periodStart', 'periodEnd', 'limit', 'currency', 'color']);
        if ($changes === []) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Debés enviar al menos un cambio.');
        }
        return TransactionRunner::run($this->pdo, function (PDO $pdo) use ($userId, $budgetId, $changes): array {
            $current = $this->getRaw($userId, $budgetId, true);
            $candidate = self::validate([
                'category' => $changes['category'] ?? $current['category'],
                'periodStart' => $changes['periodStart'] ?? $current['period_start'],
                'periodEnd' => $changes['periodEnd'] ?? $current['period_end'],
                'limit' => $changes['limit'] ?? Money::formatDatabase((string) $current['limit_hnl']),
                'currency' => $changes['currency'] ?? Money::CURRENCY,
                'color' => array_key_exists('color', $changes) ? $changes['color'] : $current['color'],
            ]);

            try {
                $statement = $pdo->prepare(
                    'UPDATE budgets
                     SET category = :category, period_start = :period_start, period_end = :period_end,
                         limit_hnl = :limit_hnl, color = :color, updated_at = :updated_at
                     WHERE id = :id AND user_id = :user_id'
                );
                $statement->execute([
                    ':category' => $candidate['category'],
                    ':period_start' => $candidate['periodStart'],
                    ':period_end' => $candidate['periodEnd'],
                    ':limit_hnl' => $candidate['limit'],
                    ':color' => $candidate['color'],
                    ':updated_at' => Dates::database((new SystemClock())->now()),
                    ':id' => $budgetId,
                    ':user_id' => $userId,
                ]);
            } catch (PDOException $exception) {
                if ((string) $exception->getCode() === '23000') {
                    throw new ApiException(409, 'CONFLICT', 'Ya existe un presupuesto para esa categoría y período.');
                }
                throw $exception;
            }

            return $this->get($userId, $budgetId);
        });
    }

    public function delete(string $userId, string $budgetId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM budgets WHERE id = :id AND user_id = :user_id');
        $statement->execute([':id' => $budgetId, ':user_id' => $userId]);
        if ($statement->rowCount() !== 1) {
            throw new ApiException(404, 'NOT_FOUND', 'El presupuesto no existe.');
        }
    }

    /** @return array<string, mixed> */
    private function get(string $userId, string $budgetId): array
    {
        $row = $this->getRaw($userId, $budgetId);
        $used = $this->used($userId, (string) $row['category'], (string) $row['period_start'], (string) $row['period_end']);
        return $this->map([...$row, 'used_hnl' => $used]);
    }

    /** @return array<string, mixed> */
    private function getRaw(string $userId, string $budgetId, bool $lock = false): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM budgets WHERE id = :id AND user_id = :user_id LIMIT 1' . ($lock ? $this->forUpdate() : '')
        );
        $statement->execute([':id' => $budgetId, ':user_id' => $userId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'NOT_FOUND', 'El presupuesto no existe.');
        }
        return $row;
    }

    private function used(string $userId, string $category, string $start, string $end): string
    {
        $statement = $this->pdo->prepare(
            'SELECT CAST(COALESCE(SUM(amount_hnl), 0.00) AS DECIMAL(15,2))
             FROM financial_transactions
             WHERE user_id = :user_id
               AND category = :category
               AND transaction_type = :type
               AND status = :status
               AND deleted_at IS NULL
               AND occurred_on BETWEEN :start AND :end'
        );
        $statement->execute([
            ':user_id' => $userId,
            ':category' => $category,
            ':type' => 'expense',
            ':status' => 'confirmed',
            ':start' => $start,
            ':end' => $end,
        ]);

        return Money::formatDatabase((string) $statement->fetchColumn(), true);
    }

    /** @param array<string, mixed> $data @return array{category: string, periodStart: string, periodEnd: string, limit: string, color: ?string} */
    private static function validate(array $data): array
    {
        FinancialInput::assertAllowedKeys($data, ['category', 'periodStart', 'periodEnd', 'limit', 'currency', 'color']);
        $currency = $data['currency'] ?? Money::CURRENCY;
        if ($currency !== Money::CURRENCY) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'La moneda autoritativa debe ser HNL.', ['field' => 'currency']);
        }

        $start = FinancialInput::date($data['periodStart'] ?? null, 'periodStart');
        $end = FinancialInput::date($data['periodEnd'] ?? null, 'periodEnd');
        if ($end < $start) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'La fecha final no puede ser anterior a la inicial.', [
                'field' => 'periodEnd',
            ]);
        }

        return [
            'category' => FinancialInput::string($data['category'] ?? null, 'category', 1, 100),
            'periodStart' => $start,
            'periodEnd' => $end,
            'limit' => FinancialInput::hnl($data['limit'] ?? null, 'limit'),
            'color' => FinancialInput::nullableString($data['color'] ?? null, 'color', 32),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function map(array $row): array
    {
        $limit = Money::formatDatabase((string) $row['limit_hnl']);
        $used = Money::formatDatabase((string) $row['used_hnl'], true);
        return [
            'id' => (string) $row['id'],
            'category' => (string) $row['category'],
            'periodStart' => (string) $row['period_start'],
            'periodEnd' => (string) $row['period_end'],
            'limit' => $limit,
            'used' => $used,
            'remaining' => Money::subtract($limit, $used),
            'currency' => Money::CURRENCY,
            'color' => $row['color'] === null ? null : (string) $row['color'],
            'createdAt' => Dates::iso((string) $row['created_at']),
            'updatedAt' => Dates::iso((string) $row['updated_at']),
        ];
    }

    private function forUpdate(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }
}
