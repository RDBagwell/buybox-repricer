<?php

use App\Simulator\Engine\Bots\BotContext;
use App\Simulator\Engine\Bots\BotRegistry;
use App\Simulator\Engine\Bots\Chaos;
use App\Simulator\Engine\Bots\Matcher;
use App\Simulator\Engine\Bots\Sleeper;
use App\Simulator\Engine\BuyBoxScorer;
use App\Simulator\Engine\Listing;
use App\Simulator\Engine\SeededRng;
use App\Simulator\Engine\SimClock;
use App\Simulator\Engine\SimOffer;
use App\Simulator\Engine\Simulation;
use App\Simulator\Engine\World;
use App\Support\Fulfillment;
use App\Support\Money;

function nb(string $seller, int $price, int $shipping = 0, ?string $bot = null, array $params = [], array $memory = []): SimOffer
{
    return new SimOffer($seller, Money::cents($price), Money::cents($shipping), Fulfillment::Merchant, 95, 1, null, $bot, $params, $memory);
}

function nbListing(?string $buyBox, SimOffer ...$offers): Listing
{
    $byId = [];
    foreach ($offers as $o) {
        $byId[$o->sellerId] = $o;
    }

    return new Listing('B0NEW', 'New', $byId, $buyBox);
}

function nbCtx(Listing $l, string $self, int $tick = 1, ?SeededRng $rng = null): BotContext
{
    $o = $l->offer($self);
    assert($o !== null);

    return new BotContext($l, $o, $o->botParams, $o->botMemory, $tick, new DateTimeImmutable('2026-01-01'), $rng ?? SeededRng::fromSeed(3));
}

function oneListingWorld(Listing $l, int $seed = 1): World
{
    return new World([$l->asin => $l], SimClock::at('2026-01-01T00:00:00Z', 15), SeededRng::fromSeed($seed));
}

describe('Matcher', function () {
    it('matches the lowest landed price, accounting for its own shipping', function () {
        $l = nbListing('A', nb('A', 1500, 200), nb('M', 2000, 100, 'matcher', ['floor' => 100]));
        expect((new Matcher)->act(nbCtx($l, 'M'))->newPrice?->cents)->toBe(1600); // 17.00 landed - 1.00 shipping
    });

    it('never goes below its floor', function () {
        $l = nbListing('A', nb('A', 900), nb('M', 2000, 0, 'matcher', ['floor' => 1200]));
        expect((new Matcher)->act(nbCtx($l, 'M'))->newPrice?->cents)->toBe(1200);
    });

    it('holds when already matching', function () {
        $l = nbListing('A', nb('A', 1500), nb('M', 1500, 0, 'matcher'));
        expect((new Matcher)->act(nbCtx($l, 'M'))->newPrice)->toBeNull();
    });

    it('tests incumbent advantage: matching the holder does not take the Buy Box', function () {
        $l = nbListing(null, nb('INCUMBENT', 1500), nb('M', 1800, 0, 'matcher', ['every' => 1]));
        $l->buyBoxSellerId = 'INCUMBENT';
        $world = oneListingWorld($l);
        (new Simulation(new BuyBoxScorer, new BotRegistry))->tick($world);

        expect($l->offer('M')?->price->cents)->toBe(1500)
            ->and($l->buyBoxSellerId)->toBe('INCUMBENT');
    });
});

describe('Sleeper', function () {
    it('sells at its price while awake', function () {
        $l = nbListing('S', nb('S', 1400, 0, 'sleeper', ['price' => 1300, 'awake_ticks' => 5]));
        $a = (new Sleeper)->act(nbCtx($l, 'S', 10));
        expect($a->newPrice?->cents)->toBe(1300)->and($a->memory['awake_since'] ?? null)->toBe(10);
    });

    it('goes out of stock after awake_ticks, then the engine restocks it after asleep_ticks', function () {
        $l = nbListing('US', nb('US', 2000), nb('S', 1500, 0, 'sleeper', ['awake_ticks' => 3, 'asleep_ticks' => 4]));
        $world = oneListingWorld($l);
        $sim = new Simulation(new BuyBoxScorer, new BotRegistry);

        $stock = [];
        $winners = [];
        for ($i = 0; $i < 12; $i++) {
            $r = $sim->tick($world);
            $stock[] = $l->offer('S')?->inStock;
            $winners[] = $l->buyBoxSellerId;
            if ($i === 3) {
                // While it is out of stock it is invisible to notifications.
                expect(array_column($r->events[0]->offers ?? [['seller' => 'S']], 'seller'))->not->toContain('S');
            }
        }

        // awake ticks 1-3, sold out at 4 for 4 ticks, restocked at 8, awake 9-11, sold out at 12
        expect($stock)->toBe([true, true, true, false, false, false, false, true, true, true, true, false])
            ->and($winners[4])->toBe('US')   // the Buy Box falls to us while it is gone…
            ->and($winners[8])->toBe('S');   // …and it takes it back when it returns cheaper
    });
});

describe('Chaos', function () {
    it('moves randomly within its band, reproducibly from the seed', function () {
        $run = function (int $seed): array {
            $rng = SeededRng::fromSeed($seed);
            $l = nbListing(null, nb('C', 1500, 0, 'chaos', ['min' => 1000, 'max' => 2000, 'react_bps' => 10000]));
            $out = [];
            for ($i = 0; $i < 200; $i++) {
                $a = (new Chaos)->act(nbCtx($l, 'C', $i, $rng));
                if ($a->newPrice !== null) {
                    $out[] = $a->newPrice->cents;
                    $l->put($l->offer('C')?->withPrice($a->newPrice) ?? throw new RuntimeException);
                }
            }

            return $out;
        };

        $a = $run(9);
        expect($a)->not->toBeEmpty()
            ->and(min($a))->toBeGreaterThanOrEqual(1000)
            ->and(max($a))->toBeLessThanOrEqual(2000)
            ->and($run(9))->toBe($a)
            ->and($run(10))->not->toBe($a);
    });

    it('respects react_bps', function () {
        $l = nbListing(null, nb('C', 1500, 0, 'chaos', ['min' => 1000, 'max' => 2000, 'react_bps' => 0]));
        expect((new Chaos)->act(nbCtx($l, 'C'))->newPrice)->toBeNull();
    });
});

it('restocks a manually stocked-out offer at its restock tick', function () {
    $l = nbListing('A', nb('A', 1000, 0, 'anchor'));
    $world = oneListingWorld($l);
    $sim = new Simulation(new BuyBoxScorer, new BotRegistry);
    $l->put($sim->outOfStock($l->offer('A') ?? throw new RuntimeException, 3));

    $sim->tick($world); // tick 1
    expect($l->offer('A')?->inStock)->toBeFalse();
    $sim->tick($world); // tick 2
    $r = $sim->tick($world); // tick 3: back
    expect($l->offer('A')?->inStock)->toBeTrue()
        ->and($r->events[0]->changeType)->toBe('competitor_stock');
});
