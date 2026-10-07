<?php

namespace App\Repricer\Rules;

use App\Repricer\Rules\Rules\FloorCeilingRule;
use App\Repricer\Rules\Rules\MarginFloorRule;
use App\Support\Money;

/**
 * Runs rules in order over a PricingContext and returns a Decision with a full rule trace.
 *
 * Invariants enforced at construction (so they hold for any configured order, not by convention):
 *  - rules appear in non-decreasing Stage order, so no shaping/damping rule can run after a guardrail;
 *  - both guardrails (margin floor, floor/ceiling) are present.
 *
 * And at run time, as a last line of defence, a price outside the guardrail bounds is vetoed.
 */
final readonly class Pipeline
{
    /** @var list<Rule> */
    private array $rules;

    /**
     * @param  list<Rule>  $rules
     */
    public function __construct(array $rules)
    {
        $previous = null;
        foreach ($rules as $rule) {
            if ($previous !== null && $rule->stage()->value < $previous->stage()->value) {
                throw new InvalidPipeline(sprintf(
                    'Rule [%s] (stage %s) cannot run after [%s] (stage %s): stages must be in order and guardrails run last.',
                    $rule->name(), $rule->stage()->name, $previous->name(), $previous->stage()->name,
                ));
            }
            $previous = $rule;
        }

        foreach ([MarginFloorRule::class, FloorCeilingRule::class] as $required) {
            if (array_filter($rules, fn (Rule $r) => $r instanceof $required) === []) {
                throw new InvalidPipeline("Pipeline is missing required guardrail [{$required}].");
            }
        }

        $this->rules = $rules;
    }

    /**
     * @return list<string>
     */
    public function ruleNames(): array
    {
        return array_map(fn (Rule $r) => $r->name(), $this->rules);
    }

    public function decide(PricingContext $context): Decision
    {
        $state = new PipelineState($context->currentPrice, $context->competitors());
        $trace = [];

        foreach ($this->rules as $rule) {
            $verdict = $rule->evaluate($context, $state);
            $before = $state->proposed;

            if ($verdict->kind === VerdictKind::Propose && $verdict->price !== null) {
                if (! $rule->stage()->mayChangePrice()) {
                    throw new InvalidPipeline("Rule [{$rule->name()}] in stage {$rule->stage()->name} may not propose a price.");
                }
                $state = $state->withProposed($verdict->price);
            }

            if ($verdict->competitors !== null) {
                $state = $state->withCompetitors($verdict->competitors);
            }

            $trace[] = new TraceEntry($rule->name(), $verdict->kind, $verdict->reason, $before, $state->proposed, $verdict->code);

            if ($verdict->kind === VerdictKind::Veto) {
                $code = $verdict->code ?? VetoCode::ConfigError;

                return new Decision(
                    outcome: match ($code) {
                        VetoCode::NoChange => DecisionOutcome::NoChange,
                        VetoCode::ConfigError, VetoCode::GuardrailViolation => DecisionOutcome::ConfigError,
                        default => DecisionOutcome::Skipped,
                    },
                    oldPrice: $context->currentPrice,
                    newPrice: null,
                    reason: $verdict->reason,
                    vetoCode: $code,
                    trace: $trace,
                );
            }
        }

        $final = $state->proposed;
        if (! $this->withinGuardrails($context, $final)) {
            $reason = "Final price {$final} is outside guardrails [{$context->effectiveFloor()}, {$context->config->ceiling}]; refusing.";
            $trace[] = new TraceEntry('post_condition', VerdictKind::Veto, $reason, $final, $final, VetoCode::GuardrailViolation);

            return new Decision(DecisionOutcome::ConfigError, $context->currentPrice, null, $reason, VetoCode::GuardrailViolation, $trace);
        }

        return new Decision(
            outcome: DecisionOutcome::Reprice,
            oldPrice: $context->currentPrice,
            newPrice: $final,
            reason: "Reprice {$context->currentPrice} → {$final}.",
            vetoCode: null,
            trace: $trace,
        );
    }

    private function withinGuardrails(PricingContext $context, Money $price): bool
    {
        return $price->greaterThanOrEqual($context->effectiveFloor())
            && $price->lessThanOrEqual($context->config->ceiling);
    }
}
