<?php

/*
|--------------------------------------------------------------------------
| Public demo
|--------------------------------------------------------------------------
|
| In demo mode anonymous visitors can operate the dashboard. They share one world, which is
| rebuilt from its seed on a schedule; every mutation is rate-limited and the world size and
| speed are capped. With demo mode off, every page and action needs a logged-in user.
|
*/

return [

    'enabled' => (bool) env('DEMO_MODE', false),

    // Rebuild the whole demo world (database included) this often, 5–30 minutes. Nothing a
    // visitor does survives a reset. 0 turns the scheduled reset off; the boot reset still runs.
    'reset_minutes' => (int) env('DEMO_RESET_MINUTES', 30),

    // Ticks run synchronously after a reset so the page opens on a price war already under way.
    'prime_ticks' => (int) env('DEMO_PRIME_TICKS', 48),

    // The product the dashboard opens on: the one in the liveliest price war.
    'featured_sku' => env('DEMO_FEATURED_SKU', 'FP-1L-STEEL'),

    // The simulator only ticks while someone has had the dashboard open recently.
    'idle_after_seconds' => (int) env('DEMO_IDLE_AFTER_SECONDS', 120),

    // Caps a visitor cannot exceed.
    'max_speed' => (int) env('DEMO_MAX_SPEED', 50),
    'default_speed' => (int) env('DEMO_DEFAULT_SPEED', 20),
    'max_offers_per_listing' => 6,
    // Products in the catalogue at once (visitors can add their own; archived ones don't count).
    'max_products' => (int) env('DEMO_MAX_PRODUCTS', 8),
    'max_fault_bps' => 3000,

    // Mutating requests per minute, per visitor IP (demo) or per user (operator mode).
    'mutations_per_minute' => (int) env('DEMO_MUTATIONS_PER_MINUTE', 30),

    // Where browsers connect to Reverb. Empty values mean "same origin as the page".
    'reverb_client' => [
        'key' => env('REVERB_APP_KEY'),
        'host' => env('REVERB_CLIENT_HOST') ?: null,
        'port' => env('REVERB_CLIENT_PORT') ?: null,
        'scheme' => env('REVERB_CLIENT_SCHEME') ?: null,
    ],

];
