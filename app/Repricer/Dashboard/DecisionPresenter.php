<?php

namespace App\Repricer\Dashboard;

use App\Repricer\Models\OfferSnapshot;
use App\Repricer\Models\PriceDecision;
use App\Repricer\Models\PricePush;

/**
 * The only shape a decision leaves the server in (API and broadcasts). Deliberately excludes
 * internals: event ids, receipt handles, raw API responses, cost fields.
 */
final class DecisionPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(PriceDecision $d): array
    {
        $pushes = $d->relationLoaded('pushes') ? $d->pushes : $d->pushes()->get();
        $last = $pushes->last();
        $snapshots = $d->relationLoaded('snapshots') ? $d->snapshots : $d->snapshots()->orderBy('id')->get();

        return [
            'id' => $d->id,
            'product_id' => $d->product_id,
            'outcome' => $d->outcome,
            'reason_code' => $d->reason_code,
            'reason' => $d->reason,
            'old_price' => $d->old_price->cents,
            'new_price' => $d->new_price?->cents,
            'event_time' => $d->event_time->format(DATE_ATOM),
            'decided_at' => $d->decided_at->format(DATE_ATOM),
            'trace' => array_map(fn (array $t) => [
                'rule' => $t['rule'],
                'verdict' => $t['verdict'],
                'reason' => $t['reason'],
                'price_before' => $t['price_before'],
                'price_after' => $t['price_after'],
                'code' => $t['code'],
            ], $d->rule_trace),
            'offers' => $snapshots->map(fn (OfferSnapshot $s) => [
                'seller' => $s->seller,
                'price' => $s->price->cents,
                'shipping' => $s->shipping->cents,
                'landed' => $s->price->cents + $s->shipping->cents,
                'is_buybox' => $s->is_buybox,
                'is_ours' => $s->is_ours,
            ])->values()->all(),
            'push' => $last instanceof PricePush ? ['status' => $last->status, 'attempts' => $last->attempts] : null,
        ];
    }
}
