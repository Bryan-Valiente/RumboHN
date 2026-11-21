<?php

declare(strict_types=1);

namespace Rumbo\Backend\Profile;

use DateTimeZone;
use PDO;
use Rumbo\Backend\Financial\Money;
use Rumbo\Backend\Http\ApiException;
use Rumbo\Backend\Http\Input;
use Rumbo\Backend\Support\Dates;
use Rumbo\Backend\Support\SystemClock;
use Rumbo\Backend\TransactionRunner;

final class ProfileRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed> */
    public function get(string $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT u.id, u.email, u.username, u.status, u.email_verified_at,
                    p.display_name, p.timezone, p.locale, p.opening_balance_hnl,
                    p.onboarding_completed, p.trial_started_at, p.trial_ends_at,
                    p.created_at, p.updated_at
             FROM users u
             INNER JOIN user_profiles p ON p.user_id = u.id
             WHERE u.id = :user_id
             LIMIT 1'
        );
        $statement->execute([':user_id' => $userId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'NOT_FOUND', 'El perfil no existe.');
        }

        $roles = $this->pdo->prepare(
            'SELECT role_code FROM user_roles WHERE user_id = :user_id ORDER BY role_code'
        );
        $roles->execute([':user_id' => $userId]);

        return [
            'id' => (string) $row['id'],
            'email' => $row['email'] === null ? null : (string) $row['email'],
            'username' => $row['username'] === null ? null : (string) $row['username'],
            'displayName' => (string) $row['display_name'],
            'timezone' => (string) $row['timezone'],
            'locale' => (string) $row['locale'],
            'openingBalance' => Money::formatDatabase((string) $row['opening_balance_hnl'], true),
            'currency' => Money::CURRENCY,
            'onboardingCompleted' => (bool) $row['onboarding_completed'],
            'accountStatus' => (string) $row['status'],
            'emailVerifiedAt' => Dates::iso($row['email_verified_at'] === null ? null : (string) $row['email_verified_at']),
            'trial' => [
                'startedAt' => Dates::iso($row['trial_started_at'] === null ? null : (string) $row['trial_started_at']),
                'endsAt' => Dates::iso($row['trial_ends_at'] === null ? null : (string) $row['trial_ends_at']),
            ],
            'roles' => array_map(
                static fn (array $role): string => (string) $role['role_code'],
                $roles->fetchAll(),
            ),
            'createdAt' => Dates::iso((string) $row['created_at']),
            'updatedAt' => Dates::iso((string) $row['updated_at']),
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function update(string $userId, array $data): array
    {
        return TransactionRunner::run($this->pdo, function (PDO $pdo) use ($userId, $data): array {
            Input::assertAllowedKeys($data, ['displayName', 'timezone', 'locale', 'openingBalance', 'onboardingCompleted']);
            if ($data === []) {
                throw new ApiException(422, 'VALIDATION_ERROR', 'Debés enviar al menos un cambio.');
            }

            $lock = $pdo->prepare(
                'SELECT onboarding_completed FROM user_profiles WHERE user_id = :user_id LIMIT 1' . $this->forUpdate()
            );
            $lock->execute([':user_id' => $userId]);
            if ($lock->fetchColumn() === false) {
                throw new ApiException(404, 'NOT_FOUND', 'El perfil no existe.');
            }

            $current = $this->get($userId);
            $updates = [];
            $parameters = [':user_id' => $userId];

            if (array_key_exists('displayName', $data)) {
                $updates[] = 'display_name = :display_name';
                $parameters[':display_name'] = Input::string($data, 'displayName', 1, 120);
            }
            if (array_key_exists('timezone', $data)) {
                $timezone = Input::string($data, 'timezone', 1, 64);
                if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
                    throw new ApiException(422, 'VALIDATION_ERROR', 'La zona horaria no es válida.', ['field' => 'timezone']);
                }
                $updates[] = 'timezone = :timezone';
                $parameters[':timezone'] = $timezone;
            }
            if (array_key_exists('locale', $data)) {
                $locale = Input::string($data, 'locale', 2, 16);
                if (!preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $locale)) {
                    throw new ApiException(422, 'VALIDATION_ERROR', 'La configuración regional no es válida.', ['field' => 'locale']);
                }
                $updates[] = 'locale = :locale';
                $parameters[':locale'] = $locale;
            }
            if (array_key_exists('openingBalance', $data)) {
                if ($current['onboardingCompleted'] === true) {
                    throw new ApiException(409, 'CONFLICT', 'El saldo inicial no puede cambiar después del onboarding.');
                }
                try {
                    $openingBalance = Money::normalizeHnl($data['openingBalance'], true);
                } catch (\InvalidArgumentException $exception) {
                    throw new ApiException(422, 'VALIDATION_ERROR', $exception->getMessage(), ['field' => 'openingBalance']);
                }
                $updates[] = 'opening_balance_hnl = :opening_balance_hnl';
                $parameters[':opening_balance_hnl'] = $openingBalance;
            }
            if (array_key_exists('onboardingCompleted', $data)) {
                $completed = Input::boolean($data, 'onboardingCompleted');
                if ($current['onboardingCompleted'] === true && !$completed) {
                    throw new ApiException(409, 'CONFLICT', 'El onboarding completado no puede revertirse.');
                }
                $updates[] = 'onboarding_completed = :onboarding_completed';
                $parameters[':onboarding_completed'] = $completed ? 1 : 0;
            }

            $updates[] = 'updated_at = :updated_at';
            $parameters[':updated_at'] = Dates::database((new SystemClock())->now());
            $statement = $pdo->prepare(
                'UPDATE user_profiles SET ' . implode(', ', $updates) . ' WHERE user_id = :user_id'
            );
            $statement->execute($parameters);

            return $this->get($userId);
        });
    }

    private function forUpdate(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }
}
