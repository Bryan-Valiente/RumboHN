<?php

declare(strict_types=1);

namespace Rumbo\Backend\Auth;

use DateTimeZone;
use InvalidArgumentException;
use Rumbo\Backend\Config;
use Rumbo\Backend\Http\ApiException;
use Rumbo\Backend\Http\Input;
use Rumbo\Backend\PasswordHasher;
use Rumbo\Backend\Profile\ProfileRepository;
use Rumbo\Backend\Security\Token;
use Rumbo\Backend\Support\Clock;
use Rumbo\Backend\Support\Dates;
use Rumbo\Backend\Support\SystemClock;
use Rumbo\Backend\Support\Uuid;
use Throwable;

final class AuthService
{
    private readonly Clock $clock;

    public function __construct(
        private readonly AuthRepository $repository,
        private readonly ProfileRepository $profiles,
        private readonly Config $config,
        private readonly PasswordResetDelivery $delivery,
        ?Clock $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    /** @param array<string, mixed> $data */
    public function register(array $data): void
    {
        Input::assertAllowedKeys($data, ['email', 'password', 'displayName', 'timezone', 'locale']);
        $email = $this->email(Input::string($data, 'email', 3, 254));
        $password = Input::rawString($data, 'password', 12, 128);
        $displayName = Input::string($data, 'displayName', 1, 120);
        $timezone = isset($data['timezone']) ? Input::string($data, 'timezone', 1, 64) : 'America/Tegucigalpa';
        $locale = isset($data['locale']) ? Input::string($data, 'locale', 2, 16) : 'es-HN';
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'La zona horaria no es válida.', ['field' => 'timezone']);
        }
        if (!preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $locale)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'La configuración regional no es válida.', ['field' => 'locale']);
        }

        try {
            $passwordHash = PasswordHasher::hash($password);
        } catch (InvalidArgumentException $exception) {
            throw new ApiException(422, 'VALIDATION_ERROR', $exception->getMessage(), ['field' => 'password']);
        }

        $this->repository->createPendingAccount(
            Uuid::v4(),
            $email,
            $passwordHash,
            $displayName,
            $timezone,
            $locale,
            $this->clock->now(),
        );
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function login(array $data, ?string $clientIp, ?string $userAgent): array
    {
        Input::assertAllowedKeys($data, ['email', 'password']);
        $rawEmail = Input::string($data, 'email', 1, 254);
        $password = Input::rawString($data, 'password', 1, 128);
        return $this->loginIdentity(
            $rawEmail,
            $password,
            $clientIp,
            $userAgent,
            function (string $identity): ?array {
                $email = filter_var($identity, FILTER_VALIDATE_EMAIL) !== false
                    ? $identity
                    : null;
                return $email === null ? null : $this->repository->findUserByEmail($email);
            },
            'El correo o la contraseña no son válidos.',
        );
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function loginAdmin(array $data, ?string $clientIp, ?string $userAgent): array
    {
        Input::assertAllowedKeys($data, ['username', 'password']);
        $rawUsername = Input::string($data, 'username', 1, 64);
        $password = Input::rawString($data, 'password', 1, 128);

        return $this->loginIdentity(
            $rawUsername,
            $password,
            $clientIp,
            $userAgent,
            function (string $identity): ?array {
                if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $identity)) {
                    return null;
                }
                return $this->repository->findUserByUsername($identity, true);
            },
            'El usuario o la contraseña no son válidos.',
        );
    }

    /** @param callable(string): (?array<string, mixed>) $finder @return array<string, mixed> */
    private function loginIdentity(
        string $rawIdentity,
        string $password,
        ?string $clientIp,
        ?string $userAgent,
        callable $finder,
        string $invalidMessage,
    ): array {
        $normalizedIdentity = strtolower(trim($rawIdentity));
        $pepper = $this->config->securityPepper();
        $identityHash = Token::keyedHash($normalizedIdentity, $pepper);
        $ipHash = $clientIp === null || $clientIp === '' ? null : Token::keyedHash($clientIp, $pepper);
        $now = $this->clock->now();
        $since = $now->modify(sprintf('-%d seconds', $this->config->loginAttemptWindowSeconds()));
        if ($this->repository->countRecentFailedAttempts($identityHash, $ipHash, $since) >= $this->config->loginMaxAttempts()) {
            throw new ApiException(429, 'RATE_LIMITED', 'No se pudo iniciar sesión. Intentá nuevamente más tarde.');
        }

        $user = $finder($normalizedIdentity);
        $hash = is_array($user) ? (string) $user['password_hash'] : self::dummyPasswordHash();
        $verified = PasswordHasher::verify($password, $hash);
        $allowed = $verified && is_array($user) && (string) $user['status'] === 'active';
        if (!$allowed || !is_array($user)) {
            $this->repository->recordAttempt($identityHash, $ipHash, false, $now);
            throw new ApiException(401, 'AUTH_INVALID', $invalidMessage);
        }

        $sessionToken = Token::random();
        $csrfToken = Token::csrf($sessionToken, $pepper);
        $expiresAt = $now->modify(sprintf('+%d seconds', $this->config->sessionTtlSeconds()));
        $userAgentHash = $userAgent === null || $userAgent === ''
            ? null
            : Token::keyedHash($userAgent, $pepper);
        $sessionId = Uuid::v4();
        $replacementHash = PasswordHasher::needsRehash((string) $user['password_hash'])
            ? PasswordHasher::hash($password)
            : null;
        $created = $this->repository->createSession(
            $sessionId,
            (string) $user['id'],
            Token::hash($sessionToken),
            $now,
            $expiresAt,
            $ipHash,
            $userAgentHash,
            (string) $user['password_hash'],
            $replacementHash,
        );
        if (!$created) {
            $this->repository->recordAttempt($identityHash, $ipHash, false, $now);
            throw new ApiException(401, 'AUTH_INVALID', $invalidMessage);
        }
        $this->repository->recordAttempt($identityHash, $ipHash, true, $now);

        return [
            'sessionToken' => $sessionToken,
            'csrfToken' => $csrfToken,
            'sessionId' => $sessionId,
            'expiresAt' => Dates::iso(Dates::database($expiresAt)),
            'expiresAtUnix' => $expiresAt->getTimestamp(),
            'profile' => $this->profiles->get((string) $user['id']),
        ];
    }

    public function logout(string $sessionId): void
    {
        $this->repository->revokeSession($sessionId, $this->clock->now());
    }

    /** @param array<string, mixed> $data */
    public function forgotPassword(array $data): void
    {
        Input::assertAllowedKeys($data, ['email']);
        $rawEmail = $data['email'] ?? '';
        $email = is_string($rawEmail) ? strtolower(trim($rawEmail)) : '';
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $user = $this->repository->findActiveUserByEmail($email);
        if (!is_array($user)) {
            PasswordHasher::verify('Rumbo-Recovery-Dummy-2026!', self::dummyPasswordHash());
            return;
        }

        $now = $this->clock->now();
        $expiresAt = $now->modify(sprintf('+%d seconds', $this->config->passwordResetTtlSeconds()));
        $token = Token::random();
        $resetId = $this->repository->createPasswordReset(
            (string) $user['id'],
            Token::hash($token),
            $now,
            $expiresAt,
        );
        try {
            $this->delivery->deliver((string) $user['email'], $token, $expiresAt);
        } catch (Throwable $exception) {
            $this->repository->invalidatePasswordReset($resetId, $now);
            throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    public function resetPassword(array $data): void
    {
        Input::assertAllowedKeys($data, ['token', 'password']);
        $token = Input::rawString($data, 'token', 32, 512);
        $password = Input::rawString($data, 'password', 12, 128);
        if (!preg_match('/^[A-Za-z0-9_-]{32,512}$/', $token)) {
            throw new ApiException(401, 'AUTH_INVALID', 'El enlace de recuperación no es válido o venció.');
        }
        try {
            $hash = PasswordHasher::hash($password);
        } catch (InvalidArgumentException $exception) {
            throw new ApiException(422, 'VALIDATION_ERROR', $exception->getMessage(), ['field' => 'password']);
        }

        if (!$this->repository->consumePasswordReset(Token::hash($token), $hash, $this->clock->now())) {
            throw new ApiException(401, 'AUTH_INVALID', 'El enlace de recuperación no es válido o venció.');
        }
    }

    public function recoveryDeliveryAvailable(): bool
    {
        return $this->delivery->isAvailable();
    }

    private function email(string $email): string
    {
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'El correo no es válido.', ['field' => 'email']);
        }

        return $email;
    }

    private static function dummyPasswordHash(): string
    {
        static $hash = null;
        if (!is_string($hash)) {
            $hash = PasswordHasher::hash('Rumbo-Dummy-Password-2026!');
        }
        return $hash;
    }
}
