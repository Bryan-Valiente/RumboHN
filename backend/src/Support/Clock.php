<?php

declare(strict_types=1);

namespace Rumbo\Backend\Support;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
