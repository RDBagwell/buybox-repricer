<?php

namespace App\Simulator\Engine;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Market time. Advances only when the simulation ticks; never reads the wall clock.
 */
final class SimClock
{
    public function __construct(
        public int $nowMs,
        public readonly int $tickMs,
    ) {}

    public static function at(string $iso, int $tickSeconds): self
    {
        $start = new DateTimeImmutable($iso, new DateTimeZone('UTC'));

        return new self($start->getTimestamp() * 1000, $tickSeconds * 1000);
    }

    public function advance(): void
    {
        $this->nowMs += $this->tickMs;
    }

    public function now(): DateTimeImmutable
    {
        return self::fromMs($this->nowMs);
    }

    public static function fromMs(int $ms): DateTimeImmutable
    {
        $seconds = intdiv($ms, 1000);
        $millis = $ms % 1000;

        return (new DateTimeImmutable('@'.$seconds))
            ->setTimezone(new DateTimeZone('UTC'))
            ->modify('+'.$millis.' milliseconds');
    }
}
