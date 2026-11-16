<?php

declare(strict_types=1);

namespace Rumbo\Backend\Auth;

use PDO;
use Rumbo\Backend\Config;
use Rumbo\Backend\Database;
use Rumbo\Backend\Http\ApiException;
use Rumbo\Backend\Http\Request;
use Rumbo\Backend\Security\Token;
use Rumbo\Backend\Support\Dates;
use Rumbo\Backend\Support\SystemClock;

final class SessionAuthenticator
{
    private readonly Config $config;

    public function __construct(private readonly Database|PDO $database, ?Config $config = null)
    {
        $this->config = $config ?? new Config();
    }

    public function authenticate(Request $request): AuthenticatedUser
    {
        $authorization = trim((string) $request->header('authorization'));
        $credentialType = 'cookie';
        $token = $request->cookie($this->config->sessionCookieName());
        if ($authorization !== '') {
            if (!preg_match('/^Bearer\s+([A-Za-z0-9._~-]{32,512})$/', $authorization, $matches)) {
                throw new ApiException(401, 'AUTH_REQUIRED', 'Se requiere una sesión válida.');
            }
            $credentialType = 'bearer';
            $token = $matches[1];
        }

        if (!is_string($token) || !preg_match('/^[A-Za-z0-9_-]{32,512}$/', $token)) {
            throw new ApiException(401, 'AUTH_REQUIRED', 'Se requiere una sesión válida.');
        }

        $now = (new SystemClock())->now()->format('Y-m-d H:i:s.u');
        $tokenHash = Token::hash($token);
        $connection = $this->connection();
        $statement = $connection->prepare(
            'SELECT s.id AS session_id, s.expires_at, u.id AS user_id, u.email
             FROM auth_sessions s
             INNER JOIN users u ON u.id = s.user_id
             WHERE s.token_hash = :token_hash
               AND s.revoked_at IS NULL
               AND s.expires_at > :now
               AND u.status = :active_status
             LIMIT 1'
        );
        $statement->bindValue(':token_hash', $tokenHash, PDO::PARAM_LOB);
        $statement->bindValue(':now', $now);
        $statement->bindValue(':active_status', 'active');
        $statement->execute();
        $record = $statement->fetch();

        if (!is_array($record)) {
            throw new ApiException(401, 'AUTH_INVALID', 'La sesión no es válida o ha vencido.');
        }

        $rolesStatement = $connection->prepare(
            'SELECT role_code FROM user_roles WHERE user_id = :user_id ORDER BY role_code'
        );
        $rolesStatement->execute([':user_id' => (string) $record['user_id']]);
        $roles = array_map(
            static fn (array $role): string => (string) $role['role_code'],
            $rolesStatement->fetchAll(),
        );

        $seenStatement = $connection->prepare(
            'UPDATE auth_sessions SET last_seen_at = :now WHERE id = :session_id'
        );
        $seenStatement->execute([':now' => $now, ':session_id' => (string) $record['session_id']]);

        $csrfToken = $credentialType === 'cookie'
            ? Token::csrf($token, $this->config->securityPepper())
            : null;

        return new AuthenticatedUser(
            (string) $record['user_id'],
            (string) $record['email'],
            (string) $record['session_id'],
            $roles,
            $credentialType,
            $csrfToken,
            Dates::iso((string) $record['expires_at']),
        );
    }

    private function connection(): PDO
    {
        return $this->database instanceof PDO ? $this->database : $this->database->connection();
    }
}
