<?php

use App\Simulator\Engine\BuyBoxScorer;
use App\Simulator\Engine\SimOffer;
use App\Support\Fulfillment;
use App\Support\Money;

function simOffer(string $seller, int $price, int $shipping = 0, Fulfillment $f = Fulfillment::Merchant, int $rating = 95, int $handling = 1): SimOffer
{
    return new SimOffer($seller, Money::cents($price), Money::cents($shipping), $f, $rating, $handling);
}

$scorer = new BuyBoxScorer(minRating: 80, marketplaceBonusBps: 300, freeHandlingDays: 2, handlingPenaltyBpsPerDay: 100);

it('awards the lowest landed price, not the lowest item price', function () use ($scorer) {
    $r = $scorer->decide([simOffer('A', 1000, 500), simOffer('B', 1400)], null);
    expect($r->winner)->toBe('B');
});

it('gives marketplace fulfilment a bonus that can beat a slightly cheaper merchant offer', function () use ($scorer) {
    // FBA 10.20 landed − 3% (0.31) = 9.89 effective < 10.00
    $r = $scorer->decide([simOffer('MERCH', 1000), simOffer('FBA', 1020, f: Fulfillment::Marketplace)], null);
    expect($r->winner)->toBe('FBA');
});

it('keeps landed price the heaviest factor: the bonus does not beat a much cheaper offer', function () use ($scorer) {
    $r = $scorer->decide([simOffer('MERCH', 900), simOffer('FBA', 1020, f: Fulfillment::Marketplace)], null);
    expect($r->winner)->toBe('MERCH');
});

it('penalises handling time beyond the free days', function () use ($scorer) {
    $score = $scorer->score(simOffer('SLOW', 1000, handling: 5));
    // 3 extra days × 1% of 10.00 = 0.30
    expect($score->effective?->cents)->toBe(1030);
    expect($scorer->decide([simOffer('SLOW', 1000, handling: 5), simOffer('FAST', 1020)], null)->winner)->toBe('FAST');
});

it('disqualifies offers rated below the threshold regardless of price', function () use ($scorer) {
    $r = $scorer->decide([simOffer('CHEAP', 100, rating: 79), simOffer('OK', 2000, rating: 80)], null);
    expect($r->winner)->toBe('OK')
        ->and($scorer->score(simOffer('CHEAP', 100, rating: 79))->effective)->toBeNull();
});

it('suppresses the Buy Box when nobody qualifies', function () use ($scorer) {
    expect($scorer->decide([simOffer('A', 100, rating: 10)], 'A')->winner)->toBeNull();
});

it('gives ties to the incumbent to avoid flapping', function () use ($scorer) {
    $offers = [simOffer('A', 1000), simOffer('B', 1000)];
    expect($scorer->decide($offers, 'B')->winner)->toBe('B')
        ->and($scorer->decide($offers, 'A')->winner)->toBe('A');
    expect($scorer->decide($offers, 'B')->reason)->toContain('incumbent');
});

it('breaks ties without an incumbent by landed price then seller id', function () use ($scorer) {
    // Equal effective (FBA 10.31 − 0.31 = 10.00 vs merchant 10.00): lower landed wins.
    $r = $scorer->decide([simOffer('Z', 1031, f: Fulfillment::Marketplace), simOffer('Y', 1000)], null);
    expect($r->winner)->toBe('Y');
    expect($scorer->decide([simOffer('B', 1000), simOffer('A', 1000)], null)->winner)->toBe('A');
});

it('an incumbent loses as soon as a challenger is strictly better', function () use ($scorer) {
    expect($scorer->decide([simOffer('INC', 1000), simOffer('NEW', 999)], 'INC')->winner)->toBe('NEW');
});

it('rounds percentage adjustments half-up to the cent', function () use ($scorer) {
    // 3% of 10.50 = 31.5c → 32c
    expect($scorer->score(simOffer('F', 1050, f: Fulfillment::Marketplace))->effective?->cents)->toBe(1018);
});
