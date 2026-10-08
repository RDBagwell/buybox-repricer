<?php

namespace App\Http\Controllers\Dashboard;

use App\Demo\ResetSchedule;
use App\Demo\SimulatorPanel;
use App\Http\Controllers\Controller;
use App\Repricer\Dashboard\DashboardChannel;
use App\Repricer\Dashboard\DashboardQuery;
use App\Repricer\Models\Product;
use App\Simulator\Console\SimDaemonCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardQuery $query,
        private readonly SimulatorPanel $simulator,
    ) {}

    public function page(): Response
    {
        $this->heartbeat();

        return Inertia::render('repricer/dashboard', [
            'initial' => $this->stateArray(),
            'config' => [
                'demo' => (bool) config('demo.enabled'),
                'replay' => false,
                'channel' => DashboardChannel::NAME,
                'private_channel' => DashboardChannel::isPrivate(),
                'reverb' => config('demo.reverb_client'),
                'our_seller_id' => config('market.seller_id'),
                'reset_minutes' => config('demo.enabled') ? ResetSchedule::minutes((int) config('demo.reset_minutes')) : null,
                'featured_sku' => config('demo.featured_sku'),
            ],
        ]);
    }

    public function state(): JsonResponse
    {
        $this->heartbeat();

        return response()->json($this->stateArray());
    }

    /** Sent every 30s by a visible dashboard tab, so the demo world keeps ticking while watched. */
    public function ping(): \Illuminate\Http\Response
    {
        $this->heartbeat();

        return response()->noContent();
    }

    public function decisions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'after_id' => ['nullable', 'integer', 'min:0'],
            'before_id' => ['nullable', 'integer', 'min:1'],
            'product_id' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $decisions = isset($validated['after_id'])
            ? $this->query->decisionsAfter((int) $validated['after_id'], (int) ($validated['limit'] ?? 200))
            : $this->query->decisionsBefore(isset($validated['before_id']) ? (int) $validated['before_id'] : null, (int) ($validated['limit'] ?? 50), isset($validated['product_id']) ? (int) $validated['product_id'] : null);

        return response()->json(['decisions' => $decisions]);
    }

    public function series(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate(['hours' => ['nullable', 'integer', 'min:1', 'max:24']]);
        $product->load('rule');

        return response()->json($this->query->series($product, (int) ($validated['hours'] ?? 3)));
    }

    /**
     * @return array<string, mixed>
     */
    private function stateArray(): array
    {
        return $this->query->state() + ['simulator' => $this->simulator->state()];
    }

    /** The simulator daemon only ticks while someone is watching (see sim:daemon --idle-after). */
    private function heartbeat(): void
    {
        Cache::put(SimDaemonCommand::HEARTBEAT_KEY, time(), 3600);
    }
}
