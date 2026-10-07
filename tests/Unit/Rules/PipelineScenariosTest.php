<?php

use App\Repricer\Rules\Decision;
use App\Repricer\Rules\DecisionOutcome;
use App\Repricer\Rules\DefaultPipeline;
use App\Repricer\Rules\NoCompetitionAction;
use App\Repricer\Rules\Strategy;
use Tests\Support\Ctx;

function decide(Ctx $ctx): Decision
{
    return DefaultPipeline::make()->decide($ctx->build());
}

/** @return list<string> rule names whose verdict changed or vetoed, for asserting the trace */
function tracedRule(Decision $d, string $rule): array
{
    foreach ($d->traceArray() as $row) {
        if ($row['rule'] === $rule) {
            return $row;
        }
    }
    throw new RuntimeException("rule {$rule} not in trace");
}

/*
 * Table-driven scenarios. Each row: a context, the expected outcome and final price (cents),
 * and the rule whose reason must explain the result.
 */
dataset('scenarios', [
    'competitor at $10.00, floor $12.00 → $12.00 with a floor reason' => [
        fn () => Ctx::make()->with(['floor' => 1200, 'current' => 1250])->competitor('A', 1000),
        DecisionOutcome::Reprice, 1200, 'floor_ceiling', 'to floor 12.00',
    ],
    'beat lowest by a cent' => [
        fn () => Ctx::make()->competitor('A', 1450),
        DecisionOutcome::Reprice, 1449, 'strategy', 'beat_lowest',
    ],
    'match lowest' => [
        fn () => Ctx::make()->with(['strategy' => Strategy::MatchLowest])->competitor('A', 1450),
        DecisionOutcome::Reprice, 1450, 'strategy', 'match_lowest',
    ],
    'beat Buy Box holder on landed price' => [
        fn () => Ctx::make()->with(['strategy' => Strategy::BeatBuyBox, 'offset' => 25])
            ->competitor('LOW', 1300, buyBox: false, rating: 60)
            ->competitor('BB', 1400, 100, buyBox: true),
        DecisionOutcome::Reprice, 1475, 'strategy', 'Buy Box holder',
    ],
    'step limit damps a big cut' => [
        fn () => Ctx::make()->competitor('A', 1100),
        DecisionOutcome::Reprice, 1350, 'step_limit', 'exceeds step limit',
    ],
    'margin floor beats a cheaper competitor' => [
        fn () => Ctx::make()->with(['floor' => 100, 'current' => 800, 'maxStepPct' => 50])->competitor('A', 500),
        DecisionOutcome::Reprice, 750, 'margin_floor', 'margin floor 7.50',
    ],
    'already winning raises toward next competitor' => [
        fn () => Ctx::make()->with(['weHoldBuyBox' => true])->competitor('A', 1600),
        DecisionOutcome::Reprice, 1599, 'already_winning', 'raising toward next competitor',
    ],
    'already winning never cuts' => [
        fn () => Ctx::make()->with(['weHoldBuyBox' => true])->competitor('A', 1200),
        DecisionOutcome::NoChange, null, 'already_winning', 'leaves no room to raise',
    ],
    'ceiling caps a raise' => [
        fn () => Ctx::make()->with(['weHoldBuyBox' => true, 'current' => 2900, 'ceiling' => 3000])->competitor('A', 5000),
        DecisionOutcome::Reprice, 3000, 'floor_ceiling', 'to ceiling 30.00',
    ],
    'edge: no competitors, hold' => [
        fn () => Ctx::make(),
        DecisionOutcome::NoChange, null, 'strategy', 'No competitors on the listing',
    ],
    'edge: no competitors, raise toward ceiling (step limited)' => [
        fn () => Ctx::make()->with(['noCompetition' => NoCompetitionAction::RaiseToCeiling]),
        DecisionOutcome::Reprice, 1650, 'strategy', 'raising toward ceiling',
    ],
    'edge: every competitor filtered out' => [
        fn () => Ctx::make()->with(['minRating' => 90])->competitor('A', 1000, rating: 50)->competitor('B', 1100, handling: 40),
        DecisionOutcome::NoChange, null, 'strategy', 'Every competitor was filtered out',
    ],
    'edge: floor above every competitor price' => [
        fn () => Ctx::make()->with(['floor' => 2000, 'current' => 2000])->competitor('A', 1500)->competitor('B', 1600),
        DecisionOutcome::NoChange, null, 'floor_ceiling', 'to floor 20.00',
    ],
    'edge: margin floor above ceiling is a config error' => [
        fn () => Ctx::make()->with(['cost' => 2800, 'fees' => 300])->competitor('A', 1400),
        DecisionOutcome::ConfigError, null, 'margin_floor', 'Configuration error',
    ],
    'edge: floor above ceiling is a config error' => [
        fn () => Ctx::make()->with(['floor' => 3500])->competitor('A', 1400),
        DecisionOutcome::ConfigError, null, 'floor_ceiling', 'Configuration error',
    ],
    'edge: we are the only seller (hold)' => [
        fn () => Ctx::make()->with(['weHoldBuyBox' => true]),
        DecisionOutcome::NoChange, null, 'already_winning', 'No competitors',
    ],
    'edge: tie on landed price is beaten by the offset' => [
        fn () => Ctx::make()->competitor('A', 1400, 100)->competitor('B', 1500),
        DecisionOutcome::Reprice, 1499, 'strategy', '2 offers tied at 15.00',
    ],
    'edge: match strategy on a tie lands on the tie' => [
        fn () => Ctx::make()->with(['strategy' => Strategy::MatchLowest])->competitor('A', 1400, 100)->competitor('B', 1500),
        DecisionOutcome::NoChange, null, 'strategy', 'tied',
    ],
    'kill switch skips' => [
        fn () => Ctx::make()->with(['killSwitch' => true])->competitor('A', 1000),
        DecisionOutcome::Skipped, null, 'should_act', 'kill switch',
    ],
    'paused product skips' => [
        fn () => Ctx::make()->with(['paused' => true])->competitor('A', 1000),
        DecisionOutcome::Skipped, null, 'should_act', 'paused',
    ],
    'cooldown skips' => [
        fn () => Ctx::make()->with(['lastChangedAt' => '2026-01-01T11:59:00Z'])->competitor('A', 1000),
        DecisionOutcome::Skipped, null, 'should_act', 'Cooldown active',
    ],
    'current price below floor is lifted even past the step limit (documented)' => [
        fn () => Ctx::make()->with(['current' => 500, 'floor' => 1000])->competitor('A', 400),
        DecisionOutcome::Reprice, 1000, 'floor_ceiling', 'to floor 10.00',
    ],
]);

it('decides as specified', function (Closure $ctx, DecisionOutcome $outcome, ?int $price, string $rule, string $reason) {
    $d = decide($ctx());

    expect($d->outcome)->toBe($outcome)
        ->and($d->newPrice?->cents)->toBe($price)
        ->and(strtolower(tracedRule($d, $rule)['reason']))->toContain(strtolower($reason));
})->with('scenarios');

it('records every rule it ran, in order, stopping at a veto', function () {
    $d = decide(Ctx::make()->competitor('A', 1450));
    expect(array_column($d->traceArray(), 'rule'))->toBe(DefaultPipeline::make()->ruleNames());

    $vetoed = decide(Ctx::make()->with(['killSwitch' => true]));
    expect(array_column($vetoed->traceArray(), 'rule'))->toBe(['should_act']);
});

it('records price before and after each rule', function () {
    $d = decide(Ctx::make()->competitor('A', 1100));
    $step = tracedRule($d, 'step_limit');
    expect($step['price_before'])->toBe(1099)->and($step['price_after'])->toBe(1350);
});
