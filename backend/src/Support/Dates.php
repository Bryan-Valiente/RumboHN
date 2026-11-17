<?php

declare(strict_types=1);

namespace Rumbo\Backend\Support;

use DateTimeImmutable;
use DateTimeZone;

final class Dates
{
    public static function database(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    public static function iso(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
