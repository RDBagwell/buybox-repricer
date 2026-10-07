<?php

namespace App\Repricer\Models;

use App\Repricer\Models\Concerns\AppendOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per push attempt (or terminal non-attempt such as superseded/cancelled).
 *
 * @property int $id
 * @property int $decision_id
 * @property string $status see PushStatus
 * @property int $attempts 1-based attempt number
 * @property array<string, mixed>|null $api_response
 * @property CarbonImmutable|null $pushed_at market time the price was applied
 * @property CarbonImmutable|null $created_at
 */
class PricePush extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'api_response' => 'array',
            'pushed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PriceDecision, $this> */
    public function decision(): BelongsTo
    {
        return $this->belongsTo(PriceDecision::class, 'decision_id');
    }
}
