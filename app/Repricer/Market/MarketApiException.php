<?php

namespace App\Repricer\Market;

use RuntimeException;

class MarketApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public readonly Operation $operation,
        public readonly int $status,
        string $message,
        public readonly ?int $retryAfterMs = null,
        public readonly array $body = [],
    ) {
        parent::__construct($message, $status);
    }

    public function isRetryable(): bool
    {
        return $this->status === 429 || $this->status >= 500;
    }
}
