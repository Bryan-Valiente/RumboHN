<?php

declare(strict_types=1);

namespace Rumbo\Backend\Admin;

use PDO;
use Rumbo\Backend\Audit\AuditLogger;
use Rumbo\Backend\Auth\AuthRepository;
use Rumbo\Backend\Auth\AuthService;
use Rumbo\Backend\Auth\UnavailablePasswordResetDelivery;
use Rumbo\Backend\Config;
use Rumbo\Backend\Http\ApiException;
use Rumbo\Backend\Profile\ProfileRepository;
use Rumbo\Backend\Support\Dates;
use Rumbo\Backend\Support\SystemClock;
use Rumbo\Backend\TransactionRunner;

/**
 * CLI-only provisioning service. No HTTP route may invoke this class.
 */
final class AdminProvisioner
{
    public function __construct(private readonly PDO $pdo, private readonly Config $config)
    {
    }

    public function provision(string $email, string $password, string $displayName): void
    {
        $repository = new AuthRepository($this->pdo);
        if ($repository->findUserByEmail(strtolower(trim($email))) !== null) {
            throw new ApiException(409, 'CONFLICT', 'La cuenta indicada ya existe; la provisión no modifica cuentas existentes.');
        }

        $auth = new AuthService(
            $repository,
            new ProfileRepository($this->pdo),
            $this->config,
            new UnavailablePasswordResetDelivery(),
        );
        $auth->register([
            'email' => $email,
            'password' => $password,
            'displayName' => $displayName,
            'timezone' => 'America/Tegucigalpa',
            'locale' => 'es-HN',
        ]);
        $user = $repository->findUserByEmail(strtolower(trim($email)));
        if (!is_array($user)) {
            throw new \RuntimeException('No se pudo localizar la cuenta recién provisionada.');
        }

        TransactionRunner::run($this->pdo, function (PDO $pdo) use ($user): void {
            $now = (new SystemClock())->now();
            $timestamp = Dates::database($now);
            $activate = $pdo->prepare(
                'UPDATE users
                 SET status = :status, email_verified_at = :verified_at, updated_at = :updated_at
                 WHERE id = :id AND status = :pending'
            );
            $activate->execute([
                ':status' => 'active',
                ':verified_at' => $timestamp,
                ':updated_at' => $timestamp,
                ':id' => (string) $user['id'],
            ]);
            if ($activate->rowCount() !== 1) {
                throw new \RuntimeException('La cuenta no está en un estado apto para la provisión administrativa.');
            }
            $trial = $pdo->prepare(
                'UPDATE user_profiles
                 SET trial_started_at = :started_at, trial_ends_at = :ends_at, updated_at = :updated_at
                 WHERE user_id = :user_id'
            );
            $trial->execute([
                ':started_at' => $timestamp,
                ':ends_at' => Dates::database($now->modify('+7 days')),
                ':updated_at' => $timestamp,
                ':user_id' => (string) $user['id'],
            ]);
            $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $roleSql = $driver === 'sqlite'
                ? 'INSERT OR IGNORE INTO user_roles (user_id, role_code, assigned_by_user_id, assigned_at)
                   VALUES (:user_id, :role_code, NULL, :assigned_at)'
                : 'INSERT IGNORE INTO user_roles (user_id, role_code, assigned_by_user_id, assigned_at)
                   VALUES (:user_id, :role_code, NULL, :assigned_at)';
            $role = $pdo->prepare($roleSql);
            $role->execute([
                ':user_id' => (string) $user['id'],
                ':role_code' => 'admin',
                ':assigned_at' => $timestamp,
            ]);
            (new AuditLogger($pdo))->record(
                null,
                (string) $user['id'],
                'admin.provisioned',
                'user',
                (string) $user['id'],
                bin2hex(random_bytes(16)),
                ['source' => 'cli'],
            );
        });
    }
}
