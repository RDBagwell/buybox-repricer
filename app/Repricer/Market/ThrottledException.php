<?php

namespace App\Repricer\Market;

/** HTTP 429: quota exceeded. Honour retryAfterMs. */
final class ThrottledException extends MarketApiException {}
