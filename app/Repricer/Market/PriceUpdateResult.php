<?php

namespace App\Repricer\Market;

use DateTimeImmutable;

final readonly class PriceUpdateResult
{
    /**
     * @param  array<string, mixed>  $raw  the response body as received (for the audit log)
     */
    public function __construct(
        public string $submissionId,
        public string $status,
        public DateTimeImmutable $appliedAt,
        public bool $replayed,
        public array $raw,
    ) {}
}
