<?php

namespace App\Repricer\Rules;

/**
 * What to do when there is nobody to price against (no competitors, or all filtered out).
 */
enum NoCompetitionAction: string
{
    /** Keep the current price. */
    case Hold = 'hold';

    /** Move toward the ceiling (still subject to the step limit). */
    case RaiseToCeiling = 'raise_to_ceiling';
}
