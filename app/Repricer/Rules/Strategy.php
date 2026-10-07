<?php

namespace App\Repricer\Rules;

/**
 * How we position against competitors. All comparisons use landed price (price + shipping).
 */
enum Strategy: string
{
    /** Land `offset` below the lowest eligible competitor. */
    case BeatLowest = 'beat_lowest';

    /** Land exactly on the lowest eligible competitor (ties go to the incumbent). */
    case MatchLowest = 'match_lowest';

    /** Land `offset` below the current Buy Box holder. */
    case BeatBuyBox = 'beat_buybox';
}
