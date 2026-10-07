<?php

namespace App\Repricer\Dashboard;

use App\Repricer\Market\MarketAdapter;
use App\Repricer\Models\AuditEntry;
use App\Repricer\Models\PriceDecision;
use App\Repricer\Models\Product;
use App\Repricer\Settings\RepricerSettings;
use App\Support\Money;
use DateTimeImmutable;

/**
 * Read side of the dashboard: the state snapshot, decision catch-up and chart series.
 * Reads repricer tables only; the market's view of the world comes from the audited snapshots.
 */
final class DashboardQuery
{
    public function __construct(
        private readonly MarketAdapter $market,
        private readonly RepricerSettings $settings,
        private readonly ProductPresenter $products,
    ) {}

    public function marketNow(): DateTimeImmutable
    {
        try {
            return $this->market->now();
        } catch (\Throwable) {
            return new DateTimeImmutable('@0');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function state(int $decisionLimit = 60): array
    {
        $now = $this->marketNow();

        return [
            'market_time' => $now->format(DATE_ATOM),
            'settings' => $this->settings(),
            'products' => Product::query()->with('rule')->orderBy('id')->get()
                ->map(fn (Product $p) => $this->products->present($p, $now))->values()->all(),
            'decisions' => $this->decisionsBefore(null, $decisionLimit),
            'audit' => $this->audit(15),
        ];
    }

    /**
     * @return array{kill_switch: bool, dry_run: bool}
     */
    public function settings(): array
    {
        return ['kill_switch' => $this->settings->killSwitch(), 'dry_run' => $this->settings->dryRun()];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function product(int $id): ?array
    {
        $p = Product::query()->with('rule')->find($id);

        return $p === null ? null : $this->products->present($p, $this->marketNow());
    }

    /**
     * Newest first, optionally before an id (paging back).
     *
     * @return list<array<string, mixed>>
     */
    public function decisionsBefore(?int $beforeId, int $limit, ?int $productId = null): array
    {
        return PriceDecision::query()
            ->with(['snapshots' => fn ($q) => $q->orderBy('id'), 'pushes'])
            ->when($beforeId !== null, fn ($q) => $q->where('id', '<', $beforeId))
            ->when($productId !== null, fn ($q) => $q->where('product_id', $productId))
            ->latest('id')->limit(max(1, min(200, $limit)))->get()
            ->map(fn (PriceDecision $d) => DecisionPresenter::present($d))->values()->all();
    }

    /**
     * Everything after an id, oldest first: what a reconnecting client missed.
     *
     * @return list<array<string, mixed>>
     */
    public function decisionsAfter(int $afterId, int $limit = 200): array
    {
        return PriceDecision::query()
            ->with(['snapshots' => fn ($q) => $q->orderBy('id'), 'pushes'])
            ->where('id', '>', $afterId)
            ->orderBy('id')->limit(max(1, min(500, $limit)))->get()
            ->map(fn (PriceDecision $d) => DecisionPresenter::present($d))->values()->all();
    }

    /**
     * Chart data for one product over the last $hours of market time: one point per decision
     * snapshot (landed prices per seller, who held the Buy Box), plus the price bands.
     *
     * @return array<string, mixed>
     */
    public function series(Product $product, int $hours = 3, int $maxPoints = 600): array
    {
        $now = $this->marketNow();
        $since = $now->modify("-{$hours} hours");
        $rule = $product->rule;

        $decisions = PriceDecision::query()
            ->with(['snapshots' => fn ($q) => $q->orderBy('id')])
            ->where('product_id', $product->id)
            ->where('event_time', '>=', $since)
            ->orderByDesc('event_time')->orderByDesc('id')
            ->limit($maxPoints)->get()->reverse()->values();

        // Carry the last known prices into the window: a quiet listing still has a chart.
        $before = PriceDecision::query()
            ->with(['snapshots' => fn ($q) => $q->orderBy('id')])
            ->where('product_id', $product->id)
            ->where('event_time', '<', $since)
            ->whereHas('snapshots')
            ->orderByDesc('event_time')->orderByDesc('id')
            ->first();
        if ($before !== null) {
            $decisions->prepend($before);
        }

        $points = [];
        foreach ($decisions as $d) {
            $t = max($d->event_time->getTimestamp(), $since->getTimestamp()) * 1000;
            $point = ['t' => $t, 'decision_id' => $d->id, 'buybox' => null, 'prices' => []];
            foreach ($d->snapshots as $s) {
                $key = $s->is_ours ? 'ours' : $s->seller;
                $point['prices'][$key] = $s->price->cents + $s->shipping->cents;
                if ($s->is_buybox) {
                    $point['buybox'] = $s->is_ours ? 'ours' : $s->seller;
                }
            }
            $points[] = $point;
        }

        return [
            'product_id' => $product->id,
            'now' => $now->getTimestamp() * 1000,
            'from' => $since->getTimestamp() * 1000,
            // Bands are on landed price, like the lines: our floor/ceiling plus our shipping.
            'floor' => $rule === null ? null : $rule->floor->plus($product->shipping)->cents,
            'ceiling' => $rule === null ? null : $rule->ceiling->plus($product->shipping)->cents,
            'margin_floor' => $rule === null ? null : $product->cost->plus($product->fees)->plus($rule->min_margin)->plus($product->shipping)->cents,
            'points' => $points,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function audit(int $limit): array
    {
        return AuditEntry::query()->latest('id')->limit($limit)->get()->map(fn (AuditEntry $a) => [
            'id' => $a->id,
            'action' => $a->action,
            'product_id' => $a->product_id,
            'actor' => $a->actor,
            'reason' => $a->reason,
            'before' => $a->before,
            'after' => $a->after,
            'market_time' => $a->market_time?->format(DATE_ATOM),
        ])->values()->all();
    }

    public static function money(int $cents): string
    {
        return Money::cents($cents)->format();
    }
}
