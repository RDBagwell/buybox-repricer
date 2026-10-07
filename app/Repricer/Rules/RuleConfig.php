<?php

namespace App\Repricer\Rules;

use App\Support\Money;
use InvalidArgumentException;

/**
 * Per-product pricing configuration.
 */
final readonly class RuleConfig
{
    /**
     * @param  Money  $offset  undercut amount for the "beat" strategies (and for raising while winning)
     * @param  Money  $minMargin  absolute margin above cost + fees that we never price below
     * @param  int  $maxStepPct  largest single move, in whole percent of the current price
     * @param  int  $cooldownSeconds  minimum market-time seconds between price changes
     * @param  int  $minCompetitorRating  competitors rated below this (0–100) are ignored
     * @param  int  $maxCompetitorHandlingDays  competitors slower than this are ignored
     */
    public function __construct(
        public Strategy $strategy,
        public Money $offset,
        public Money $floor,
        public Money $ceiling,
        public Money $minMargin,
        public int $maxStepPct,
        public int $cooldownSeconds,
        public int $minCompetitorRating = 0,
        public int $maxCompetitorHandlingDays = PHP_INT_MAX,
        public NoCompetitionAction $noCompetition = NoCompetitionAction::Hold,
    ) {
        if ($offset->isNegative() || $minMargin->isNegative()) {
            throw new InvalidArgumentException('Offset and minimum margin cannot be negative.');
        }
        if ($maxStepPct < 1 || $maxStepPct > 100) {
            throw new InvalidArgumentException('max_step_pct must be between 1 and 100.');
        }
        if ($cooldownSeconds < 0) {
            throw new InvalidArgumentException('cooldown cannot be negative.');
        }
        // floor > ceiling is deliberately NOT rejected here: it is a configuration error the
        // pipeline vetoes and records, so it shows up in the audit log instead of crashing a job.
    }

    /** How far below the reference landed price we aim. Matching never undercuts. */
    public function undercut(): Money
    {
        return $this->strategy === Strategy::MatchLowest ? Money::zero() : $this->offset;
    }
}
