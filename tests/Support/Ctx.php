<?php

namespace Tests\Support;

use App\Repricer\Rules\Flags;
use App\Repricer\Rules\NoCompetitionAction;
use App\Repricer\Rules\Offer;
use App\Repricer\Rules\PricingContext;
use App\Repricer\Rules\RuleConfig;
use App\Repricer\Rules\Strategy;
use App\Support\Fulfillment;
use App\Support\Money;
use DateTimeImmutable;

/**
 * Fluent builder for hand-built PricingContexts. Amounts are integer cents.
 *
 * Defaults: cost 5.00, fees 1.50, min margin 1.00 (margin floor 7.50), floor 10.00,
 * ceiling 30.00, current price 15.00, free shipping, beat lowest by 0.01, 10% step, 300s cooldown.
 */
final class Ctx
{
    public int $cost = 500;

    public int $fees = 150;

    public int $current = 1500;

    public int $ourShipping = 0;

    public Strategy $strategy = Strategy::BeatLowest;

    public int $offset = 1;

    public int $floor = 1000;

    public int $ceiling = 3000;

    public int $minMargin = 100;

    public int $maxStepPct = 10;

    public int $cooldown = 300;

    public int $minRating = 0;

    public int $maxHandling = 30;

    public NoCompetitionAction $noCompetition = NoCompetitionAction::Hold;

    /** @var list<Offer> */
    public array $competitors = [];

    public bool $weHoldBuyBox = false;

    public ?string $lastChangedAt = null;

    public string $now = '2026-01-01T12:00:00Z';

    public bool $killSwitch = false;

    public bool $paused = false;

    public bool $dryRun = false;

    public static function make(): self
    {
        return new self;
    }

    /**
     * @param  array<string, mixed>  $props
     */
    public function with(array $props): self
    {
        $clone = clone $this;
        foreach ($props as $k => $v) {
            $clone->{$k} = $v;
        }

        return $clone;
    }

    public function competitor(string $seller, int $price, int $shipping = 0, bool $buyBox = false, int $rating = 95, int $handling = 2, Fulfillment $fulfillment = Fulfillment::Merchant): self
    {
        $clone = clone $this;
        $clone->competitors[] = new Offer($seller, Money::cents($price), Money::cents($shipping), $fulfillment, $rating, $handling, $buyBox);

        return $clone;
    }

    public static function offer(string $seller, int $price, int $shipping = 0, bool $buyBox = false, int $rating = 95, int $handling = 2): Offer
    {
        return new Offer($seller, Money::cents($price), Money::cents($shipping), Fulfillment::Merchant, $rating, $handling, $buyBox);
    }

    public function config(): RuleConfig
    {
        return new RuleConfig(
            strategy: $this->strategy,
            offset: Money::cents($this->offset),
            floor: Money::cents($this->floor),
            ceiling: Money::cents($this->ceiling),
            minMargin: Money::cents($this->minMargin),
            maxStepPct: $this->maxStepPct,
            cooldownSeconds: $this->cooldown,
            minCompetitorRating: $this->minRating,
            maxCompetitorHandlingDays: $this->maxHandling,
            noCompetition: $this->noCompetition,
        );
    }

    public function build(): PricingContext
    {
        $ours = new Offer('US', Money::cents($this->current), Money::cents($this->ourShipping), Fulfillment::Merchant, 99, 1, $this->weHoldBuyBox, isOurs: true);

        return new PricingContext(
            sku: 'SKU-1',
            cost: Money::cents($this->cost),
            fees: Money::cents($this->fees),
            currentPrice: Money::cents($this->current),
            ourShipping: Money::cents($this->ourShipping),
            config: $this->config(),
            offers: [$ours, ...$this->competitors],
            weHoldBuyBox: $this->weHoldBuyBox,
            lastChangedAt: $this->lastChangedAt === null ? null : new DateTimeImmutable($this->lastChangedAt),
            now: new DateTimeImmutable($this->now),
            flags: new Flags($this->killSwitch, $this->dryRun, $this->paused),
        );
    }
}
