<?php

namespace App\Repricer\Rules\Rules;

use App\Repricer\Rules\PipelineState;
use App\Repricer\Rules\PricingContext;
use App\Repricer\Rules\Rule;
use App\Repricer\Rules\Stage;
use App\Repricer\Rules\Verdict;
use App\Repricer\Rules\VetoCode;

/**
 * 1. Should we act? Kill switch, product pause and cooldown.
 *
 * Cooldown is measured in market time (context->now comes from the market clock).
 * If the last change is *after* now, the market clock has been reset; we treat the
 * cooldown as expired rather than locking the product forever, and say so in the trace.
 */
final class ShouldActRule implements Rule
{
    public function name(): string
    {
        return 'should_act';
    }

    public function stage(): Stage
    {
        return Stage::Gate;
    }

    public function evaluate(PricingContext $context, PipelineState $state): Verdict
    {
        if ($context->flags->killSwitch) {
            return Verdict::veto(VetoCode::KillSwitch, 'Global kill switch is on.');
        }

        if ($context->flags->productPaused) {
            return Verdict::veto(VetoCode::Paused, 'Product is paused.');
        }

        $last = $context->lastChangedAt;
        if ($last === null) {
            return Verdict::pass('No previous price change; cooldown does not apply.');
        }

        $elapsed = $context->now->getTimestamp() - $last->getTimestamp();
        if ($elapsed < 0) {
            return Verdict::pass('Last change is later than market time (clock reset); cooldown treated as expired.');
        }

        $cooldown = $context->config->cooldownSeconds;
        if ($elapsed < $cooldown) {
            $remaining = $cooldown - $elapsed;

            return Verdict::veto(VetoCode::Cooldown, "Cooldown active: {$elapsed}s since last change, {$remaining}s of {$cooldown}s remaining.");
        }

        return Verdict::pass("Cooldown expired ({$elapsed}s since last change, cooldown {$cooldown}s).");
    }
}
