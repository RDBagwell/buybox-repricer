<?php

namespace App\Repricer\Catalog;

/**
 * What kind of marketplace a product sells on, which decides what "winning" means.
 */
enum Channel: string
{
    /** Sellers share one listing and compete for a single featured offer (Amazon-style Buy Box). */
    case BuyBox = 'buybox';

    /** Every seller lists separately (social commerce); no box to win, only price against comparable listings. */
    case Open = 'open';

    public function hasBuyBox(): bool
    {
        return $this === self::BuyBox;
    }
}
