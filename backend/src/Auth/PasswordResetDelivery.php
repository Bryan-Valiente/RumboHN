<?php

declare(strict_types=1);

namespace Rumbo\Backend\Auth;

use DateTimeImmutable;

interface PasswordResetDelivery
{
    public function isAvailable(): bool;

    public function deliver(string $email, string $token, DateTimeImmutable $expiresAt): void;
}
