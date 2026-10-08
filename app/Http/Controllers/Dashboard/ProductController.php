<?php

namespace App\Http\Controllers\Dashboard;

use App\Demo\SimulatorPanel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\StoreProductRequest;
use App\Http\Requests\Dashboard\UpdatePricingRuleRequest;
use App\Repricer\Catalog\Channel;
use App\Repricer\Dashboard\DashboardQuery;
use App\Repricer\Events\ProductUpdated;
use App\Repricer\Market\MarketAdapter;
use App\Repricer\Models\AuditEntry;
use App\Repricer\Models\Product;
use App\Repricer\Pricing\RulePreview;
use App\Repricer\Safety\AuditLog;
use App\Simulator\Engine\Listing;
use App\Simulator\Engine\SimOffer;
use App\Simulator\SimulatorControl;
use App\Support\Fulfillment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProductController extends Controller
{
    /** @var list<string> */
    private const DEFAULT_RESTORE_COMPETITORS = ['penny_pincher', 'anchor'];

    public function __construct(
        private readonly AuditLog $audit,
        private readonly DashboardQuery $query,
        private readonly MarketAdapter $market,
        private readonly SimulatorControl $simulator,
        private readonly SimulatorPanel $panel,
    ) {}

    /**
     * Add a product to the catalogue and open a matching listing in the simulated marketplace
     * (our offer plus the chosen competitor bots). Its first offer-change notification is
     * published at once, so the repricer decides on it straight away.
     *
     * The repricer and the simulator never import each other; this controller is where the two
     * meet, as it would be for an operator adding a product in a real seller account.
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $max = (int) config('demo.max_products');
        if (config('demo.enabled') && Product::query()->active()->count() >= $max) {
            return response()->json(['message' => "The public demo holds at most {$max} products. Archive one first."], 422);
        }

        $data = $request->validated();
        /** @var list<string> $competitors */
        $competitors = array_values($data['competitors']);
        $asin = $this->simulator->nextAsin(array_values(Product::query()->pluck('asin')->map(fn ($a) => (string) $a)->all()));
        $ruleFields = ['strategy', 'offset', 'floor', 'ceiling', 'min_margin', 'max_step_pct', 'cooldown_sec', 'min_competitor_rating', 'max_competitor_handling_days', 'no_competition'];

        $product = DB::transaction(function () use ($request, $data, $asin, $ruleFields, $competitors) {
            // Explicit field lists: nothing outside the validated fields can be written.
            $product = Product::query()->create([
                'asin' => $asin,
                'channel' => $data['channel'] ?? Channel::BuyBox->value,
                'sku' => $data['sku'],
                'title' => $data['title'],
                'cost' => $data['cost'],
                'fees' => $data['fees'],
                'shipping' => $data['shipping'],
                'current_price' => $data['price'],
            ]);
            $product->rule()->create(array_intersect_key($data, array_flip($ruleFields)));
            $this->audit->record('product.created', Actor::of($request), $product->id, null,
                array_intersect_key($data, array_flip(['sku', 'title', 'cost', 'fees', 'shipping', 'price', ...$ruleFields])) + ['asin' => $asin, 'channel' => $data['channel'] ?? Channel::BuyBox->value, 'competitors' => $competitors]);

            return $product;
        });

        try {
            $this->simulator->addListing($asin, $product->title, $this->ourOffer($product), $competitors, $this->listingModel($product));
        } catch (InvalidArgumentException $e) {
            // Nothing has been decided for it yet: take the catalogue entry back out.
            DB::transaction(function () use ($product) {
                $product->rule()->delete();
                $product->delete();
            });

            return response()->json(['message' => $e->getMessage()], 422);
        }

        ProductUpdated::dispatch($product->id);

        return response()->json([
            'product' => $this->query->product($product->id),
            'simulator' => $this->panel->state(),
        ], 201);
    }

    /**
     * Archive (never delete): the product stops repricing and leaves the marketplace simulation,
     * but its decisions, pushes and audit trail stay, because those tables are append-only.
     */
    public function archive(Request $request, Product $product): JsonResponse
    {
        $request->validate(['confirm' => ['accepted']]);

        if (! $product->isArchived()) {
            $now = $this->market->now();
            // Remembered on the audit row, so a restore brings the same competitors back.
            $competitors = $this->simulator->listingBots($product->asin);
            DB::transaction(function () use ($request, $product, $now, $competitors) {
                $before = ['archived' => false, 'paused' => $product->paused];
                $product->forceFill([
                    'archived_at' => $now,
                    'paused' => true,
                    'paused_reason' => 'Archived by an operator.',
                    'paused_at' => $product->paused_at ?? $now,
                ])->save();
                $this->audit->record('product.archived', Actor::of($request), $product->id, $before, ['archived' => true, 'paused' => true, 'competitors' => $competitors]);
            });
            $this->simulator->removeListing($product->asin);
            ProductUpdated::dispatch($product->id);
        }

        return response()->json([
            'product' => $this->query->product($product->id),
            'simulator' => $this->panel->state(),
        ]);
    }

    /**
     * Bring an archived product back. It rejoins the marketplace simulation at its last price with
     * the competitors it had when it was archived (kept on the archive's audit row), and
     * repricing resumes. Restoring is as deliberate as archiving: the request must confirm it.
     */
    public function restore(Request $request, Product $product): JsonResponse
    {
        $request->validate(['confirm' => ['accepted']]);

        if (! $product->isArchived()) {
            // Already active: nothing to do (like archiving an archived product).
            return response()->json(['product' => $this->query->product($product->id), 'simulator' => $this->panel->state()]);
        }
        $max = (int) config('demo.max_products');
        if (config('demo.enabled') && Product::query()->active()->count() >= $max) {
            return response()->json(['message' => "The public demo holds at most {$max} products. Archive one first."], 422);
        }

        $competitors = $this->archivedCompetitors($product);
        try {
            $this->simulator->addListing($product->asin, $product->title, $this->ourOffer($product), $competitors, $this->listingModel($product));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        try {
            DB::transaction(function () use ($request, $product, $competitors) {
                $product->forceFill([
                    'archived_at' => null,
                    'paused' => false,
                    'paused_reason' => null,
                    'paused_at' => null,
                ])->save();
                $this->audit->record('product.restored', Actor::of($request), $product->id,
                    ['archived' => true, 'paused' => true],
                    ['archived' => false, 'paused' => false, 'competitors' => $competitors]);
            });
        } catch (\Throwable $e) {
            $this->simulator->removeListing($product->asin); // still archived: take it back off the market

            throw $e;
        }

        ProductUpdated::dispatch($product->id);

        return response()->json([
            'product' => $this->query->product($product->id),
            'simulator' => $this->panel->state(),
        ]);
    }

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
        if ($product->isArchived()) {
            return response()->json(['message' => 'This product is archived; it can no longer be resumed.'], 422);
        }

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

    /**
     * The rule editor's live preview: what the draft rules would do against the latest market
     * snapshot. Read-only (nothing is decided, pushed or audited), so it has its own, looser
     * rate limit than the mutations.
     */
    public function previewRule(UpdatePricingRuleRequest $request, Product $product, RulePreview $preview): JsonResponse
    {
        if ($product->rule === null || $product->isArchived()) {
            abort(404);
        }

        return response()->json(['preview' => $preview->preview($product, $request->validated())]);
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

    /** Our offer on the simulated marketplace, at the product's current price. */
    private function ourOffer(Product $product): SimOffer
    {
        return new SimOffer(
            (string) config('market.seller_id'),
            $product->current_price,
            $product->shipping,
            Fulfillment::Marketplace,
            98,
            1,
            $product->sku,
        );
    }

    private function listingModel(Product $product): string
    {
        return $product->channel->hasBuyBox() ? Listing::BUYBOX : Listing::OPEN;
    }

    /**
     * The competitors recorded when the product was last archived. Products archived before
     * competitors were recorded come back against a penny-pincher and an anchor.
     *
     * @return list<string>
     */
    private function archivedCompetitors(Product $product): array
    {
        $entry = AuditEntry::query()
            ->where('product_id', $product->id)
            ->where('action', 'product.archived')
            ->latest('id')
            ->first();
        $recorded = $entry?->after['competitors'] ?? null;
        if (! is_array($recorded)) {
            return self::DEFAULT_RESTORE_COMPETITORS;
        }

        $competitors = [];
        foreach ($recorded as $type) {
            if (is_string($type)) {
                $competitors[] = $type;
            }
        }

        return $competitors;
    }
}
