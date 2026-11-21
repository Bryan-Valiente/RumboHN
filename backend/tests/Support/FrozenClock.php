<?php

declare(strict_types=1);

namespace Rumbo\Backend\Tests\Support;

use DateTimeImmutable;
use Rumbo\Backend\Support\Clock;

final class FrozenClock implements Clock
{
    public function __construct(private DateTimeImmutable $current)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->current;
    }

    public function advance(string $modifier): void
    {
        $this->current = $this->current->modify($modifier);
    }
}
