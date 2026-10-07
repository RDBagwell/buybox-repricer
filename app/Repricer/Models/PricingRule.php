<?php

namespace App\Repricer\Models;

use App\Repricer\Models\Casts\MoneyCast;
use App\Repricer\Rules\NoCompetitionAction;
use App\Repricer\Rules\RuleConfig;
use App\Repricer\Rules\Strategy;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_id
 * @property Strategy $strategy
 * @property Money $offset
 * @property Money $floor
 * @property Money $ceiling
 * @property Money $min_margin
 * @property int $max_step_pct
 * @property int $cooldown_sec
 * @property int $min_competitor_rating
 * @property int $max_competitor_handling_days
 * @property NoCompetitionAction $no_competition
 */
class PricingRule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'strategy' => Strategy::class,
            'offset' => MoneyCast::class,
            'floor' => MoneyCast::class,
            'ceiling' => MoneyCast::class,
            'min_margin' => MoneyCast::class,
            'max_step_pct' => 'integer',
            'cooldown_sec' => 'integer',
            'min_competitor_rating' => 'integer',
            'max_competitor_handling_days' => 'integer',
            'no_competition' => NoCompetitionAction::class,
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function toConfig(): RuleConfig
    {
        return new RuleConfig(
            strategy: $this->strategy,
            offset: $this->offset,
            floor: $this->floor,
            ceiling: $this->ceiling,
            minMargin: $this->min_margin,
            maxStepPct: $this->max_step_pct,
            cooldownSeconds: $this->cooldown_sec,
            minCompetitorRating: $this->min_competitor_rating,
            maxCompetitorHandlingDays: $this->max_competitor_handling_days,
            noCompetition: $this->no_competition,
        );
    }
}
