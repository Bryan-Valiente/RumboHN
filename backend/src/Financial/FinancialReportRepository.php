<?php

declare(strict_types=1);

namespace Rumbo\Backend\Financial;

use PDO;
use Rumbo\Backend\Http\ApiException;

final class FinancialReportRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{from: ?string, to: ?string, category: ?string} $filters
     * @return array<string, mixed>
     */
    public function summary(string $userId, array $filters): array
    {
        $opening = $this->openingBalance($userId);
        $movement = $this->movementTotals($userId, $filters);
        $savings = $this->savingsTotals($userId, $filters);
        $obligations = $this->obligationTotals($userId, $filters);
        $totalBalance = Money::add($opening, $movement['income'], '-' . $movement['expenses']);
        $availableBalance = Money::subtract($totalBalance, $savings);
        $projectedBalance = Money::subtract($totalBalance, $obligations);

        return [
            'openingBalance' => $opening,
            'confirmedIncome' => $movement['income'],
            'confirmedExpenses' => $movement['expenses'],
            'totalBalance' => $totalBalance,
            'savingsAllocated' => $savings,
            'availableBalance' => $availableBalance,
            'pendingObligations' => $obligations,
            'projectedBalance' => $projectedBalance,
            'currency' => Money::CURRENCY,
            'filters' => $filters,
        ];
    }

    /**
     * @param array{from: ?string, to: ?string, category: ?string} $filters
     * @return array<string, mixed>
     */
    public function daily(string $userId, array $filters): array
    {
        [$where, $parameters] = $this->movementFilter($userId, $filters);
        $statement = $this->pdo->prepare(
            'SELECT occurred_on,
                    CAST(COALESCE(SUM(CASE WHEN transaction_type = \'income\' THEN amount_hnl ELSE 0.00 END), 0.00) AS DECIMAL(15,2)) AS income_hnl,
                    CAST(COALESCE(SUM(CASE WHEN transaction_type = \'expense\' THEN amount_hnl ELSE 0.00 END), 0.00) AS DECIMAL(15,2)) AS expenses_hnl
             FROM financial_transactions
             ' . $where . '
             GROUP BY occurred_on
             ORDER BY occurred_on ASC'
        );
        $statement->execute($parameters);
        $days = [];
        foreach ($statement->fetchAll() as $row) {
            $income = Money::formatDatabase((string) $row['income_hnl'], true);
            $expenses = Money::formatDatabase((string) $row['expenses_hnl'], true);
            $days[] = [
                'date' => (string) $row['occurred_on'],
                'confirmedIncome' => $income,
                'confirmedExpenses' => $expenses,
                'net' => Money::subtract($income, $expenses),
                'currency' => Money::CURRENCY,
            ];
        }

        return [
            'summary' => $this->summary($userId, $filters),
            'days' => $days,
            'filters' => $filters,
        ];
    }

    /** @return array{from: ?string, to: ?string, category: ?string} */
    public static function filters(?string $from, ?string $to, ?string $category): array
    {
        $start = $from === null || $from === '' ? null : FinancialInput::date($from, 'from');
        $end = $to === null || $to === '' ? null : FinancialInput::date($to, 'to');
        if ($start !== null && $end !== null && $end < $start) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'El final del período no puede ser anterior al inicio.', [
                'field' => 'to',
            ]);
        }
        $normalizedCategory = $category === null || trim($category) === ''
            ? null
            : FinancialInput::string($category, 'category', 1, 100);

        return ['from' => $start, 'to' => $end, 'category' => $normalizedCategory];
    }

    private function openingBalance(string $userId): string
    {
        $statement = $this->pdo->prepare(
            'SELECT opening_balance_hnl FROM user_profiles WHERE user_id = :user_id LIMIT 1'
        );
        $statement->execute([':user_id' => $userId]);
        $value = $statement->fetchColumn();
        if ($value === false) {
            throw new ApiException(404, 'NOT_FOUND', 'El perfil financiero no existe.');
        }
        return Money::formatDatabase((string) $value, true);
    }

    /** @param array{from: ?string, to: ?string, category: ?string} $filters @return array{income: string, expenses: string} */
    private function movementTotals(string $userId, array $filters): array
    {
        [$where, $parameters] = $this->movementFilter($userId, $filters);
        $statement = $this->pdo->prepare(
            'SELECT
                CAST(COALESCE(SUM(CASE WHEN transaction_type = \'income\' THEN amount_hnl ELSE 0.00 END), 0.00) AS DECIMAL(15,2)) AS income_hnl,
                CAST(COALESCE(SUM(CASE WHEN transaction_type = \'expense\' THEN amount_hnl ELSE 0.00 END), 0.00) AS DECIMAL(15,2)) AS expenses_hnl
             FROM financial_transactions ' . $where
        );
        $statement->execute($parameters);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return ['income' => '0.00', 'expenses' => '0.00'];
        }
        return [
            'income' => Money::formatDatabase((string) $row['income_hnl'], true),
            'expenses' => Money::formatDatabase((string) $row['expenses_hnl'], true),
        ];
    }

    /** @param array{from: ?string, to: ?string, category: ?string} $filters */
    private function savingsTotals(string $userId, array $filters): string
    {
        $sql = 'SELECT CAST(COALESCE(SUM(amount_hnl), 0.00) AS DECIMAL(15,2))
                FROM goal_contributions WHERE user_id = :user_id';
        $parameters = [':user_id' => $userId];
        if ($filters['from'] !== null) {
            $sql .= ' AND contributed_on >= :from';
            $parameters[':from'] = $filters['from'];
        }
        if ($filters['to'] !== null) {
            $sql .= ' AND contributed_on <= :to';
            $parameters[':to'] = $filters['to'];
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return Money::formatDatabase((string) $statement->fetchColumn(), true);
    }

    /** @param array{from: ?string, to: ?string, category: ?string} $filters */
    private function obligationTotals(string $userId, array $filters): string
    {
        $sql = 'SELECT CAST(COALESCE(SUM(expected_amount_hnl), 0.00) AS DECIMAL(15,2))
                FROM reminders
                WHERE user_id = :user_id
                  AND status IN (\'upcoming\', \'today\', \'overdue\')
                  AND archived_at IS NULL';
        $parameters = [':user_id' => $userId];
        if ($filters['from'] !== null) {
            $sql .= ' AND due_date >= :from';
            $parameters[':from'] = $filters['from'];
        }
        if ($filters['to'] !== null) {
            $sql .= ' AND due_date <= :to';
            $parameters[':to'] = $filters['to'];
        }
        if ($filters['category'] !== null) {
            $sql .= ' AND category = :category';
            $parameters[':category'] = $filters['category'];
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return Money::formatDatabase((string) $statement->fetchColumn(), true);
    }

    /**
     * @param array{from: ?string, to: ?string, category: ?string} $filters
     * @return array{0: string, 1: array<string, string>}
     */
    private function movementFilter(string $userId, array $filters): array
    {
        $where = 'WHERE user_id = :user_id
                    AND status = :status
                    AND deleted_at IS NULL';
        $parameters = [':user_id' => $userId, ':status' => 'confirmed'];
        if ($filters['from'] !== null) {
            $where .= ' AND occurred_on >= :from';
            $parameters[':from'] = $filters['from'];
        }
        if ($filters['to'] !== null) {
            $where .= ' AND occurred_on <= :to';
            $parameters[':to'] = $filters['to'];
        }
        if ($filters['category'] !== null) {
            $where .= ' AND category = :category';
            $parameters[':category'] = $filters['category'];
        }
        return [$where, $parameters];
    }
}
