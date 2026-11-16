<?php

declare(strict_types=1);

namespace Rumbo\Backend\Notifications;

use DateTimeImmutable;
use PDO;
use Rumbo\Backend\Financial\FinancialInput;
use Rumbo\Backend\Http\ApiException;
use Rumbo\Backend\Support\Dates;
use Rumbo\Backend\Support\SystemClock;
use Rumbo\Backend\Support\Uuid;

final class NotificationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed> */
    public function preferences(string $userId): array
    {
        $this->ensurePreferences($userId);
        $statement = $this->pdo->prepare(
            'SELECT * FROM notification_preferences WHERE user_id = :user_id LIMIT 1'
        );
        $statement->execute([':user_id' => $userId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new \RuntimeException('No se pudieron inicializar las preferencias de notificación.');
        }

        return $this->mapPreferences($row);
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    public function updatePreferences(string $userId, array $changes): array
    {
        if ($changes === []) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Debés enviar al menos un cambio.');
        }
        FinancialInput::assertAllowedKeys($changes, [
            'morningEnabled', 'eveningEnabled', 'paymentsEnabled', 'closingEnabled', 'dueEnabled',
            'savingsEnabled', 'budgetsEnabled', 'goalsEnabled', 'spendingEnabled', 'morningTime',
            'eveningTime', 'timezone',
        ]);
        $current = $this->preferences($userId);
        $mapped = [
            'morningEnabled' => array_key_exists('morningEnabled', $changes)
                ? FinancialInput::boolean($changes['morningEnabled'], 'morningEnabled') : $current['morningEnabled'],
            'eveningEnabled' => array_key_exists('eveningEnabled', $changes)
                ? FinancialInput::boolean($changes['eveningEnabled'], 'eveningEnabled') : $current['eveningEnabled'],
            'paymentsEnabled' => array_key_exists('paymentsEnabled', $changes)
                ? FinancialInput::boolean($changes['paymentsEnabled'], 'paymentsEnabled') : $current['paymentsEnabled'],
            'closingEnabled' => array_key_exists('closingEnabled', $changes)
                ? FinancialInput::boolean($changes['closingEnabled'], 'closingEnabled') : $current['closingEnabled'],
            'dueEnabled' => array_key_exists('dueEnabled', $changes)
                ? FinancialInput::boolean($changes['dueEnabled'], 'dueEnabled') : $current['dueEnabled'],
            'savingsEnabled' => array_key_exists('savingsEnabled', $changes)
                ? FinancialInput::boolean($changes['savingsEnabled'], 'savingsEnabled') : $current['savingsEnabled'],
            'budgetsEnabled' => array_key_exists('budgetsEnabled', $changes)
                ? FinancialInput::boolean($changes['budgetsEnabled'], 'budgetsEnabled') : $current['budgetsEnabled'],
            'goalsEnabled' => array_key_exists('goalsEnabled', $changes)
                ? FinancialInput::boolean($changes['goalsEnabled'], 'goalsEnabled') : $current['goalsEnabled'],
            'spendingEnabled' => array_key_exists('spendingEnabled', $changes)
                ? FinancialInput::boolean($changes['spendingEnabled'], 'spendingEnabled') : $current['spendingEnabled'],
            'morningTime' => array_key_exists('morningTime', $changes)
                ? FinancialInput::time($changes['morningTime'], 'morningTime') : $current['morningTime'] . ':00',
            'eveningTime' => array_key_exists('eveningTime', $changes)
                ? FinancialInput::time($changes['eveningTime'], 'eveningTime') : $current['eveningTime'] . ':00',
            'timezone' => array_key_exists('timezone', $changes)
                ? $this->timezone($changes['timezone']) : $current['timezone'],
        ];
        $statement = $this->pdo->prepare(
            'UPDATE notification_preferences
             SET morning_enabled = :morning_enabled, evening_enabled = :evening_enabled,
                 payments_enabled = :payments_enabled, closing_enabled = :closing_enabled,
                 due_enabled = :due_enabled, savings_enabled = :savings_enabled,
                 budgets_enabled = :budgets_enabled, goals_enabled = :goals_enabled,
                 spending_enabled = :spending_enabled, morning_time = :morning_time,
                 evening_time = :evening_time, timezone = :timezone, updated_at = :updated_at
             WHERE user_id = :user_id'
        );
        $statement->execute([
            ':morning_enabled' => $mapped['morningEnabled'] ? 1 : 0,
            ':evening_enabled' => $mapped['eveningEnabled'] ? 1 : 0,
            ':payments_enabled' => $mapped['paymentsEnabled'] ? 1 : 0,
            ':closing_enabled' => $mapped['closingEnabled'] ? 1 : 0,
            ':due_enabled' => $mapped['dueEnabled'] ? 1 : 0,
            ':savings_enabled' => $mapped['savingsEnabled'] ? 1 : 0,
            ':budgets_enabled' => $mapped['budgetsEnabled'] ? 1 : 0,
            ':goals_enabled' => $mapped['goalsEnabled'] ? 1 : 0,
            ':spending_enabled' => $mapped['spendingEnabled'] ? 1 : 0,
            ':morning_time' => $mapped['morningTime'],
            ':evening_time' => $mapped['eveningTime'],
            ':timezone' => $mapped['timezone'],
            ':updated_at' => Dates::database((new SystemClock())->now()),
            ':user_id' => $userId,
        ]);

        return $this->preferences($userId);
    }

    /** @return array{items: list<array<string, mixed>>, unreadCount: int} */
    public function list(string $userId, bool $unreadOnly, int $limit): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'El límite debe estar entre 1 y 100.', ['field' => 'limit']);
        }
        $sql = 'SELECT * FROM notifications WHERE user_id = :user_id';
        if ($unreadOnly) {
            $sql .= ' AND read_at IS NULL';
        }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit;
        $statement = $this->pdo->prepare($sql);
        $statement->execute([':user_id' => $userId]);
        $items = array_map(fn (array $row): array => $this->mapNotification($row), $statement->fetchAll());

        $unread = $this->pdo->prepare(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND read_at IS NULL'
        );
        $unread->execute([':user_id' => $userId]);
        return ['items' => $items, 'unreadCount' => (int) $unread->fetchColumn()];
    }

    /** @return array<string, mixed> */
    public function markRead(string $userId, string $notificationId, bool $read): array
    {
        $record = $this->pdo->prepare('SELECT * FROM notifications WHERE id = :id AND user_id = :user_id LIMIT 1');
        $record->execute([':id' => $notificationId, ':user_id' => $userId]);
        $existing = $record->fetch();
        if (!is_array($existing)) {
            throw new ApiException(404, 'NOT_FOUND', 'La notificación no existe.');
        }
        $statement = $this->pdo->prepare(
            'UPDATE notifications SET read_at = :read_at WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute([
            ':read_at' => $read ? Dates::database((new SystemClock())->now()) : null,
            ':id' => $notificationId,
            ':user_id' => $userId,
        ]);
        $row = $this->pdo->prepare('SELECT * FROM notifications WHERE id = :id AND user_id = :user_id LIMIT 1');
        $row->execute([':id' => $notificationId, ':user_id' => $userId]);
        $recorded = $row->fetch();
        if (!is_array($recorded)) {
            throw new ApiException(404, 'NOT_FOUND', 'La notificación no existe.');
        }
        return $this->mapNotification($recorded);
    }

    public function record(
        string $userId,
        string $kind,
        string $title,
        string $body,
        string $tone,
        string $dedupeKey,
        ?DateTimeImmutable $now = null,
    ): bool {
        if (!in_array($kind, ['morning', 'evening', 'payments', 'closing', 'due', 'savings', 'budgets', 'goals', 'spending'], true)
            || !in_array($tone, ['info', 'warning', 'success'], true)) {
            throw new \InvalidArgumentException('La notificación interna no tiene un tipo válido.');
        }
        $now ??= (new SystemClock())->now();
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'sqlite'
            ? 'INSERT OR IGNORE INTO notifications
                (id, user_id, kind, title, body, tone, dedupe_key, created_at, read_at, dismissed_at)
               VALUES (:id, :user_id, :kind, :title, :body, :tone, :dedupe_key, :created_at, NULL, NULL)'
            : 'INSERT IGNORE INTO notifications
                (id, user_id, kind, title, body, tone, dedupe_key, created_at, read_at, dismissed_at)
               VALUES (:id, :user_id, :kind, :title, :body, :tone, :dedupe_key, :created_at, NULL, NULL)';
        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            ':id' => Uuid::v4(),
            ':user_id' => $userId,
            ':kind' => $kind,
            ':title' => $title,
            ':body' => $body,
            ':tone' => $tone,
            ':dedupe_key' => $dedupeKey,
            ':created_at' => Dates::database($now),
        ]);
        return $statement->rowCount() === 1;
    }

    private function ensurePreferences(string $userId): void
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'sqlite'
            ? 'INSERT OR IGNORE INTO notification_preferences (user_id) VALUES (:user_id)'
            : 'INSERT IGNORE INTO notification_preferences (user_id) VALUES (:user_id)';
        $statement = $this->pdo->prepare($sql);
        $statement->execute([':user_id' => $userId]);
    }

    private function timezone(mixed $value): string
    {
        $timezone = FinancialInput::string($value, 'timezone', 1, 64);
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'La zona horaria no es válida.', ['field' => 'timezone']);
        }
        return $timezone;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function mapPreferences(array $row): array
    {
        return [
            'morningEnabled' => (bool) $row['morning_enabled'],
            'eveningEnabled' => (bool) $row['evening_enabled'],
            'paymentsEnabled' => (bool) $row['payments_enabled'],
            'closingEnabled' => (bool) $row['closing_enabled'],
            'dueEnabled' => (bool) $row['due_enabled'],
            'savingsEnabled' => (bool) $row['savings_enabled'],
            'budgetsEnabled' => (bool) $row['budgets_enabled'],
            'goalsEnabled' => (bool) $row['goals_enabled'],
            'spendingEnabled' => (bool) $row['spending_enabled'],
            'morningTime' => substr((string) $row['morning_time'], 0, 5),
            'eveningTime' => substr((string) $row['evening_time'], 0, 5),
            'timezone' => (string) $row['timezone'],
            'updatedAt' => Dates::iso((string) $row['updated_at']),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function mapNotification(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'kind' => (string) $row['kind'],
            'title' => (string) $row['title'],
            'body' => (string) $row['body'],
            'tone' => (string) $row['tone'],
            'readAt' => Dates::iso($row['read_at'] === null ? null : (string) $row['read_at']),
            'createdAt' => Dates::iso((string) $row['created_at']),
        ];
    }
}
