<?php

namespace Codr\Eventually\Doctrine\Tests\Fixture;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final readonly class FixedClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $now = new DateTimeImmutable('2026-01-02 10:30:45.123456 UTC'))
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
