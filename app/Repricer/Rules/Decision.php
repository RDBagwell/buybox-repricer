<?php

namespace App\Repricer\Rules;

use App\Support\Money;

final readonly class Decision
{
    /**
     * @param  list<TraceEntry>  $trace
     */
    public function __construct(
        public DecisionOutcome $outcome,
        public Money $oldPrice,
        public ?Money $newPrice,
        public string $reason,
        public ?VetoCode $vetoCode,
        public array $trace,
    ) {}

    /**
     * @return list<array{rule: string, verdict: string, reason: string, price_before: int, price_after: int, code: string|null}>
     */
    public function traceArray(): array
    {
        return array_map(fn (TraceEntry $e) => $e->toArray(), $this->trace);
    }
}
