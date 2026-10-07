<?php

use App\Support\Money;
use App\Support\Rounding;

it('parses decimal strings without floats', function (string $in, int $cents) {
    expect(Money::parse($in)->cents)->toBe($cents);
})->with([
    ['12.34', 1234], ['12', 1200], ['0.5', 50], ['0.05', 5], ['-3.10', -310], ['0', 0],
]);

it('rejects malformed amounts', function (string $in) {
    Money::parse($in);
})->throws(InvalidArgumentException::class)->with(['1.234', 'abc', '1,00', '']);

it('formats cents', function (int $cents, string $out) {
    expect(Money::cents($cents)->format())->toBe($out);
})->with([[1234, '12.34'], [5, '0.05'], [-5, '-0.05'], [100, '1.00'], [0, '0.00']]);

it('does arithmetic and comparisons', function () {
    $a = Money::cents(1000);
    $b = Money::cents(250);
    expect($a->plus($b)->cents)->toBe(1250)
        ->and($a->minus($b)->cents)->toBe(750)
        ->and($b->minus($a)->isNegative())->toBeTrue()
        ->and(Money::max($a, $b))->toEqual($a)
        ->and(Money::min($a, $b))->toEqual($b)
        ->and(Money::cents(5)->clamp(Money::cents(10), Money::cents(20))->cents)->toBe(10)
        ->and(Money::cents(25)->clamp(Money::cents(10), Money::cents(20))->cents)->toBe(20);
});

it('refuses to clamp to an inverted range', function () {
    Money::cents(5)->clamp(Money::cents(20), Money::cents(10));
})->throws(InvalidArgumentException::class);

it('rounds percentages explicitly', function (int $cents, int $bps, Rounding $mode, int $expected) {
    expect(Money::cents($cents)->percentage($bps, $mode)->cents)->toBe($expected);
})->with([
    // 5% of 10.01 = 50.05 cents
    [1001, 500, Rounding::Down, 50],
    [1001, 500, Rounding::Up, 51],
    [1001, 500, Rounding::HalfUp, 50],
    // 5% of 10.10 = 50.5 cents: the half case
    [1010, 500, Rounding::HalfUp, 51],
    [1010, 500, Rounding::Down, 50],
    [1010, 500, Rounding::Up, 51],
    // exact
    [2000, 500, Rounding::Up, 100],
    // negatives: Down is toward -inf, Up toward +inf, HalfUp away from zero
    [-1010, 500, Rounding::Down, -51],
    [-1010, 500, Rounding::Up, -50],
    [-1010, 500, Rounding::HalfUp, -51],
    [-1001, 500, Rounding::HalfUp, -50],
]);

it('serialises to JSON as integer cents', function () {
    expect(json_encode(['p' => Money::cents(1999)]))->toBe('{"p":1999}');
});

it('divides with each rounding mode consistently against a brute-force oracle', function () {
    mt_srand(7);
    for ($i = 0; $i < 2000; $i++) {
        $n = mt_rand(-100000, 100000);
        $d = mt_rand(1, 997);
        // Oracle via exact integer reasoning on floor division.
        $floor = intdiv($n - (($n % $d + $d) % $d), $d);
        $ceil = ($n % $d === 0) ? $floor : $floor + 1;
        expect(Money::divide($n, $d, Rounding::Down))->toBe($floor)
            ->and(Money::divide($n, $d, Rounding::Up))->toBe($ceil);
        $half = Money::divide($n, $d, Rounding::HalfUp);
        expect(abs($half * $d - $n) * 2)->toBeLessThanOrEqual($d);
    }
});
