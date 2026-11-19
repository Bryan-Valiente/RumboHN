<?php

declare(strict_types=1);

namespace Rumbo\Backend\Financial;

use PDO;
use Rumbo\Backend\Http\ApiException;

final class FinancialSummaryRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, string> */
    public function get(string $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT income_hnl, expenses_hnl, balance_hnl, allocated_savings_hnl,
                    available_balance_hnl, pending_obligations_hnl, projected_balance_hnl
             FROM v_user_financial_summary
             WHERE user_id = :user_id
             LIMIT 1'
        );
        $statement->execute([':user_id' => $userId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'NOT_FOUND', 'El resumen financiero no está disponible.');
        }

        return [
            'confirmedIncome' => Money::formatDatabaseSigned((string) $row['income_hnl']),
            'confirmedExpenses' => Money::formatDatabaseSigned((string) $row['expenses_hnl']),
            'totalBalance' => Money::formatDatabaseSigned((string) $row['balance_hnl']),
            'savingsAllocated' => Money::formatDatabaseSigned((string) $row['allocated_savings_hnl']),
            'availableBalance' => Money::formatDatabaseSigned((string) $row['available_balance_hnl']),
            'pendingObligations' => Money::formatDatabaseSigned((string) $row['pending_obligations_hnl']),
            'projectedBalance' => Money::formatDatabaseSigned((string) $row['projected_balance_hnl']),
            'currency' => Money::CURRENCY,
        ];
    }
}
