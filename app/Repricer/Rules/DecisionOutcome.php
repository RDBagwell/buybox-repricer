<?php

namespace App\Repricer\Rules;

enum DecisionOutcome: string
{
    /** A new price should be pushed. */
    case Reprice = 'reprice';

    /** The final price equals the current one; nothing to push. */
    case NoChange = 'no_change';

    /** A gate stopped the pipeline (kill switch, pause, cooldown). */
    case Skipped = 'skipped';

    /** The product's configuration is contradictory; flagged for a human. */
    case ConfigError = 'config_error';
}
