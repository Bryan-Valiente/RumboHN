<?php

declare(strict_types=1);

namespace Rumbo\Backend\Auth;

use Rumbo\Backend\Database;
use Rumbo\Backend\Http\ApiException;
use Rumbo\Backend\Http\Request;

final class SessionAuthenticator
{
    public function __construct(private readonly Database $database)
    {
    }

    public function authenticate(Request $request): AuthenticatedUser
    {
        $authorization = trim((string) $request->header('authorization'));
        if (!preg_match('/^Bearer\s+([A-Za-z0-9._~-]{32,512})$/', $authorization, $matches)) {
            throw new ApiException(401, 'AUTH_REQUIRED', 'Se requiere una sesión válida.');
        }

        $tokenHash = hash('sha256', $matches[1], true);
        $statement = $this->database->connection()->prepare(
            'SELECT s.id AS session_id, u.id AS user_id, u.email
             FROM auth_sessions s
             INNER JOIN users u ON u.id = s.user_id
             WHERE s.token_hash = :token_hash
               AND s.revoked_at IS NULL
               AND s.expires_at > UTC_TIMESTAMP(6)
               AND u.status = :active_status
             LIMIT 1'
        );
        $statement->bindValue(':token_hash', $tokenHash, PDO::PARAM_LOB);
        $statement->bindValue(':active_status', 'active');
        $statement->execute();
        $record = $statement->fetch();

        if (!is_array($record)) {
            throw new ApiException(401, 'AUTH_INVALID', 'La sesión no es válida o ha vencido.');
        }

        return new AuthenticatedUser(
            (string) $record['user_id'],
            (string) $record['email'],
            (string) $record['session_id'],
        );
    }
}
