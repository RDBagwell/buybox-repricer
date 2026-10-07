<?php

namespace App\Http\Controllers\Dashboard;

use App\Demo\SimulatorPanel;
use App\Http\Controllers\Controller;
use App\Repricer\Safety\AuditLog;
use App\Simulator\SimulationRunner;
use App\Simulator\SimulatorControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class SimulatorController extends Controller
{
    public function __construct(
        private readonly SimulatorControl $control,
        private readonly SimulatorPanel $panel,
        private readonly AuditLog $audit,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(['simulator' => $this->panel->state()]);
    }

    public function speed(Request $request): JsonResponse
    {
        $max = config('demo.enabled') ? (int) config('demo.max_speed') : 100;
        $validated = $request->validate(['speed' => ['required', 'integer', 'min:1', "max:{$max}"]]);
        $this->control->setSpeed((int) $validated['speed']);

        return $this->done($request, 'sim.speed', $validated);
    }

    public function running(Request $request): JsonResponse
    {
        $validated = $request->validate(['running' => ['required', 'boolean']]);
        $this->control->setRunning((bool) $validated['running']);

        return $this->done($request, 'sim.running', $validated);
    }

    public function faults(Request $request): JsonResponse
    {
        $max = (int) config('demo.max_fault_bps');
        $validated = $request->validate([
            'http_429_bps' => ['required', 'integer', 'min:0', "max:{$max}"],
            'http_503_bps' => ['required', 'integer', 'min:0', "max:{$max}"],
        ]);
        $this->control->setFaults((int) $validated['http_429_bps'], (int) $validated['http_503_bps']);

        return $this->done($request, 'sim.faults', $validated);
    }

    public function addBot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'asin' => ['required', 'string', 'max:16'],
            'type' => ['required', 'string', Rule::in(['penny_pincher', 'anchor', 'matcher', 'sleeper', 'chaos'])],
        ]);

        return $this->attempt(fn () => $this->control->addBot($validated['asin'], $validated['type']), $request, 'sim.bot_added', $validated);
    }

    public function removeBot(Request $request, string $asin, string $seller): JsonResponse
    {
        return $this->attempt(fn () => $this->control->removeBot($asin, $seller), $request, 'sim.bot_removed', ['asin' => $asin, 'seller' => $seller]);
    }

    public function stockout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'asin' => ['required', 'string', 'max:16'],
            'seller' => ['required', 'string', 'max:64'],
            'ticks' => ['nullable', 'integer', 'min:1', 'max:400'],
        ]);

        return $this->attempt(fn () => $this->control->stockout($validated['asin'], $validated['seller'], (int) ($validated['ticks'] ?? 60)), $request, 'sim.stockout', $validated);
    }

    public function reset(Request $request, SimulationRunner $runner): JsonResponse
    {
        $runner->reset((int) config('simulator.seed', 42));

        return $this->done($request, 'sim.reset', ['seed' => (int) config('simulator.seed', 42)]);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function attempt(\Closure $action, Request $request, string $auditAction, array $details): JsonResponse
    {
        try {
            $action();
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->done($request, $auditAction, $details);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function done(Request $request, string $auditAction, array $details): JsonResponse
    {
        $this->audit->record($auditAction, Actor::of($request), null, null, $details);

        return response()->json(['simulator' => $this->panel->state()]);
    }
}
