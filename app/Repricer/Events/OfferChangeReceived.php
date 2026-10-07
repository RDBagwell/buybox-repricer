<?php

namespace App\Repricer\Events;

use App\Repricer\Market\OfferChangeNotification;
use Illuminate\Foundation\Events\Dispatchable;

final class OfferChangeReceived
{
    use Dispatchable;

    public function __construct(public readonly OfferChangeNotification $notification) {}
}
