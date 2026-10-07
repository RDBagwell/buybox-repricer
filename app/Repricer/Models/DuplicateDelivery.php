<?php

namespace App\Repricer\Models;

use App\Repricer\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

/**
 * A notification delivered again for a (product, event) that already has a decision.
 * Recorded so duplicates are visible, never acted on twice.
 *
 * @property int $id
 * @property int $product_id
 * @property string $event_id
 * @property int $decision_id
 */
class DuplicateDelivery extends Model
{
    use AppendOnly;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['received_at' => 'immutable_datetime'];
    }
}
