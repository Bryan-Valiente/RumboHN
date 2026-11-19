<?php

declare(strict_types=1);

namespace Rumbo\Backend\Auth;

use Rumbo\Backend\Http\ApiException;
use Rumbo\Backend\Support\Clock;
use Rumbo\Backend\Support\SystemClock;

final class AccountActivator
{
    private readonly Clock $clock;

    public function __construct(private readonly AuthRepository $repository, ?Clock $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    public function activate(string $userId): void
    {
        if (!$this->repository->activateAccount($userId, $this->clock->now())) {
            throw new ApiException(409, 'CONFLICT', 'La cuenta no puede activarse en su estado actual.');
        }
    }
}
