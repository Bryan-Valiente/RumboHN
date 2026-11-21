<?php

declare(strict_types=1);

namespace Rumbo\Backend\Admin;

use DateTimeImmutable;
use PDO;
use Rumbo\Backend\Audit\AuditLogger;
use Rumbo\Backend\Http\ApiException;
use Rumbo\Backend\Http\Input;
use Rumbo\Backend\Support\Dates;
use Rumbo\Backend\Support\SystemClock;
use Rumbo\Backend\TransactionRunner;

final class AdminRepository
{
    private readonly AuditLogger $audit;

    public function __construct(
        private readonly PDO $pdo,
        private readonly bool $markEmailVerified = true,
    )
    {
        $this->audit = new AuditLogger($pdo);
    }

    /** @return array<string, mixed> */
    public function dashboard(): array
    {
        $counts = $this->pdo->query(
            'SELECT
                COUNT(*) AS total_users,
                SUM(CASE WHEN status = \'active\' THEN 1 ELSE 0 END) AS active_users,
                SUM(CASE WHEN status = \'pending\' THEN 1 ELSE 0 END) AS pending_users,
                SUM(CASE WHEN status IN (\'locked\', \'disabled\') THEN 1 ELSE 0 END) AS restricted_users
             FROM users'
        )->fetch();
        if (!is_array($counts)) {
            throw new \RuntimeException('No se pudieron obtener las métricas administrativas.');
        }
        $now = Dates::database((new SystemClock())->now());
        $trials = $this->pdo->prepare(
            'SELECT
                SUM(CASE WHEN u.status = \'active\' AND p.trial_ends_at >= :now THEN 1 ELSE 0 END) AS active_trials,
                SUM(CASE WHEN p.trial_ends_at < :now THEN 1 ELSE 0 END) AS expired_trials
             FROM user_profiles p INNER JOIN users u ON u.id = p.user_id'
        );
        $trials->execute([':now' => $now]);
        $trialCounts = $trials->fetch();
        if (!is_array($trialCounts)) {
            $trialCounts = ['active_trials' => 0, 'expired_trials' => 0];
        }
        $recent = $this->pdo->query(
            'SELECT u.id, u.email, u.username, u.status, u.created_at, u.last_login_at,
                    p.display_name, p.trial_started_at, p.trial_ends_at
             FROM users u INNER JOIN user_profiles p ON p.user_id = u.id
             ORDER BY u.created_at DESC LIMIT 8'
        )->fetchAll();

        return [
            'metrics' => [
                'totalUsers' => (int) $counts['total_users'],
                'activeUsers' => (int) $counts['active_users'],
                'pendingUsers' => (int) $counts['pending_users'],
                'restrictedUsers' => (int) $counts['restricted_users'],
                'activeTrials' => (int) $trialCounts['active_trials'],
                'expiredTrials' => (int) $trialCounts['expired_trials'],
            ],
            'recentRegistrations' => array_map(fn (array $row): array => $this->mapUser($row), $recent),
            'system' => $this->systemStatus(),
        ];
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function users(?string $search, ?string $status, int $limit, int $offset): array
    {
        if ($limit < 1 || $limit > 100 || $offset < 0) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'La paginación solicitada no es válida.');
        }
        $where = 'WHERE 1 = 1';
        $parameters = [];
        if ($search !== null && trim($search) !== '') {
            $where .= ' AND (u.email LIKE :search OR u.username LIKE :search OR p.display_name LIKE :search)';
            $parameters[':search'] = '%' . trim($search) . '%';
        }
        if ($status !== null && $status !== '') {
            if (!in_array($status, ['pending', 'active', 'locked', 'disabled'], true)) {
                throw new ApiException(422, 'VALIDATION_ERROR', 'El estado de cuenta no es válido.', ['field' => 'status']);
            }
            $where .= ' AND u.status = :status';
            $parameters[':status'] = $status;
        }
        $count = $this->pdo->prepare(
            'SELECT COUNT(*) FROM users u INNER JOIN user_profiles p ON p.user_id = u.id ' . $where
        );
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();

        $sql = 'SELECT u.id, u.email, u.username, u.status, u.email_verified_at, u.last_login_at, u.created_at, u.updated_at,
                       p.display_name, p.timezone, p.locale, p.trial_started_at, p.trial_ends_at
                FROM users u INNER JOIN user_profiles p ON p.user_id = u.id ' . $where . '
                ORDER BY u.created_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return [
            'items' => array_map(fn (array $row): array => $this->mapUser($row), $statement->fetchAll()),
            'total' => $total,
        ];
    }

    /** @return array<string, mixed> */
    public function user(string $userId): array
    {
        $row = $this->findUser($userId);
        return $this->mapUser($row, true);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function manageAccount(string $actorUserId, string $subjectUserId, array $payload, string $requestId): array
    {
        Input::assertAllowedKeys($payload, ['action', 'days']);
        $action = $payload['action'] ?? null;
        if (!is_string($action) || !in_array($action, ['activate', 'suspend', 'restore', 'extend-trial'], true)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'La acción administrativa no está permitida.', ['field' => 'action']);
        }
        if ($actorUserId === $subjectUserId && $action === 'suspend') {
            throw new ApiException(409, 'CONFLICT', 'No podés suspender tu propia cuenta administrativa.');
        }

        return TransactionRunner::run($this->pdo, function (PDO $pdo) use ($actorUserId, $subjectUserId, $action, $payload, $requestId): array {
            $subject = $this->findUser($subjectUserId, true);
            $now = (new SystemClock())->now();
            $timestamp = Dates::database($now);
            $metadata = ['previousStatus' => (string) $subject['status']];

            if ($action === 'activate' || $action === 'restore') {
                if ($this->markEmailVerified) {
                    $account = $pdo->prepare(
                        'UPDATE users
                         SET status = :status, email_verified_at = COALESCE(email_verified_at, :verified_at), updated_at = :updated_at
                         WHERE id = :id'
                    );
                    $account->execute([
                        ':status' => 'active',
                        ':verified_at' => $timestamp,
                        ':updated_at' => $timestamp,
                        ':id' => $subjectUserId,
                    ]);
                } else {
                    $account = $pdo->prepare(
                        'UPDATE users SET status = :status, updated_at = :updated_at WHERE id = :id'
                    );
                    $account->execute([
                        ':status' => 'active',
                        ':updated_at' => $timestamp,
                        ':id' => $subjectUserId,
                    ]);
                }
                if ($action === 'activate') {
                    $trial = $pdo->prepare(
                        'UPDATE user_profiles
                         SET trial_started_at = COALESCE(trial_started_at, :started_at),
                             trial_ends_at = COALESCE(trial_ends_at, :ends_at),
                             updated_at = :updated_at
                         WHERE user_id = :user_id'
                    );
                    $trial->execute([
                        ':started_at' => $timestamp,
                        ':ends_at' => Dates::database($now->modify('+7 days')),
                        ':updated_at' => $timestamp,
                        ':user_id' => $subjectUserId,
                    ]);
                }
            } elseif ($action === 'suspend') {
                $account = $pdo->prepare(
                    'UPDATE users SET status = :status, updated_at = :updated_at WHERE id = :id'
                );
                $account->execute([':status' => 'disabled', ':updated_at' => $timestamp, ':id' => $subjectUserId]);
                $sessions = $pdo->prepare(
                    'UPDATE auth_sessions SET revoked_at = COALESCE(revoked_at, :revoked_at) WHERE user_id = :user_id'
                );
                $sessions->execute([':revoked_at' => $timestamp, ':user_id' => $subjectUserId]);
            } else {
                $days = $payload['days'] ?? null;
                if (!is_int($days) || $days < 1 || $days > 30) {
                    throw new ApiException(422, 'VALIDATION_ERROR', 'La extensión debe estar entre 1 y 30 días.', [
                        'field' => 'days',
                    ]);
                }
                if ((string) $subject['status'] !== 'active') {
                    throw new ApiException(409, 'CONFLICT', 'Solo se puede extender el trial de una cuenta activa.');
                }
                $currentEnd = $subject['trial_ends_at'] === null
                    ? $now
                    : new DateTimeImmutable((string) $subject['trial_ends_at'], new \DateTimeZone('UTC'));
                $base = $currentEnd > $now ? $currentEnd : $now;
                $trial = $pdo->prepare(
                    'UPDATE user_profiles
                     SET trial_started_at = COALESCE(trial_started_at, :started_at),
                         trial_ends_at = :ends_at, updated_at = :updated_at
                     WHERE user_id = :user_id'
                );
                $trial->execute([
                    ':started_at' => $timestamp,
                    ':ends_at' => Dates::database($base->modify('+' . $days . ' days')),
                    ':updated_at' => $timestamp,
                    ':user_id' => $subjectUserId,
                ]);
                $metadata['days'] = $days;
            }

            $this->audit->record(
                $actorUserId,
                $subjectUserId,
                'account.' . $action,
                'user',
                $subjectUserId,
                $requestId,
                $metadata,
            );
            return $this->user($subjectUserId);
        });
    }

    /** @return array<string, mixed> */
    public function systemStatus(): array
    {
        $database = 'unavailable';
        $migrations = [];
        try {
            $this->pdo->query('SELECT 1')->fetchColumn();
            $database = 'available';
            $migrations = array_map(
                static fn (array $row): string => (string) $row['version'],
                $this->pdo->query('SELECT version FROM schema_migrations ORDER BY version ASC')->fetchAll(),
            );
        } catch (\Throwable) {
            // Health state intentionally carries no connection detail.
        }
        return [
            'api' => 'available',
            'database' => $database,
            'migrations' => $migrations,
            'checkedAt' => Dates::iso(Dates::database((new SystemClock())->now())),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function auditLogs(int $limit): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'El límite debe estar entre 1 y 100.', ['field' => 'limit']);
        }
        $statement = $this->pdo->query(
            'SELECT action, entity_type, entity_id, request_id, created_at
             FROM audit_logs ORDER BY created_at DESC, id DESC LIMIT ' . $limit
        );
        return array_map(static fn (array $row): array => [
            'action' => (string) $row['action'],
            'entityType' => $row['entity_type'] === null ? null : (string) $row['entity_type'],
            'entityId' => $row['entity_id'] === null ? null : (string) $row['entity_id'],
            'requestId' => (string) $row['request_id'],
            'createdAt' => Dates::iso((string) $row['created_at']),
        ], $statement->fetchAll());
    }

    /** @return list<array<string, mixed>> */
    public function authenticationEvents(int $limit): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'El límite debe estar entre 1 y 100.', ['field' => 'limit']);
        }
        $statement = $this->pdo->query(
            'SELECT succeeded, attempted_at FROM auth_attempts
             ORDER BY attempted_at DESC, id DESC LIMIT ' . $limit
        );
        return array_map(static fn (array $row): array => [
            'type' => (bool) $row['succeeded'] ? 'login-success' : 'login-failed',
            'occurredAt' => Dates::iso((string) $row['attempted_at']),
        ], $statement->fetchAll());
    }

    /** @return array<string, mixed> */
    private function findUser(string $userId, bool $lock = false): array
    {
        $statement = $this->pdo->prepare(
            'SELECT u.id, u.email, u.username, u.status, u.email_verified_at, u.last_login_at, u.created_at, u.updated_at,
                    p.display_name, p.timezone, p.locale, p.trial_started_at, p.trial_ends_at
             FROM users u INNER JOIN user_profiles p ON p.user_id = u.id
             WHERE u.id = :id LIMIT 1' . ($lock ? $this->forUpdate() : '')
        );
        $statement->execute([':id' => $userId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'NOT_FOUND', 'La cuenta no existe.');
        }
        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function mapUser(array $row, bool $detail = false): array
    {
        $roles = $this->pdo->prepare('SELECT role_code FROM user_roles WHERE user_id = :user_id ORDER BY role_code');
        $roles->execute([':user_id' => (string) $row['id']]);
        $mapped = [
            'id' => (string) $row['id'],
            'email' => $row['email'] === null ? null : (string) $row['email'],
            'username' => $row['username'] === null ? null : (string) $row['username'],
            'displayName' => (string) $row['display_name'],
            'status' => (string) $row['status'],
            'roles' => array_map(static fn (array $role): string => (string) $role['role_code'], $roles->fetchAll()),
            'trial' => [
                'startedAt' => Dates::iso($row['trial_started_at'] === null ? null : (string) $row['trial_started_at']),
                'endsAt' => Dates::iso($row['trial_ends_at'] === null ? null : (string) $row['trial_ends_at']),
            ],
            'createdAt' => Dates::iso((string) $row['created_at']),
            'lastLoginAt' => Dates::iso($row['last_login_at'] === null ? null : (string) $row['last_login_at']),
        ];
        if ($detail) {
            $mapped['timezone'] = (string) $row['timezone'];
            $mapped['locale'] = (string) $row['locale'];
            $mapped['emailVerifiedAt'] = Dates::iso($row['email_verified_at'] === null ? null : (string) $row['email_verified_at']);
            $mapped['updatedAt'] = Dates::iso((string) $row['updated_at']);
        }
        return $mapped;
    }

    private function forUpdate(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }
}
