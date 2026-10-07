<?php

namespace App\Repricer\Rules\Rules;

use App\Repricer\Rules\PipelineState;
use App\Repricer\Rules\PricingContext;
use App\Repricer\Rules\Rule;
use App\Repricer\Rules\Stage;
use App\Repricer\Rules\Verdict;
use App\Support\Money;
use App\Support\Rounding;

/**
 * 5. Step limit: cap a single move at max_step_pct of the current price to damp price wars.
 *
 * The cap rounds DOWN (we never exceed the configured percentage) but is at least 1 cent,
 * otherwise very cheap items could never move. The guardrails that follow may still move the
 * price further when the current price sits outside the floor–ceiling range; that is the only
 * documented way a decision exceeds the step limit.
 */
final class StepLimitRule implements Rule
{
    public function name(): string
    {
        return 'step_limit';
    }

    public function stage(): Stage
    {
        return Stage::Damping;
    }

    public function evaluate(PricingContext $context, PipelineState $state): Verdict
    {
        $current = $context->currentPrice;
        $maxStep = self::maxStep($current, $context->config->maxStepPct);
        $delta = $state->proposed->minus($current);

        if ($delta->abs()->lessThanOrEqual($maxStep)) {
            return Verdict::pass("Move of {$delta} within step limit {$maxStep} ({$context->config->maxStepPct}%).");
        }

        $capped = $delta->isNegative() ? $current->minus($maxStep) : $current->plus($maxStep);
        $capped = Money::max($capped, Money::cents(1));

        return Verdict::propose($capped, "Move of {$delta} exceeds step limit {$maxStep} ({$context->config->maxStepPct}%); capped at {$capped}.");
    }

    public static function maxStep(Money $current, int $maxStepPct): Money
    {
        return Money::max($current->percentage($maxStepPct * 100, Rounding::Down), Money::cents(1));
    }
}
