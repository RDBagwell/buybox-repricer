<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\UpdatePricingRuleRequest;
use App\Repricer\Dashboard\DashboardQuery;
use App\Repricer\Events\ProductUpdated;
use App\Repricer\Market\MarketAdapter;
use App\Repricer\Models\Product;
use App\Repricer\Safety\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function __construct(
        private readonly AuditLog $audit,
        private readonly DashboardQuery $query,
        private readonly MarketAdapter $market,
    ) {}

    public function pause(Request $request, Product $product): JsonResponse
    {
        if (! $product->paused) {
            DB::transaction(function () use ($request, $product) {
                $product->forceFill(['paused' => true, 'paused_reason' => 'Paused by an operator.', 'paused_at' => $this->market->now()])->save();
                $this->audit->record('product.paused', Actor::of($request), $product->id, ['paused' => false], ['paused' => true]);
            });
            ProductUpdated::dispatch($product->id);
        }

        return response()->json(['product' => $this->query->product($product->id)]);
    }

    /**
     * Resuming (including after a circuit-breaker trip) is a deliberate act: the request must
     * acknowledge it, and it is audited with the reason the product was paused.
     */
    public function resume(Request $request, Product $product): JsonResponse
    {
        $request->validate(['acknowledge' => ['accepted']]);

        if ($product->paused) {
            $reason = $product->paused_reason;
            DB::transaction(function () use ($request, $product, $reason) {
                $product->forceFill(['paused' => false, 'paused_reason' => null, 'paused_at' => null])->save();
                $this->audit->record('product.resumed', Actor::of($request), $product->id, ['paused' => true, 'paused_reason' => $reason], ['paused' => false]);
            });
            ProductUpdated::dispatch($product->id);
        }

        return response()->json(['product' => $this->query->product($product->id)]);
    }

    public function updateRule(UpdatePricingRuleRequest $request, Product $product): JsonResponse
    {
        $rule = $product->rule ?? abort(404);
        $data = $request->validated();
        $fields = ['strategy', 'offset', 'floor', 'ceiling', 'min_margin', 'max_step_pct', 'cooldown_sec', 'min_competitor_rating', 'max_competitor_handling_days', 'no_competition'];
        $snapshot = fn () => collect($fields)->mapWithKeys(fn (string $f) => [$f => $rule->getRawOriginal($f)])->all();

        DB::transaction(function () use ($request, $product, $rule, $data, $fields, $snapshot) {
            $before = $snapshot();
            // Explicit field list: nothing outside the validated rule fields can be written.
            $rule->forceFill(array_intersect_key($data, array_flip($fields)))->save();
            $rule->refresh();
            $after = $snapshot();
            $changed = array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));
            $this->audit->record('rule.updated', Actor::of($request), $product->id,
                array_intersect_key($before, array_flip($changed)), array_intersect_key($after, array_flip($changed)));
        });

        ProductUpdated::dispatch($product->id);

        return response()->json(['product' => $this->query->product($product->id)]);
    }
}
