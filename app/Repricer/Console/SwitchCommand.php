<?php

namespace App\Repricer\Console;

use App\Repricer\Settings\RepricerSettings;
use Illuminate\Console\Command;

class SwitchCommand extends Command
{
    protected $signature = 'repricer:switch {name : kill_switch or dry_run} {state? : on|off (omit to show)}';

    protected $description = 'Show or flip the global kill switch or dry-run mode.';

    public function handle(RepricerSettings $settings): int
    {
        $name = (string) $this->argument('name');
        if (! in_array($name, [RepricerSettings::KILL_SWITCH, RepricerSettings::DRY_RUN], true)) {
            $this->error('name must be kill_switch or dry_run');

            return self::INVALID;
        }

        $state = $this->argument('state');
        if ($state !== null) {
            if (! in_array($state, ['on', 'off'], true)) {
                $this->error('state must be on or off');

                return self::INVALID;
            }
            $settings->set($name, $state === 'on');
        }

        $on = $name === RepricerSettings::KILL_SWITCH ? $settings->killSwitch() : $settings->dryRun();
        $this->info("{$name}: ".($on ? 'ON' : 'off'));

        return self::SUCCESS;
    }
}
