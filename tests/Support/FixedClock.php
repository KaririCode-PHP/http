<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Support;

use Psr\Clock\ClockInterface;

/**
 * A clock that always reads the instant it was given, so validity checks
 * do not depend on the day the suite runs.
 */
final readonly class FixedClock implements ClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct(string $now)
    {
        $this->now = new \DateTimeImmutable($now, new \DateTimeZone('UTC'));
    }

    #[\Override]
    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}
