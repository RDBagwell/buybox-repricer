<?php

namespace Tests\Contract;

use App\Repricer\Market\MarketAdapter;

/**
 * What the shared MarketAdapter contract suite needs from an implementation under test.
 * A future SP-API client provides one of these (e.g. against the SP-API sandbox) and runs
 * the same suite.
 */
interface AdapterHarness
{
    public function adapter(): MarketAdapter;

    /** An ASIN on which we have an offer. */
    public function knownAsin(): string;

    /** Our SKU on that ASIN. */
    public function ourSku(): string;

    public function ourSellerId(): string;

    /** Cause at least one offer-change notification to be emitted for knownAsin(). */
    public function triggerOfferChange(): void;
}
