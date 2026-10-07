<?php

namespace App\Repricer\Rules;

use App\Repricer\Rules\Rules\AlreadyWinningRule;
use App\Repricer\Rules\Rules\CompetitorFilterRule;
use App\Repricer\Rules\Rules\FloorCeilingRule;
use App\Repricer\Rules\Rules\MarginFloorRule;
use App\Repricer\Rules\Rules\NoOpRule;
use App\Repricer\Rules\Rules\ShouldActRule;
use App\Repricer\Rules\Rules\StepLimitRule;
use App\Repricer\Rules\Rules\StrategyRule;

/**
 * The one place the rule order is configured.
 */
final class DefaultPipeline
{
    /**
     * @return list<class-string<Rule>>
     */
    public static function ruleClasses(): array
    {
        return [
            ShouldActRule::class,        // 1. should we act?
            CompetitorFilterRule::class, // 4. competitor filters (moved first: see class doc)
            AlreadyWinningRule::class,   // 2. already winning?
            StrategyRule::class,         // 3. strategy
            StepLimitRule::class,        // 5. step limit
            MarginFloorRule::class,      // 6. margin floor   ┐ guardrails: always last
            FloorCeilingRule::class,     // 7. floor/ceiling  ┘ to touch the price
            NoOpRule::class,             // 8. no-op check
        ];
    }

    public static function make(): Pipeline
    {
        return new Pipeline(array_map(fn (string $class) => new $class, self::ruleClasses()));
    }
}
