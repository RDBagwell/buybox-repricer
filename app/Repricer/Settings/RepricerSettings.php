<?php

namespace App\Repricer\Settings;

use App\Repricer\Events\SettingsChanged;
use App\Repricer\Models\Setting;

/**
 * Global switches stored in the settings table. Read fresh on every decision and push, so
 * flipping one takes effect on the next job without restarting workers.
 */
final class RepricerSettings
{
    public const KILL_SWITCH = 'kill_switch';

    public const DRY_RUN = 'dry_run';

    public function killSwitch(): bool
    {
        return $this->bool(self::KILL_SWITCH);
    }

    public function dryRun(): bool
    {
        return $this->bool(self::DRY_RUN);
    }

    public function set(string $key, bool $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value ? '1' : '0']);
        SettingsChanged::dispatch();
    }

    private function bool(string $key): bool
    {
        return Setting::query()->whereKey($key)->value('value') === '1';
    }
}
