<?php

declare(strict_types=1);

namespace Rumbo\Backend\Tests\Support;

use DateTimeImmutable;
use Rumbo\Backend\Auth\PasswordResetDelivery;

final class FakePasswordResetDelivery implements PasswordResetDelivery
{
    /** @var list<array{email: string, token: string, expiresAt: DateTimeImmutable}> */
    public array $deliveries = [];

    public function isAvailable(): bool
    {
        return true;
    }

    public function deliver(string $email, string $token, DateTimeImmutable $expiresAt): void
    {
        $this->deliveries[] = ['email' => $email, 'token' => $token, 'expiresAt' => $expiresAt];
    }
}
