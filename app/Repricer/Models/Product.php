<?php

namespace App\Repricer\Models;

use App\Repricer\Catalog\Channel;
use App\Repricer\Models\Casts\MoneyCast;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $asin
 * @property Channel $channel
 * @property string $sku
 * @property string $title
 * @property Money $cost
 * @property Money $fees
 * @property Money $shipping
 * @property Money $current_price
 * @property bool $paused
 * @property string|null $paused_reason
 * @property CarbonImmutable|null $paused_at market time
 * @property CarbonImmutable|null $archived_at market time; archived products never reprice
 * @property CarbonImmutable|null $last_price_change_at market time
 * @property-read PricingRule|null $rule
 */
class Product extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'cost' => MoneyCast::class,
            'fees' => MoneyCast::class,
            'shipping' => MoneyCast::class,
            'current_price' => MoneyCast::class,
            'paused' => 'boolean',
            'channel' => Channel::class,
            'paused_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'last_price_change_at' => 'immutable_datetime',
        ];
    }

    /**
     * Products still in the catalogue (not archived).
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @return HasOne<PricingRule, $this> */
    public function rule(): HasOne
    {
        return $this->hasOne(PricingRule::class);
    }

    /** @return HasMany<PriceDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(PriceDecision::class);
    }

    /** @return HasMany<BuyBoxHistory, $this> */
    public function buyBoxHistory(): HasMany
    {
        return $this->hasMany(BuyBoxHistory::class);
    }
}
