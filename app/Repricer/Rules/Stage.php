<?php

namespace App\Repricer\Rules;

/**
 * Pipeline stages. A pipeline must list its rules in non-decreasing stage order;
 * that is what guarantees the guardrails run after every price-shaping rule.
 */
enum Stage: int
{
    /** Decide whether to act at all (kill switch, pause, cooldown). */
    case Gate = 10;

    /** Narrow the competitor set. Never changes the price. */
    case Filter = 20;

    /** Propose a price (winning/raise logic, strategy). */
    case Shaping = 30;

    /** Damp the proposed move (step limit). */
    case Damping = 40;

    /** Hard money guardrails (margin floor, floor/ceiling). Always last to touch the price. */
    case Guardrail = 50;

    /** Read-only final checks (no-op detection). Never changes the price. */
    case Final = 60;

    public function mayChangePrice(): bool
    {
        return $this !== self::Filter && $this !== self::Final && $this !== self::Gate;
    }
}
