<?php

namespace App\Repricer\Outbound;

enum PushStatus: string
{
    case Succeeded = 'succeeded';
    case Retrying = 'retrying';     // a 429/503 attempt that will be retried
    case Failed = 'failed';         // gave up (attempt cap or non-retryable error)
    case Superseded = 'superseded'; // a newer decision for the product exists
    case Cancelled = 'cancelled';   // kill switch or dry run turned on before the push
    case Blocked = 'blocked';       // circuit breaker open

    public function isTerminal(): bool
    {
        return $this !== self::Retrying;
    }
}
