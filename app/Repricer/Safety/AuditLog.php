<?php

namespace App\Repricer\Safety;

use App\Repricer\Market\MarketAdapter;
use App\Repricer\Models\AuditEntry;

/**
 * Writes operator and safety actions to the append-only audit_log, stamped with market time.
 */
final class AuditLog
{
    public const SYSTEM = 'system';

    public function __construct(private readonly MarketAdapter $market) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(string $action, string $actor, ?int $productId = null, ?array $before = null, ?array $after = null, ?string $reason = null): AuditEntry
    {
        return AuditEntry::query()->create([
            'action' => $action,
            'product_id' => $productId,
            'actor' => $actor,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'market_time' => $this->marketNow(),
        ]);
    }

    private function marketNow(): ?\DateTimeImmutable
    {
        try {
            return $this->market->now();
        } catch (\Throwable) {
            return null; // the market clock is unavailable (e.g. before the simulator exists)
        }
    }
}
