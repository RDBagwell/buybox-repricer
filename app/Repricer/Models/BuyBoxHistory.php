<?php

namespace App\Repricer\Models;

use App\Repricer\Models\Casts\MoneyCast;
use App\Repricer\Models\Concerns\AppendOnly;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $product_id
 * @property string|null $winner
 * @property Money|null $our_price
 * @property CarbonImmutable $changed_at market time
 */
class BuyBoxHistory extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $table = 'buybox_history';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'our_price' => MoneyCast::class,
            'changed_at' => 'immutable_datetime',
        ];
    }
}
