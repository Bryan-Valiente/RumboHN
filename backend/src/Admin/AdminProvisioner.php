<?php

declare(strict_types=1);

namespace Rumbo\Backend\Admin;

use PDO;
use PDOException;
use Rumbo\Backend\Audit\AuditLogger;
use Rumbo\Backend\Auth\AuthRepository;
use Rumbo\Backend\Config;
use Rumbo\Backend\Http\ApiException;
use Rumbo\Backend\Http\Input;
use Rumbo\Backend\PasswordHasher;
use Rumbo\Backend\Support\Dates;
use Rumbo\Backend\Support\SystemClock;
use Rumbo\Backend\TransactionRunner;
use Rumbo\Backend\Support\Uuid;

/**
 * CLI-only provisioning service. No HTTP route may invoke this class.
 */
final class AdminProvisioner
{
    public function __construct(private readonly PDO $pdo, private readonly Config $config)
    {
    }

    public function provision(string $username, string $password, string $displayName): void
    {
        $repository = new AuthRepository($this->pdo);
        $username = strtolower(Input::string(['username' => $username], 'username', 3, 64));
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $username)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'El usuario administrativo no tiene un formato válido.', ['field' => 'username']);
        }
        $displayName = Input::string(['displayName' => $displayName], 'displayName', 1, 120);
        try {
            $passwordHash = PasswordHasher::hash($password);
        } catch (\InvalidArgumentException $exception) {
            throw new ApiException(422, 'VALIDATION_ERROR', $exception->getMessage(), ['field' => 'password']);
        }
        if ($repository->findUserByUsername($username) !== null) {
            throw new ApiException(409, 'CONFLICT', 'La cuenta indicada ya existe; la provisión no modifica cuentas existentes.');
        }

        $userId = Uuid::v4();
        try {
            TransactionRunner::run($this->pdo, function (PDO $pdo) use ($userId, $username, $passwordHash, $displayName): void {
            $now = (new SystemClock())->now();
            $timestamp = Dates::database($now);
            $user = $pdo->prepare(
                'INSERT INTO users (id, email, username, password_hash, status, created_at, updated_at)
                 VALUES (:id, NULL, :username, :password_hash, :status, :created_at, :updated_at)'
            );
            $user->execute([
                ':id' => $userId,
                ':username' => $username,
                ':password_hash' => $passwordHash,
                ':status' => 'active',
                ':created_at' => $timestamp,
                ':updated_at' => $timestamp,
            ]);
            $trial = $pdo->prepare(
                'INSERT INTO user_profiles
                    (user_id, display_name, timezone, locale, opening_balance_hnl, onboarding_completed, trial_started_at, trial_ends_at, created_at, updated_at)
                 VALUES (:user_id, :display_name, :timezone, :locale, 0.00, 0, :started_at, :ends_at, :created_at, :updated_at)'
            );
            $trial->execute([
                ':user_id' => $userId,
                ':display_name' => $displayName,
                ':timezone' => 'America/Tegucigalpa',
                ':locale' => 'es-HN',
                ':started_at' => $timestamp,
                ':ends_at' => Dates::database($now->modify('+7 days')),
                ':created_at' => $timestamp,
                ':updated_at' => $timestamp,
            ]);
            $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $roleSql = $driver === 'sqlite'
                ? 'INSERT OR IGNORE INTO user_roles (user_id, role_code, assigned_by_user_id, assigned_at)
                   VALUES (:user_id, :role_code, NULL, :assigned_at)'
                : 'INSERT IGNORE INTO user_roles (user_id, role_code, assigned_by_user_id, assigned_at)
                   VALUES (:user_id, :role_code, NULL, :assigned_at)';
            $role = $pdo->prepare($roleSql);
            foreach (['member', 'admin'] as $roleCode) {
                $role->execute([
                    ':user_id' => $userId,
                    ':role_code' => $roleCode,
                    ':assigned_at' => $timestamp,
                ]);
            }
            (new AuditLogger($pdo))->record(
                null,
                $userId,
                'admin.provisioned',
                'user',
                $userId,
                bin2hex(random_bytes(16)),
                ['source' => 'cli', 'identity' => 'username'],
            );
            });
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw new ApiException(409, 'CONFLICT', 'La cuenta indicada ya existe; la provisión no modifica cuentas existentes.');
            }
            throw $exception;
        }
    }
}
