<?php

namespace App\Repricer\Outbound;

final readonly class PushAttempt
{
    private function __construct(
        public bool $finished,
        public ?int $retryInMs,
        public string $note,
    ) {}

    public static function done(string $note): self
    {
        return new self(true, null, $note);
    }

    public static function retryIn(int $ms, string $note): self
    {
        return new self(false, $ms, $note);
    }
}
