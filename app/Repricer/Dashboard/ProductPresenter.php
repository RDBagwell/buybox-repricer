<?php

namespace App\Repricer\Dashboard;

use App\Repricer\Models\BuyBoxHistory;
use App\Repricer\Models\PricePush;
use App\Repricer\Models\Product;
use App\Repricer\Outbound\PushStatus;
use App\Support\Money;
use App\Support\Rounding;
use DateTimeImmutable;

/**
 * Product rows for the dashboard table. All money in integer cents; percentages in basis points.
 */
final class ProductPresenter
{
    public function __construct(private readonly int $breakerLimit) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Product $p, DateTimeImmutable $marketNow): array
    {
        $rule = $p->rule;
        $marginCents = $p->current_price->cents - $p->cost->cents - $p->fees->cents;
        $holder = BuyBoxHistory::query()->where('product_id', $p->id)->latest('id')->first();

        return [
            'id' => $p->id,
            'sku' => $p->sku,
            'asin' => $p->asin,
            'title' => $p->title,
            'current_price' => $p->current_price->cents,
            'shipping' => $p->shipping->cents,
            'cost' => $p->cost->cents,
            'fees' => $p->fees->cents,
            'margin' => $marginCents,
            'margin_bps' => $p->current_price->cents > 0 ? Money::divide($marginCents * 10_000, $p->current_price->cents, Rounding::HalfUp) : 0,
            'paused' => $p->paused,
            'paused_reason' => $p->paused_reason,
            'buybox' => [
                'winner' => $holder?->winner,
                'ours' => $holder !== null && $holder->winner === config('market.seller_id'),
                'since' => $holder?->changed_at->format(DATE_ATOM),
            ],
            'win_rate_24h_bps' => self::winRate($p->id, $marketNow, 24 * 3600),
            'reprices_last_hour' => self::repricesSince($p->id, $marketNow->modify('-1 hour')),
            'breaker_limit' => $this->breakerLimit,
            'rule' => $rule === null ? null : [
                'strategy' => $rule->strategy->value,
                'offset' => $rule->offset->cents,
                'floor' => $rule->floor->cents,
                'ceiling' => $rule->ceiling->cents,
                'min_margin' => $rule->min_margin->cents,
                'max_step_pct' => $rule->max_step_pct,
                'cooldown_sec' => $rule->cooldown_sec,
                'min_competitor_rating' => $rule->min_competitor_rating,
                'max_competitor_handling_days' => $rule->max_competitor_handling_days,
                'no_competition' => $rule->no_competition->value,
                'margin_floor' => $p->cost->cents + $p->fees->cents + $rule->min_margin->cents,
            ],
        ];
    }

    /**
     * Share of the last $windowSeconds of MARKET time in which we held the Buy Box, in basis
     * points. Time before the first recorded winner is not counted.
     */
    public static function winRate(int $productId, DateTimeImmutable $now, int $windowSeconds): int
    {
        $start = $now->modify("-{$windowSeconds} seconds");
        $us = config('market.seller_id');

        $before = BuyBoxHistory::query()->where('product_id', $productId)->where('changed_at', '<=', $start)->latest('changed_at')->latest('id')->first();
        $rows = BuyBoxHistory::query()->where('product_id', $productId)->where('changed_at', '>', $start)->where('changed_at', '<=', $now)->orderBy('changed_at')->orderBy('id')->get();

        // Walk the winner changes as [from, to, winner] segments; time before the first known
        // winner is not counted.
        $segments = [];
        $cursor = $before === null ? null : [$start, $before->winner];
        foreach ($rows as $row) {
            $at = $row->changed_at->toDateTimeImmutable();
            if ($cursor !== null) {
                $segments[] = [$cursor[0], $at, $cursor[1]];
            }
            $cursor = [$at, $row->winner];
        }
        if ($cursor !== null) {
            $segments[] = [$cursor[0], $now, $cursor[1]];
        }

        $total = 0;
        $won = 0;
        foreach ($segments as [$from, $to, $winner]) {
            $len = max(0, $to->getTimestamp() - $from->getTimestamp());
            $total += $len;
            $won += $winner === $us ? $len : 0;
        }

        return $total === 0 ? 0 : Money::divide($won * 10_000, $total, Rounding::HalfUp);
    }

    public static function repricesSince(int $productId, DateTimeImmutable $since): int
    {
        return PricePush::query()
            ->join('price_decisions', 'price_decisions.id', '=', 'price_pushes.decision_id')
            ->where('price_decisions.product_id', $productId)
            ->where('price_pushes.status', PushStatus::Succeeded->value)
            ->where('price_pushes.pushed_at', '>', $since)
            ->count();
    }
}
