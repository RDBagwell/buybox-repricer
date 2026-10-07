<?php

namespace App\Repricer\Console;

use App\Repricer\Jobs\PushPriceJob;
use App\Repricer\Models\PriceDecision;
use App\Repricer\Outbound\PushStatus;
use App\Repricer\Pricing\DecisionStatus;
use Illuminate\Console\Command;

/**
 * Safety net for a worker dying between recording a reprice and queueing (or finishing) its
 * push: re-queue every reprice older than --older-than seconds with no terminal push row.
 * Pushes are idempotent, so re-queueing one that is merely slow is harmless.
 */
class ResumePushesCommand extends Command
{
    protected $signature = 'repricer:resume-pushes {--older-than=60 : Seconds since the decision was recorded}';

    protected $description = 'Re-queue reprice decisions whose push never finished.';

    public function handle(): int
    {
        $terminal = PushStatus::terminalValues();

        $ids = PriceDecision::query()
            ->where('outcome', DecisionStatus::Reprice->value)
            ->where('created_at', '<', now()->subSeconds((int) $this->option('older-than')))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('price_pushes')
                ->whereColumn('price_pushes.decision_id', 'price_decisions.id')
                ->whereIn('status', $terminal))
            ->orderBy('id')
            ->limit(500)
            ->pluck('id');

        foreach ($ids as $id) {
            PushPriceJob::dispatch((int) $id);
        }

        $this->info("Re-queued {$ids->count()} push(es).");

        return self::SUCCESS;
    }
}
