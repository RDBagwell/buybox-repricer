<?php

namespace App\Repricer\Console;

use App\Repricer\Pricing\CooldownSweeper;
use Illuminate\Console\Command;

class RecheckCooldownsCommand extends Command
{
    protected $signature = 'repricer:recheck-cooldowns';

    protected $description = 'Re-decide products whose cooldown expired since an event was skipped (repricer:listen also does this continuously).';

    public function handle(CooldownSweeper $sweeper): int
    {
        $this->info('Queued '.$sweeper->sweep().' re-check(s).');

        return self::SUCCESS;
    }
}
