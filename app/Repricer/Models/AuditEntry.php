<?php

namespace App\Repricer\Models;

use App\Repricer\Models\Concerns\AppendOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $action
 * @property int|null $product_id
 * @property string $actor
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property string|null $reason
 * @property CarbonImmutable|null $market_time
 * @property CarbonImmutable|null $created_at
 */
class AuditEntry extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $table = 'audit_log';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'market_time' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
