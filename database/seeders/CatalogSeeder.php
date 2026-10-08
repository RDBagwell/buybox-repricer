<?php

namespace Database\Seeders;

use App\Repricer\Models\Product;
use App\Repricer\Models\Setting;
use App\Repricer\Settings\RepricerSettings;
use Illuminate\Database\Seeder;

/**
 * Our catalogue: one product per simulated listing we sell on, each with a rule config chosen
 * to show a different behaviour against the scenario's bots. All amounts are cents.
 */
class CatalogSeeder extends Seeder
{
    public function run(RepricerSettings $settings): void
    {
        $catalog = [
            // A price war: Penny Pincher's floor is below ours, so we fight down to our floor and hold it.
            ['asin' => 'B0SIM00001', 'sku' => 'FP-1L-STEEL', 'title' => 'Stainless French Press, 1 L', 'cost' => 1400, 'fees' => 435, 'shipping' => 0, 'price' => 2899,
                'rule' => ['strategy' => 'beat_buybox', 'offset' => 10, 'floor' => 2699, 'ceiling' => 3499, 'min_margin' => 300, 'max_step_pct' => 5, 'cooldown_sec' => 120, 'min_competitor_rating' => 85, 'max_competitor_handling_days' => 5]],
            // BARGAIN-BIN is cheap but low-rated and slow: the competitor filter ignores it.
            ['asin' => 'B0SIM00002', 'sku' => 'MAT-SIL-2', 'title' => 'Silicone Baking Mat, Set of 2', 'cost' => 520, 'fees' => 225, 'shipping' => 0, 'price' => 1499,
                'rule' => ['strategy' => 'beat_lowest', 'offset' => 5, 'floor' => 1099, 'ceiling' => 1799, 'min_margin' => 200, 'max_step_pct' => 5, 'cooldown_sec' => 300, 'min_competitor_rating' => 85, 'max_competitor_handling_days' => 5]],
            // We start with the Buy Box: shows raising toward the next competitor instead of cutting.
            ['asin' => 'B0SIM00003', 'sku' => 'CBL-USBC-2M', 'title' => 'USB-C Charging Cable, 2 m', 'cost' => 380, 'fees' => 180, 'shipping' => 0, 'price' => 1199,
                'rule' => ['strategy' => 'beat_lowest', 'offset' => 1, 'floor' => 899, 'ceiling' => 1599, 'min_margin' => 150, 'max_step_pct' => 5, 'cooldown_sec' => 300]],
            // Two pincers and our paid shipping: landed-price comparisons matter.
            ['asin' => 'B0SIM00004', 'sku' => 'BRD-BAM-L', 'title' => 'Bamboo Cutting Board, Large', 'cost' => 1500, 'fees' => 600, 'shipping' => 499, 'price' => 3499,
                'rule' => ['strategy' => 'beat_buybox', 'offset' => 25, 'floor' => 2999, 'ceiling' => 4499, 'min_margin' => 400, 'max_step_pct' => 5, 'cooldown_sec' => 300]],
            // A quiet listing against one anchored seller.
            ['asin' => 'B0SIM00005', 'sku' => 'BTL-INS-750', 'title' => 'Insulated Water Bottle, 750 ml', 'cost' => 900, 'fees' => 330, 'shipping' => 0, 'price' => 2199,
                'rule' => ['strategy' => 'match_lowest', 'offset' => 0, 'floor' => 1799, 'ceiling' => 2599, 'min_margin' => 250, 'max_step_pct' => 10, 'cooldown_sec' => 600, 'no_competition' => 'raise_to_ceiling']],
            // On an open-listing marketplace (no Buy Box): stay just under the cheapest comparable listing.
            ['asin' => 'B0SIM00006', 'channel' => 'open', 'sku' => 'RNG-LED-10', 'title' => 'LED Ring Light, 10 in', 'cost' => 900, 'fees' => 400, 'shipping' => 0, 'price' => 2499,
                'rule' => ['strategy' => 'beat_lowest', 'offset' => 10, 'floor' => 1799, 'ceiling' => 2999, 'min_margin' => 300, 'max_step_pct' => 5, 'cooldown_sec' => 180]],
        ];

        foreach ($catalog as $item) {
            $product = Product::query()->updateOrCreate(['sku' => $item['sku']], [
                'asin' => $item['asin'],
                'channel' => $item['channel'] ?? 'buybox',
                'title' => $item['title'],
                'cost' => $item['cost'],
                'fees' => $item['fees'],
                'shipping' => $item['shipping'],
                'current_price' => $item['price'],
            ]);
            $product->rule()->updateOrCreate([], $item['rule']);
        }

        foreach ([RepricerSettings::KILL_SWITCH, RepricerSettings::DRY_RUN] as $key) {
            if (Setting::query()->whereKey($key)->doesntExist()) {
                $settings->set($key, false);
            }
        }
    }
}
