<?php

namespace App\Repricer\Market;

/** HTTP 503: temporarily unavailable. Honour retryAfterMs when present. */
final class ServiceUnavailableException extends MarketApiException {}
