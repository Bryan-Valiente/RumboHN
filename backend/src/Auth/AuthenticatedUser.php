<?php

declare(strict_types=1);

namespace Rumbo\Backend\Auth;

final class AuthenticatedUser
{
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly string $sessionId,
    ) {
    }
}
