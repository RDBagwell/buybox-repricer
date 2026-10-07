<?php

use App\Simulator\Engine\Bots\Anchor;
use App\Simulator\Engine\Bots\BotContext;
use App\Simulator\Engine\Bots\BotRegistry;
use App\Simulator\Engine\Bots\PennyPincher;
use App\Simulator\Engine\Listing;
use App\Simulator\Engine\SeededRng;
use App\Simulator\Engine\SimOffer;
use App\Support\Fulfillment;
use App\Support\Money;

function botCtx(Listing $listing, string $self, array $params, ?SeededRng $rng = null): BotContext
{
    $offer = $listing->offer($self);
    assert($offer !== null);

    return new BotContext($listing, $offer, $params, [], 1, new DateTimeImmutable('2026-01-01'), $rng ?? SeededRng::fromSeed(1));
}

function listingOf(?string $buyBox, SimOffer ...$offers): Listing
{
    $byId = [];
    foreach ($offers as $o) {
        $byId[$o->sellerId] = $o;
    }

    return new Listing('B0TEST', 'Test', $byId, $buyBox);
}

function o(string $seller, int $price, int $shipping = 0): SimOffer
{
    return new SimOffer($seller, Money::cents($price), Money::cents($shipping), Fulfillment::Merchant, 95, 1);
}

describe('PennyPincher', function () {
    it('undercuts the Buy Box holder landed price by one cent', function () {
        $l = listingOf('HOLDER', o('HOLDER', 1500, 200), o('PP', 2000, 100));
        $a = (new PennyPincher)->act(botCtx($l, 'PP', ['floor' => 100, 'react_bps' => 10000]));
        // holder lands 17.00 → PP lands 16.99 → price 15.99 with its 1.00 shipping
        expect($a->newPrice?->cents)->toBe(1599);
    });

    it('stops at its own floor', function () {
        $l = listingOf('HOLDER', o('HOLDER', 1000), o('PP', 1300));
        $a = (new PennyPincher)->act(botCtx($l, 'PP', ['floor' => 1200, 'react_bps' => 10000]));
        expect($a->newPrice?->cents)->toBe(1200);

        $atFloor = listingOf('HOLDER', o('HOLDER', 1000), o('PP', 1200));
        expect((new PennyPincher)->act(botCtx($atFloor, 'PP', ['floor' => 1200, 'react_bps' => 10000]))->newPrice)->toBeNull();
    });

    it('holds while it holds the Buy Box', function () {
        $l = listingOf('PP', o('PP', 1000), o('X', 2000));
        expect((new PennyPincher)->act(botCtx($l, 'PP', ['floor' => 1, 'react_bps' => 10000]))->newPrice)->toBeNull();
    });

    it('undercuts the lowest offer when the Buy Box is suppressed', function () {
        $l = listingOf(null, o('X', 900), o('PP', 2000));
        expect((new PennyPincher)->act(botCtx($l, 'PP', ['floor' => 1, 'react_bps' => 10000]))->newPrice?->cents)->toBe(899);
    });

    it('reacts only as often as react_bps allows, using the seeded RNG', function () {
        $l = listingOf('HOLDER', o('HOLDER', 1500), o('PP', 2000));
        $rng = SeededRng::fromSeed(42);
        $reacted = 0;
        for ($i = 0; $i < 1000; $i++) {
            $reacted += (new PennyPincher)->act(botCtx($l, 'PP', ['floor' => 1, 'react_bps' => 3000], $rng))->newPrice !== null ? 1 : 0;
        }
        expect($reacted)->toBeGreaterThan(250)->toBeLessThan(350);
    });
});

describe('Anchor', function () {
    it('holds its anchor price', function () {
        $l = listingOf(null, o('A', 1234), o('X', 100));
        expect((new Anchor)->act(botCtx($l, 'A', ['price' => 1234]))->newPrice)->toBeNull();
    });

    it('returns to its anchor if moved', function () {
        $l = listingOf(null, o('A', 999));
        expect((new Anchor)->act(botCtx($l, 'A', ['price' => 1234]))->newPrice?->cents)->toBe(1234);
    });
});

it('resolves bots by key and rejects unknown ones', function () {
    $r = new BotRegistry;
    expect($r->get('penny_pincher'))->toBeInstanceOf(PennyPincher::class)
        ->and($r->keys())->toBe(['penny_pincher', 'anchor', 'matcher', 'sleeper', 'chaos']);
    $r->get('nope');
})->throws(InvalidArgumentException::class);
