<?php

declare(strict_types=1);

namespace Rumbo\Backend\Auth;

final class AuthenticatedUser
{
    /** @param list<string> $roles */
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly string $sessionId,
        public readonly array $roles = [],
        public readonly string $credentialType = 'bearer',
        public readonly ?string $csrfToken = null,
        public readonly ?string $sessionExpiresAt = null,
    ) {
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }
}
