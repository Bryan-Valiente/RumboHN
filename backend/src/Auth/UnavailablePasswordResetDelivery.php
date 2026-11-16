<?php

declare(strict_types=1);

namespace Rumbo\Backend\Auth;

use DateTimeImmutable;
use RuntimeException;

final class UnavailablePasswordResetDelivery implements PasswordResetDelivery
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function deliver(string $email, string $token, DateTimeImmutable $expiresAt): void
    {
        throw new RuntimeException('No hay un transporte de recuperación configurado.');
    }
}
