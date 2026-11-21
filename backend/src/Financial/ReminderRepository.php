<?php

declare(strict_types=1);

namespace Rumbo\Backend\Financial;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Rumbo\Backend\Http\ApiException;
use Rumbo\Backend\Support\Dates;
use Rumbo\Backend\Support\SystemClock;
use Rumbo\Backend\Support\Uuid;
use Rumbo\Backend\TransactionRunner;

final class ReminderRepository
{
    private readonly DateTimeZone $utc;

    public function __construct(private readonly PDO $pdo)
    {
        $this->utc = new DateTimeZone('UTC');
    }

    /** @return list<array<string, mixed>> */
    public function list(string $userId, bool $includeArchived = false): array
    {
        $sql = 'SELECT * FROM reminders WHERE user_id = :user_id';
        if (!$includeArchived) {
            $sql .= ' AND archived_at IS NULL';
        }
        $sql .= ' ORDER BY CASE status WHEN \'overdue\' THEN 0 WHEN \'today\' THEN 1 WHEN \'upcoming\' THEN 2 ELSE 3 END,
                             due_date ASC, id ASC';
        $statement = $this->pdo->prepare($sql);
        $statement->execute([':user_id' => $userId]);
        return array_map(fn (array $row): array => $this->mapReminder($row), $statement->fetchAll());
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function create(string $userId, array $data): array
    {
        $reminder = self::validate($data);
        $id = Uuid::v4();
        $now = (new SystemClock())->now();
        $this->insert($this->pdo, $id, $userId, null, $reminder, self::dueStatus($reminder['dueDate'], $now), $now);
        return $this->get($userId, $id);
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    public function update(string $userId, string $reminderId, array $changes): array
    {
        FinancialInput::assertAllowedKeys($changes, [
            'name', 'category', 'expectedAmount', 'currency', 'dueDate', 'firstPaymentDate', 'recurrence',
            'twiceMonthlyDayOne', 'twiceMonthlyDayTwo', 'customIntervalDays', 'reminderDaysBefore', 'notes',
            'isCreditCard', 'cardNickname', 'maskedIdentifier', 'bankName', 'closingDate', 'statementDate',
            'minimumPayment',
        ]);
        if ($changes === []) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Debés enviar al menos un cambio.');
        }

        return TransactionRunner::run($this->pdo, function (PDO $pdo) use ($userId, $reminderId, $changes): array {
            $current = $this->getRaw($userId, $reminderId, true);
            if (in_array((string) $current['status'], ['paid', 'archived'], true) || $current['archived_at'] !== null) {
                throw new ApiException(409, 'CONFLICT', 'El recordatorio no puede modificarse en su estado actual.');
            }
            $reminder = self::validate([
                'name' => $changes['name'] ?? $current['name'],
                'category' => $changes['category'] ?? $current['category'],
                'expectedAmount' => $changes['expectedAmount'] ?? Money::formatDatabase((string) $current['expected_amount_hnl']),
                'currency' => $changes['currency'] ?? Money::CURRENCY,
                'dueDate' => $changes['dueDate'] ?? $current['due_date'],
                'firstPaymentDate' => array_key_exists('firstPaymentDate', $changes)
                    ? $changes['firstPaymentDate'] : $current['first_payment_date'],
                'recurrence' => $changes['recurrence'] ?? $current['recurrence'],
                'twiceMonthlyDayOne' => array_key_exists('twiceMonthlyDayOne', $changes)
                    ? $changes['twiceMonthlyDayOne']
                    : ($current['twice_monthly_day_one'] === null ? null : (int) $current['twice_monthly_day_one']),
                'twiceMonthlyDayTwo' => array_key_exists('twiceMonthlyDayTwo', $changes)
                    ? $changes['twiceMonthlyDayTwo']
                    : ($current['twice_monthly_day_two'] === null ? null : (int) $current['twice_monthly_day_two']),
                'customIntervalDays' => array_key_exists('customIntervalDays', $changes)
                    ? $changes['customIntervalDays']
                    : ($current['custom_interval_days'] === null ? null : (int) $current['custom_interval_days']),
                'reminderDaysBefore' => $changes['reminderDaysBefore'] ?? (int) $current['reminder_days_before'],
                'notes' => array_key_exists('notes', $changes) ? $changes['notes'] : $current['notes'],
                'isCreditCard' => array_key_exists('isCreditCard', $changes)
                    ? $changes['isCreditCard'] : (bool) $current['is_credit_card'],
                'cardNickname' => array_key_exists('cardNickname', $changes) ? $changes['cardNickname'] : $current['card_nickname'],
                'maskedIdentifier' => array_key_exists('maskedIdentifier', $changes)
                    ? $changes['maskedIdentifier'] : $current['masked_identifier'],
                'bankName' => array_key_exists('bankName', $changes) ? $changes['bankName'] : $current['bank_name'],
                'closingDate' => array_key_exists('closingDate', $changes) ? $changes['closingDate'] : $current['closing_date'],
                'statementDate' => array_key_exists('statementDate', $changes) ? $changes['statementDate'] : $current['statement_date'],
                'minimumPayment' => array_key_exists('minimumPayment', $changes)
                    ? $changes['minimumPayment']
                    : ($current['minimum_payment_hnl'] === null ? null : Money::formatDatabase((string) $current['minimum_payment_hnl'], true)),
            ]);
            $now = (new SystemClock())->now();
            $statement = $pdo->prepare(
                'UPDATE reminders
                 SET name = :name, category = :category, expected_amount_hnl = :expected_amount_hnl,
                     due_date = :due_date, first_payment_date = :first_payment_date, recurrence = :recurrence,
                     twice_monthly_day_one = :day_one, twice_monthly_day_two = :day_two,
                     custom_interval_days = :custom_interval_days, reminder_days_before = :reminder_days_before,
                     status = :status, notes = :notes, is_credit_card = :is_credit_card,
                     card_nickname = :card_nickname, masked_identifier = :masked_identifier, bank_name = :bank_name,
                     closing_date = :closing_date, statement_date = :statement_date,
                     minimum_payment_hnl = :minimum_payment_hnl, updated_at = :updated_at
                 WHERE id = :id AND user_id = :user_id AND archived_at IS NULL'
            );
            $this->bindReminder($statement, $reminder, self::dueStatus($reminder['dueDate'], $now), $now, false);
            $statement->bindValue(':id', $reminderId);
            $statement->bindValue(':user_id', $userId);
            $statement->execute();
            return $this->get($userId, $reminderId);
        });
    }

    public function archive(string $userId, string $reminderId): void
    {
        $now = Dates::database((new SystemClock())->now());
        $statement = $this->pdo->prepare(
            'UPDATE reminders
             SET status = :status, archived_at = COALESCE(archived_at, :archived_at), updated_at = :updated_at
             WHERE id = :id AND user_id = :user_id AND archived_at IS NULL'
        );
        $statement->execute([
            ':status' => 'archived',
            ':archived_at' => $now,
            ':updated_at' => $now,
            ':id' => $reminderId,
            ':user_id' => $userId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new ApiException(404, 'NOT_FOUND', 'El recordatorio no existe o ya está archivado.');
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array{payment: array<string, mixed>, reminder: array<string, mixed>, nextReminder: ?array<string, mixed>, replayed: bool}
     */
    public function confirmPayment(string $userId, string $reminderId, array $data, string $idempotencyKey): array
    {
        return TransactionRunner::run($this->pdo, function (PDO $pdo) use ($userId, $reminderId, $data, $idempotencyKey): array {
            $reminder = $this->getRaw($userId, $reminderId, true);
            $payment = self::validatePayment($data, Money::formatDatabase((string) $reminder['expected_amount_hnl']));
            $canonical = ['reminderId' => $reminderId, ...$payment];
            ksort($canonical);
            $requestHash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), true);
            $existing = $this->findIdempotency($userId, 'reminders.payment', $idempotencyKey, true);
            if ($existing !== null) {
                if (!hash_equals((string) $existing['request_hash'], $requestHash)) {
                    throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', 'La clave de idempotencia ya se usó con otra solicitud.');
                }
                if ((string) $existing['state'] === 'completed' && is_string($existing['resource_id'])) {
                    $replay = $this->getPayment($userId, $reminderId, (string) $existing['resource_id']);
                    return [
                        'payment' => $replay,
                        'reminder' => $this->get($userId, $reminderId),
                        'nextReminder' => null,
                        'replayed' => true,
                    ];
                }
                throw new ApiException(409, 'CONFLICT', 'La operación con esa clave aún no está disponible.');
            }
            if ($reminder['archived_at'] !== null || in_array((string) $reminder['status'], ['paid', 'archived'], true)) {
                throw new ApiException(409, 'CONFLICT', 'El recordatorio no acepta un nuevo pago.');
            }

            $now = (new SystemClock())->now();
            $this->beginIdempotency($pdo, $userId, 'reminders.payment', $idempotencyKey, $requestHash, $now);
            $transactionId = Uuid::v4();
            $movement = $pdo->prepare(
                'INSERT INTO financial_transactions
                    (id, user_id, transaction_type, name, category, amount_hnl, currency, occurred_on,
                     occurred_at, status, source_type, recurrence, is_quick_expense, notes,
                     created_at, updated_at, deleted_at)
                 VALUES
                    (:id, :user_id, :transaction_type, :name, :category, :amount_hnl, :currency, :occurred_on,
                     :occurred_at, :status, :source_type, :recurrence, :is_quick_expense, :notes,
                     :created_at, :updated_at, NULL)'
            );
            $movement->execute([
                ':id' => $transactionId,
                ':user_id' => $userId,
                ':transaction_type' => 'expense',
                ':name' => 'Pago: ' . (string) $reminder['name'],
                ':category' => (string) $reminder['category'],
                ':amount_hnl' => $payment['amount'],
                ':currency' => Money::CURRENCY,
                ':occurred_on' => $payment['paidOn'],
                ':occurred_at' => Dates::database($now),
                ':status' => 'confirmed',
                ':source_type' => 'reminder-payment',
                ':recurrence' => 'one-time',
                ':is_quick_expense' => 0,
                ':notes' => $payment['notes'],
                ':created_at' => Dates::database($now),
                ':updated_at' => Dates::database($now),
            ]);

            $paymentId = Uuid::v4();
            $insertPayment = $pdo->prepare(
                'INSERT INTO reminder_payments
                    (id, reminder_id, user_id, transaction_id, amount_hnl, currency, status, idempotency_key,
                     paid_at, reversed_at, created_at, updated_at)
                 VALUES
                    (:id, :reminder_id, :user_id, :transaction_id, :amount_hnl, :currency, :status, :idempotency_key,
                     :paid_at, NULL, :created_at, :updated_at)'
            );
            $insertPayment->execute([
                ':id' => $paymentId,
                ':reminder_id' => $reminderId,
                ':user_id' => $userId,
                ':transaction_id' => $transactionId,
                ':amount_hnl' => $payment['amount'],
                ':currency' => Money::CURRENCY,
                ':status' => 'confirmed',
                ':idempotency_key' => $idempotencyKey,
                ':paid_at' => Dates::database($now),
                ':created_at' => Dates::database($now),
                ':updated_at' => Dates::database($now),
            ]);

            $paid = $pdo->prepare(
                'UPDATE reminders
                 SET status = :status, completed_at = :completed_at, updated_at = :updated_at
                 WHERE id = :id AND user_id = :user_id AND archived_at IS NULL'
            );
            $paid->execute([
                ':status' => 'paid',
                ':completed_at' => Dates::database($now),
                ':updated_at' => Dates::database($now),
                ':id' => $reminderId,
                ':user_id' => $userId,
            ]);

            $next = null;
            if ((string) $reminder['recurrence'] !== 'one-time') {
                $nextId = Uuid::v4();
                $nextInput = $this->rowToInput($reminder);
                $nextInput['dueDate'] = self::nextDueDate($reminder)->format('Y-m-d');
                $nextInput['firstPaymentDate'] = $nextInput['dueDate'];
                $nextInput['notes'] = $reminder['notes'];
                $nextData = self::validate($nextInput);
                $this->insert($pdo, $nextId, $userId, $reminderId, $nextData, self::dueStatus($nextData['dueDate'], $now), $now);
                $next = $this->get($userId, $nextId);
            }

            $this->completeIdempotency(
                $pdo,
                $userId,
                'reminders.payment',
                $idempotencyKey,
                'reminder-payment',
                $paymentId,
                201,
                $now,
            );
            return [
                'payment' => $this->getPayment($userId, $reminderId, $paymentId),
                'reminder' => $this->get($userId, $reminderId),
                'nextReminder' => $next,
                'replayed' => false,
            ];
        });
    }

    /** @return list<array<string, mixed>> */
    public function payments(string $userId, string $reminderId): array
    {
        $this->getRaw($userId, $reminderId);
        $statement = $this->pdo->prepare(
            'SELECT * FROM reminder_payments
             WHERE user_id = :user_id AND reminder_id = :reminder_id
             ORDER BY paid_at DESC, id DESC'
        );
        $statement->execute([':user_id' => $userId, ':reminder_id' => $reminderId]);
        return array_map(fn (array $row): array => $this->mapPayment($row), $statement->fetchAll());
    }

    /** @return array<string, mixed> */
    private function get(string $userId, string $reminderId): array
    {
        return $this->mapReminder($this->getRaw($userId, $reminderId));
    }

    /** @return array<string, mixed> */
    private function getRaw(string $userId, string $reminderId, bool $lock = false): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM reminders WHERE id = :id AND user_id = :user_id LIMIT 1' . ($lock ? $this->forUpdate() : '')
        );
        $statement->execute([':id' => $reminderId, ':user_id' => $userId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'NOT_FOUND', 'El recordatorio no existe.');
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private function getPayment(string $userId, string $reminderId, string $paymentId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM reminder_payments
             WHERE id = :id AND reminder_id = :reminder_id AND user_id = :user_id LIMIT 1'
        );
        $statement->execute([':id' => $paymentId, ':reminder_id' => $reminderId, ':user_id' => $userId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'NOT_FOUND', 'El pago no existe.');
        }
        return $this->mapPayment($row);
    }

    /** @param array<string, mixed> $reminder */
    private function insert(
        PDO $pdo,
        string $id,
        string $userId,
        ?string $parentReminderId,
        array $reminder,
        string $status,
        DateTimeImmutable $now,
    ): void {
        $statement = $pdo->prepare(
            'INSERT INTO reminders
                (id, user_id, parent_reminder_id, name, category, expected_amount_hnl, currency, due_date,
                 first_payment_date, recurrence, twice_monthly_day_one, twice_monthly_day_two, custom_interval_days,
                 reminder_days_before, status, notes, is_credit_card, card_nickname, masked_identifier, bank_name,
                 closing_date, statement_date, minimum_payment_hnl, created_at, updated_at, completed_at, archived_at)
             VALUES
                (:id, :user_id, :parent_reminder_id, :name, :category, :expected_amount_hnl, :currency, :due_date,
                 :first_payment_date, :recurrence, :day_one, :day_two, :custom_interval_days,
                 :reminder_days_before, :status, :notes, :is_credit_card, :card_nickname, :masked_identifier, :bank_name,
                 :closing_date, :statement_date, :minimum_payment_hnl, :created_at, :updated_at, NULL, NULL)'
        );
        $statement->bindValue(':id', $id);
        $statement->bindValue(':user_id', $userId);
        $statement->bindValue(':parent_reminder_id', $parentReminderId, $parentReminderId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $this->bindReminder($statement, $reminder, $status, $now);
        $statement->execute();
    }

    /** @param array<string, mixed> $reminder */
    private function bindReminder(
        \PDOStatement $statement,
        array $reminder,
        string $status,
        DateTimeImmutable $now,
        bool $includeCreatedAt = true,
    ): void
    {
        $statement->bindValue(':name', $reminder['name']);
        $statement->bindValue(':category', $reminder['category']);
        $statement->bindValue(':expected_amount_hnl', $reminder['expectedAmount']);
        $statement->bindValue(':currency', Money::CURRENCY);
        $statement->bindValue(':due_date', $reminder['dueDate']);
        $statement->bindValue(':first_payment_date', $reminder['firstPaymentDate'], $reminder['firstPaymentDate'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':recurrence', $reminder['recurrence']);
        $statement->bindValue(':day_one', $reminder['twiceMonthlyDayOne'], $reminder['twiceMonthlyDayOne'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':day_two', $reminder['twiceMonthlyDayTwo'], $reminder['twiceMonthlyDayTwo'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':custom_interval_days', $reminder['customIntervalDays'], $reminder['customIntervalDays'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':reminder_days_before', $reminder['reminderDaysBefore'], PDO::PARAM_INT);
        $statement->bindValue(':status', $status);
        $statement->bindValue(':notes', $reminder['notes'], $reminder['notes'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':is_credit_card', $reminder['isCreditCard'] ? 1 : 0, PDO::PARAM_INT);
        $statement->bindValue(':card_nickname', $reminder['cardNickname'], $reminder['cardNickname'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':masked_identifier', $reminder['maskedIdentifier'], $reminder['maskedIdentifier'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':bank_name', $reminder['bankName'], $reminder['bankName'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':closing_date', $reminder['closingDate'], $reminder['closingDate'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':statement_date', $reminder['statementDate'], $reminder['statementDate'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':minimum_payment_hnl', $reminder['minimumPayment'], $reminder['minimumPayment'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        if ($includeCreatedAt) {
            $statement->bindValue(':created_at', Dates::database($now));
        }
        $statement->bindValue(':updated_at', Dates::database($now));
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
        DateTimeImmutable $now,
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
        DateTimeImmutable $now,
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

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function validate(array $data): array
    {
        FinancialInput::assertAllowedKeys($data, [
            'name', 'category', 'expectedAmount', 'currency', 'dueDate', 'firstPaymentDate', 'recurrence',
            'twiceMonthlyDayOne', 'twiceMonthlyDayTwo', 'customIntervalDays', 'reminderDaysBefore', 'notes',
            'isCreditCard', 'cardNickname', 'maskedIdentifier', 'bankName', 'closingDate', 'statementDate',
            'minimumPayment',
        ]);
        $currency = $data['currency'] ?? Money::CURRENCY;
        if ($currency !== Money::CURRENCY) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'La moneda autoritativa debe ser HNL.', ['field' => 'currency']);
        }
        $recurrence = FinancialInput::enum(
            $data['recurrence'] ?? 'one-time',
            'recurrence',
            ['one-time', 'weekly', 'every-15-days', 'twice-monthly', 'monthly', 'custom'],
        );
        $dayOne = FinancialInput::nullablePositiveInt($data['twiceMonthlyDayOne'] ?? null, 'twiceMonthlyDayOne', 1, 31);
        $dayTwo = FinancialInput::nullablePositiveInt($data['twiceMonthlyDayTwo'] ?? null, 'twiceMonthlyDayTwo', 1, 31);
        $interval = FinancialInput::nullablePositiveInt($data['customIntervalDays'] ?? null, 'customIntervalDays', 1, 365);
        if ($recurrence === 'twice-monthly' && ($dayOne === null || $dayTwo === null || $dayOne === $dayTwo)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'La recurrencia dos veces al mes requiere dos días distintos.', [
                'field' => 'twiceMonthlyDayOne',
            ]);
        }
        if ($recurrence !== 'twice-monthly') {
            $dayOne = null;
            $dayTwo = null;
        }
        if ($recurrence === 'custom' && $interval === null) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'La recurrencia personalizada requiere un intervalo.', [
                'field' => 'customIntervalDays',
            ]);
        }
        if ($recurrence !== 'custom') {
            $interval = null;
        }

        $creditCard = FinancialInput::boolean($data['isCreditCard'] ?? false, 'isCreditCard');
        $minimum = $data['minimumPayment'] ?? null;
        $minimumPayment = $minimum === null ? null : FinancialInput::hnl($minimum, 'minimumPayment', true);
        $expectedAmount = FinancialInput::hnl($data['expectedAmount'] ?? null, 'expectedAmount');
        if ($minimumPayment !== null && Money::compare($minimumPayment, $expectedAmount) > 0) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'El pago mínimo no puede superar el importe esperado.', [
                'field' => 'minimumPayment',
            ]);
        }

        $cardNickname = FinancialInput::nullableString($data['cardNickname'] ?? null, 'cardNickname', 120);
        if ($creditCard && $cardNickname === null) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'La tarjeta requiere un apodo.', ['field' => 'cardNickname']);
        }
        if (!$creditCard) {
            $cardNickname = null;
            $minimumPayment = null;
        }

        return [
            'name' => FinancialInput::string($data['name'] ?? null, 'name', 1, 160),
            'category' => FinancialInput::string($data['category'] ?? null, 'category', 1, 100),
            'expectedAmount' => $expectedAmount,
            'dueDate' => FinancialInput::date($data['dueDate'] ?? null, 'dueDate'),
            'firstPaymentDate' => FinancialInput::nullableDate($data['firstPaymentDate'] ?? null, 'firstPaymentDate'),
            'recurrence' => $recurrence,
            'twiceMonthlyDayOne' => $dayOne,
            'twiceMonthlyDayTwo' => $dayTwo,
            'customIntervalDays' => $interval,
            'reminderDaysBefore' => FinancialInput::nullablePositiveInt(
                $data['reminderDaysBefore'] ?? 3,
                'reminderDaysBefore',
                0,
                365,
            ) ?? 3,
            'notes' => FinancialInput::nullableString($data['notes'] ?? null, 'notes', 1000),
            'isCreditCard' => $creditCard,
            'cardNickname' => $cardNickname,
            'maskedIdentifier' => $creditCard
                ? FinancialInput::nullableString($data['maskedIdentifier'] ?? null, 'maskedIdentifier', 32)
                : null,
            'bankName' => $creditCard
                ? FinancialInput::nullableString($data['bankName'] ?? null, 'bankName', 120)
                : null,
            'closingDate' => $creditCard
                ? FinancialInput::nullableDate($data['closingDate'] ?? null, 'closingDate')
                : null,
            'statementDate' => $creditCard
                ? FinancialInput::nullableDate($data['statementDate'] ?? null, 'statementDate')
                : null,
            'minimumPayment' => $minimumPayment,
        ];
    }

    /** @param array<string, mixed> $data @return array{amount: string, paidOn: string, notes: ?string} */
    private static function validatePayment(array $data, string $expectedAmount): array
    {
        FinancialInput::assertAllowedKeys($data, ['amount', 'currency', 'paidOn', 'notes']);
        $currency = $data['currency'] ?? Money::CURRENCY;
        if ($currency !== Money::CURRENCY) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'La moneda autoritativa debe ser HNL.', ['field' => 'currency']);
        }
        return [
            'amount' => FinancialInput::hnl($data['amount'] ?? $expectedAmount, 'amount'),
            'paidOn' => FinancialInput::date($data['paidOn'] ?? gmdate('Y-m-d'), 'paidOn'),
            'notes' => FinancialInput::nullableString($data['notes'] ?? null, 'notes', 1000),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function mapReminder(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'parentReminderId' => $row['parent_reminder_id'] === null ? null : (string) $row['parent_reminder_id'],
            'name' => (string) $row['name'],
            'category' => (string) $row['category'],
            'expectedAmount' => Money::formatDatabase((string) $row['expected_amount_hnl']),
            'currency' => Money::CURRENCY,
            'dueDate' => (string) $row['due_date'],
            'firstPaymentDate' => $row['first_payment_date'] === null ? null : (string) $row['first_payment_date'],
            'recurrence' => (string) $row['recurrence'],
            'twiceMonthlyDayOne' => $row['twice_monthly_day_one'] === null ? null : (int) $row['twice_monthly_day_one'],
            'twiceMonthlyDayTwo' => $row['twice_monthly_day_two'] === null ? null : (int) $row['twice_monthly_day_two'],
            'customIntervalDays' => $row['custom_interval_days'] === null ? null : (int) $row['custom_interval_days'],
            'reminderDaysBefore' => (int) $row['reminder_days_before'],
            'status' => self::displayStatus($row),
            'notes' => $row['notes'] === null ? null : (string) $row['notes'],
            'creditCard' => [
                'enabled' => (bool) $row['is_credit_card'],
                'nickname' => $row['card_nickname'] === null ? null : (string) $row['card_nickname'],
                'maskedIdentifier' => $row['masked_identifier'] === null ? null : (string) $row['masked_identifier'],
                'bankName' => $row['bank_name'] === null ? null : (string) $row['bank_name'],
                'closingDate' => $row['closing_date'] === null ? null : (string) $row['closing_date'],
                'statementDate' => $row['statement_date'] === null ? null : (string) $row['statement_date'],
                'minimumPayment' => $row['minimum_payment_hnl'] === null
                    ? null : Money::formatDatabase((string) $row['minimum_payment_hnl'], true),
            ],
            'completedAt' => Dates::iso($row['completed_at'] === null ? null : (string) $row['completed_at']),
            'archivedAt' => Dates::iso($row['archived_at'] === null ? null : (string) $row['archived_at']),
            'createdAt' => Dates::iso((string) $row['created_at']),
            'updatedAt' => Dates::iso((string) $row['updated_at']),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function mapPayment(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'reminderId' => (string) $row['reminder_id'],
            'transactionId' => (string) $row['transaction_id'],
            'amount' => Money::formatDatabase((string) $row['amount_hnl']),
            'currency' => Money::CURRENCY,
            'status' => (string) $row['status'],
            'paidAt' => Dates::iso((string) $row['paid_at']),
            'reversedAt' => Dates::iso($row['reversed_at'] === null ? null : (string) $row['reversed_at']),
            'createdAt' => Dates::iso((string) $row['created_at']),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function rowToInput(array $row): array
    {
        return [
            'name' => $row['name'],
            'category' => $row['category'],
            'expectedAmount' => Money::formatDatabase((string) $row['expected_amount_hnl']),
            'currency' => Money::CURRENCY,
            'dueDate' => $row['due_date'],
            'firstPaymentDate' => $row['first_payment_date'],
            'recurrence' => $row['recurrence'],
            'twiceMonthlyDayOne' => $row['twice_monthly_day_one'] === null ? null : (int) $row['twice_monthly_day_one'],
            'twiceMonthlyDayTwo' => $row['twice_monthly_day_two'] === null ? null : (int) $row['twice_monthly_day_two'],
            'customIntervalDays' => $row['custom_interval_days'] === null ? null : (int) $row['custom_interval_days'],
            'reminderDaysBefore' => (int) $row['reminder_days_before'],
            'notes' => $row['notes'],
            'isCreditCard' => (bool) $row['is_credit_card'],
            'cardNickname' => $row['card_nickname'],
            'maskedIdentifier' => $row['masked_identifier'],
            'bankName' => $row['bank_name'],
            'closingDate' => $row['closing_date'],
            'statementDate' => $row['statement_date'],
            'minimumPayment' => $row['minimum_payment_hnl'] === null
                ? null : Money::formatDatabase((string) $row['minimum_payment_hnl'], true),
        ];
    }

    /** @param array<string, mixed> $row */
    private static function nextDueDate(array $row): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        $due = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $row['due_date'], $utc);
        if (!$due instanceof DateTimeImmutable) {
            throw new \RuntimeException('La fecha del recordatorio almacenado no es válida.');
        }
        return match ((string) $row['recurrence']) {
            'weekly' => $due->modify('+7 days'),
            'every-15-days' => $due->modify('+15 days'),
            'monthly' => self::nextMonthly($due),
            'twice-monthly' => self::nextTwiceMonthly($due, (int) $row['twice_monthly_day_one'], (int) $row['twice_monthly_day_two']),
            'custom' => $due->modify('+' . (int) $row['custom_interval_days'] . ' days'),
            default => throw new \RuntimeException('La recurrencia almacenada no es válida.'),
        };
    }

    private static function nextMonthly(DateTimeImmutable $due): DateTimeImmutable
    {
        $nextMonth = $due->modify('first day of next month');
        $day = min((int) $due->format('d'), (int) $nextMonth->format('t'));
        return $nextMonth->setDate((int) $nextMonth->format('Y'), (int) $nextMonth->format('m'), $day);
    }

    private static function nextTwiceMonthly(DateTimeImmutable $due, int $dayOne, int $dayTwo): DateTimeImmutable
    {
        $days = [$dayOne, $dayTwo];
        sort($days, SORT_NUMERIC);
        $year = (int) $due->format('Y');
        $month = (int) $due->format('m');
        foreach ([[$year, $month], self::nextMonthParts($year, $month)] as [$candidateYear, $candidateMonth]) {
            foreach ($days as $day) {
                $candidate = self::dateForMonth($candidateYear, $candidateMonth, $day);
                if ($candidate > $due) {
                    return $candidate;
                }
            }
        }
        throw new \RuntimeException('No se pudo calcular la siguiente fecha recurrente.');
    }

    /** @return array{0: int, 1: int} */
    private static function nextMonthParts(int $year, int $month): array
    {
        return $month === 12 ? [$year + 1, 1] : [$year, $month + 1];
    }

    private static function dateForMonth(int $year, int $month, int $day): DateTimeImmutable
    {
        $base = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), new DateTimeZone('UTC'));
        return $base->setDate($year, $month, min($day, (int) $base->format('t')));
    }

    private static function dueStatus(string $dueDate, DateTimeImmutable $now): string
    {
        $today = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
        return $dueDate < $today ? 'overdue' : ($dueDate === $today ? 'today' : 'upcoming');
    }

    /** @param array<string, mixed> $row */
    private static function displayStatus(array $row): string
    {
        $stored = (string) $row['status'];
        if (!in_array($stored, ['upcoming', 'today', 'overdue'], true)) {
            return $stored;
        }
        return self::dueStatus((string) $row['due_date'], (new SystemClock())->now());
    }

    private function forUpdate(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }
}
