<?php

namespace App\Repricer\Models;

use App\Repricer\Models\Casts\MoneyCast;
use App\Repricer\Models\Concerns\AppendOnly;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One audit row per (product, event): every decision, including skips, vetoes and stale events.
 *
 * @property int $id
 * @property int $product_id
 * @property string $event_id
 * @property CarbonImmutable $event_time market time of the offer snapshot
 * @property Money $old_price
 * @property Money|null $new_price
 * @property string $outcome see DecisionStatus
 * @property string|null $reason_code
 * @property string $reason
 * @property list<array{rule: string, verdict: string, reason: string, price_before: int, price_after: int, code: string|null}> $rule_trace
 * @property CarbonImmutable $decided_at market time
 * @property CarbonImmutable|null $created_at
 * @property-read Product $product
 */
class PriceDecision extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'event_time' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'old_price' => MoneyCast::class,
            'new_price' => MoneyCast::class,
            'rule_trace' => 'array',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<OfferSnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(OfferSnapshot::class, 'decision_id');
    }

    /** @return HasMany<PricePush, $this> */
    public function pushes(): HasMany
    {
        return $this->hasMany(PricePush::class, 'decision_id')->orderBy('attempts');
    }
}
