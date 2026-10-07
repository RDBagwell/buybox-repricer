<?php

/*
|--------------------------------------------------------------------------
| Marketplace simulator
|--------------------------------------------------------------------------
|
| The simulator stands in for the marketplace. All of its state lives in
| `sim_` tables; it never reads repricer tables. Money is integer cents.
|
*/

return [

    // Market time starts here on a fresh database. One tick advances it by tick_seconds.
    'epoch' => env('SIM_EPOCH', '2026-01-01T00:00:00Z'),
    'tick_seconds' => (int) env('SIM_TICK_SECONDS', 15),

    // Default speed multiplier for `sim:run` (1x–100x). Speed only changes real-time pacing,
    // never the market time a tick represents.
    'speed' => (int) env('SIM_SPEED', 100),

    /*
     | Buy Box scoring (an approximation; the real algorithm is private).
     | effective = landed − FBA bonus + handling penalty; lowest effective wins.
     */
    'buybox' => [
        'min_rating' => 80,                    // below this positive-feedback %, an offer is disqualified
        'marketplace_bonus_bps' => 300,        // 3% of landed price off for marketplace-fulfilled offers
        'free_handling_days' => 2,             // handling up to this many days is not penalised
        'handling_penalty_bps_per_day' => 100, // +1% of landed per extra day
    ],

    /*
     | Per-operation request quotas, modelled as token buckets (burst + one token every refill_ms),
     | in the spirit of SP-API usage plans. Measured in real (wall-clock) time.
     */
    'quotas' => [
        'getItemOffers' => ['burst' => 1, 'refill_ms' => 2000],       // 0.5 req/s
        'patchListingsItem' => ['burst' => 10, 'refill_ms' => 200],   // 5 req/s
    ],

    /*
     | Seeded fault injection on the adapter. Probabilities are in basis points (100 = 1%).
     */
    'faults' => [
        'seed' => (int) env('SIM_FAULT_SEED', 7),
        'http_429_bps' => (int) env('SIM_FAULT_429_BPS', 0),
        'http_503_bps' => (int) env('SIM_FAULT_503_BPS', 0),
        'retry_after_ms' => (int) env('SIM_FAULT_RETRY_AFTER_MS', 1000),
    ],

    // Notification delivery: a Redis stream read through a consumer group (see README).
    'notifications' => [
        'stream' => env('SIM_NOTIFICATION_STREAM', 'market:notifications'),
        'group' => env('SIM_NOTIFICATION_GROUP', 'repricer'),
        'maxlen' => 10000,
        'visibility_timeout_ms' => 30000, // unacknowledged messages are redelivered after this
        'consumer' => env('SIM_NOTIFICATION_CONSUMER', 'repricer-1'),
    ],

    /*
     | The seeded world. `our_store` offers carry a SKU and are priced only through the adapter;
     | every other offer is driven by a competitor bot.
     */
    'scenario' => [
        [
            'asin' => 'B0SIM00001', 'title' => 'Stainless French Press, 1 L',
            'offers' => [
                ['seller' => 'our_store', 'sku' => 'FP-1L-STEEL', 'price' => 2899, 'shipping' => 0, 'fulfillment' => 'merchant', 'rating' => 98, 'handling_days' => 1],
                ['seller' => 'PENNYWISE', 'price' => 2799, 'shipping' => 0, 'fulfillment' => 'merchant', 'rating' => 94, 'handling_days' => 2, 'bot' => 'penny_pincher', 'params' => ['floor' => 2299, 'every' => 2]],
                ['seller' => 'STEADY-CO', 'price' => 3099, 'shipping' => 0, 'fulfillment' => 'marketplace', 'rating' => 97, 'handling_days' => 1, 'bot' => 'anchor', 'params' => ['price' => 3099]],
            ],
        ],
        [
            'asin' => 'B0SIM00002', 'title' => 'Silicone Baking Mat, Set of 2',
            'offers' => [
                ['seller' => 'our_store', 'sku' => 'MAT-SIL-2', 'price' => 1499, 'shipping' => 0, 'fulfillment' => 'merchant', 'rating' => 98, 'handling_days' => 1],
                ['seller' => 'PENNYWISE', 'price' => 1399, 'shipping' => 0, 'fulfillment' => 'merchant', 'rating' => 94, 'handling_days' => 2, 'bot' => 'penny_pincher', 'params' => ['floor' => 1049, 'every' => 3]],
                ['seller' => 'BARGAIN-BIN', 'price' => 999, 'shipping' => 0, 'fulfillment' => 'merchant', 'rating' => 71, 'handling_days' => 6, 'bot' => 'anchor', 'params' => ['price' => 999]],
            ],
        ],
        [
            'asin' => 'B0SIM00003', 'title' => 'USB-C Charging Cable, 2 m',
            'offers' => [
                ['seller' => 'our_store', 'sku' => 'CBL-USBC-2M', 'price' => 1199, 'shipping' => 0, 'fulfillment' => 'marketplace', 'rating' => 98, 'handling_days' => 1],
                ['seller' => 'STEADY-CO', 'price' => 1249, 'shipping' => 0, 'fulfillment' => 'marketplace', 'rating' => 97, 'handling_days' => 1, 'bot' => 'anchor', 'params' => ['price' => 1249]],
                ['seller' => 'CABLE-KING', 'price' => 899, 'shipping' => 399, 'fulfillment' => 'merchant', 'rating' => 92, 'handling_days' => 3, 'bot' => 'anchor', 'params' => ['price' => 899]],
            ],
        ],
        [
            'asin' => 'B0SIM00004', 'title' => 'Bamboo Cutting Board, Large',
            'offers' => [
                ['seller' => 'our_store', 'sku' => 'BRD-BAM-L', 'price' => 3499, 'shipping' => 499, 'fulfillment' => 'merchant', 'rating' => 98, 'handling_days' => 2],
                ['seller' => 'PENNYWISE', 'price' => 3699, 'shipping' => 0, 'fulfillment' => 'merchant', 'rating' => 94, 'handling_days' => 2, 'bot' => 'penny_pincher', 'params' => ['floor' => 3299, 'every' => 5]],
                ['seller' => 'TIMBERLINE', 'price' => 3899, 'shipping' => 0, 'fulfillment' => 'marketplace', 'rating' => 99, 'handling_days' => 1, 'bot' => 'penny_pincher', 'params' => ['floor' => 3599, 'every' => 6]],
            ],
        ],
        [
            'asin' => 'B0SIM00005', 'title' => 'Insulated Water Bottle, 750 ml',
            'offers' => [
                ['seller' => 'our_store', 'sku' => 'BTL-INS-750', 'price' => 2199, 'shipping' => 0, 'fulfillment' => 'marketplace', 'rating' => 98, 'handling_days' => 1],
                ['seller' => 'STEADY-CO', 'price' => 2249, 'shipping' => 0, 'fulfillment' => 'marketplace', 'rating' => 97, 'handling_days' => 1, 'bot' => 'anchor', 'params' => ['price' => 2249]],
            ],
        ],
    ],

];
