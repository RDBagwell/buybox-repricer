<?php

use App\Repricer\Rules\NoCompetitionAction;
use App\Repricer\Rules\PipelineState;
use App\Repricer\Rules\PricingContext;
use App\Repricer\Rules\Rule;
use App\Repricer\Rules\Rules\AlreadyWinningRule;
use App\Repricer\Rules\Rules\CompetitorFilterRule;
use App\Repricer\Rules\Rules\FloorCeilingRule;
use App\Repricer\Rules\Rules\MarginFloorRule;
use App\Repricer\Rules\Rules\NoOpRule;
use App\Repricer\Rules\Rules\ShouldActRule;
use App\Repricer\Rules\Rules\StepLimitRule;
use App\Repricer\Rules\Rules\StrategyRule;
use App\Repricer\Rules\Strategy;
use App\Repricer\Rules\Verdict;
use App\Repricer\Rules\VerdictKind;
use App\Repricer\Rules\VetoCode;
use App\Support\Money;
use Tests\Support\Ctx;

/** Evaluate one rule with the proposal so far defaulting to the current price. */
function run(Rule $rule, PricingContext $ctx, ?int $proposed = null): Verdict
{
    return $rule->evaluate($ctx, new PipelineState(Money::cents($proposed ?? $ctx->currentPrice->cents), $ctx->competitors()));
}

describe('ShouldActRule', function () {
    it('vetoes when the kill switch is on', function () {
        $v = run(new ShouldActRule, Ctx::make()->with(['killSwitch' => true])->build());
        expect($v->kind)->toBe(VerdictKind::Veto)->and($v->code)->toBe(VetoCode::KillSwitch);
    });

    it('vetoes when the product is paused', function () {
        $v = run(new ShouldActRule, Ctx::make()->with(['paused' => true])->build());
        expect($v->code)->toBe(VetoCode::Paused);
    });

    it('kill switch wins over pause', function () {
        $v = run(new ShouldActRule, Ctx::make()->with(['paused' => true, 'killSwitch' => true])->build());
        expect($v->code)->toBe(VetoCode::KillSwitch);
    });

    it('vetoes inside the cooldown and reports the remaining time', function () {
        $v = run(new ShouldActRule, Ctx::make()->with(['lastChangedAt' => '2026-01-01T11:58:00Z', 'cooldown' => 300])->build());
        expect($v->code)->toBe(VetoCode::Cooldown)->and($v->reason)->toContain('180s of 300s remaining');
    });

    it('passes exactly when the cooldown expires', function () {
        $v = run(new ShouldActRule, Ctx::make()->with(['lastChangedAt' => '2026-01-01T11:55:00Z', 'cooldown' => 300])->build());
        expect($v->kind)->toBe(VerdictKind::Pass);
    });

    it('passes with no previous change', function () {
        expect(run(new ShouldActRule, Ctx::make()->build())->kind)->toBe(VerdictKind::Pass);
    });

    it('treats a last change in the future (market clock reset) as expired', function () {
        $v = run(new ShouldActRule, Ctx::make()->with(['lastChangedAt' => '2026-01-02T00:00:00Z'])->build());
        expect($v->kind)->toBe(VerdictKind::Pass)->and($v->reason)->toContain('clock reset');
    });
});

describe('CompetitorFilterRule', function () {
    it('drops low-rated and slow competitors and explains why', function () {
        $ctx = Ctx::make()->with(['minRating' => 90, 'maxHandling' => 3])
            ->competitor('GOOD', 1400)
            ->competitor('LOWRATED', 1100, rating: 70)
            ->competitor('SLOW', 1200, handling: 10)
            ->build();
        $v = run(new CompetitorFilterRule, $ctx);

        expect($v->kind)->toBe(VerdictKind::Pass)
            ->and(array_map(fn ($o) => $o->sellerId, $v->competitors ?? []))->toBe(['GOOD'])
            ->and($v->reason)->toContain('LOWRATED (rating 70 < 90)')->toContain('SLOW (handling 10d > 3d)');
    });

    it('notes when every competitor is filtered out', function () {
        $ctx = Ctx::make()->with(['minRating' => 99])->competitor('A', 1400)->build();
        $v = run(new CompetitorFilterRule, $ctx);
        expect($v->competitors)->toBe([])->and($v->reason)->toContain('Every competitor was filtered out');
    });

    it('never proposes a price', function () {
        $v = run(new CompetitorFilterRule, Ctx::make()->competitor('A', 100)->build());
        expect($v->price)->toBeNull();
    });
});

describe('AlreadyWinningRule', function () {
    it('passes when we do not hold the Buy Box', function () {
        expect(run(new AlreadyWinningRule, Ctx::make()->competitor('A', 1400)->build())->kind)->toBe(VerdictKind::Pass);
    });

    it('raises toward the next competitor landed price minus the offset', function () {
        $ctx = Ctx::make()->with(['weHoldBuyBox' => true, 'current' => 1500])->competitor('A', 1800, shipping: 100)->build();
        $v = run(new AlreadyWinningRule, $ctx);
        // next landed 19.00 - 0.01 = 18.99
        expect($v->kind)->toBe(VerdictKind::Propose)->and($v->price?->cents)->toBe(1899);
    });

    it('accounts for our own shipping when raising', function () {
        $ctx = Ctx::make()->with(['weHoldBuyBox' => true, 'ourShipping' => 200])->competitor('A', 1900)->build();
        expect(run(new AlreadyWinningRule, $ctx)->price?->cents)->toBe(1699);
    });

    it('never cuts when winning even if a competitor is cheaper', function () {
        $ctx = Ctx::make()->with(['weHoldBuyBox' => true, 'current' => 1500])->competitor('A', 1400)->build();
        $v = run(new AlreadyWinningRule, $ctx);
        expect($v->kind)->toBe(VerdictKind::Pass)->and($v->price)->toBeNull();
    });

    it('holds with no competitors by default', function () {
        $v = run(new AlreadyWinningRule, Ctx::make()->with(['weHoldBuyBox' => true])->build());
        expect($v->kind)->toBe(VerdictKind::Pass)->and($v->reason)->toContain('No competitors');
    });

    it('raises to the ceiling with no competitors when configured', function () {
        $v = run(new AlreadyWinningRule, Ctx::make()->with(['weHoldBuyBox' => true, 'noCompetition' => NoCompetitionAction::RaiseToCeiling])->build());
        expect($v->price?->cents)->toBe(3000);
    });
});

describe('StrategyRule', function () {
    it('beats the lowest landed price by the offset', function () {
        $ctx = Ctx::make()->with(['offset' => 5])->competitor('A', 1400, 100)->competitor('B', 1450)->build();
        // lowest landed is B at 14.50 → 14.45
        expect(run(new StrategyRule, $ctx)->price?->cents)->toBe(1445);
    });

    it('matches the lowest landed price', function () {
        $ctx = Ctx::make()->with(['strategy' => Strategy::MatchLowest, 'offset' => 5])->competitor('A', 1400)->build();
        expect(run(new StrategyRule, $ctx)->price?->cents)->toBe(1400);
    });

    it('beats the Buy Box holder, not the lowest', function () {
        $ctx = Ctx::make()->with(['strategy' => Strategy::BeatBuyBox, 'offset' => 10])
            ->competitor('CHEAP', 1300)->competitor('HOLDER', 1600, buyBox: true)->build();
        expect(run(new StrategyRule, $ctx)->price?->cents)->toBe(1590);
    });

    it('falls back to lowest when no eligible competitor holds the Buy Box', function () {
        $ctx = Ctx::make()->with(['strategy' => Strategy::BeatBuyBox])->competitor('A', 1300)->build();
        $v = run(new StrategyRule, $ctx);
        expect($v->price?->cents)->toBe(1299)->and($v->reason)->toContain('no eligible Buy Box holder');
    });

    it('subtracts our shipping to compare on landed price', function () {
        $ctx = Ctx::make()->with(['ourShipping' => 300])->competitor('A', 1500, 100)->build();
        // competitor lands 16.00 → we land 15.99 → price 12.99
        expect(run(new StrategyRule, $ctx)->price?->cents)->toBe(1299);
    });

    it('reports ties on landed price and prices against the shared landed price', function () {
        $ctx = Ctx::make()->competitor('A', 1400, 100)->competitor('B', 1500)->build();
        $v = run(new StrategyRule, $ctx);
        expect($v->price?->cents)->toBe(1499)->and($v->reason)->toContain('2 offers tied at 15.00');
    });

    it('defers to already_winning when we hold the Buy Box', function () {
        $ctx = Ctx::make()->with(['weHoldBuyBox' => true])->competitor('A', 1000)->build();
        expect(run(new StrategyRule, $ctx)->kind)->toBe(VerdictKind::Pass);
    });

    it('never proposes below one cent', function () {
        $ctx = Ctx::make()->with(['ourShipping' => 500])->competitor('A', 1)->build();
        expect(run(new StrategyRule, $ctx)->price?->cents)->toBe(1);
    });
});

describe('StepLimitRule', function () {
    it('passes moves within the limit', function () {
        expect(run(new StepLimitRule, Ctx::make()->build(), 1400)->kind)->toBe(VerdictKind::Pass);
    });

    it('caps a downward move', function () {
        // 10% of 15.00 = 1.50 → 13.50
        expect(run(new StepLimitRule, Ctx::make()->build(), 1000)->price?->cents)->toBe(1350);
    });

    it('caps an upward move', function () {
        expect(run(new StepLimitRule, Ctx::make()->build(), 3000)->price?->cents)->toBe(1650);
    });

    it('rounds the cap down so it never exceeds the percentage', function () {
        // 5% of 10.01 = 50.05c → 50c
        expect(StepLimitRule::maxStep(Money::cents(1001), 5)->cents)->toBe(50);
    });

    it('allows at least one cent so cheap items can move', function () {
        expect(StepLimitRule::maxStep(Money::cents(10), 5)->cents)->toBe(1);
    });
});

describe('MarginFloorRule', function () {
    it('raises to cost + fees + min margin', function () {
        $v = run(new MarginFloorRule, Ctx::make()->build(), 600);
        expect($v->price?->cents)->toBe(750);
    });

    it('passes prices above the margin floor', function () {
        expect(run(new MarginFloorRule, Ctx::make()->build(), 1200)->kind)->toBe(VerdictKind::Pass);
    });

    it('vetoes a margin floor above the ceiling as a configuration error', function () {
        $v = run(new MarginFloorRule, Ctx::make()->with(['cost' => 3000])->build());
        expect($v->code)->toBe(VetoCode::ConfigError)->and($v->reason)->toContain('above ceiling');
    });
});

describe('FloorCeilingRule', function () {
    it('clamps up to the floor', function () {
        expect(run(new FloorCeilingRule, Ctx::make()->build(), 900)->price?->cents)->toBe(1000);
    });

    it('clamps down to the ceiling', function () {
        expect(run(new FloorCeilingRule, Ctx::make()->build(), 3100)->price?->cents)->toBe(3000);
    });

    it('passes in range', function () {
        expect(run(new FloorCeilingRule, Ctx::make()->build(), 2000)->kind)->toBe(VerdictKind::Pass);
    });

    it('vetoes a floor above the ceiling', function () {
        expect(run(new FloorCeilingRule, Ctx::make()->with(['floor' => 4000])->build())->code)->toBe(VetoCode::ConfigError);
    });
});

describe('NoOpRule', function () {
    it('vetoes when nothing changes', function () {
        expect(run(new NoOpRule, Ctx::make()->build())->code)->toBe(VetoCode::NoChange);
    });

    it('passes a change', function () {
        expect(run(new NoOpRule, Ctx::make()->build(), 1499)->kind)->toBe(VerdictKind::Pass);
    });
});
