<?php

namespace App\Support;

/**
 * How a fractional cent is resolved. Every percentage calculation must name one.
 */
enum Rounding: string
{
    /** Toward negative infinity. Use for caps you must never exceed (e.g. step limits). */
    case Down = 'down';

    /** Toward positive infinity. Use for minimums you must always reach (e.g. margin floors). */
    case Up = 'up';

    /** Nearest cent, exact halves away from zero. Use for neutral display/scoring maths. */
    case HalfUp = 'half_up';
}
