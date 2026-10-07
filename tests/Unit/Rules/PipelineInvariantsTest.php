<?php

use App\Repricer\Rules\DefaultPipeline;
use App\Repricer\Rules\InvalidPipeline;
use App\Repricer\Rules\Pipeline;
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
use App\Repricer\Rules\Stage;
use App\Repricer\Rules\Verdict;
use App\Support\Money;
use Tests\Support\Ctx;

it('runs the guardrails after every rule that can change the price', function () {
    $rules = array_map(fn (string $c) => new $c, DefaultPipeline::ruleClasses());
    $stages = array_map(fn (Rule $r) => $r->stage(), $rules);

    $lastShaping = max(array_keys(array_filter($stages, fn (Stage $s) => in_array($s, [Stage::Shaping, Stage::Damping], true))));
    $guardrails = array_keys(array_filter($stages, fn (Stage $s) => $s === Stage::Guardrail));

    expect(min($guardrails))->toBeGreaterThan($lastShaping)
        ->and(array_slice(array_map(fn (Rule $r) => $r::class, $rules), -3, 2))->toBe([MarginFloorRule::class, FloorCeilingRule::class]);
});

it('cannot be built with a price-shaping rule after a guardrail', function () {
    new Pipeline([new ShouldActRule, new MarginFloorRule, new FloorCeilingRule, new StrategyRule]);
})->throws(InvalidPipeline::class, 'guardrails run last');

it('cannot be built with the step limit after a guardrail', function () {
    new Pipeline([new MarginFloorRule, new FloorCeilingRule, new StepLimitRule]);
})->throws(InvalidPipeline::class);

it('cannot be built without both guardrails', function () {
    new Pipeline([new ShouldActRule, new StrategyRule, new FloorCeilingRule, new NoOpRule]);
})->throws(InvalidPipeline::class, 'MarginFloorRule');

it('runs filters before anything that reads competitors', function () {
    $classes = DefaultPipeline::ruleClasses();
    expect(array_search(CompetitorFilterRule::class, $classes))
        ->toBeLessThan(array_search(AlreadyWinningRule::class, $classes))
        ->toBeLessThan(array_search(StrategyRule::class, $classes));
});

it('refuses a filter or final rule that tries to change the price', function () {
    $sneaky = new class implements Rule
    {
        public function name(): string
        {
            return 'sneaky';
        }

        public function stage(): Stage
        {
            return Stage::Final;
        }

        public function evaluate(PricingContext $context, PipelineState $state): Verdict
        {
            return Verdict::propose(Money::cents(1), 'undercut after the guardrails');
        }
    };

    (new Pipeline([new MarginFloorRule, new FloorCeilingRule, $sneaky]))->decide(Ctx::make()->build());
})->throws(InvalidPipeline::class, 'may not propose');

it('vetoes as a last line of defence if a guardrail is buggy', function () {
    $broken = new class implements Rule
    {
        public function name(): string
        {
            return 'broken_guardrail';
        }

        public function stage(): Stage
        {
            return Stage::Guardrail;
        }

        public function evaluate(PricingContext $context, PipelineState $state): Verdict
        {
            return Verdict::propose(Money::cents(1), 'buggy');
        }
    };

    $d = (new Pipeline([new MarginFloorRule, new FloorCeilingRule, $broken]))->decide(Ctx::make()->build());
    expect($d->outcome->value)->toBe('config_error')->and($d->reason)->toContain('outside guardrails');
});
