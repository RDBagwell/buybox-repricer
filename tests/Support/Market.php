<?php

namespace Tests\Support;

use App\Repricer\Market\MarketOffer;
use App\Repricer\Market\OfferChangeNotification;
use App\Repricer\Models\Product;
use App\Support\Fulfillment;
use App\Support\Money;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\MarketplaceSeeder;
use DateTimeImmutable;
use Illuminate\Support\Str;

/**
 * Fixtures for repricer feature tests.
 */
final class Market
{
    public const US = 'OUR-STORE';

    /** Seed the simulated marketplace (seed 42) and our catalogue. */
    public static function seed(): void
    {
        test()->seed([MarketplaceSeeder::class, CatalogSeeder::class]);
    }

    public static function product(string $sku = 'MAT-SIL-2'): Product
    {
        return Product::query()->with('rule')->where('sku', $sku)->firstOrFail();
    }

    /**
     * A notification for the Silicone Baking Mat listing (B0SIM00002) by default.
     *
     * @param  list<array{0: string, 1: int, 2?: int, 3?: int, 4?: int}>  $offers  [seller, price, shipping, rating, handling]
     */
    public static function notification(
        array $offers = [[self::US, 1499], ['PENNYWISE', 1399], ['BARGAIN-BIN', 999, 0, 71, 6]],
        ?string $buyBox = 'PENNYWISE',
        string $time = '2026-01-01T00:10:00Z',
        ?string $id = null,
        string $asin = 'B0SIM00002',
    ): OfferChangeNotification {
        $market = array_map(fn (array $o) => new MarketOffer(
            $o[0], Money::cents($o[1]), Money::cents($o[2] ?? 0), Fulfillment::Merchant, $o[3] ?? 95, $o[4] ?? 1, $o[0] === $buyBox,
        ), $offers);

        $lowest = min(array_map(fn (MarketOffer $o) => $o->landed()->cents, $market));

        return new OfferChangeNotification(
            notificationId: $id ?? (string) Str::uuid7(),
            asin: $asin,
            eventTime: new DateTimeImmutable($time),
            offers: $market,
            lowestLanded: ['overall' => $lowest, 'marketplace' => null, 'merchant' => $lowest],
            buyBoxSellerId: $buyBox,
            buyBoxLanded: null,
            triggerSellerId: 'PENNYWISE',
            changeType: 'competitor_price',
        );
    }
}
