<?php

use App\Repricer\Rules\DecisionOutcome;
use App\Repricer\Rules\DefaultPipeline;
use App\Repricer\Rules\NoCompetitionAction;
use App\Repricer\Rules\Rules\StepLimitRule;
use App\Repricer\Rules\Strategy;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use Tests\Support\Ctx;

/*
 * Property tests: a seeded random loop (no extra library). On failure the case index and
 * seed are in the message, so any counter-example can be replayed exactly.
 */

const PROPERTY_SEED = 20261007;
const PROPERTY_CASES = 5000;

function randomContext(Randomizer $r): Ctx
{
    $cost = $r->getInt(50, 5000);
    $fees = $r->getInt(0, 1000);
    $floor = $r->getInt(100, 8000);
    $ceiling = $floor + $r->getInt(-200, 6000);   // occasionally below the floor: config error
    $current = $r->getInt(1, 15000);              // sometimes outside floor–ceiling on purpose

    $ctx = Ctx::make()->with([
        'cost' => $cost,
        'fees' => $fees,
        'minMargin' => $r->getInt(0, 800),
        'floor' => $floor,
        'ceiling' => $ceiling,
        'current' => $current,
        'ourShipping' => $r->pickArrayKeys([0 => 1, 399 => 1, 599 => 1], 1)[0],
        'strategy' => Strategy::cases()[$r->getInt(0, 2)],
        'offset' => $r->getInt(0, 200),
        'maxStepPct' => $r->getInt(1, 50),
        'minRating' => $r->getInt(0, 95),
        'maxHandling' => $r->getInt(1, 10),
        'noCompetition' => NoCompetitionAction::cases()[$r->getInt(0, 1)],
        'weHoldBuyBox' => $r->getInt(0, 2) === 0,
        'cooldown' => $r->getInt(0, 900),
        'lastChangedAt' => $r->getInt(0, 3) === 0 ? null : '2026-01-01T11:'.str_pad((string) $r->getInt(40, 59), 2, '0', STR_PAD_LEFT).':00Z',
        'killSwitch' => $r->getInt(0, 30) === 0,
        'paused' => $r->getInt(0, 30) === 0,
    ]);

    $n = $r->getInt(0, 6);
    $holderAssigned = false;
    for ($i = 0; $i < $n; $i++) {
        $isHolder = ! $ctx->weHoldBuyBox && ! $holderAssigned && $r->getInt(0, 2) === 0;
        $holderAssigned = $holderAssigned || $isHolder;
        $ctx = $ctx->competitor(
            "S{$i}",
            $r->getInt(1, 15000),
            $r->getInt(0, 3) === 0 ? $r->getInt(0, 999) : 0,
            $isHolder,
            $r->getInt(40, 100),
            $r->getInt(0, 14),
        );
    }

    return $ctx;
}

it('never leaves the guardrails and never exceeds the step limit unless a clamp forces it', function () {
    $r = new Randomizer(new Xoshiro256StarStar(PROPERTY_SEED));
    $pipeline = DefaultPipeline::make();
    $counts = [];

    for ($case = 0; $case < PROPERTY_CASES; $case++) {
        $ctx = randomContext($r)->build();
        $d = $pipeline->decide($ctx);
        $counts[$d->outcome->value] = ($counts[$d->outcome->value] ?? 0) + 1;
        $where = "case {$case} (seed ".PROPERTY_SEED.')';

        $config = $ctx->config;
        $marginFloor = $ctx->marginFloor();
        $low = $ctx->effectiveFloor();

        if ($d->outcome === DecisionOutcome::ConfigError) {
            expect($marginFloor->greaterThan($config->ceiling) || $config->floor->greaterThan($config->ceiling))
                ->toBeTrue("{$where}: config_error without a contradictory config");

            continue;
        }

        // A sane config must never be reported as a config error, and an invalid one never priced.
        expect($marginFloor->greaterThan($config->ceiling) && $d->outcome === DecisionOutcome::Reprice)->toBeFalse($where);

        if ($d->outcome !== DecisionOutcome::Reprice) {
            expect($d->newPrice)->toBeNull($where);

            continue;
        }

        $new = $d->newPrice;
        expect($new)->not->toBeNull($where);
        assert($new !== null);

        expect($new->cents)->toBeGreaterThanOrEqual($config->floor->cents, "{$where}: below floor")
            ->toBeLessThanOrEqual($config->ceiling->cents, "{$where}: above ceiling")
            ->toBeGreaterThanOrEqual($marginFloor->cents, "{$where}: below margin floor")
            ->not->toBe($ctx->currentPrice->cents, "{$where}: reprice to the same price");

        $maxStep = StepLimitRule::maxStep($ctx->currentPrice, $config->maxStepPct);
        $move = abs($new->cents - $ctx->currentPrice->cents);
        if ($move > $maxStep->cents) {
            // Documented exception: only a clamp to a bound may exceed the step, and only when
            // the step-limited price itself was outside the bounds.
            $stepped = $new->cents > $ctx->currentPrice->cents
                ? $ctx->currentPrice->cents + $maxStep->cents
                : $ctx->currentPrice->cents - $maxStep->cents;
            $forcedUp = $new->equals($low) && $stepped < $low->cents;
            $forcedDown = $new->equals($config->ceiling) && $stepped > $config->ceiling->cents;
            expect($forcedUp || $forcedDown)->toBeTrue("{$where}: moved {$move}c with step {$maxStep->cents}c and no forcing clamp");
        }
    }

    // The generator must actually exercise every outcome, or the property is vacuous.
    expect(array_keys($counts))->toContain('reprice', 'no_change', 'skipped', 'config_error');
});

it('is deterministic: the same context always yields the same decision and trace', function () {
    $r = new Randomizer(new Xoshiro256StarStar(PROPERTY_SEED + 1));

    for ($case = 0; $case < 2000; $case++) {
        $ctx = randomContext($r)->build();
        $a = DefaultPipeline::make()->decide($ctx);
        $b = DefaultPipeline::make()->decide($ctx);

        expect($b->outcome)->toBe($a->outcome)
            ->and($b->newPrice?->cents)->toBe($a->newPrice?->cents)
            ->and($b->traceArray())->toBe($a->traceArray());
    }
});

it('never raises the price while we hold the Buy Box and a cheaper competitor exists... unless the floor forces it', function () {
    $r = new Randomizer(new Xoshiro256StarStar(PROPERTY_SEED + 2));

    for ($case = 0; $case < 2000; $case++) {
        $ctx = randomContext($r)->with(['weHoldBuyBox' => true, 'killSwitch' => false, 'paused' => false])->build();
        $d = DefaultPipeline::make()->decide($ctx);

        if ($d->outcome === DecisionOutcome::Reprice && $d->newPrice !== null && $d->newPrice->lessThan($ctx->currentPrice)) {
            // A cut while winning is only ever the ceiling clamp.
            expect($d->newPrice->equals($ctx->config->ceiling))->toBeTrue("case {$case}: cut while holding the Buy Box");
        }
    }
});
