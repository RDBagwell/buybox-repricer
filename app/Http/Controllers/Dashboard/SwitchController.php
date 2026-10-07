<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Repricer\Dashboard\DashboardQuery;
use App\Repricer\Safety\AuditLog;
use App\Repricer\Settings\RepricerSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SwitchController extends Controller
{
    public function __construct(
        private readonly RepricerSettings $settings,
        private readonly AuditLog $audit,
        private readonly DashboardQuery $query,
    ) {}

    /** The kill switch needs an explicit confirmation in the request, not just a click. */
    public function killSwitch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'on' => ['required', 'boolean'],
            'confirm' => ['accepted'],
        ]);

        return $this->flip(RepricerSettings::KILL_SWITCH, (bool) $validated['on'], $request);
    }

    public function dryRun(Request $request): JsonResponse
    {
        $validated = $request->validate(['on' => ['required', 'boolean']]);

        return $this->flip(RepricerSettings::DRY_RUN, (bool) $validated['on'], $request);
    }

    private function flip(string $key, bool $on, Request $request): JsonResponse
    {
        $before = $this->query->settings();
        $this->settings->set($key, $on);
        $this->audit->record("{$key}.".($on ? 'on' : 'off'), Actor::of($request), null, $before, $this->query->settings());

        return response()->json(['settings' => $this->query->settings()]);
    }
}
