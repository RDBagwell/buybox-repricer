<?php

namespace App\Repricer\Rules;

use App\Support\Money;

/**
 * One rule's answer: propose a price, pass it through, or veto (stop the pipeline).
 *
 * A pass-through may also narrow the competitor set seen by later rules
 * (only Filter-stage rules do this).
 */
final readonly class Verdict
{
    /**
     * @param  list<Offer>|null  $competitors
     */
    private function __construct(
        public VerdictKind $kind,
        public string $reason,
        public ?Money $price = null,
        public ?VetoCode $code = null,
        public ?array $competitors = null,
    ) {}

    public static function propose(Money $price, string $reason): self
    {
        return new self(VerdictKind::Propose, $reason, price: $price);
    }

    public static function pass(string $reason): self
    {
        return new self(VerdictKind::Pass, $reason);
    }

    /**
     * @param  list<Offer>  $competitors
     */
    public static function narrow(array $competitors, string $reason): self
    {
        return new self(VerdictKind::Pass, $reason, competitors: $competitors);
    }

    public static function veto(VetoCode $code, string $reason): self
    {
        return new self(VerdictKind::Veto, $reason, code: $code);
    }
}
