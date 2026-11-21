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

final class GoalRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(string $userId, bool $includeArchived = false): array
    {
        $sql = 'SELECT g.*, CAST(COALESCE(SUM(c.amount_hnl), 0.00) AS DECIMAL(15,2)) AS contributed_hnl
                FROM savings_goals g
                LEFT JOIN goal_contributions c ON c.goal_id = g.id AND c.user_id = g.user_id
                WHERE g.user_id = :user_id';
        if (!$includeArchived) {
            $sql .= ' AND g.archived_at IS NULL';
        }
        $sql .= ' GROUP BY g.id
                  ORDER BY CASE WHEN g.status = \'active\' THEN 0 ELSE 1 END, g.target_date ASC, g.created_at DESC';
        $statement = $this->pdo->prepare($sql);
        $statement->execute([':user_id' => $userId]);

        return array_map(fn (array $row): array => $this->mapGoal($row), $statement->fetchAll());
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function create(string $userId, array $data): array
    {
        $goal = self::validate($data);
        $id = Uuid::v4();
        $now = Dates::database((new SystemClock())->now());
        $statement = $this->pdo->prepare(
            'INSERT INTO savings_goals
                (id, user_id, name, label, target_hnl, currency, target_date, preferred_frequency,
                 twice_monthly_day_one, twice_monthly_day_two, color, status, created_at, updated_at, archived_at)
             VALUES
                (:id, :user_id, :name, :label, :target_hnl, :currency, :target_date, :preferred_frequency,
                 :day_one, :day_two, :color, :status, :created_at, :updated_at, NULL)'
        );
        $statement->execute([
            ':id' => $id,
            ':user_id' => $userId,
            ':name' => $goal['name'],
            ':label' => $goal['label'],
            ':target_hnl' => $goal['target'],
            ':currency' => Money::CURRENCY,
            ':target_date' => $goal['targetDate'],
            ':preferred_frequency' => $goal['preferredFrequency'],
            ':day_one' => $goal['twiceMonthlyDayOne'],
            ':day_two' => $goal['twiceMonthlyDayTwo'],
            ':color' => $goal['color'],
            ':status' => 'active',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return $this->get($userId, $id);
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    public function update(string $userId, string $goalId, array $changes): array
    {
        FinancialInput::assertAllowedKeys($changes, [
            'name', 'label', 'target', 'currency', 'targetDate', 'preferredFrequency',
            'twiceMonthlyDayOne', 'twiceMonthlyDayTwo', 'color',
        ]);
        if ($changes === []) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Debés enviar al menos un cambio.');
        }

        return TransactionRunner::run($this->pdo, function (PDO $pdo) use ($userId, $goalId, $changes): array {
            $current = $this->getRaw($userId, $goalId, true);
            if ($current['archived_at'] !== null) {
                throw new ApiException(409, 'CONFLICT', 'La meta está archivada.');
            }
            $goal = self::validate([
                'name' => $changes['name'] ?? $current['name'],
                'label' => array_key_exists('label', $changes) ? $changes['label'] : $current['label'],
                'target' => $changes['target'] ?? Money::formatDatabase((string) $current['target_hnl']),
                'currency' => $changes['currency'] ?? Money::CURRENCY,
                'targetDate' => array_key_exists('targetDate', $changes) ? $changes['targetDate'] : $current['target_date'],
                'preferredFrequency' => array_key_exists('preferredFrequency', $changes)
                    ? $changes['preferredFrequency'] : $current['preferred_frequency'],
                'twiceMonthlyDayOne' => array_key_exists('twiceMonthlyDayOne', $changes)
                    ? $changes['twiceMonthlyDayOne'] : $current['twice_monthly_day_one'],
                'twiceMonthlyDayTwo' => array_key_exists('twiceMonthlyDayTwo', $changes)
                    ? $changes['twiceMonthlyDayTwo'] : $current['twice_monthly_day_two'],
                'color' => array_key_exists('color', $changes) ? $changes['color'] : $current['color'],
            ]);

            $contributed = $this->contributed($userId, $goalId);
            if (Money::compare($goal['target'], $contributed) < 0) {
                throw new ApiException(422, 'VALIDATION_ERROR', 'La meta no puede ser menor que los aportes ya registrados.', [
                    'field' => 'target',
                ]);
            }
            $status = Money::compare($contributed, $goal['target']) >= 0 ? 'completed' : 'active';

            $statement = $pdo->prepare(
                'UPDATE savings_goals
                 SET name = :name, label = :label, target_hnl = :target_hnl, target_date = :target_date,
                     preferred_frequency = :preferred_frequency, twice_monthly_day_one = :day_one,
                     twice_monthly_day_two = :day_two, color = :color, status = :status, updated_at = :updated_at
                 WHERE id = :id AND user_id = :user_id AND archived_at IS NULL'
            );
            $statement->execute([
                ':name' => $goal['name'],
                ':label' => $goal['label'],
                ':target_hnl' => $goal['target'],
                ':target_date' => $goal['targetDate'],
                ':preferred_frequency' => $goal['preferredFrequency'],
                ':day_one' => $goal['twiceMonthlyDayOne'],
                ':day_two' => $goal['twiceMonthlyDayTwo'],
                ':color' => $goal['color'],
                ':status' => $status,
                ':updated_at' => Dates::database((new SystemClock())->now()),
                ':id' => $goalId,
                ':user_id' => $userId,
            ]);

            return $this->get($userId, $goalId);
        });
    }

    public function archive(string $userId, string $goalId): void
    {
        $now = Dates::database((new SystemClock())->now());
        $statement = $this->pdo->prepare(
            'UPDATE savings_goals
             SET status = :status, archived_at = COALESCE(archived_at, :archived_at), updated_at = :updated_at
             WHERE id = :id AND user_id = :user_id AND archived_at IS NULL'
        );
        $statement->execute([
            ':status' => 'archived',
            ':archived_at' => $now,
            ':updated_at' => $now,
            ':id' => $goalId,
            ':user_id' => $userId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new ApiException(404, 'NOT_FOUND', 'La meta no existe o ya está archivada.');
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array{contribution: array<string, mixed>, goal: array<string, mixed>, replayed: bool}
     */
    public function contribute(string $userId, string $goalId, array $data, string $idempotencyKey): array
    {
        $contribution = self::validateContribution($data);
        $canonical = $contribution;
        ksort($canonical);
        $requestHash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), true);

        return TransactionRunner::run($this->pdo, function (PDO $pdo) use (
            $userId,
            $goalId,
            $contribution,
            $idempotencyKey,
            $requestHash,
        ): array {
            $existing = $this->findIdempotency($userId, 'goals.contribution', $idempotencyKey, true);
            if ($existing !== null) {
                if (!hash_equals((string) $existing['request_hash'], $requestHash)) {
                    throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', 'La clave de idempotencia ya se usó con otra solicitud.');
                }
                if ((string) $existing['state'] === 'completed' && is_string($existing['resource_id'])) {
                    $replay = $this->getContribution($userId, $goalId, (string) $existing['resource_id']);
                    return [
                        'contribution' => $replay,
                        'goal' => $this->get($userId, $goalId),
                        'replayed' => true,
                    ];
                }
                throw new ApiException(409, 'CONFLICT', 'La operación con esa clave aún no está disponible.');
            }

            $goal = $this->getRaw($userId, $goalId, true);
            if ((string) $goal['status'] !== 'active' || $goal['archived_at'] !== null) {
                throw new ApiException(409, 'CONFLICT', 'La meta no acepta nuevos aportes.');
            }
            $contributed = $this->contributed($userId, $goalId);
            $remaining = Money::nonNegative(Money::subtract((string) $goal['target_hnl'], $contributed));
            if (Money::compare($contribution['amount'], $remaining) > 0) {
                throw new ApiException(422, 'VALIDATION_ERROR', 'El aporte supera el monto pendiente de la meta.', [
                    'field' => 'amount',
                ]);
            }

            $now = (new SystemClock())->now();
            $this->beginIdempotency($pdo, $userId, 'goals.contribution', $idempotencyKey, $requestHash, $now);
            $id = Uuid::v4();
            $statement = $pdo->prepare(
                'INSERT INTO goal_contributions
                    (id, goal_id, user_id, amount_hnl, currency, contributed_on, idempotency_key, notes, created_at)
                 VALUES
                    (:id, :goal_id, :user_id, :amount_hnl, :currency, :contributed_on, :idempotency_key, :notes, :created_at)'
            );
            $statement->execute([
                ':id' => $id,
                ':goal_id' => $goalId,
                ':user_id' => $userId,
                ':amount_hnl' => $contribution['amount'],
                ':currency' => Money::CURRENCY,
                ':contributed_on' => $contribution['contributedOn'],
                ':idempotency_key' => $idempotencyKey,
                ':notes' => $contribution['notes'],
                ':created_at' => Dates::database($now),
            ]);

            $newTotal = Money::add($contributed, $contribution['amount']);
            if (Money::compare($newTotal, (string) $goal['target_hnl']) >= 0) {
                $complete = $pdo->prepare(
                    'UPDATE savings_goals SET status = :status, updated_at = :updated_at
                     WHERE id = :id AND user_id = :user_id'
                );
                $complete->execute([
                    ':status' => 'completed',
                    ':updated_at' => Dates::database($now),
                    ':id' => $goalId,
                    ':user_id' => $userId,
                ]);
            }

            $this->completeIdempotency($pdo, $userId, 'goals.contribution', $idempotencyKey, 'goal-contribution', $id, 201, $now);
            return [
                'contribution' => $this->getContribution($userId, $goalId, $id),
                'goal' => $this->get($userId, $goalId),
                'replayed' => false,
            ];
        });
    }

    /** @return list<array<string, mixed>> */
    public function contributions(string $userId, string $goalId): array
    {
        $this->getRaw($userId, $goalId);
        $statement = $this->pdo->prepare(
            'SELECT * FROM goal_contributions
             WHERE user_id = :user_id AND goal_id = :goal_id
             ORDER BY contributed_on DESC, id DESC'
        );
        $statement->execute([':user_id' => $userId, ':goal_id' => $goalId]);
        return array_map(fn (array $row): array => $this->mapContribution($row), $statement->fetchAll());
    }

    /** @return array<string, mixed> */
    private function get(string $userId, string $goalId): array
    {
        $row = $this->getRaw($userId, $goalId);
        return $this->mapGoal([...$row, 'contributed_hnl' => $this->contributed($userId, $goalId)]);
    }

    /** @return array<string, mixed> */
    private function getRaw(string $userId, string $goalId, bool $lock = false): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM savings_goals WHERE id = :id AND user_id = :user_id LIMIT 1' . ($lock ? $this->forUpdate() : '')
        );
        $statement->execute([':id' => $goalId, ':user_id' => $userId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'NOT_FOUND', 'La meta no existe.');
        }
        return $row;
    }

    private function contributed(string $userId, string $goalId): string
    {
        $statement = $this->pdo->prepare(
            'SELECT CAST(COALESCE(SUM(amount_hnl), 0.00) AS DECIMAL(15,2))
             FROM goal_contributions WHERE user_id = :user_id AND goal_id = :goal_id'
        );
        $statement->execute([':user_id' => $userId, ':goal_id' => $goalId]);
        return Money::formatDatabase((string) $statement->fetchColumn(), true);
    }

    /** @return array<string, mixed> */
    private function getContribution(string $userId, string $goalId, string $contributionId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM goal_contributions
             WHERE id = :id AND goal_id = :goal_id AND user_id = :user_id
             LIMIT 1'
        );
        $statement->execute([':id' => $contributionId, ':goal_id' => $goalId, ':user_id' => $userId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'NOT_FOUND', 'El aporte no existe.');
        }
        return $this->mapContribution($row);
    }

    /** @return array<string, mixed>|null */
    private function findIdempotency(string $userId, string $scope, string $key, bool $lock): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT request_hash, state, resource_id FROM idempotency_keys
             WHERE user_id = :user_id AND operation_scope = :scope AND idempotency_key = :idempotency_key
             LIMIT 1' . ($lock ? $this->forUpdate() : '')
        );
        $statement->execute([':user_id' => $userId, ':scope' => $scope, ':idempotency_key' => $key]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    private function beginIdempotency(
        PDO $pdo,
        string $userId,
        string $scope,
        string $key,
        string $requestHash,
        \DateTimeImmutable $now,
    ): void {
        $statement = $pdo->prepare(
            'INSERT INTO idempotency_keys
                (user_id, operation_scope, idempotency_key, request_hash, state, resource_type,
                 resource_id, response_status, created_at, completed_at, expires_at)
             VALUES
                (:user_id, :scope, :idempotency_key, :request_hash, :state, NULL, NULL, NULL,
                 :created_at, NULL, :expires_at)'
        );
        $statement->bindValue(':user_id', $userId);
        $statement->bindValue(':scope', $scope);
        $statement->bindValue(':idempotency_key', $key);
        $statement->bindValue(':request_hash', $requestHash, PDO::PARAM_LOB);
        $statement->bindValue(':state', 'processing');
        $statement->bindValue(':created_at', Dates::database($now));
        $statement->bindValue(':expires_at', Dates::database($now->modify('+24 hours')));
        $statement->execute();
    }

    private function completeIdempotency(
        PDO $pdo,
        string $userId,
        string $scope,
        string $key,
        string $resourceType,
        string $resourceId,
        int $responseStatus,
        \DateTimeImmutable $now,
    ): void {
        $statement = $pdo->prepare(
            'UPDATE idempotency_keys
             SET state = :state, resource_type = :resource_type, resource_id = :resource_id,
                 response_status = :response_status, completed_at = :completed_at
             WHERE user_id = :user_id AND operation_scope = :scope AND idempotency_key = :idempotency_key'
        );
        $statement->execute([
            ':state' => 'completed',
            ':resource_type' => $resourceType,
            ':resource_id' => $resourceId,
            ':response_status' => $responseStatus,
            ':completed_at' => Dates::database($now),
            ':user_id' => $userId,
            ':scope' => $scope,
            ':idempotency_key' => $key,
        ]);
    }

    /** @param array<string, mixed> $data @return array{name: string, label: ?string, target: string, targetDate: ?string, preferredFrequency: ?string, twiceMonthlyDayOne: ?int, twiceMonthlyDayTwo: ?int, color: ?string} */
    private static function validate(array $data): array
    {
        FinancialInput::assertAllowedKeys($data, [
            'name', 'label', 'target', 'currency', 'targetDate', 'preferredFrequency',
            'twiceMonthlyDayOne', 'twiceMonthlyDayTwo', 'color',
        ]);
        $currency = $data['currency'] ?? Money::CURRENCY;
        if ($currency !== Money::CURRENCY) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'La moneda autoritativa debe ser HNL.', ['field' => 'currency']);
        }
        $frequencyValue = $data['preferredFrequency'] ?? null;
        $frequency = $frequencyValue === null ? null : FinancialInput::enum(
            $frequencyValue,
            'preferredFrequency',
            ['daily', 'weekly', 'biweekly', 'twice-monthly', 'monthly'],
        );
        $dayOne = FinancialInput::nullablePositiveInt($data['twiceMonthlyDayOne'] ?? null, 'twiceMonthlyDayOne', 1, 31);
        $dayTwo = FinancialInput::nullablePositiveInt($data['twiceMonthlyDayTwo'] ?? null, 'twiceMonthlyDayTwo', 1, 31);
        if ($frequency === 'twice-monthly' && ($dayOne === null || $dayTwo === null || $dayOne === $dayTwo)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'La frecuencia dos veces al mes requiere dos días distintos.', [
                'field' => 'twiceMonthlyDayOne',
            ]);
        }
        if ($frequency !== 'twice-monthly') {
            $dayOne = null;
            $dayTwo = null;
        }

        return [
            'name' => FinancialInput::string($data['name'] ?? null, 'name', 1, 160),
            'label' => FinancialInput::nullableString($data['label'] ?? null, 'label', 120),
            'target' => FinancialInput::hnl($data['target'] ?? null, 'target'),
            'targetDate' => FinancialInput::nullableDate($data['targetDate'] ?? null, 'targetDate'),
            'preferredFrequency' => $frequency,
            'twiceMonthlyDayOne' => $dayOne,
            'twiceMonthlyDayTwo' => $dayTwo,
            'color' => FinancialInput::nullableString($data['color'] ?? null, 'color', 32),
        ];
    }

    /** @param array<string, mixed> $data @return array{amount: string, contributedOn: string, notes: ?string} */
    private static function validateContribution(array $data): array
    {
        FinancialInput::assertAllowedKeys($data, ['amount', 'currency', 'contributedOn', 'notes']);
        $currency = $data['currency'] ?? Money::CURRENCY;
        if ($currency !== Money::CURRENCY) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'La moneda autoritativa debe ser HNL.', ['field' => 'currency']);
        }
        return [
            'amount' => FinancialInput::hnl($data['amount'] ?? null, 'amount'),
            'contributedOn' => FinancialInput::date($data['contributedOn'] ?? null, 'contributedOn'),
            'notes' => FinancialInput::nullableString($data['notes'] ?? null, 'notes', 500),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function mapGoal(array $row): array
    {
        $target = Money::formatDatabase((string) $row['target_hnl']);
        $contributed = Money::formatDatabase((string) $row['contributed_hnl'], true);
        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'label' => $row['label'] === null ? null : (string) $row['label'],
            'target' => $target,
            'contributed' => $contributed,
            'remaining' => Money::nonNegative(Money::subtract($target, $contributed)),
            'progressPercent' => Money::percentage($contributed, $target),
            'currency' => Money::CURRENCY,
            'targetDate' => $row['target_date'] === null ? null : (string) $row['target_date'],
            'preferredFrequency' => $row['preferred_frequency'] === null ? null : (string) $row['preferred_frequency'],
            'twiceMonthlyDayOne' => $row['twice_monthly_day_one'] === null ? null : (int) $row['twice_monthly_day_one'],
            'twiceMonthlyDayTwo' => $row['twice_monthly_day_two'] === null ? null : (int) $row['twice_monthly_day_two'],
            'color' => $row['color'] === null ? null : (string) $row['color'],
            'status' => (string) $row['status'],
            'archivedAt' => Dates::iso($row['archived_at'] === null ? null : (string) $row['archived_at']),
            'createdAt' => Dates::iso((string) $row['created_at']),
            'updatedAt' => Dates::iso((string) $row['updated_at']),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function mapContribution(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'goalId' => (string) $row['goal_id'],
            'amount' => Money::formatDatabase((string) $row['amount_hnl']),
            'currency' => Money::CURRENCY,
            'contributedOn' => (string) $row['contributed_on'],
            'notes' => $row['notes'] === null ? null : (string) $row['notes'],
            'createdAt' => Dates::iso((string) $row['created_at']),
        ];
    }

    private function forUpdate(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }
}
