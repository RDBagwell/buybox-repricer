<?php

namespace App\Repricer\Rules;

use App\Support\Money;

final readonly class TraceEntry
{
    public function __construct(
        public string $rule,
        public VerdictKind $verdict,
        public string $reason,
        public Money $priceBefore,
        public Money $priceAfter,
        public ?VetoCode $code = null,
    ) {}

    /**
     * @return array{rule: string, verdict: string, reason: string, price_before: int, price_after: int, code: string|null}
     */
    public function toArray(): array
    {
        return [
            'rule' => $this->rule,
            'verdict' => $this->verdict->value,
            'reason' => $this->reason,
            'price_before' => $this->priceBefore->cents,
            'price_after' => $this->priceAfter->cents,
            'code' => $this->code?->value,
        ];
    }
}
