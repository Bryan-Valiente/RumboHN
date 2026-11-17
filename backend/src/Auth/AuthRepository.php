<?php

declare(strict_types=1);

namespace Rumbo\Backend\Auth;

use DateTimeImmutable;
use PDO;
use PDOException;
use Rumbo\Backend\Support\Dates;
use Rumbo\Backend\Support\Uuid;
use Rumbo\Backend\TransactionRunner;

final class AuthRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function findUserByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, email, username, password_hash, status, email_verified_at
             FROM users WHERE email = :email LIMIT 1'
        );
        $statement->execute([':email' => $email]);
        $user = $statement->fetch();
        return is_array($user) ? $user : null;
    }

    /** @return array<string, mixed>|null */
    public function findUserByUsername(string $username, bool $adminOnly = false): ?array
    {
        $sql = 'SELECT u.id, u.email, u.username, u.password_hash, u.status, u.email_verified_at
                FROM users u';
        if ($adminOnly) {
            $sql .= ' INNER JOIN user_roles ur ON ur.user_id = u.id AND ur.role_code = :role_code';
        }
        $sql .= ' WHERE u.username = :username LIMIT 1';
        $statement = $this->pdo->prepare($sql);
        $parameters = [':username' => $username];
        if ($adminOnly) {
            $parameters[':role_code'] = 'admin';
        }
        $statement->execute($parameters);
        $user = $statement->fetch();
        return is_array($user) ? $user : null;
    }

    /** @return array<string, mixed>|null */
    public function findActiveUserByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, email FROM users WHERE email = :email AND status = :status LIMIT 1'
        );
        $statement->execute([':email' => $email, ':status' => 'active']);
        $user = $statement->fetch();
        return is_array($user) ? $user : null;
    }

    public function createPendingAccount(
        string $userId,
        string $email,
        string $passwordHash,
        string $displayName,
        string $timezone,
        string $locale,
        DateTimeImmutable $now,
    ): bool {
        try {
            TransactionRunner::run($this->pdo, function (PDO $pdo) use (
                $userId,
                $email,
                $passwordHash,
                $displayName,
                $timezone,
                $locale,
                $now,
            ): void {
                $timestamp = Dates::database($now);
                $user = $pdo->prepare(
                    'INSERT INTO users (id, email, password_hash, status, created_at, updated_at)
                     VALUES (:id, :email, :password_hash, :status, :created_at, :updated_at)'
                );
                $user->execute([
                    ':id' => $userId,
                    ':email' => $email,
                    ':password_hash' => $passwordHash,
                    ':status' => 'pending',
                    ':created_at' => $timestamp,
                    ':updated_at' => $timestamp,
                ]);

                $profile = $pdo->prepare(
                    'INSERT INTO user_profiles
                        (user_id, display_name, timezone, locale, opening_balance_hnl, onboarding_completed, created_at, updated_at)
                     VALUES (:user_id, :display_name, :timezone, :locale, :opening_balance, :onboarding_completed, :created_at, :updated_at)'
                );
                $profile->execute([
                    ':user_id' => $userId,
                    ':display_name' => $displayName,
                    ':timezone' => $timezone,
                    ':locale' => $locale,
                    ':opening_balance' => '0.00',
                    ':onboarding_completed' => 0,
                    ':created_at' => $timestamp,
                    ':updated_at' => $timestamp,
                ]);

                $role = $pdo->prepare(
                    'INSERT INTO user_roles (user_id, role_code, assigned_by_user_id, assigned_at)
                     VALUES (:user_id, :role_code, NULL, :assigned_at)'
                );
                $role->execute([
                    ':user_id' => $userId,
                    ':role_code' => 'member',
                    ':assigned_at' => $timestamp,
                ]);
            });
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return false;
            }
            throw $exception;
        }

        return true;
    }

    public function activateAccount(string $userId, DateTimeImmutable $now): bool
    {
        return TransactionRunner::run($this->pdo, function (PDO $pdo) use ($userId, $now): bool {
            $select = $pdo->prepare(
                'SELECT status FROM users WHERE id = :id LIMIT 1' . $this->forUpdate()
            );
            $select->execute([':id' => $userId]);
            $status = $select->fetchColumn();
            if (!is_string($status) || in_array($status, ['locked', 'disabled'], true)) {
                return false;
            }

            $timestamp = Dates::database($now);
            $trialEnds = Dates::database($now->modify('+7 days'));
            $user = $pdo->prepare(
                'UPDATE users
                 SET status = :status, email_verified_at = COALESCE(email_verified_at, :verified_at), updated_at = :updated_at
                 WHERE id = :id'
            );
            $user->execute([
                ':status' => 'active',
                ':verified_at' => $timestamp,
                ':updated_at' => $timestamp,
                ':id' => $userId,
            ]);

            $profile = $pdo->prepare(
                'UPDATE user_profiles
                 SET trial_started_at = COALESCE(trial_started_at, :trial_started_at),
                     trial_ends_at = COALESCE(trial_ends_at, :trial_ends_at),
                     updated_at = :updated_at
                 WHERE user_id = :user_id'
            );
            $profile->execute([
                ':trial_started_at' => $timestamp,
                ':trial_ends_at' => $trialEnds,
                ':updated_at' => $timestamp,
                ':user_id' => $userId,
            ]);

            return true;
        });
    }

    public function countRecentFailedAttempts(string $identityHash, ?string $ipHash, DateTimeImmutable $since): int
    {
        $sql = 'SELECT COUNT(*) FROM auth_attempts
                WHERE succeeded = 0 AND attempted_at >= :since AND identity_hash = :identity_hash';
        if ($ipHash !== null) {
            $sql = 'SELECT COUNT(*) FROM auth_attempts
                    WHERE succeeded = 0 AND attempted_at >= :since
                      AND (identity_hash = :identity_hash OR ip_hash = :ip_hash)';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->bindValue(':since', Dates::database($since));
        $statement->bindValue(':identity_hash', $identityHash, PDO::PARAM_LOB);
        if ($ipHash !== null) {
            $statement->bindValue(':ip_hash', $ipHash, PDO::PARAM_LOB);
        }
        $statement->execute();
        return (int) $statement->fetchColumn();
    }

    public function recordAttempt(
        string $identityHash,
        ?string $ipHash,
        bool $succeeded,
        DateTimeImmutable $now,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO auth_attempts (identity_hash, ip_hash, succeeded, attempted_at)
             VALUES (:identity_hash, :ip_hash, :succeeded, :attempted_at)'
        );
        $statement->bindValue(':identity_hash', $identityHash, PDO::PARAM_LOB);
        $statement->bindValue(':ip_hash', $ipHash, $ipHash === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $statement->bindValue(':succeeded', $succeeded ? 1 : 0, PDO::PARAM_INT);
        $statement->bindValue(':attempted_at', Dates::database($now));
        $statement->execute();
    }

    public function createSession(
        string $sessionId,
        string $userId,
        string $tokenHash,
        DateTimeImmutable $now,
        DateTimeImmutable $expiresAt,
        ?string $ipHash,
        ?string $userAgentHash,
        string $expectedPasswordHash,
        ?string $replacementPasswordHash,
    ): bool {
        return TransactionRunner::run($this->pdo, function (PDO $pdo) use (
            $sessionId,
            $userId,
            $tokenHash,
            $now,
            $expiresAt,
            $ipHash,
            $userAgentHash,
            $expectedPasswordHash,
            $replacementPasswordHash,
        ): bool {
            $user = $pdo->prepare(
                'SELECT password_hash, status FROM users WHERE id = :id LIMIT 1' . $this->forUpdate()
            );
            $user->execute([':id' => $userId]);
            $current = $user->fetch();
            if (!is_array($current)
                || (string) $current['status'] !== 'active'
                || !hash_equals($expectedPasswordHash, (string) $current['password_hash'])) {
                return false;
            }

            $session = $pdo->prepare(
                'INSERT INTO auth_sessions
                    (id, user_id, token_hash, created_at, last_seen_at, expires_at, revoked_at, ip_hash, user_agent_hash)
                 VALUES (:id, :user_id, :token_hash, :created_at, :last_seen_at, :expires_at, NULL, :ip_hash, :user_agent_hash)'
            );
            $session->bindValue(':id', $sessionId);
            $session->bindValue(':user_id', $userId);
            $session->bindValue(':token_hash', $tokenHash, PDO::PARAM_LOB);
            $session->bindValue(':created_at', Dates::database($now));
            $session->bindValue(':last_seen_at', Dates::database($now));
            $session->bindValue(':expires_at', Dates::database($expiresAt));
            $session->bindValue(':ip_hash', $ipHash, $ipHash === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
            $session->bindValue(':user_agent_hash', $userAgentHash, $userAgentHash === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
            $session->execute();

            if ($replacementPasswordHash === null) {
                $login = $pdo->prepare('UPDATE users SET last_login_at = :last_login_at WHERE id = :id');
                $login->execute([':last_login_at' => Dates::database($now), ':id' => $userId]);
            } else {
                $login = $pdo->prepare(
                    'UPDATE users
                     SET last_login_at = :last_login_at, password_hash = :password_hash, updated_at = :updated_at
                     WHERE id = :id'
                );
                $login->execute([
                    ':last_login_at' => Dates::database($now),
                    ':password_hash' => $replacementPasswordHash,
                    ':updated_at' => Dates::database($now),
                    ':id' => $userId,
                ]);
            }

            return true;
        });
    }

    public function revokeSession(string $sessionId, DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE auth_sessions SET revoked_at = COALESCE(revoked_at, :revoked_at) WHERE id = :id'
        );
        $statement->execute([':revoked_at' => Dates::database($now), ':id' => $sessionId]);
    }

    public function createPasswordReset(
        string $userId,
        string $tokenHash,
        DateTimeImmutable $now,
        DateTimeImmutable $expiresAt,
    ): string {
        return TransactionRunner::run($this->pdo, function (PDO $pdo) use (
            $userId,
            $tokenHash,
            $now,
            $expiresAt,
        ): string {
            $timestamp = Dates::database($now);
            $revoke = $pdo->prepare(
                'UPDATE password_reset_tokens
                 SET consumed_at = :consumed_at
                 WHERE user_id = :user_id AND consumed_at IS NULL'
            );
            $revoke->execute([':consumed_at' => $timestamp, ':user_id' => $userId]);

            $id = Uuid::v4();
            $insert = $pdo->prepare(
                'INSERT INTO password_reset_tokens (id, user_id, token_hash, created_at, expires_at, consumed_at)
                 VALUES (:id, :user_id, :token_hash, :created_at, :expires_at, NULL)'
            );
            $insert->bindValue(':id', $id);
            $insert->bindValue(':user_id', $userId);
            $insert->bindValue(':token_hash', $tokenHash, PDO::PARAM_LOB);
            $insert->bindValue(':created_at', $timestamp);
            $insert->bindValue(':expires_at', Dates::database($expiresAt));
            $insert->execute();

            return $id;
        });
    }

    public function invalidatePasswordReset(string $resetId, DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE password_reset_tokens SET consumed_at = :consumed_at WHERE id = :id AND consumed_at IS NULL'
        );
        $statement->execute([':consumed_at' => Dates::database($now), ':id' => $resetId]);
    }

    public function consumePasswordReset(
        string $tokenHash,
        string $newPasswordHash,
        DateTimeImmutable $now,
    ): bool {
        return TransactionRunner::run($this->pdo, function (PDO $pdo) use ($tokenHash, $newPasswordHash, $now): bool {
            $select = $pdo->prepare(
                'SELECT r.id, r.user_id
                 FROM password_reset_tokens r
                 INNER JOIN users u ON u.id = r.user_id
                 WHERE r.token_hash = :token_hash
                   AND r.consumed_at IS NULL
                   AND r.expires_at > :now
                   AND u.status = :status
                 LIMIT 1' . $this->forUpdate()
            );
            $select->bindValue(':token_hash', $tokenHash, PDO::PARAM_LOB);
            $select->bindValue(':now', Dates::database($now));
            $select->bindValue(':status', 'active');
            $select->execute();
            $reset = $select->fetch();
            if (!is_array($reset)) {
                return false;
            }

            $timestamp = Dates::database($now);
            $consume = $pdo->prepare(
                'UPDATE password_reset_tokens
                 SET consumed_at = :consumed_at
                 WHERE id = :id AND consumed_at IS NULL'
            );
            $consume->execute([':consumed_at' => $timestamp, ':id' => (string) $reset['id']]);
            if ($consume->rowCount() !== 1) {
                return false;
            }

            $password = $pdo->prepare(
                'UPDATE users SET password_hash = :password_hash, updated_at = :updated_at WHERE id = :id'
            );
            $password->execute([
                ':password_hash' => $newPasswordHash,
                ':updated_at' => $timestamp,
                ':id' => (string) $reset['user_id'],
            ]);

            $sessions = $pdo->prepare(
                'UPDATE auth_sessions SET revoked_at = COALESCE(revoked_at, :revoked_at) WHERE user_id = :user_id'
            );
            $sessions->execute([
                ':revoked_at' => $timestamp,
                ':user_id' => (string) $reset['user_id'],
            ]);

            return true;
        });
    }

    private function forUpdate(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }
}
