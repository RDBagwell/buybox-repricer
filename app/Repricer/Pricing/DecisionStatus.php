<?php

namespace App\Repricer\Pricing;

/**
 * The outcome column of price_decisions.
 */
enum DecisionStatus: string
{
    /** A new price was decided and a push queued. */
    case Reprice = 'reprice';

    /** A new price was decided but dry-run mode is on: recorded, never pushed. */
    case DryRun = 'dry_run';

    case NoChange = 'no_change';

    /** Gate veto: kill switch, paused or cooldown (see reason_code). */
    case Skipped = 'skipped';

    case ConfigError = 'config_error';

    /** The event is older than the snapshot behind the product's latest decision. */
    case Stale = 'stale';
}
