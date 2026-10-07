<?php

namespace App\Repricer\Models;

use App\Repricer\Models\Casts\MoneyCast;
use App\Repricer\Models\Concerns\AppendOnly;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $decision_id
 * @property string $seller
 * @property Money $price
 * @property Money $shipping
 * @property string $fulfillment
 * @property int $rating
 * @property int $handling_days
 * @property bool $is_buybox
 * @property bool $is_ours
 * @property CarbonImmutable $captured_at
 */
class OfferSnapshot extends Model
{
    use AppendOnly;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => MoneyCast::class,
            'shipping' => MoneyCast::class,
            'is_buybox' => 'boolean',
            'is_ours' => 'boolean',
            'captured_at' => 'immutable_datetime',
        ];
    }
}
