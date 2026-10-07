<?php

return [

    // Our seller id on the marketplace. The simulator's scenario uses `our_store` as a
    // placeholder for it.
    'seller_id' => env('MARKET_SELLER_ID', 'OUR-STORE'),

    // Which MarketAdapter implementation the repricer talks to.
    'adapter' => env('MARKET_ADAPTER', 'simulator'),

];
